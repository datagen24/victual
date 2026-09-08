<?php

namespace Victual\Services\Labels;

/**
 * The operations ADR-0021 decision item 2 fixes: issue, exact reprint, revised print, and
 * promoting a preview - plus attaching a finished artifact to the job that was waiting for
 * it.
 *
 * Two of these look similar and are deliberately different operations:
 *
 * - **An exact reprint replays stored bytes.** Same uid, same capture, same artifact, no
 *   renderer involved at all. That is what makes it renderer-independent, and it is why a
 *   reprint whose bytes have been collected is refused rather than rerendered - a rerender
 *   is a different artifact nobody compared to the first.
 * - **A revised print keeps the uid and captures again.** A renamed location gets a current
 *   label under the same identity, and the earlier artifact is untouched. Recording these as
 *   one operation would lose the distinction that makes a wrong label diagnosable.
 */
class LabelOperationsService extends LabelService
{
    public function __construct(\PDO $db, private $permissionCheck = null, private ?int $userId = null)
    {
        parent::__construct($db);
    }

    /**
     * Issues a label for a location and queues its render. The job exists immediately and is
     * not claimable until the artifact is attached.
     */
    public function IssueLocation(int $locationId, int $epoch, int $printerId, ?int $templateId, ?int $templateVersionId, string $locale, string $timezone): array
    {
        $this->Transaction();

        $identity = new LabelIdentityService($this->db);
        try {
            $uid = $identity->IssueLocation($locationId, $epoch);
        } catch (\RuntimeException $error) {
            $this->Refuse('import_epoch', 'stale_location_context', $error->getMessage());
        }

        $resolved = $this->ResolvePrinter($printerId);
        $version = $this->ResolveTemplate('location', $templateId, $templateVersionId, $resolved['profile']);

        $capture = (new LabelCaptureService($this->db, $this->permissionCheck))
            ->Capture('location', $locationId, $uid, self::FieldsOf($version['document']), $locale, $timezone, $this->userId);

        $request = (new RenderRequestService($this->db))
            ->CreateForVersion('production', (int)$version['id'], (int)$resolved['profile']['id'], (int)$capture['id'], $this->userId);

        return $this->CreateJob('issue', $uid, $capture, $resolved, $version, (int)$request['id'], null, null);
    }

    /**
     * An exact reprint: a new job over the retained bytes of an existing one.
     *
     * The renderer is not consulted and does not need to exist. That is plan 27 verification
     * 5, and it is a property of the design rather than a test fixture: nothing in this path
     * reads a template document.
     */
    public function Reprint(int $sourceJobId, ?int $printerId): array
    {
        $this->Transaction();

        $source = $this->Query('SELECT * FROM print_jobs WHERE id=? FOR UPDATE', [$sourceJobId])->fetch(\PDO::FETCH_ASSOC);
        if (!$source) {
            $this->Refuse('job_id', 'not_found', 'No such print job');
        }
        if ($source['artifact_id'] === null) {
            $this->Refuse('job_id', 'no_artifact', 'That job never had an artifact to replay');
        }

        // Retirement first, then the bytes. A retired label is a refusal about the *thing*,
        // and answering "the bytes are gone" to somebody reprinting a label for a shelf that
        // no longer exists would send them looking for the wrong problem.
        $this->AssertLabelLive((string)$source['label_uid']);

        $artifacts = new ArtifactService($this->db);
        // Reads the bytes rather than the row, so a collected artifact refuses here - where
        // a person asked for it - instead of at claim time.
        $artifacts->Bytes((int)$source['artifact_id']);
        $artifact = $artifacts->Get((int)$source['artifact_id']);

        $resolved = $this->ResolvePrinter($printerId ?? (int)$source['printer_id']);
        $this->AssertArtifactFitsProfile($artifact, $resolved['profile']);

        $capture = (new LabelCaptureService($this->db))->Get((int)$artifact['capture_id']);
        $version = $artifact['template_version_id'] !== null
            ? $this->Query('SELECT * FROM label_template_versions WHERE id=?', [$artifact['template_version_id']])->fetch(\PDO::FETCH_ASSOC)
            : null;

        return $this->CreateJob('reprint', (string)$source['label_uid'], $capture, $resolved, $version, (int)$artifact['render_request_id'], (int)$artifact['id'], $sourceJobId);
    }

    /**
     * A revised print: the same uid, current data, a new capture and a new render. The
     * earlier artifact is not touched.
     */
    public function RevisedPrint(int $locationId, int $epoch, int $printerId, ?int $templateId, ?int $templateVersionId, string $locale, string $timezone): array
    {
        $this->Transaction();

        $uid = $this->Query("SELECT uid FROM labels WHERE kind='location' AND target_id=? AND retired_at IS NULL", [$locationId])->fetchColumn();
        if (!$uid) {
            $this->Refuse('location_id', 'no_live_label', 'That location has no live label; a revised print keeps an existing identity rather than minting one');
        }
        // The epoch guard applies here too: a request composed before an import and executed
        // after it would otherwise capture whatever now holds that id.
        (new LabelIdentityService($this->db))->IssueLocation($locationId, $epoch);

        $resolved = $this->ResolvePrinter($printerId);
        $version = $this->ResolveTemplate('location', $templateId, $templateVersionId, $resolved['profile']);
        $capture = (new LabelCaptureService($this->db, $this->permissionCheck))
            ->Capture('location', $locationId, (string)$uid, self::FieldsOf($version['document']), $locale, $timezone, $this->userId);
        $request = (new RenderRequestService($this->db))
            ->CreateForVersion('production', (int)$version['id'], (int)$resolved['profile']['id'], (int)$capture['id'], $this->userId);

        return $this->CreateJob('revised_print', (string)$uid, $capture, $resolved, $version, (int)$request['id'], null, null);
    }

    /**
     * Promotes an authoritative live preview to a print.
     *
     * The server rechecks print permission, retirement and target-profile compatibility
     * before queueing the same bytes and the same captured values, so "print this preview"
     * is not a way around a check the print path would have made. Sample and draft previews
     * cannot be promoted, which the capture's own `is_sample` and the request's purpose both
     * say.
     */
    public function PromotePreview(int $artifactId, int $printerId): array
    {
        $this->Transaction();

        $artifacts = new ArtifactService($this->db);
        $artifact = $artifacts->Get($artifactId);
        $request = (new RenderRequestService($this->db))->Get((int)$artifact['render_request_id']);

        if ($request['purpose'] !== 'preview_live') {
            $this->Refuse('artifact_id', 'not_promotable', 'Only an authoritative live preview can be promoted; sample and draft previews create no mapping and cannot print');
        }

        $capture = (new LabelCaptureService($this->db))->Get((int)$artifact['capture_id']);
        if ((int)$capture['is_sample'] === 1 || $capture['label_uid'] === null) {
            $this->Refuse('artifact_id', 'not_promotable', 'That preview carries sample data and no label identity');
        }

        $this->AssertLabelLive((string)$capture['label_uid']);
        $artifacts->Bytes($artifactId);
        $resolved = $this->ResolvePrinter($printerId);
        $this->AssertArtifactFitsProfile($artifact, $resolved['profile']);

        // Promotion and collection must not race. Promote() takes the artifact row lock that
        // collection also takes and refuses one already collected, so the ordering is
        // decided by the database rather than by which request arrived first.
        $artifacts->Promote($artifactId);

        $version = $artifact['template_version_id'] !== null
            ? $this->Query('SELECT * FROM label_template_versions WHERE id=?', [$artifact['template_version_id']])->fetch(\PDO::FETCH_ASSOC)
            : null;

        return $this->CreateJob('promote_preview', (string)$capture['label_uid'], $capture, $resolved, $version, (int)$artifact['render_request_id'], $artifactId, null);
    }

    /**
     * Attaches a finished artifact to the job that was waiting for it, and rewrites the
     * outbox payload to name it.
     *
     * This is the moment a job becomes claimable, and it is the only one. It is idempotent:
     * a job that already carries an artifact keeps it, so a repeated renderer result cannot
     * swap the bytes under a job a worker may already be printing.
     */
    public function AttachArtifact(int $renderRequestId, int $artifactId): int
    {
        $this->Transaction();

        $jobs = $this->Query('SELECT * FROM print_jobs WHERE render_request_id=? AND artifact_id IS NULL AND cancelled_at IS NULL FOR UPDATE', [$renderRequestId])->fetchAll(\PDO::FETCH_ASSOC);
        if (!$jobs) {
            return 0;
        }

        $artifact = (new ArtifactService($this->db))->Get($artifactId);
        $attached = 0;

        foreach ($jobs as $job) {
            $payload = json_decode((string)$this->Query('SELECT payload FROM outbox WHERE id=?', [$job['outbox_id']])->fetchColumn(), true);
            $payload['artifact'] = [
                'id' => (int)$artifact['id'], 'form' => $artifact['form'], 'byte_digest' => $artifact['byte_digest'],
                'byte_length' => (int)$artifact['byte_length'], 'width_px' => (int)$artifact['width_px'], 'height_px' => (int)$artifact['height_px'],
                'dpi_x' => (int)$artifact['dpi_x'], 'dpi_y' => (int)$artifact['dpi_y'], 'color_mode' => $artifact['color_mode'],
            ];
            $this->Query('UPDATE outbox SET payload=? WHERE id=?', [$this->Json($payload), $job['outbox_id']]);
            $this->Query('UPDATE print_jobs SET artifact_id=? WHERE id=?', [$artifactId, $job['id']]);
            $this->Query("UPDATE label_artifacts SET retention_class='retained' WHERE id=?", [$artifactId]);
            $attached++;
        }

        return $attached;
    }

    /**
     * Cancels a job that has not been claimed.
     *
     * A cancellation racing a claim produces one truthful outcome and not two: this takes
     * the job row lock the claim takes, and refuses once an attempt exists. Retirement stops
     * *new* claims without rewriting a running attempt as safely cancelled, because a worker
     * that is already talking to a printer is not something a database row can recall.
     */
    public function Cancel(int $jobId, string $reason): array
    {
        $this->Transaction();
        $job = $this->Query('SELECT * FROM print_jobs WHERE id=? FOR UPDATE', [$jobId])->fetch(\PDO::FETCH_ASSOC);
        if (!$job) {
            $this->Refuse('job_id', 'not_found', 'No such print job');
        }
        if ($job['cancelled_at'] !== null) {
            return $job;
        }
        if ($job['current_attempt_id'] !== null) {
            $this->Refuse('job_id', 'already_claimed', 'An attempt has already been made; cancelling now would record a print that may physically exist as never having happened');
        }
        if ($job['outcome'] !== null) {
            $this->Refuse('job_id', 'already_completed', 'That job already has an outcome');
        }
        $this->Query('UPDATE outbox SET dead_lettered_at=CURRENT_TIMESTAMP,last_error=? WHERE id=?', ['Cancelled: ' . $reason, $job['outbox_id']]);
        return $this->Query('UPDATE print_jobs SET cancelled_at=CURRENT_TIMESTAMP,cancelled_reason=? WHERE id=? RETURNING *', [$reason, $jobId])->fetch(\PDO::FETCH_ASSOC);
    }

    /** Resolves a printer, its driver, its combination and the immutable profile for it. */
    public function ResolvePrinter(int $printerId): array
    {
        $resolved = (new PrinterConfigurationService($this->db))->Resolve($printerId);
        $printer = $resolved['printer'];

        if (!$this->Query('SELECT id FROM label_worker_capabilities WHERE worker_id=? AND driver_id=? AND schema_version=?',
            [$printer['worker_id'], $printer['driver_id'], $printer['driver_schema_version']])->fetchColumn()) {
            $this->Refuse('printer_id', 'missing_capability', 'The assigned worker does not advertise this driver version');
        }

        $profile = (new MediaProfileService($this->db))->Ensure($printer, $resolved['driver'] ?? [], $resolved['combination']);
        return $resolved + ['profile' => $profile];
    }

    private function ResolveTemplate(string $entityKind, ?int $templateId, ?int $versionId, array $profile): array
    {
        if ($templateId === null && $versionId === null) {
            $templateId = (int)$this->Query('SELECT id FROM label_templates WHERE entity_kind=? AND archived_at IS NULL AND default_version_id IS NOT NULL ORDER BY id LIMIT 1', [$entityKind])->fetchColumn();
            if (!$templateId) {
                $this->Refuse('template_id', 'no_template', 'No published template exists for ' . $entityKind);
            }
        }
        if ($templateId === null && $versionId !== null) {
            $templateId = (int)$this->Query('SELECT template_id FROM label_template_versions WHERE id=?', [$versionId])->fetchColumn();
        }

        $version = (new LabelTemplateService($this->db))->ResolveVersion((int)$templateId, $versionId);

        // A template that paints red against a profile that cannot is refused where the
        // person asked for the label, not at the device. ADR-0019 says the enqueue-time check
        // exists to make the claim-time one rare rather than to make it unnecessary.
        $required = $version['required_capabilities']['color_mode'] ?? 'monochrome';
        if ($required === 'black_red' && $profile['color_mode'] !== 'black_red') {
            $this->Refuse('template_version_id', 'unsupported_combination', 'The template needs two-colour media and this printer resolves to ' . $profile['color_mode']);
        }
        return $version;
    }

    private function AssertLabelLive(string $uid): void
    {
        $retired = $this->Query('SELECT retired_at FROM labels WHERE uid=?', [$uid])->fetchColumn();
        if ($retired === false) {
            $this->Refuse('label_uid', 'unknown_label', 'That label is not known');
        }
        if ($retired !== null) {
            $this->Refuse('label_uid', 'label_retired', 'That label is retired; retirement stops new claims');
        }
    }

    private function AssertArtifactFitsProfile(array $artifact, array $profile): void
    {
        $document = $profile['document'];
        if ((int)$artifact['width_px'] !== (int)$document['raster_width_px']
            || (int)$artifact['dpi_x'] !== (int)$document['dpi_x']
            || (int)$artifact['dpi_y'] !== (int)$document['dpi_y']
            || $artifact['color_mode'] !== $document['color_mode']) {
            // No fallback to the nearest size, the latest profile, or monochrome. The three
            // things a caller most wants at this moment are the three things plan 27 piece 3
            // forbids.
            $this->Refuse('printer_id', 'media_incompatible', 'The stored artifact was produced for a different media profile and cannot be replayed on this printer');
        }
    }

    private function CreateJob(string $operation, string $uid, array $capture, array $resolved, ?array $version, int $renderRequestId, ?int $artifactId, ?int $sourceJobId): array
    {
        $template = $version === null
            ? ['template_id' => null, 'template_version_id' => null, 'document_digest' => null]
            : ['template_id' => (int)$version['template_id'], 'template_version_id' => (int)$version['id'], 'document_digest' => $version['document_digest']];

        $artifact = $artifactId === null
            ? ['id' => 0, 'form' => ArtifactService::FORM, 'byte_digest' => str_repeat('0', 64), 'byte_length' => 1,
               'width_px' => 1, 'height_px' => 1, 'dpi_x' => 1, 'dpi_y' => 1, 'color_mode' => $resolved['profile']['color_mode']]
            : (new ArtifactService($this->db))->Get($artifactId);

        $payload = PrintJobPayload::Build($uid, $capture, (int)$resolved['printer']['id'], $operation, $artifact, $template, $resolved['profile']);
        $outbox = \Victual\Services\Outbox\OutboxService::EnqueueInTransaction($this->db, \Victual\Services\Outbox\OutboxService::EVENT_LABEL_PRINT_REQUESTED, $payload);

        $job = $this->Query('INSERT INTO print_jobs(outbox_id,printer_id,label_uid,operation,artifact_id,render_request_id,capture_id,source_job_id)
            VALUES (?,?,?,?,?,?,?,?) RETURNING *',
            [$outbox, (int)$resolved['printer']['id'], $uid, $operation, $artifactId, $renderRequestId, (int)$capture['id'], $sourceJobId])->fetch(\PDO::FETCH_ASSOC);

        if ($artifactId !== null) {
            $this->Query("UPDATE label_artifacts SET retention_class='retained' WHERE id=?", [$artifactId]);
        }
        return $job;
    }

    /**
     * The fields a capture reads: whatever the document draws, plus the entity's name.
     *
     * The name is captured whether or not the template prints it, because the job payload's
     * provenance carries it and because issue 79's scan surface shows a human-readable line
     * that has to say what the label was for. A template that draws only a QR still produces
     * a job somebody can read.
     */
    private static function FieldsOf(array $document): array
    {
        $fields = [$document['entity_kind'] . '.name'];
        foreach ($document['elements'] as $element) {
            if ($element['type'] === 'text' && ($element['field'] ?? null) !== null) {
                $fields[] = $element['field'];
            }
        }
        return array_values(array_unique($fields));
    }
}
