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
     'labels-status' => [ApiKeyService::API_KEY_TYPE_LABEL_WORKER]
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
    }
}
