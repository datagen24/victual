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
     * Issues a label for a target of the given kind and queues its render. The job exists
     * immediately and is not claimable until the artifact is attached.
     *
     * Named for locations, which is what this was before plan 32: the method kept its name
     * and gained a leading `$kind` rather than being renamed, per that plan's piece B.
     */
    public function IssueLocation(string $kind, int $targetId, int $epoch, ?int $printerId, ?int $templateId, ?int $templateVersionId, string $locale, string $timezone): array
    {
        $this->Transaction();

        $identity = new LabelIdentityService($this->db);
        try {
            $uid = $identity->Issue($kind, $targetId, $epoch);
        } catch (\RuntimeException $error) {
            $this->Refuse('import_epoch', 'stale_target_context', $error->getMessage());
        }

        $resolved = $this->ResolvePrinter($printerId);
        $version = $this->ResolveTemplate($kind, $templateId, $templateVersionId, $resolved['profile']);

        $capture = (new LabelCaptureService($this->db, $this->permissionCheck))
            ->Capture($kind, $targetId, $uid, self::FieldsOf($version['document']), $locale, $timezone, $this->userId);

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

        // A cheap, unlocked preview first, purely for the two refusals that do not depend on
        // locking anything (a caller naming a job that does not exist, or one with no
        // artifact to replay) - kept in their original order and wording, ahead of the label
        // check below, since nothing about the lock-order fix changes what a plain "no such
        // job" or "never rendered" refusal should say.
        $preview = $this->Query('SELECT label_uid, artifact_id FROM print_jobs WHERE id=?', [$sourceJobId])->fetch(\PDO::FETCH_ASSOC);
        if (!$preview) {
            $this->Refuse('job_id', 'not_found', 'No such print job');
        }
        if ($preview['artifact_id'] === null) {
            $this->Refuse('job_id', 'no_artifact', 'That job never had an artifact to replay');
        }

        // The label is locked (AssertLabelLive()'s own FOR SHARE) before the source job row
        // (FOR UPDATE below), not after - deadlock evidence from PR #626 review: a retirement
        // locks labels first (its own UPDATE, inside the retire_*_labels trigger) and only
        // then locks print_jobs (cancel_queued_label_jobs()'s own UPDATE, migrations/
        // 0296.pgsql.sql). Reprint() used to lock print_jobs first and labels second - the
        // reverse order - so a still-queued source job racing a concurrent retirement of the
        // same label could deadlock (SQLSTATE 40P01): Reprint() holding the source row,
        // waiting on labels; retirement holding labels, waiting on that same source row (it
        // is itself a queued, unclaimed job for that label, so cancel_queued_label_jobs()
        // reaches it too). Locking labels first here, before the source row lock is taken at
        // all, makes both sides take labels before print_jobs - the same order - so the two
        // can never form a cycle: whichever reaches labels first, the other simply waits
        // behind it, all the way to commit.
        //
        // Retirement first, then the bytes. A retired label is a refusal about the *thing*,
        // and answering "the bytes are gone" to somebody reprinting a label for a shelf that
        // no longer exists would send them looking for the wrong problem.
        $this->AssertLabelLive((string)$preview['label_uid']);

        // Re-fetched now under FOR UPDATE - not trusted from the unlocked preview above -
        // because AssertLabelLive() may have waited an arbitrary amount of time for a
        // concurrent retirement to finish, and this row is the one thing this method must
        // not act on a stale read of.
        $source = $this->Query('SELECT * FROM print_jobs WHERE id=? FOR UPDATE', [$sourceJobId])->fetch(\PDO::FETCH_ASSOC);
        if (!$source) {
            $this->Refuse('job_id', 'not_found', 'No such print job');
        }
        if ($source['artifact_id'] === null) {
            $this->Refuse('job_id', 'no_artifact', 'That job never had an artifact to replay');
        }

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
    public function RevisedPrint(string $kind, int $targetId, int $epoch, ?int $printerId, ?int $templateId, ?int $templateVersionId, string $locale, string $timezone): array
    {
        $this->Transaction();

        // A cheap, unlocked preview first: refuses "no_live_label" outright, without taking
        // any lock, for the ordinary case where the target simply has none. This is not the
        // read RevisedPrint() acts on - see the FOR SHARE re-check below, after the entity
        // row is locked - it exists only to hand Issue() (and, if the label really is live,
        // AssertLabelLive() below) the uid to work with.
        $uid = $this->Query('SELECT uid FROM labels WHERE kind=? AND target_id=? AND retired_at IS NULL', [$kind, $targetId])->fetchColumn();
        if (!$uid) {
            $this->Refuse('target_id', 'no_live_label', 'That target has no live label; a revised print keeps an existing identity rather than minting one');
        }

        // Entity row first (Issue()'s own FOR UPDATE on the target table), then the label -
        // the same order every retirement path uses. PR #626 review, second delta round:
        // locking labels *before* the entity row (an earlier version of this fix) reversed
        // that order and deadlocked (SQLSTATE 40P01) against a concurrent retirement, whose
        // own DELETE locks the entity row first (as part of the DELETE itself) and only then
        // locks labels (inside its BEFORE DELETE trigger). The epoch guard Issue() itself
        // applies is also why it has to run first here regardless of locking: a request
        // composed before an import and executed after it would otherwise capture whatever
        // now holds that id.
        (new LabelIdentityService($this->db))->Issue($kind, $targetId, $epoch);

        // Re-checked now that the entity row is locked, not trusted from the unlocked read
        // above: AssertLabelLive()'s own FOR SHARE is what actually serialises this against a
        // concurrent retirement reaching the label after this call already holds the entity
        // row - migrations/0296.pgsql.sql's trg_cascade_product_removal, in particular, locks
        // every affected stock row (PERFORM ... FOR UPDATE) before it ever touches `labels`,
        // for exactly this reason. Retirement either finishes first (and this refuses,
        // correctly, on a now-retired label) or waits behind this call's own entity lock (and
        // then sees - and cancels - whatever job this call went on to create).
        $this->AssertLabelLive((string)$uid);

        $resolved = $this->ResolvePrinter($printerId);
        $version = $this->ResolveTemplate($kind, $templateId, $templateVersionId, $resolved['profile']);
        $capture = (new LabelCaptureService($this->db, $this->permissionCheck))
            ->Capture($kind, $targetId, (string)$uid, self::FieldsOf($version['document']), $locale, $timezone, $this->userId);
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

        // outcome IS NULL alongside cancelled_at IS NULL: a job can reach a terminal state
        // two ways before its render ever finishes - cancellation (cancelled_at) or a
        // pre-render dead letter such as an unreadable payload (outcome) - and a renderer
        // result that outlives either must not resurrect a job that is already done.
        $jobs = $this->Query('SELECT * FROM print_jobs WHERE render_request_id=? AND artifact_id IS NULL AND cancelled_at IS NULL AND outcome IS NULL FOR UPDATE', [$renderRequestId])->fetchAll(\PDO::FETCH_ASSOC);
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

    /**
     * Resolves a printer, its driver, its combination and the immutable profile for it.
     *
     * A null id resolves to the default printer (the first active one, `is_default` first) -
     * plan 32 question 3's answer for a purchase-time job, whose form names no printer.
     */
    public function ResolvePrinter(?int $printerId): array
    {
        if ($printerId === null) {
            $printerId = (int)$this->Query("SELECT id FROM label_printers WHERE active=1 ORDER BY is_default DESC, name LIMIT 1")->fetchColumn();
            if ($printerId === 0) {
                $this->Refuse('printer_id', 'no_printer', 'No active printer is configured');
            }
        }
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

    /**
     * `FOR SHARE`, not a bare read: this call's own transaction holds the row lock through
     * CreateJob() below, so a concurrent retirement's own `UPDATE labels ... WHERE
     * retired_at IS NULL` (every retire_*_labels trigger, migrations/0296.pgsql.sql) has to
     * wait for it to release. Without that lock, a reprint or a promoted preview could read
     * "still live" from an uncommitted retirement's perspective, create a new queued job
     * after that retirement's own cancel_queued_label_jobs() call had already run over the
     * jobs that existed at that moment, and leave the newly created job queued forever -
     * eligible to print by no code path (Claim()'s own retired-label check still excludes
     * it), but never cancelled either, which is exactly the inaccurate monitor state issue
     * #516 (D2) exists to prevent. `FOR SHARE` (not `FOR UPDATE`) is enough: two readers of a
     * still-live label do not need to block each other, only a concurrent writer does, and
     * the retirement trigger's `UPDATE` is the only writer of this row.
     *
     * LOCK ORDER (PR #626 review, SQLSTATE 40P01 reproduced multiple times before this
     * comment existed): a caller must take this lock *after* any entity-row lock its own
     * operation takes, and *before* anything else cancel_queued_label_jobs() itself locks -
     * `print_jobs`. That function already retires the label (its own `UPDATE`) before it
     * ever touches `print_jobs` (migrations/0296.pgsql.sql), and every retirement site locks
     * its entity row before it ever touches `labels` (a direct `DELETE` locks its own row as
     * part of the statement itself, before its `BEFORE DELETE` trigger runs;
     * `trg_cascade_product_removal`'s product -> stock_entry cascade locks every affected
     * `stock` row with its own `PERFORM ... FOR UPDATE` before touching `labels`, for the
     * same reason). So the rule for every caller of this method is: entity row (if this
     * operation has one), then this lock, then nothing else that touches `print_jobs` until
     * after it.
     *
     * - Reprint() has no entity row at all - it locks its source `print_jobs` row instead.
     *   It used to lock that row *before* calling this method, the reverse of
     *   cancel_queued_label_jobs()'s own order; it now calls this method first (see its own
     *   comment).
     * - RevisedPrint() has an entity row (`LabelIdentityService::Issue()`'s own `FOR UPDATE`)
     *   and now calls this method *after* that lock, not instead of it: an earlier version of
     *   this fix left RevisedPrint() with no lock on `labels` at all, reasoning that Issue()'s
     *   entity lock was enough on its own - true for the direct single-row retirement
     *   triggers, but not for the product cascade above, whose `UPDATE labels ... FROM stock`
     *   join takes no lock on the `stock` rows it reads until the `PERFORM` this same review
     *   round added. Without a lock on `labels` too, RevisedPrint('stock_entry', S) could
     *   still read a label as live and queue a job after a concurrent product delete had
     *   already retired it and cancelled everything queued at that moment - D2 violated, with
     *   no deadlock and nothing to catch it.
     * - PromotePreview() locks nothing at all before this call, and the one lock it takes
     *   afterwards (`ArtifactService::Promote()`'s `label_artifacts` row) is a table
     *   cancel_queued_label_jobs() never touches, so no reordering was needed there.
     */
    private function AssertLabelLive(string $uid): void
    {
        $retired = $this->Query('SELECT retired_at FROM labels WHERE uid=? FOR SHARE', [$uid])->fetchColumn();
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
     *
     * A stock entry has no name of its own - `FieldCatalogue::For('stock_entry')` names the
     * product it holds `stock_entry.product_name` rather than `stock_entry.name` - so that is
     * the field always captured for that one kind.
     */
    private static function FieldsOf(array $document): array
    {
        $nameField = $document['entity_kind'] === 'stock_entry' ? 'stock_entry.product_name' : $document['entity_kind'] . '.name';
        $fields = [$nameField];
        foreach ($document['elements'] as $element) {
            if ($element['type'] === 'text' && ($element['field'] ?? null) !== null) {
                $fields[] = $element['field'];
            }
        }
        return array_values(array_unique($fields));
    }
}
