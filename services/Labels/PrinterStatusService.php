<?php

namespace Victual\Services\Labels;

class PrinterStatusService extends LabelService
{
    public function Report(int $workerId, int $printerId, array $report): array
    {
        $this->Transaction();
        if (isset($report['device_state']) && !is_string($report['device_state'])) {
            $this->Refuse('device_state', 'value_out_of_range', 'Device state must be a string');
        }
        (new LabelWorkerAuthorization($this->db))->Authorize('labels-status', $workerId, $printerId);
        return $this->Query('INSERT INTO label_printer_status(printer_id,reported_by_worker_id,reported_media,device_state,error_state,warning_state,raw_report) VALUES (?,?,?::jsonb,?,?::jsonb,?::jsonb,?::jsonb) ON CONFLICT(printer_id) DO UPDATE SET reported_by_worker_id=EXCLUDED.reported_by_worker_id,last_seen_at=CURRENT_TIMESTAMP,reported_media=EXCLUDED.reported_media,device_state=EXCLUDED.device_state,error_state=EXCLUDED.error_state,warning_state=EXCLUDED.warning_state,raw_report=EXCLUDED.raw_report RETURNING *', [$printerId,$workerId,$this->Json($report['reported_media'] ?? null),$report['device_state'] ?? null,$this->Json($report['error_state'] ?? null),$this->Json($report['warning_state'] ?? null),$this->Json($report)])->fetch(\PDO::FETCH_ASSOC);
    }
}
