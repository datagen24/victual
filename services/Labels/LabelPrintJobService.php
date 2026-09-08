<?php

namespace Victual\Services\Labels;

use Victual\Services\Outbox\OutboxService;

class LabelPrintJobService extends LabelService
{
    /**
     * Issues a location label and queues its render and its print job.
     *
     * Kept as this class's entry point because plan 25 group B's route and regressions call
     * it, but the work moved to LabelOperationsService when plan 27 gave a job an artifact:
     * issuing is now a mapping, a capture, a render request and a job in one transaction,
     * and the job is deliberately not claimable until the artifact is attached.
     */
    public function Enqueue(int $locationId, int $epoch, int $printerId, ?int $templateId = null, ?int $templateVersionId = null, string $locale = 'en', string $timezone = 'UTC', $permissionCheck = null, ?int $userId = null): int
    {
        $job = (new LabelOperationsService($this->db, $permissionCheck, $userId))
            ->IssueLocation($locationId, $epoch, $printerId, $templateId, $templateVersionId, $locale, $timezone);
        return (int)$job['id'];
    }

    public function AuthorizeAnotherAttempt(int $jobId, int $attemptId): array
    {
        $this->Transaction();
        $job = $this->Query('SELECT * FROM print_jobs WHERE id=? FOR UPDATE', [$jobId])->fetch(\PDO::FETCH_ASSOC);
        if (!$job || (int)$job['current_attempt_id'] !== $attemptId) {
            $this->Refuse('attempt_id', 'not_current', 'Review the current attempt');
        }
        (new PrintAttemptService($this->db))->Reap($jobId);
        $attempt = $this->Query('SELECT * FROM print_attempts WHERE id=?', [$attemptId])->fetch(\PDO::FETCH_ASSOC);
        if ($attempt['ended_at'] === null) {
            $this->Refuse('attempt_id', 'attempt_running', 'Attempt has not ended');
        }
        if ($job['outcome'] !== null) {
            $this->Refuse('attempt_id', 'already_completed', 'Job has a successful delivery report');
        }
        if ((int)$job['attempts_authorized'] === (int)$job['attempts_made']) {
            $this->Query('UPDATE print_jobs SET attempts_authorized=attempts_authorized+1 WHERE id=?', [$jobId]);
        }
        return $this->Query('SELECT * FROM print_jobs WHERE id=?', [$jobId])->fetch(\PDO::FETCH_ASSOC);
    }
    public function Monitor(): array
    {
        // Backlog uses authorization state, not OutboxService::CountUndelivered(): an
        // unresolved job is neither delivered nor claimable, so delivered_at alone is not
        // this consumer's work queue.
        //
        // `awaiting_artifact` is now a real condition rather than the closed seam it was in
        // group B - a job carries no artifact until plan 27's renderer produces and Victual
        // verifies one - and `cancelled` is its own state because a cancelled job never
        // printed, and recording it as printed or dead-lettered would say something untrue
        // about a physical object.
        return $this->Query("SELECT j.*, p.name AS printer_name, a.id AS attempt_id, a.error_text, a.reported_outcome, a.reported_detail,
    EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP-s.last_seen_at)) AS printer_status_age,
    CASE WHEN j.cancelled_at IS NOT NULL THEN 'cancelled'
     WHEN j.outcome IS NOT NULL THEN 'completed'
     WHEN j.artifact_id IS NULL THEN 'awaiting_artifact'
     WHEN j.attempts_authorized>j.attempts_made THEN 'awaiting_worker'
     WHEN a.ended_at IS NOT NULL OR a.lease_expires_at<=CURRENT_TIMESTAMP THEN 'awaiting_authorization'
     ELSE 'in_progress' END AS authorization_state,
    CASE WHEN j.cancelled_at IS NOT NULL THEN 'cancelled'
     WHEN j.outcome='dead_lettered' THEN 'dead_lettered'
     WHEN j.artifact_id IS NULL AND j.current_attempt_id IS NULL THEN 'awaiting_artifact'
     WHEN (a.outcome='uncertain' OR (a.ended_at IS NULL AND a.lease_expires_at<=CURRENT_TIMESTAMP)) AND a.reported_at IS NOT NULL THEN 'uncertain_but_reported'
     WHEN a.outcome='uncertain' OR (a.ended_at IS NULL AND a.lease_expires_at<=CURRENT_TIMESTAMP) THEN 'uncertain'
     WHEN j.outcome='printed' THEN 'reported' WHEN j.outcome='sent' THEN 'sent'
     WHEN a.ended_at IS NOT NULL AND j.attempts_authorized>j.attempts_made THEN 'queued'
     WHEN a.outcome='blocked' THEN 'blocked' WHEN a.outcome='failed' THEN 'failed'
     WHEN a.bytes_sent_at IS NOT NULL AND a.ended_at IS NULL THEN 'sent'
     WHEN a.ended_at IS NULL AND a.id IS NOT NULL THEN 'claimed'
     WHEN j.attempts_made=j.attempts_authorized THEN 'awaiting_authorization'
     ELSE 'queued' END AS state
   , r.state AS render_state, r.error_code AS render_error_code, r.error_element AS render_error_element, r.error_detail AS render_error_detail
   FROM print_jobs j JOIN outbox o ON o.id=j.outbox_id
   LEFT JOIN label_render_requests r ON r.id=j.render_request_id
   LEFT JOIN print_attempts a ON a.id=j.current_attempt_id
   LEFT JOIN label_printer_status s ON s.printer_id=j.printer_id
   LEFT JOIN label_printers p ON p.id=j.printer_id
   ORDER BY CASE WHEN (a.outcome='uncertain' OR (a.ended_at IS NULL AND a.lease_expires_at<=CURRENT_TIMESTAMP)) AND a.reported_at IS NOT NULL THEN 0 ELSE 1 END, j.id DESC")->fetchAll(\PDO::FETCH_ASSOC);
    }
}
