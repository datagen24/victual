<?php

namespace Victual\Services\Labels;

class PrintAttemptService extends LabelService
{
    public const LEASE_SECONDS = 60;
    public const HARD_LEASE_SECONDS = 300;
    public function __construct(\PDO $db, private int $leaseSeconds = self::LEASE_SECONDS, private int $hardSeconds = self::HARD_LEASE_SECONDS)
    {
        parent::__construct($db);
        if ($leaseSeconds < 1 || $hardSeconds < $leaseSeconds) {
            throw new \InvalidArgumentException('Invalid lease durations');
        }
    }
    /**
     * A job is claimable only once a validated artifact is attached.
     *
     * Group B shipped this fail-closed, returning false for every job, because the artifact
     * it names did not exist yet and the maintainer chose to keep production claims blocked
     * rather than open them early against nothing. Plan 27's 0272 gives `print_jobs` the
     * column, `ArtifactService` gives it something to point at, and this is now the real
     * condition rather than a seam: no runtime setting turns it off, and there is no path
     * that claims a job whose bytes have not been verified.
     */
    protected function ArtifactReady(array $job): bool
    {
        return $job['artifact_id'] !== null && $job['cancelled_at'] === null;
    }
    protected function LockClause(): string
    {
        return ' FOR UPDATE OF j SKIP LOCKED';
    }
    public function Reap(int $jobId): void
    {
        $this->Transaction();
        $reaped = $this->Query("UPDATE print_attempts SET ended_at=lease_expires_at,outcome='uncertain',error_text=COALESCE(error_text,'Lease expired; delivery is uncertain') WHERE job_id=? AND ended_at IS NULL AND lease_expires_at<=CURRENT_TIMESTAMP", [$jobId]);
        if ($reaped->rowCount() > 0) {
            // A lease that expired while its printer was gone has nowhere left to go: Claim()
            // inner-joins label_printers, so this job could never be offered again. Without
            // this it would sit "uncertain" forever instead of the dead-lettered state
            // deletion already gives every other job it is walking away from.
            $this->DeadLetterIfPrinterGone($jobId);
        }
    }
    public function Claim(int $workerId, int $limit = 1): array
    {
        $this->Transaction();
        if ($limit < 1 || $limit > 50) {
            $this->Refuse('limit', 'value_out_of_range', 'Claim limit must be 1 through 50');
        }
        // Retirement is excluded from the candidate set here, in SQL, rather than checked per
        // row after fetching: a page of retired jobs ahead of an eligible one must not be able
        // to fill the LIMIT below and crowd it out. "Retirement stops new claims" (plan 27
        // verification 10) is all this checks for - ADR-0019 dead-letters an unclaimed job only
        // for a payload no version can read or a deleted printer, never for merely being
        // unclaimed, so a retired label's job is left exactly as queued as it was.
        //
        // ADR-0037 section 9: a stock-entry label can turn live again when an undo revives it.
        // A job that existed when the label retired (id at or below the event's jobs_through_id)
        // stays unclaimable, so an authorized retry from before the consumption does not print
        // by itself. A job requested after the revival has a higher id and is claimable.
        $jobs = $this->Query("SELECT j.*,o.payload FROM print_jobs j JOIN outbox o ON o.id=j.outbox_id
   JOIN label_printers p ON p.id=j.printer_id
   WHERE p.worker_id=? AND j.outcome IS NULL AND j.cancelled_at IS NULL AND j.attempts_made<j.attempts_authorized
   AND o.delivered_at IS NULL AND o.dead_lettered_at IS NULL
   AND NOT EXISTS (SELECT 1 FROM print_attempts a WHERE a.id=j.current_attempt_id AND a.ended_at IS NULL AND a.lease_expires_at>CURRENT_TIMESTAMP)
   AND NOT EXISTS (SELECT 1 FROM labels lb WHERE lb.uid=j.label_uid AND lb.retired_at IS NOT NULL)
   AND NOT EXISTS (SELECT 1 FROM stock_label_retirements r WHERE r.label_uid=j.label_uid AND r.jobs_through_id>=j.id)
   ORDER BY j.outbox_id LIMIT 200" . $this->LockClause(), [$workerId])->fetchAll(\PDO::FETCH_ASSOC);
        $claimed = [];
        foreach ($jobs as $job) {
            $this->Reap((int)$job['id']);
            $payload = json_decode($job['payload'], true);
            $error = PrintJobPayload::DescribeUnreadable($payload);
            if ($error !== null) {
                $this->DeadLetter($job, $error);
                continue;
            }
            try {
                $resolved = (new PrinterConfigurationService($this->db))->Resolve((int)$job['printer_id']);
            } catch (LabelValidationException $error) {
                $this->DeadLetter($job, $error->getMessage());
                continue;
            }
            $printer = $resolved['printer'];
            $advertised = $this->Query('SELECT id FROM label_worker_capabilities WHERE worker_id=? AND driver_id=? AND schema_version=?', [$workerId,$printer['driver_id'],$printer['driver_schema_version']])->fetchColumn();
            // A job whose artifact has not been validated and attached is not claimable,
            // full stop (ADR-0019 section 4) - not only when the worker also advertises the
            // right capability. Gating this on $advertised let an unrendered job with no
            // matching advertisement fall through to the block below and spend its sole
            // authorized attempt on a claim that was never really offered to anyone.
            if (!$this->ArtifactReady($job)) {
                continue;
            }
            $attempt = $this->Query("INSERT INTO print_attempts(outbox_id,job_id,attempt_number,worker_id,lease_expires_at,lease_hard_deadline,acknowledged_on)
     VALUES (?,?,?,?,CURRENT_TIMESTAMP+make_interval(secs=>?),CURRENT_TIMESTAMP+make_interval(secs=>?),?)
     ON CONFLICT (outbox_id,attempt_number) DO NOTHING RETURNING *", [$job['outbox_id'],$job['id'],(int)$job['attempts_made'] + 1,$workerId,$this->leaseSeconds,$this->hardSeconds,$resolved['acknowledged_on']])->fetch(\PDO::FETCH_ASSOC);
            if (!$attempt) {
                continue;
            }
            if ($job['current_attempt_id'] !== null) {
                $this->Query('UPDATE print_attempts SET superseded_at=CURRENT_TIMESTAMP WHERE id=?', [$job['current_attempt_id']]);
            }
            $this->Query('UPDATE print_jobs SET current_attempt_id=?,attempts_made=attempts_made+1 WHERE id=?', [$attempt['id'],$job['id']]);
            if (!$advertised) {
                $this->Query("UPDATE print_attempts SET ended_at=CURRENT_TIMESTAMP,outcome='blocked',error_text='Assigned worker no longer advertises the exact driver version' WHERE id=?", [$attempt['id']]);
                continue;
            }
            // The manifest travels with the claim so the worker can compare what it was
            // handed against the device it is holding, rather than assuming the two agree.
            // The bytes do not: it fetches those over its own authorized route.
            $manifest = (new ArtifactService($this->db))->Get((int)$job['artifact_id'])['manifest'];
            $claimed[] = ['attempt' => $attempt,'payload' => $payload,'printer' => $printer,'artifact' => $manifest];
            if (count($claimed) >= $limit) {
                break;
            }
        }
        return $claimed;
    }
    private function DeadLetter(array $job, string $error): void
    {
        $this->Query("UPDATE print_jobs SET outcome='dead_lettered',outcome_at=CURRENT_TIMESTAMP WHERE id=?", [$job['id']]);
        $this->Query('UPDATE outbox SET dead_lettered_at=CURRENT_TIMESTAMP,last_error=? WHERE id=?', [$error,$job['outbox_id']]);
    }
    /**
     * ADR-0019 decision item 4's deleted-printer case, reached from the two places an attempt
     * can end unsuccessfully after its printer is already gone: a worker's own failure report
     * (Result()) and a lease nobody ever reported against (Reap()). DeletePrinter() itself only
     * dead-letters what is still queued; a job a worker already holds is deliberately left
     * alone there, so it falls to whichever of these two runs next to finish it once that hold
     * ends badly, with the same last_error wording the deletion path itself uses.
     */
    private function DeadLetterIfPrinterGone(int $jobId): void
    {
        $job = $this->Query('SELECT id,outbox_id,printer_id,outcome FROM print_jobs WHERE id=?', [$jobId])->fetch(\PDO::FETCH_ASSOC);
        if (!$job || $job['outcome'] !== null) {
            return;
        }
        if (!$this->Query('SELECT 1 FROM label_printers WHERE id=?', [$job['printer_id']])->fetchColumn()) {
            $this->DeadLetter($job, 'Printer deleted');
        }
    }
    private function Owned(int $workerId, int $attemptId): array
    {
        $this->Transaction();
        $job = $this->Query('SELECT j.id FROM print_jobs j JOIN print_attempts a ON a.job_id=j.id WHERE a.id=? AND a.worker_id=? FOR UPDATE OF j', [$attemptId,$workerId])->fetchColumn();
        if (!$job) {
            $this->Refuse('attempt_id', 'forbidden', 'Attempt is not owned by this worker');
        }
        $this->Reap((int)$job);
        return $this->Query('SELECT a.*,j.current_attempt_id,j.outcome AS job_outcome FROM print_attempts a JOIN print_jobs j ON j.id=a.job_id WHERE a.id=?', [$attemptId])->fetch(\PDO::FETCH_ASSOC);
    }
    public function Heartbeat(int $workerId, int $attemptId): array
    {
        $a = $this->Owned($workerId, $attemptId);
        if ($a['ended_at'] !== null || (int)$a['current_attempt_id'] !== $attemptId) {
            $this->Refuse('attempt_id', 'lease_ended', 'Heartbeat requires a live current attempt');
        }
        return $this->Query('UPDATE print_attempts SET heartbeats=heartbeats+1,lease_expires_at=LEAST(lease_hard_deadline,CURRENT_TIMESTAMP+make_interval(secs=>?)) WHERE id=? RETURNING *', [$this->leaseSeconds,$attemptId])->fetch(\PDO::FETCH_ASSOC);
    }
    public function Sent(int $workerId, int $attemptId): array
    {
        $a = $this->Owned($workerId, $attemptId);
        $this->Query('UPDATE print_attempts SET bytes_sent_at=COALESCE(bytes_sent_at,CURRENT_TIMESTAMP) WHERE id=?', [$attemptId]);
        if ($a['job_outcome'] === null && $a['ended_at'] === null && (int)$a['current_attempt_id'] === $attemptId && $a['acknowledged_on'] === 'send') {
            $this->Complete($a, 'sent');
        }
        return $this->Query('SELECT * FROM print_attempts WHERE id=?', [$attemptId])->fetch(\PDO::FETCH_ASSOC);
    }
    public function Result(int $workerId, int $attemptId, string $outcome, array $detail): array
    {
        if (!in_array($outcome, ['printed','failed'], true)) {
            $this->Refuse('outcome', 'value_out_of_range', 'Result must be printed or failed');
        }
        $a = $this->Owned($workerId, $attemptId);
        if ($a['reported_at'] !== null) {
            if ($a['reported_outcome'] !== $outcome || json_decode($a['reported_detail'], true) != $detail) {
                $this->Refuse('result', 'conflicting_report', 'An attempt already has a different result');
            }
            return $a;
        }
        $this->Query('UPDATE print_attempts SET reported_at=CURRENT_TIMESTAMP,device_reported_at=CURRENT_TIMESTAMP,reported_outcome=?,reported_detail=?::jsonb WHERE id=?', [$outcome,$this->Json($detail),$attemptId]);
        if ($a['job_outcome'] === null && $a['ended_at'] === null && (int)$a['current_attempt_id'] === $attemptId) {
            if ($outcome === 'printed') {
                $this->Complete($a, 'printed');
            } else {
                $this->Query("UPDATE print_attempts SET ended_at=CURRENT_TIMESTAMP,outcome='failed',error_text=? WHERE id=?", [(string)($detail['error'] ?? 'Worker reported failure'),$attemptId]);
                // Same reasoning as Reap(): a failed report against a deleted printer has
                // nowhere else to go either, since Claim() can never select this job again.
                $this->DeadLetterIfPrinterGone((int)$a['job_id']);
            }
        }
        return $this->Query('SELECT * FROM print_attempts WHERE id=?', [$attemptId])->fetch(\PDO::FETCH_ASSOC);
    }
    private function Complete(array $a, string $outcome): void
    {
        $this->Query('UPDATE print_attempts SET ended_at=CURRENT_TIMESTAMP,outcome=? WHERE id=?', [$outcome,$a['id']]);
        $this->Query('UPDATE print_jobs SET outcome=?,outcome_at=CURRENT_TIMESTAMP WHERE id=? AND current_attempt_id=?',[$outcome,$a['job_id'],$a['id']]);
        $this->Query('UPDATE outbox SET delivered_at=CURRENT_TIMESTAMP WHERE id=?',[$a['outbox_id']]);
    }
}
