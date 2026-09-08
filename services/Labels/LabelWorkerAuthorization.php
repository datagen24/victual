<?php

namespace Victual\Services\Labels;

use Victual\Services\ApiKeyService;

class LabelWorkerAuthorization extends LabelService
{
    public const ROUTE_KEY_TYPES = [
     'labels-pair' => [], 'labels-rotate' => [ApiKeyService::API_KEY_TYPE_LABEL_WORKER],
     'labels-register' => [ApiKeyService::API_KEY_TYPE_LABEL_WORKER],
     'labels-claim' => [ApiKeyService::API_KEY_TYPE_LABEL_WORKER],
     'labels-heartbeat' => [ApiKeyService::API_KEY_TYPE_LABEL_WORKER],
     'labels-sent' => [ApiKeyService::API_KEY_TYPE_LABEL_WORKER],
     'labels-result' => [ApiKeyService::API_KEY_TYPE_LABEL_WORKER],
     'labels-evidence' => [ApiKeyService::API_KEY_TYPE_LABEL_WORKER,ApiKeyService::API_KEY_TYPE_LABEL_VERIFIER],
     'labels-status' => [ApiKeyService::API_KEY_TYPE_LABEL_WORKER],
     // The worker fetches the bytes it was handed a manifest for. It never receives them
     // inline, and it never reaches an artifact belonging to a job it is not printing.
     'labels-artifact-bytes' => [ApiKeyService::API_KEY_TYPE_LABEL_WORKER],
     // Renderer routes. A renderer key is refused on every worker route above, which is what
     // makes "may not claim a print attempt" a property of the credential rather than a rule
     // the renderer is trusted to follow.
     'labels-render-claim' => [ApiKeyService::API_KEY_TYPE_LABEL_RENDERER],
     'labels-render-result' => [ApiKeyService::API_KEY_TYPE_LABEL_RENDERER],
     'labels-render-invalid' => [ApiKeyService::API_KEY_TYPE_LABEL_RENDERER],
     'labels-render-failed' => [ApiKeyService::API_KEY_TYPE_LABEL_RENDERER],
     'labels-asset-bytes' => [ApiKeyService::API_KEY_TYPE_LABEL_RENDERER]
    ];
    public function Authorize(string $route, int $workerId, ?int $targetId = null): void
    {
        if (!isset(self::ROUTE_KEY_TYPES[$route]) || $route === 'labels-pair') {
            $this->Refuse('route', 'forbidden', 'Not a worker operation');
        }
        if (!$this->Query('SELECT id FROM label_workers WHERE id=? AND active=1', [$workerId])->fetchColumn()) {
            $this->Refuse('worker', 'unauthorized', 'Worker is inactive');
        }
        if (in_array($route, ['labels-heartbeat','labels-sent','labels-result','labels-evidence'], true)) {
            if (!$this->Query('SELECT id FROM print_attempts WHERE id=? AND worker_id=?', [$targetId,$workerId])->fetchColumn()) {
                $this->Refuse('attempt_id', 'forbidden', 'Attempt is not owned by this worker');
            }
        }
        if ($route === 'labels-status' && !$this->Query('SELECT id FROM label_printers WHERE id=? AND worker_id=?', [$targetId,$workerId])->fetchColumn()) {
            $this->Refuse('printer_id','forbidden','Printer is not assigned to this worker');
        }
        if ($route === 'labels-artifact-bytes') {
            // Authorization follows the owning job and attempt, never the digest. Two jobs
            // may legitimately point at the same bytes, and holding one of them is not a
            // reason to be handed the other's.
            $owned = $this->Query('SELECT 1 FROM print_jobs j JOIN print_attempts a ON a.id=j.current_attempt_id
                WHERE j.artifact_id=? AND a.worker_id=? AND a.ended_at IS NULL AND a.lease_expires_at>CURRENT_TIMESTAMP',
                [$targetId,$workerId])->fetchColumn();
            if (!$owned) {
                $this->Refuse('artifact_id','forbidden','No live attempt of this worker carries that artifact');
            }
        }
    }
}
