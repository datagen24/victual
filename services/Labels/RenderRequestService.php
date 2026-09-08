<?php

namespace Victual\Services\Labels;

/**
 * Durable render requests: pending -> rendering -> ready | invalid | failed.
 *
 * The state lives in the database and not in a process, because plan 27 piece 4 requires
 * that no request depend on a live Victual process remembering to launch something. A
 * renderer that scales to zero has to be able to find work that was queued while it did not
 * exist, and a dispatcher that lost an invocation has to be able to find the same work
 * again.
 *
 * **This is the one place in the label subsystem where automatic retry is allowed**, and the
 * reason is structural rather than a judgement about how likely a failure is: a renderer
 * cannot touch a printer. ADR-0019's no-automatic-redispatch rule is about physical
 * attempts, and retrying a render can no more produce a second label than recomputing a
 * digest can. Plan 25 piece 4 makes that true by construction by giving the render
 * invocation no printer access at all.
 */
class RenderRequestService extends LabelService
{
    public const LEASE_SECONDS = 120;
    public const MAX_ATTEMPTS = 5;

    /** An unpromoted preview expires after 24 hours (plan 27 piece 6). */
    public const PREVIEW_TTL_SECONDS = 86400;

    public function __construct(\PDO $db, private int $leaseSeconds = self::LEASE_SECONDS)
    {
        parent::__construct($db);
    }

    public function CreateForVersion(string $purpose, int $templateVersionId, int $profileId, int $captureId, ?int $userId): array
    {
        $this->Transaction();
        if (!in_array($purpose, ['production', 'preview_live', 'preview_sample'], true)) {
            $this->Refuse('purpose', 'invalid_value', 'A published version renders for production, preview_live or preview_sample');
        }
        $expires = $purpose === 'production' ? null : 'CURRENT_TIMESTAMP+make_interval(secs=>' . self::PREVIEW_TTL_SECONDS . ')';
        return $this->Query('INSERT INTO label_render_requests(purpose,template_version_id,profile_id,capture_id,created_by_user_id,expires_at)
            VALUES (?,?,?,?,?,' . ($expires ?? 'NULL') . ') RETURNING *', [$purpose, $templateVersionId, $profileId, $captureId, $userId])->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * A draft preview pins the document it was asked about, so an edit landing while the
     * render is in flight cannot change the result somebody is waiting to look at.
     */
    public function CreateForDraft(int $templateId, array $document, int $profileId, int $captureId, ?int $userId): array
    {
        $this->Transaction();
        return $this->Query('INSERT INTO label_render_requests(purpose,draft_template_id,draft_document,profile_id,capture_id,created_by_user_id,expires_at)
            VALUES (?,?,?::jsonb,?,?,?,CURRENT_TIMESTAMP+make_interval(secs=>?)) RETURNING *',
            ['preview_draft', $templateId, $this->Json($document), $profileId, $captureId, $userId, self::PREVIEW_TTL_SECONDS])->fetch(\PDO::FETCH_ASSOC);
    }

    public function Get(int $requestId): array
    {
        $row = $this->Query('SELECT * FROM label_render_requests WHERE id=?', [$requestId])->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            $this->Refuse('render_request_id', 'not_found', 'No such render request');
        }
        return $row;
    }

    /**
     * Returns an expired lease to the queue.
     *
     * The generation is bumped as part of it, which is the fence: a renderer that comes back
     * from the dead holding the old token cannot commit its result over the one the
     * replacement produced. Bounded by MAX_ATTEMPTS, past which the request is `failed` and
     * a person looks at it - an unbounded retry of a render that always fails is a loop, not
     * resilience.
     */
    public function ReapExpired(): int
    {
        $this->Transaction();
        $reaped = $this->Query("UPDATE label_render_requests
            SET state=CASE WHEN attempts>=? THEN 'failed' ELSE 'pending' END,
                generation=generation+1, generation_token=NULL, lease_expires_at=NULL,
                error_code=CASE WHEN attempts>=? THEN 'RENDER_LEASE_EXHAUSTED' ELSE error_code END,
                error_detail=CASE WHEN attempts>=? THEN 'The render lease expired ' || attempts || ' times' ELSE error_detail END
            WHERE state='rendering' AND lease_expires_at<=CURRENT_TIMESTAMP RETURNING id", [self::MAX_ATTEMPTS, self::MAX_ATTEMPTS, self::MAX_ATTEMPTS]);
        return count($reaped->fetchAll());
    }

    /**
     * Claims the oldest pending request and returns everything the renderer needs.
     *
     * The renderer is handed a resolved document: the template, the captured values, the
     * profile geometry and the QR module matrix Victual computed. It fetches its assets by
     * id through its own authorized route and reaches nothing else - it accepts no
     * caller-controlled fetch destination, which is why there is no URL anywhere in here.
     */
    public function Claim(): ?array
    {
        $this->Transaction();
        $this->ReapExpired();

        $request = $this->Query("SELECT * FROM label_render_requests
            WHERE state='pending' AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)
            ORDER BY id FOR UPDATE SKIP LOCKED LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        if (!$request) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        $request = $this->Query("UPDATE label_render_requests
            SET state='rendering', generation=generation+1, generation_token=?, attempts=attempts+1,
                lease_expires_at=CURRENT_TIMESTAMP+make_interval(secs=>?)
            WHERE id=? RETURNING *", [$token, $this->leaseSeconds, $request['id']])->fetch(\PDO::FETCH_ASSOC);

        return $this->Input($request);
    }

    /** The resolved render input for a request, without claiming it. */
    public function Input(array $request): array
    {
        $capture = (new LabelCaptureService($this->db))->Get((int)$request['capture_id']);
        $profile = (new MediaProfileService($this->db))->Get((int)$request['profile_id']);

        if ($request['purpose'] === 'preview_draft') {
            $document = json_decode($request['draft_document'], true);
            $assetNames = self::AssetNames($document);
            $assets = [];
            foreach ($assetNames as $name) {
                $row = $this->Query('SELECT id,name,asset_kind,mime_type,content_digest,font_family FROM label_assets WHERE name=?', [$name])->fetch(\PDO::FETCH_ASSOC);
                if (!$row) {
                    $this->Refuse('assets', 'asset_unavailable', 'The draft references asset "' . $name . '", which is not stored');
                }
                $assets[$name] = $row;
            }
            $templateIdentity = ['template_id' => (int)$request['draft_template_id'], 'template_version_id' => null, 'document_digest' => null];
        } else {
            $version = $this->Query('SELECT * FROM label_template_versions WHERE id=?', [$request['template_version_id']])->fetch(\PDO::FETCH_ASSOC);
            $document = json_decode($version['document'], true);
            $assets = [];
            foreach (json_decode($version['asset_ids'], true) as $name => $assetId) {
                $assets[$name] = $this->Query('SELECT id,name,asset_kind,mime_type,content_digest,font_family FROM label_assets WHERE id=?', [$assetId])->fetch(\PDO::FETCH_ASSOC);
            }
            $templateIdentity = ['template_id' => (int)$version['template_id'], 'template_version_id' => (int)$version['id'], 'document_digest' => $version['document_digest']];
        }

        // A sample capture has no uid, so its QR carries a payload that is visibly sample
        // data rather than a uid that would resolve to a shelf if somebody scanned the
        // preview off a screen.
        $payload = $capture['label_uid'] !== null
            ? QrMatrix::Payload($capture['label_uid'])
            : 'VCTL:SAMPLE-PREVIEW';

        $qr = [];
        foreach ($document['elements'] as $element) {
            if ($element['type'] === 'qr') {
                $qr[$element['id']] = QrMatrix::For($payload, $element['ec_level']);
            }
        }

        return [
            'render_request_id' => (int)$request['id'],
            'generation' => (int)$request['generation'],
            'generation_token' => $request['generation_token'],
            'purpose' => $request['purpose'],
            'template' => $templateIdentity,
            'document' => $document,
            'capture' => ['id' => (int)$capture['id'], 'fields' => $capture['captured_fields'], 'locale' => $capture['locale'], 'timezone' => $capture['timezone'], 'digest' => $capture['digest']],
            'profile' => ['id' => (int)$profile['id'], 'version' => (int)$profile['version']] + $profile['document'],
            'assets' => array_values($assets),
            'qr' => $qr,
            'expected_form' => ArtifactService::FORM,
        ];
    }

    /**
     * `invalid` is an input or layout error and names the element it is about; `failed` is
     * infrastructure and is retried up to a bound. Conflating them would make a template
     * nobody can fix look like a service that might recover on its own.
     */
    public function RecordInvalid(int $requestId, string $token, string $code, ?string $element, string $detail): array
    {
        $this->Transaction();
        $this->AssertCurrent($requestId, $token);
        return $this->Query("UPDATE label_render_requests SET state='invalid',error_code=?,error_element=?,error_detail=?,generation_token=NULL,lease_expires_at=NULL WHERE id=? RETURNING *",
            [$code, $element, $detail, $requestId])->fetch(\PDO::FETCH_ASSOC);
    }

    public function RecordFailure(int $requestId, string $token, string $detail): array
    {
        $this->Transaction();
        $request = $this->AssertCurrent($requestId, $token);
        $terminal = (int)$request['attempts'] >= self::MAX_ATTEMPTS;
        return $this->Query('UPDATE label_render_requests SET state=?,error_code=?,error_detail=?,generation_token=NULL,lease_expires_at=NULL WHERE id=? RETURNING *',
            [$terminal ? 'failed' : 'pending', 'RENDER_FAILED', $detail, $requestId])->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * A stale result cannot replace a committed artifact, and cannot replace a live newer
     * attempt either. Both are the same check: the token has to be the one the current
     * generation issued.
     */
    public function AssertCurrent(int $requestId, string $token): array
    {
        $request = $this->Query('SELECT * FROM label_render_requests WHERE id=? FOR UPDATE', [$requestId])->fetch(\PDO::FETCH_ASSOC);
        if (!$request) {
            $this->Refuse('render_request_id', 'not_found', 'No such render request');
        }
        if ($request['state'] === 'ready') {
            $this->Refuse('generation_token', 'already_committed', 'This request already has a committed artifact; a stale result cannot replace it');
        }
        if ($request['generation_token'] === null || !hash_equals((string)$request['generation_token'], $token)) {
            $this->Refuse('generation_token', 'stale_generation', 'The render lease has moved on; this result belongs to a superseded generation');
        }
        return $request;
    }

    private static function AssetNames(array $document): array
    {
        $names = [];
        foreach ($document['elements'] as $element) {
            if ($element['type'] === 'text') {
                $names[] = $element['font_asset'];
            } elseif ($element['type'] === 'image') {
                $names[] = $element['asset'];
            }
        }
        return array_values(array_unique($names));
    }
}
