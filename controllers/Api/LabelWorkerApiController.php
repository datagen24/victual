<?php

namespace Victual\Controllers\Api;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Victual\Services\DatabaseService;
use Victual\Services\Labels\{LabelWorkerAuthorization,LabelWorkerCredentialService,DriverRegistryService,PrintAttemptService,PrintEvidenceService,PrinterStatusService,LabelValidationException};
use Slim\Routing\RouteContext;

class LabelWorkerApiController extends BaseApiController
{
    public function Dispatch(Request $request, Response $response, array $args)
    {
        return $this->HandleApiCall($response, function () use ($request, $response, $args) {
            $db = DatabaseService::GetInstance()->GetDbConnectionRaw();
            $route = RouteContext::fromRequest($request)->getRoute()->getName();
            $body = $request->getParsedBody();
            if (!is_array($body)) {
                return $this->ApiResponse($response->withStatus(422), ['field' => 'body','code' => 'invalid_body','error_message' => 'JSON object required']);
            }
            $worker = (int)$request->getAttribute('label_worker_id');
            $target = isset($args['attemptId']) ? (int)$args['attemptId'] : (isset($args['printerId']) ? (int)$args['printerId'] : 0);
            $db->beginTransaction();
            try {
                if ($route !== 'labels-pair') {
                    (new LabelWorkerAuthorization($db))->Authorize($route, $worker, $target);
                }
                $credential = new LabelWorkerCredentialService($db);
                $attempt = new PrintAttemptService($db);
                $result = match($route) {
                    'labels-pair' => $credential->Pair($this->StringField($body, 'material')),
                    'labels-rotate' => $credential->Rotate($request->getHeaderLine($this->AppContainer->get('ApiKeyHeaderName')), $this->StringField($body, 'rotation_request_id'), $this->StringField($body, 'material')),
                    'labels-register' => $this->Register($db, $worker, $body),
                    'labels-claim' => $attempt->Claim($worker, $this->IntegerField($body, 'limit', 1)),
                    'labels-heartbeat' => $attempt->Heartbeat($worker, $target),
                    'labels-sent' => $attempt->Sent($worker, $target),
                    'labels-result' => $attempt->Result($worker, $target, $this->StringField($body, 'outcome'), $this->ObjectField($body, 'detail')),
                    'labels-evidence' => (new PrintEvidenceService($db))->Submit($worker, $target, $body),
                    'labels-status' => (new PrinterStatusService($db))->Report($worker, $target, $body),
                    default => throw new \LogicException('Unknown worker route')
                };
                $db->commit();
                return $this->ApiResponse($response->withStatus(isset($result['revoked']) ? 401 : 200), $result);
            } catch (LabelValidationException $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $status = in_array($error->errorCode, ['unauthorized','inactive_worker'], true) ? 401 : ($error->errorCode === 'forbidden' ? 403 : 422);
                return $this->ApiResponse($response->withStatus($status), ['field' => $error->field,'code' => $error->errorCode,'error_message' => $error->getMessage()]);
            } catch (\Throwable $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                } throw $error;
            }
        });
    }
    private function Register(\PDO $db, int $worker, array $body): array
    {
        if (!is_array($body['drivers'] ?? null) || !array_is_list($body['drivers']) || count($body['drivers']) > 64) {
            throw new LabelValidationException('drivers', 'value_out_of_range', 'Expected at most 64 driver definitions');
        }
        (new DriverRegistryService($db))->Register($worker, $body['drivers']);
        return ['registered' => true];
    }
    private function StringField(array $body, string $field): string
    {
        if (!is_string($body[$field] ?? null)) {
            throw new LabelValidationException($field, 'value_out_of_range', 'String required');
        } return $body[$field];
    }
    private function IntegerField(array $body, string $field, int $default): int
    {
        $value = $body[$field] ?? $default;
        if (!is_int($value)) {
            throw new LabelValidationException($field, 'value_out_of_range', 'Integer required');
        } return $value;
    }
    private function ObjectField(array $body, string $field): array
    {
        if (!is_array($body[$field] ?? null)) {
            throw new LabelValidationException($field,'value_out_of_range','Object required');
        } return $body[$field];
    }
}
