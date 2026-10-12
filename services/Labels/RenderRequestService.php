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

    /**
     * How many pending requests one claim will settle as unrenderable before it gives up and
     * reports nothing to do, and how many expired previews one claim will end or collect.
     * Both bound the work a polling renderer can be made to wait for; the next poll continues.
     */
    public const CLAIM_SETTLE_LIMIT = 20;
    public const EXPIRY_BATCH = 100;

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
            WHERE state='rendering' AND lease_expires_at<=CURRENT_TIMESTAMP RETURNING id,state,error_code,error_detail", [self::MAX_ATTEMPTS, self::MAX_ATTEMPTS, self::MAX_ATTEMPTS])->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($reaped as $request) {
            if ($request['state'] === 'failed') {
                $this->DeadLetterWaitingJobs((int)$request['id'], 'failed', $request['error_code'], $request['error_detail']);
            }
        }
        return count($reaped);
    }

    /**
     * Ends the life of a preview nobody rendered in time (plan 27 piece 6: unpromoted
     * previews expire after 24 hours).
     *
     * An expired request is never claimed, so without this it would stay `pending` for ever
     * and keep being reported as something worth waiting for. No attempt is spent: nothing
     * was tried, and `failed` with `expired` says so.
     */
    private function ExpirePending(): void
    {
        $expired = $this->Query("UPDATE label_render_requests
            SET state='failed', error_code='expired', error_detail='The preview expired before it was rendered'
            WHERE id IN (SELECT id FROM label_render_requests
                WHERE state='pending' AND expires_at IS NOT NULL AND expires_at<=CURRENT_TIMESTAMP
                ORDER BY id LIMIT " . self::EXPIRY_BATCH . " FOR UPDATE SKIP LOCKED)
            RETURNING id")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($expired as $requestId) {
            $this->DeadLetterWaitingJobs((int)$requestId, 'failed', 'expired', 'The preview expired before it was rendered');
        }
    }

    /**
     * A print job waits for exactly one thing, its artifact. When the render request it waits
     * on can no longer produce one, nothing else ever will: the job could never be claimed
     * and the only transition out of "unrendered" is a successful render. It is dead-lettered
     * the way a job with an unreadable payload is, naming the render outcome, so the monitor
     * shows a finished job and the claim query does not carry it for ever.
     *
     * Cancelled jobs already ended and keep their own reason; a job with an artifact is
     * claimable and is not this request's to end.
     */
    private function DeadLetterWaitingJobs(int $requestId, string $state, ?string $code, ?string $detail): void
    {
        $reason = 'Render request ' . $requestId . ' ' . $state . ': ' . ($code ?? 'no code') . ($detail !== null && $detail !== '' ? ' - ' . $detail : '');
        $jobs = $this->Query("UPDATE print_jobs SET outcome='dead_lettered',outcome_at=CURRENT_TIMESTAMP
            WHERE render_request_id=? AND artifact_id IS NULL AND outcome IS NULL AND cancelled_at IS NULL RETURNING outbox_id", [$requestId])->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($jobs as $outboxId) {
            $this->Query('UPDATE outbox SET dead_lettered_at=CURRENT_TIMESTAMP,last_error=? WHERE id=?', [$reason, $outboxId]);
        }
    }

    /**
     * Claims the oldest pending request and returns everything the renderer needs.
     *
     * The renderer is handed a resolved document: the template, the captured values, the
     * profile geometry and the QR module matrix Victual computed. It fetches its assets by
     * id through its own authorized route and reaches nothing else - it accepts no
     * caller-controlled fetch destination, which is why there is no URL anywhere in here.
     *
     * The claim is also the periodic tick of the render queue, because the renderer polls it
     * about once a minute: it returns expired leases, ends expired previews and collects
     * their artifacts, so no separate job has to remember to.
     *
     * A request whose input cannot be resolved (an asset deleted since it was enqueued, say)
     * is settled as `invalid` and the claim moves on to the next one. The refusal is never
     * thrown out of here: the caller's transaction would roll back the attempt that was
     * counted, and the same row would head the queue on every later poll.
     */
    public function Claim(): ?array
    {
        $this->Transaction();
        $this->ReapExpired();
        $this->ExpirePending();
        (new ArtifactService($this->db))->CollectExpiredPreviews(self::EXPIRY_BATCH);

        for ($settled = 0; $settled < self::CLAIM_SETTLE_LIMIT; $settled++) {
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

            try {
                return $this->Input($request);
            } catch (LabelValidationException $refusal) {
                $this->RecordInvalid((int)$request['id'], $token, $refusal->errorCode, null, $refusal->getMessage());
            }
        }
        return null;
    }

    /** The resolved render input for a request, without claiming it. */
    public function Input(array $request): array
    {
        $capture = (new LabelCaptureService($this->db))->Get((int)$request['capture_id']);
        $profile = (new MediaProfileService($this->db))->Get((int)$request['profile_id']);

        if ($request['purpose'] === 'preview_draft') {
            $document = json_decode($request['draft_document'], true);
            $assets = (new LabelAssetService($this->db))->ResolveByName(self::AssetNames($document));
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
        $invalid = $this->Query("UPDATE label_render_requests SET state='invalid',error_code=?,error_element=?,error_detail=?,generation_token=NULL,lease_expires_at=NULL WHERE id=? RETURNING *",
            [$code, $element, $detail, $requestId])->fetch(\PDO::FETCH_ASSOC);
        $this->DeadLetterWaitingJobs($requestId, 'invalid', $code, $detail);
        return $invalid;
    }

    public function RecordFailure(int $requestId, string $token, string $detail): array
    {
        $this->Transaction();
        $request = $this->AssertCurrent($requestId, $token);
        $terminal = (int)$request['attempts'] >= self::MAX_ATTEMPTS;
        $failure = $this->Query('UPDATE label_render_requests SET state=?,error_code=?,error_detail=?,generation_token=NULL,lease_expires_at=NULL WHERE id=? RETURNING *',
            [$terminal ? 'failed' : 'pending', 'RENDER_FAILED', $detail, $requestId])->fetch(\PDO::FETCH_ASSOC);
        if ($terminal) {
            $this->DeadLetterWaitingJobs($requestId, 'failed', 'RENDER_FAILED', $detail);
        }
        return $failure;
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
