<?php

namespace Victual\Services\Labels;

class PrintEvidenceService extends LabelService
{
    public function Submit(int $workerId, int $attemptId, array $evidence): array
    {
        $this->Transaction();
        (new LabelWorkerAuthorization($this->db))->Authorize('labels-evidence', $workerId, $attemptId);
        foreach (['submission_id','evidence_type','observed_at'] as $key) {
            if (!is_string($evidence[$key] ?? null) || $evidence[$key] === '') {
                $this->Refuse($key, 'value_out_of_range', 'Required observation field');
            }
        }
        if (!is_array($evidence['detail'] ?? null) || !$evidence['detail']) {
            $this->Refuse('detail', 'value_out_of_range', 'Type-specific observation detail is required');
        }
        if (!in_array($evidence['evidence_type'], ['device_status','decoded_scan'], true)) {
            $this->Refuse('evidence_type', 'value_out_of_range', 'Unsupported observation type');
        }
        if (isset($evidence['image_ref'])) {
            $this->Refuse('image_ref', 'value_out_of_range', 'Image verification is not implemented');
        }
        if ($evidence['evidence_type'] === 'device_status' && !is_array($evidence['printer_status'] ?? null)) {
            $this->Refuse('printer_status', 'value_out_of_range', 'Device status is required');
        }
        if ($evidence['evidence_type'] === 'decoded_scan' && !is_string($evidence['decoded_uid'] ?? null)) {
            $this->Refuse('decoded_uid', 'value_out_of_range', 'Decoded uid is required');
        }
        if (isset($evidence['confidence']) && (!is_numeric($evidence['confidence']) || $evidence['confidence'] < 0 || $evidence['confidence'] > 1)) {
            $this->Refuse('confidence', 'value_out_of_range', 'Confidence is outside 0 through 1');
        }
        try {
            new \DateTimeImmutable($evidence['observed_at']);
        } catch (\Throwable) {
            $this->Refuse('observed_at', 'value_out_of_range', 'Invalid observation time');
        }
        $source = 'worker:'.$workerId;
        $this->Query('INSERT INTO print_evidence(attempt_id,submission_id,evidence_type,source,observed_at,decoded_uid,printer_status,confidence,detail) VALUES (?,?,?,?,?,?,?::jsonb,?,?::jsonb) ON CONFLICT(source,submission_id) DO NOTHING', [$attemptId,$evidence['submission_id'],$evidence['evidence_type'],$source,$evidence['observed_at'],$evidence['decoded_uid'] ?? null,isset($evidence['printer_status']) ? $this->Json($evidence['printer_status']) : null,$evidence['confidence'] ?? null,$this->Json($evidence['detail'])]);
        $row = $this->Query('SELECT * FROM print_evidence WHERE source=? AND submission_id=?', [$source,$evidence['submission_id']])->fetch(\PDO::FETCH_ASSOC);
        if ((int)$row['attempt_id'] !== $attemptId || $row['evidence_type'] !== $evidence['evidence_type'] || json_decode($row['detail'], true) != $evidence['detail'] || $row['decoded_uid'] !== ($evidence['decoded_uid'] ?? null) || json_decode($row['printer_status'] ?? 'null', true) != ($evidence['printer_status'] ?? null) || (new \DateTimeImmutable($row['observed_at'])) != (new \DateTimeImmutable($evidence['observed_at'])) || ($row['confidence'] === null ? null : (float)$row['confidence']) !== ($evidence['confidence'] ?? null)) {
            $this->Refuse('submission_id','conflicting_evidence','Submission id already has a different observation');
        }
        return $row;
    }
}
