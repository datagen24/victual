<?php

namespace Victual\Controllers\Api;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Victual\Services\DatabaseService;
use Victual\Services\Labels\{ArtifactService,LabelAssetService,LabelOperationsService,LabelValidationException,RenderRequestService};
use Slim\Routing\RouteContext;

/**
 * The renderer's five routes, and the worker's one byte-fetch route.
 *
 * Everything here authenticates with a typed key and authorizes against a row, never against
 * a digest. Two artifacts may legitimately be the same bytes, so holding one job is not a
 * reason to be handed another's picture - `LabelWorkerAuthorization` checks the owning
 * attempt, and this controller does not second-guess it.
 *
 * There is no route here that takes a URL. The renderer is handed identifiers and fetches
 * what it was assigned; Victual remains the server and never the client, which is the
 * property plan 25's security section claims and this plan must not quietly spend.
 */
class LabelRenderApiController extends BaseApiController
{
    public function Dispatch(Request $request, Response $response, array $args)
    {
        return $this->HandleApiCall($response, function () use ($request, $response, $args) {
            $db = DatabaseService::GetInstance()->GetDbConnectionRaw();
            $route = RouteContext::fromRequest($request)->getRoute()->getName();
            $worker = (int)$request->getAttribute('label_worker_id');

            $body = $request->getParsedBody() ?? [];
            if (!is_array($body)) {
                return $this->ApiResponse($response->withStatus(422), ['field' => 'body', 'code' => 'invalid_body', 'error_message' => 'Object required']);
            }

            try {
                $db->beginTransaction();
                $authorization = new \Victual\Services\Labels\LabelWorkerAuthorization($db);
                $target = match ($route) {
                    'labels-artifact-bytes' => (int)$args['artifactId'],
                    default => null,
                };
                $authorization->Authorize($route, $worker, $target);

                $requests = new RenderRequestService($db);
                $result = match ($route) {
                    'labels-render-claim' => $requests->Claim() ?? ['render_request_id' => null],
                    'labels-render-result' => $this->Result($db, (int)$args['requestId'], $body),
                    'labels-render-invalid' => $requests->RecordInvalid((int)$args['requestId'], (string)($body['generation_token'] ?? ''), (string)($body['code'] ?? 'UNSPECIFIED'), $body['element'] ?? null, (string)($body['detail'] ?? '')),
                    'labels-render-failed' => $requests->RecordFailure((int)$args['requestId'], (string)($body['generation_token'] ?? ''), (string)($body['detail'] ?? '')),
                    default => throw new \LogicException('Unknown render route'),
                };
                $db->commit();

                if ($route === 'labels-artifact-bytes' || $route === 'labels-asset-bytes') {
                    return $result;
                }
                return $this->ApiResponse($response, $result);
            } catch (LabelValidationException $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                return $this->ApiResponse($response->withStatus(422), ['field' => $error->field, 'code' => $error->errorCode, 'error_message' => $error->getMessage()]);
            } catch (\Throwable $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $error;
            }
        });
    }

    /**
     * Streams the exact bytes of an artifact or an asset.
     *
     * A separate entry point from Dispatch() because it answers with bytes rather than with
     * the JSON envelope, and because these are the only two routes in the subsystem that do.
     * Neither group is in the OpenAPI `FileGroups` enum, so `FilesApiController` refuses them
     * on its own three routes by the check it already runs - this is the only door.
     */
    public function Bytes(Request $request, Response $response, array $args)
    {
        $db = DatabaseService::GetInstance()->GetDbConnectionRaw();
        $route = RouteContext::fromRequest($request)->getRoute()->getName();
        $worker = (int)$request->getAttribute('label_worker_id');

        try {
            $db->beginTransaction();
            $authorization = new \Victual\Services\Labels\LabelWorkerAuthorization($db);

            if ($route === 'labels-artifact-bytes') {
                $authorization->Authorize($route, $worker, (int)$args['artifactId']);
                $bytes = (new ArtifactService($db))->Bytes((int)$args['artifactId']);
                $type = ArtifactService::MIME_TYPE;
            } else {
                $authorization->Authorize($route, $worker, null);
                $assets = new LabelAssetService($db);
                $bytes = $assets->Bytes((int)$args['assetId']);
                if ($bytes === null) {
                    $db->rollBack();
                    return $this->ApiResponse($response->withStatus(404), ['field' => 'asset_id', 'code' => 'not_found', 'error_message' => 'No such asset']);
                }
                $type = (string)$db->query('SELECT mime_type FROM label_assets WHERE id=' . (int)$args['assetId'])->fetchColumn();
            }
            $db->commit();

            $response->getBody()->write($bytes);
            return $response->withHeader('Content-Type', $type)->withHeader('Cache-Control', 'no-store');
        } catch (LabelValidationException $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return $this->ApiResponse($response->withStatus(422), ['field' => $error->field, 'code' => $error->errorCode, 'error_message' => $error->getMessage()]);
        }
    }

    /**
     * Accepts a rendered artifact and, when it verifies, attaches it to the waiting job.
     *
     * The bytes arrive base64-encoded in the JSON envelope rather than as a multipart upload,
     * which keeps one request shape across the whole worker and renderer API and keeps the
     * generation token in the same document as the payload it authorizes. The size bound is
     * enforced on the decoded bytes, where it means something.
     */
    private function Result($db, int $requestId, array $body): array
    {
        $encoded = $body['artifact_base64'] ?? null;
        if (!is_string($encoded) || $encoded === '') {
            throw new LabelValidationException('artifact_base64', 'invalid_value', 'A render result carries its artifact bytes');
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false) {
            throw new LabelValidationException('artifact_base64', 'invalid_value', 'The artifact is not valid base64');
        }

        $artifact = (new ArtifactService($db))->Accept(
            $requestId,
            (string)($body['generation_token'] ?? ''),
            $bytes,
            (string)($body['renderer_id'] ?? 'unknown'),
            (string)($body['renderer_version'] ?? 'unknown')
        );

        $attached = (new LabelOperationsService($db))->AttachArtifact($requestId, (int)$artifact['id']);

        return ['artifact_id' => (int)$artifact['id'], 'byte_digest' => $artifact['byte_digest'],
            'manifest_digest' => $artifact['manifest_digest'], 'jobs_attached' => $attached];
    }
}
