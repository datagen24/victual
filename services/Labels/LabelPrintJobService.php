<?php

namespace Victual\Services\Labels;

use Victual\Services\Outbox\OutboxService;

class LabelPrintJobService extends LabelService
{
    public function Enqueue(int $locationId, int $epoch, int $printerId): int
    {
        $this->Transaction();
        $identity = new LabelIdentityService($this->db);
        try {
            $uid = $identity->IssueLocation($locationId, $epoch);
        } catch (\PDOException $error) {
            throw $error;
        } catch (\RuntimeException $error) {
            $this->Refuse('import_epoch', 'stale_location_context', $error->getMessage());
        }
        $resolved = (new PrinterConfigurationService($this->db))->Resolve($printerId);
        $printer = $resolved['printer'];
        if (!$this->Query('SELECT id FROM label_worker_capabilities WHERE worker_id=? AND driver_id=? AND schema_version=?', [$printer['worker_id'], $printer['driver_id'], $printer['driver_schema_version']])->fetchColumn()) {
            $this->Refuse('printer_id', 'missing_capability', 'The assigned worker does not advertise this driver version');
        }
        $payload = PrintJobPayload::Build($uid, $identity->LocationContext($locationId), $printerId);
        // Use this exact caller-owned PDO, including tests with an isolated search_path.
        $outbox = OutboxService::EnqueueInTransaction($this->db, OutboxService::EVENT_LABEL_PRINT_REQUESTED, $payload);
        return (int)$this->Query('INSERT INTO print_jobs(outbox_id,printer_id,label_uid) VALUES (?,?,?) RETURNING id', [$outbox,$printerId,$uid])->fetchColumn();
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
        // Backlog uses authorization state, not OutboxService::CountUndelivered().
        // With no plan 27 artifact seam yet, otherwise queued jobs await_artifact.
        return $this->Query("SELECT j.*, p.name AS printer_name, a.id AS attempt_id, a.error_text, a.reported_outcome, a.reported_detail,
    EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP-s.last_seen_at)) AS printer_status_age,
    CASE WHEN j.outcome IS NOT NULL THEN 'completed'
     WHEN j.attempts_authorized>j.attempts_made THEN 'awaiting_artifact'
     WHEN a.ended_at IS NOT NULL OR a.lease_expires_at<=CURRENT_TIMESTAMP THEN 'awaiting_authorization'
     ELSE 'in_progress' END AS authorization_state,
    CASE WHEN j.outcome='dead_lettered' THEN 'dead_lettered'
     WHEN (a.outcome='uncertain' OR (a.ended_at IS NULL AND a.lease_expires_at<=CURRENT_TIMESTAMP)) AND a.reported_at IS NOT NULL THEN 'uncertain_but_reported'
     WHEN a.outcome='uncertain' OR (a.ended_at IS NULL AND a.lease_expires_at<=CURRENT_TIMESTAMP) THEN 'uncertain'
     WHEN j.outcome='printed' THEN 'reported' WHEN j.outcome='sent' THEN 'sent'
     WHEN a.ended_at IS NOT NULL AND j.attempts_authorized>j.attempts_made THEN 'awaiting_artifact'
     WHEN a.outcome='blocked' THEN 'blocked' WHEN a.outcome='failed' THEN 'failed'
     WHEN a.bytes_sent_at IS NOT NULL AND a.ended_at IS NULL THEN 'sent'
     WHEN a.ended_at IS NULL AND a.id IS NOT NULL THEN 'claimed'
     WHEN j.attempts_made=j.attempts_authorized THEN 'awaiting_authorization'
     ELSE 'awaiting_artifact' END AS state
   FROM print_jobs j JOIN outbox o ON o.id=j.outbox_id
   LEFT JOIN print_attempts a ON a.id=j.current_attempt_id
   LEFT JOIN label_printer_status s ON s.printer_id=j.printer_id
   LEFT JOIN label_printers p ON p.id=j.printer_id
   ORDER BY CASE WHEN (a.outcome='uncertain' OR (a.ended_at IS NULL AND a.lease_expires_at<=CURRENT_TIMESTAMP)) AND a.reported_at IS NOT NULL THEN 0 ELSE 1 END, j.id DESC")->fetchAll(\PDO::FETCH_ASSOC);
    }
}
