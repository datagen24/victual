<?php

namespace Victual\Controllers\Api;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Victual\Controllers\Users\User;
use Victual\Services\DatabaseService;
use Victual\Services\Labels\{ArtifactService,LabelAssetService,LabelCaptureService,LabelOperationsService,
    LabelTemplateService,LabelValidationException,RenderRequestService};
use Slim\Routing\RouteContext;

/**
 * Template and asset administration, and previews.
 *
 * `ADMIN` throughout, per the maintainer's answer to plan 27 question 2: templates and
 * printers are administration, and printing is not. That answer is a permission check rather
 * than a role name, so a custom role carrying `ADMIN` qualifies and nothing here privileges a
 * role by what it is called.
 *
 * Previews are here rather than beside the print operations because a preview is a design
 * activity: it creates no print event and no physical attempt, a watermark is a UI overlay
 * rather than a modification of an artifact that might later print, and promoting one is a
 * separate operation that rechecks the print permission from scratch.
 */
class LabelTemplatesApiController extends BaseApiController
{
    public function Dispatch(Request $request, Response $response, array $args)
    {
        User::CheckPermission($request, User::PERMISSION_ADMIN);

        return $this->HandleApiCall($response, function () use ($request, $response, $args) {
            $db = DatabaseService::GetInstance()->GetDbConnectionRaw();
            $route = RouteContext::fromRequest($request)->getRoute()->getName();
            $body = $request->getParsedBody() ?? [];

            if (!is_array($body)) {
                return $this->ApiResponse($response->withStatus(422), ['field' => 'body', 'code' => 'invalid_body', 'error_message' => 'Object required']);
            }

            try {
                $db->beginTransaction();
                $templates = new LabelTemplateService($db);
                $user = defined('VICTUAL_USER_ID') ? (int)VICTUAL_USER_ID : null;

                $result = match ($route) {
                    'label-templates-list' => $db->query('SELECT t.*, v.version AS default_version FROM label_templates t LEFT JOIN label_template_versions v ON v.id=t.default_version_id ORDER BY t.id')->fetchAll(\PDO::FETCH_ASSOC),
                    'label-templates-create' => $templates->Create((string)($body['name'] ?? ''), $body['description'] ?? null, (string)($body['entity_kind'] ?? 'location'), $user),
                    'label-templates-draft' => $templates->GetDraft((int)$args['templateId']),
                    'label-templates-save-draft' => $templates->SaveDraft((int)$args['templateId'], (array)($body['document'] ?? []), (string)($body['revision_token'] ?? ''), $user),
                    'label-templates-publish' => $templates->Publish((int)$args['templateId'], $user),
                    'label-templates-versions' => $this->Versions($db, (int)$args['templateId']),
                    'label-templates-default' => $templates->SetDefaultVersion((int)$args['templateId'], (int)($body['version_id'] ?? 0)),
                    'label-templates-archive' => $templates->Archive((int)$args['templateId']),
                    'label-assets-list' => $db->query('SELECT id,name,asset_kind,mime_type,byte_length,content_digest,width_px,height_px,font_family,font_style,licence,licence_notice,row_created_timestamp FROM label_assets ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
                    'label-assets-create' => $this->StoreAsset($db, $body),
                    'label-preview' => $this->Preview($db, (int)$args['templateId'], $body, $user),
                    'label-render-status' => $this->RenderStatus($db, (int)$args['requestId']),
                    'label-renderer-credential' => (new \Victual\Services\Labels\LabelWorkerCredentialService($db))->IssueRenderer((int)$args['workerId'], $user ?? 0),
                    default => throw new \LogicException('Unknown label template route'),
                };

                $db->commit();
                return $this->ApiResponse($response, $result);
            } catch (LabelValidationException $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $status = in_array($error->errorCode, ['stale_revision', 'idempotency_conflict'], true) ? 409 : 422;
                return $this->ApiResponse($response->withStatus($status), ['field' => $error->field, 'code' => $error->errorCode, 'error_message' => $error->getMessage()]);
            } catch (\Victual\Helpers\ECanonicalizationFailed $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                return $this->ApiResponse($response->withStatus(422), ['field' => 'document', 'code' => 'not_canonicalizable', 'error_message' => $error->getMessage()]);
            } catch (\Throwable $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $error;
            }
        });
    }

    /** The rendered image of a preview, for the designer. Never the generic files API. */
    public function PreviewImage(Request $request, Response $response, array $args)
    {
        User::CheckPermission($request, User::PERMISSION_ADMIN);
        $db = DatabaseService::GetInstance()->GetDbConnectionRaw();

        try {
            $bytes = (new ArtifactService($db))->Bytes((int)$args['artifactId']);
        } catch (LabelValidationException $error) {
            return $this->ApiResponse($response->withStatus(422), ['field' => $error->field, 'code' => $error->errorCode, 'error_message' => $error->getMessage()]);
        }

        $response->getBody()->write($bytes);
        return $response->withHeader('Content-Type', ArtifactService::MIME_TYPE)->withHeader('Cache-Control', 'no-store');
    }

    private function Versions($db, int $templateId): array
    {
        $statement = $db->prepare('SELECT id,version,document_digest,required_capabilities,published_at,published_by_user_id FROM label_template_versions WHERE template_id=? ORDER BY version DESC');
        $statement->execute([$templateId]);
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function StoreAsset($db, array $body): array
    {
        $encoded = $body['content_base64'] ?? null;
        if (!is_string($encoded) || $encoded === '') {
            throw new LabelValidationException('content_base64', 'invalid_value', 'An asset upload carries its bytes');
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false) {
            throw new LabelValidationException('content_base64', 'invalid_value', 'The upload is not valid base64');
        }
        return (new LabelAssetService($db))->Store(
            (string)($body['name'] ?? ''),
            (string)($body['asset_kind'] ?? ''),
            (string)($body['mime_type'] ?? ''),
            $bytes,
            (string)($body['licence'] ?? ''),
            $body['licence_notice'] ?? null
        );
    }

    /**
     * Queues a preview render.
     *
     * Three kinds, and the differences are the point. A **sample** preview invents its values
     * and creates no mapping, so it can never be promoted. A **draft** preview pins a
     * snapshot of the document, so an edit landing while it renders cannot change what
     * somebody is waiting to look at. A **live** preview uses a real uid and real captured
     * values, which is why it needs the same read permission a capture needs and is the only
     * kind that can be promoted to a print.
     */
    private function Preview($db, int $templateId, array $body, ?int $user): array
    {
        $kind = (string)($body['kind'] ?? 'sample');
        $templates = new LabelTemplateService($db);
        $draft = $templates->GetDraft($templateId);
        $entityKind = $draft['entity_kind'];

        $operations = new LabelOperationsService($db, null, $user);
        $resolved = $operations->ResolvePrinter((int)($body['printer_id'] ?? 0));
        $profileId = (int)$resolved['profile']['id'];

        $captures = new LabelCaptureService($db);
        $requests = new RenderRequestService($db);

        if ($kind === 'draft') {
            $document = \Victual\Services\Labels\TemplateDocument::Validate($draft['document'], $entityKind)['document'];
            $fields = $this->FieldsOf($document);
            $capture = $captures->CaptureSample($entityKind, $fields, (string)($body['locale'] ?? 'en'), (string)($body['timezone'] ?? 'UTC'), $user);
            return $requests->CreateForDraft($templateId, $document, $profileId, (int)$capture['id'], $user);
        }

        $version = $templates->ResolveVersion($templateId, isset($body['version_id']) ? (int)$body['version_id'] : null);
        $fields = $this->FieldsOf($version['document']);

        if ($kind === 'live') {
            $targetId = (int)($body['target_id'] ?? 0);
            $uid = $db->query('SELECT uid FROM labels WHERE kind=' . $db->quote($entityKind) . ' AND target_id=' . $targetId . ' AND retired_at IS NULL')->fetchColumn();
            if ($uid === false) {
                // A location with no label gets a sample, and the UI must not describe that
                // as the exact image that will print.
                $capture = $captures->CaptureSample($entityKind, $fields, (string)($body['locale'] ?? 'en'), (string)($body['timezone'] ?? 'UTC'), $user);
                return $requests->CreateForVersion('preview_sample', (int)$version['id'], $profileId, (int)$capture['id'], $user) + ['is_sample' => true];
            }
            $capture = $captures->Capture($entityKind, $targetId, (string)$uid, $fields, (string)($body['locale'] ?? 'en'), (string)($body['timezone'] ?? 'UTC'), $user);
            return $requests->CreateForVersion('preview_live', (int)$version['id'], $profileId, (int)$capture['id'], $user);
        }

        $capture = $captures->CaptureSample($entityKind, $fields, (string)($body['locale'] ?? 'en'), (string)($body['timezone'] ?? 'UTC'), $user);
        return $requests->CreateForVersion('preview_sample', (int)$version['id'], $profileId, (int)$capture['id'], $user) + ['is_sample' => true];
    }

    /**
     * A pending image exposes its status rather than an empty body, so a client polling it
     * can tell "not yet" from "there is nothing here".
     */
    private function RenderStatus($db, int $requestId): array
    {
        $request = (new RenderRequestService($db))->Get($requestId);
        return [
            'render_request_id' => (int)$request['id'],
            'state' => $request['state'],
            'purpose' => $request['purpose'],
            'artifact_id' => $request['artifact_id'] === null ? null : (int)$request['artifact_id'],
            'error_code' => $request['error_code'],
            'error_element' => $request['error_element'],
            'error_detail' => $request['error_detail'],
            'retryable' => in_array($request['state'], ['pending', 'rendering'], true),
        ];
    }

    private function FieldsOf(array $document): array
    {
        $fields = [$document['entity_kind'] . '.name'];
        foreach ($document['elements'] as $element) {
            if ($element['type'] === 'text' && ($element['field'] ?? null) !== null) {
                $fields[] = $element['field'];
            }
        }
        return array_values(array_unique($fields));
    }
}
