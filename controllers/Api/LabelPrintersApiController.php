<?php

namespace Victual\Controllers\Api;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Victual\Controllers\Users\User;
use Victual\Services\DatabaseService;
use Victual\Services\Labels\{PrinterConfigurationService,LabelWorkerCredentialService,LabelPrintJobService,LabelValidationException};
use Slim\Routing\RouteContext;

class LabelPrintersApiController extends BaseApiController
{
    public function Dispatch(Request $request, Response $response, array $args)
    {
        User::CheckPermission($request, User::PERMISSION_ADMIN);
        return $this->HandleApiCall($response, function () use ($request, $response, $args) {
            $db = DatabaseService::GetInstance()->GetDbConnectionRaw();
            $action = RouteContext::fromRequest($request)->getRoute()->getName();
            $body = $request->getParsedBody() ?? [];
            if (!is_array($body)) {
                return $this->ApiResponse($response->withStatus(422), ['field' => 'body','code' => 'invalid_body','error_message' => 'Object required']);
            }
            try {
                // Both validation layers run before transaction start, then again inside Save.
                if (in_array($action, ['label-admin-printer-create','label-admin-printer-update','label-admin-printer-move'], true)) {
                    (new PrinterConfigurationService($db))->Validate($body);
                }
                $db->beginTransaction();
                $credentials = new LabelWorkerCredentialService($db);
                $configuration = new PrinterConfigurationService($db);
                $result = match($action) {
                    'label-admin-printer-create' => ['id' => $configuration->Save($body)],
                    'label-admin-printer-update' => ['id' => $configuration->Save($body, (int)$args['printerId'])],
                    'label-admin-printer-move' => ['id' => $configuration->Save($body, (int)$args['printerId'], true)],
                    'label-admin-printer-delete' => $this->DeletePrinter($db, (int)$args['printerId']),
                    'label-admin-worker-create' => $this->SaveWorker($db, $body, null),
                    'label-admin-worker-update' => $this->SaveWorker($db, $body, (int)$args['workerId']),
                    'label-admin-pairing' => $credentials->PairingMaterial((int)$args['workerId'], VICTUAL_USER_ID),
                    'label-admin-issue' => $credentials->IssueDeclared((int)$args['workerId'], VICTUAL_USER_ID),
                    'label-admin-revoke' => $this->Revoke($credentials, (int)$args['workerId']),
                    'label-admin-jobs' => (new LabelPrintJobService($db))->Monitor(),
                    'label-admin-authorize' => (new LabelPrintJobService($db))->AuthorizeAnotherAttempt((int)$args['jobId'], $this->Id($body, 'attempt_id')),
                    'label-admin-schema' => $this->Schema($db, $args),
                    default => throw new \LogicException('Unknown label administration route')
                };
                $db->commit();
                return $this->ApiResponse($response, $result);
            } catch (LabelValidationException $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                return $this->ApiResponse($response->withStatus(422), ['field' => $error->field,'code' => $error->errorCode,'error_message' => $error->getMessage()]);
            } catch (\Throwable $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }throw $error;
            }
        });
    }
    private function Id(array $body, string $key): int
    {
        if (!is_int($body[$key] ?? null) || $body[$key] < 1) {
            throw new LabelValidationException($key, 'value_out_of_range', 'Positive integer required');
        }return $body[$key];
    }
    private function DeletePrinter(\PDO $db, int $id): array
    {
        // Retire queued work visibly before removing its configuration. History retains the id.
        $q = $db->prepare("UPDATE outbox SET dead_lettered_at=CURRENT_TIMESTAMP,last_error='Printer deleted' WHERE id IN (SELECT outbox_id FROM print_jobs WHERE printer_id=? AND outcome IS NULL)");
        $q->execute([$id]);
        $q = $db->prepare("UPDATE print_jobs SET outcome='dead_lettered',outcome_at=CURRENT_TIMESTAMP WHERE printer_id=? AND outcome IS NULL");
        $q->execute([$id]);
        $q = $db->prepare('DELETE FROM label_printer_status WHERE printer_id=?');
        $q->execute([$id]);
        $q = $db->prepare('DELETE FROM label_printers WHERE id=?');
        $q->execute([$id]);
        return ['deleted' => $q->rowCount() === 1];
    }
    private function SaveWorker(\PDO $db, array $body, ?int $id): array
    {
        if (!is_string($body['name'] ?? null) || trim($body['name']) === '') {
            throw new LabelValidationException('name', 'value_out_of_range', 'Name required');
        }
        if (!in_array($body['configuration_mode'] ?? null, ['declared','paired'], true)) {
            throw new LabelValidationException('configuration_mode', 'value_out_of_range', 'Invalid mode');
        }
        $active = $body['active'] ?? 1;
        if (!in_array($active, [0,1], true)) {
            throw new LabelValidationException('active', 'value_out_of_range', 'Use 0 or 1');
        }
        if ($id === null) {
            $q = $db->prepare('INSERT INTO label_workers(name,description,configuration_mode,active) VALUES (?,?,?,?) RETURNING id');
            $q->execute([$body['name'],$body['description'] ?? null,$body['configuration_mode'],$active]);
            return ['id' => (int)$q->fetchColumn()];
        }
        $q = $db->prepare('SELECT configuration_mode FROM label_workers WHERE id=? FOR UPDATE');
        $q->execute([$id]);
        $mode = $q->fetchColumn();
        if (!$mode || $mode !== $body['configuration_mode']) {
            throw new LabelValidationException('configuration_mode', 'value_out_of_range', 'Create a new worker to change mode');
        }
        $q = $db->prepare('UPDATE label_workers SET name=?,description=?,active=? WHERE id=?');
        $q->execute([$body['name'],$body['description'] ?? null,$active,$id]);
        if (!$active) {
            $q = $db->prepare("UPDATE label_worker_sessions SET revoked_at=CURRENT_TIMESTAMP,revoked_reason='worker_deactivated' WHERE worker_id=? AND revoked_at IS NULL");
            $q->execute([$id]);
            $q = $db->prepare("UPDATE api_keys SET api_key='revoked:'||id::text,expires=CURRENT_TIMESTAMP WHERE id IN (SELECT api_key_id FROM label_worker_credentials WHERE worker_id=?)");
            $q->execute([$id]);
        }
        return ['id' => $id];
    }
    private function Revoke(LabelWorkerCredentialService $service, int $id): array
    {
        $service->Revoke($id);
        return ['revoked' => true];
    }
    private function Schema(\PDO $db, array $args): array
    {
        $q = $db->prepare('SELECT settings_schemas,discriminator_properties,combination_binding,capability_document FROM label_drivers WHERE driver_id=? AND schema_version=?');
        $q->execute([$args['driverId'],$args['schemaVersion']]);
        $row = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new LabelValidationException('driver_schema_version','unknown_driver_version','Unknown driver version');
        }
        return array_map(fn ($json) => json_decode($json,true,512,JSON_THROW_ON_ERROR),$row);
    }
}
