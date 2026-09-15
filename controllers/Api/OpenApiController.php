<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Services\ApiKeyService;
use Victual\Services\ApplicationService;
use Victual\Services\UserfieldsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves the OpenAPI documentation endpoints (/api and /api/openapi/specification)
 * and the API key management pages (/manageapikeys) - a mix of API and view routes.
 */
class OpenApiController extends BaseApiController
{
	/**
	 * GET /manageapikeys - renders the API key management page; non-admins only see
	 * their own keys. The optional integer query parameter "key" preselects a key.
	 */
	public function ApiKeysList(Request $request, Response $response, array $args)
	{
		$selectedKeyId = -1;
		if (isset($request->getQueryParams()['key']) && filter_var($request->getQueryParams()['key'], FILTER_VALIDATE_INT))
		{
			$selectedKeyId = $request->getQueryParams()['key'];
		}

		return $this->RenderApiKeysPage($response, $selectedKeyId);
	}

	/**
	 * Renders the manage-keys page, optionally highlighting one key and showing the
	 * plaintext of one that has just been created.
	 *
	 * @param int $selectedKeyId Row id to highlight, or -1
	 * @param string|null $newApiKey The plaintext of a key created by this request - the
	 *                               only moment it exists, since what is stored is a hash
	 * @param string|null $newApiKeyDescription That key's description, so the one-time
	 *                                          reveal says which key it is showing
	 * @param int|null $rotatedFromId The predecessor this key replaces, when the request
	 *                                that created it was a rotation rather than a plain
	 *                                "add" (issue #130)
	 */
	private function RenderApiKeysPage(Response $response, int $selectedKeyId, ?string $newApiKey = null, ?string $newApiKeyDescription = null, ?int $rotatedFromId = null)
	{
		$apiKeys = $this->DB->api_keys();
		if (!User::HasPermissions(User::PERMISSION_ADMIN))
		{
			$apiKeys = $apiKeys->where('user_id', VICTUAL_USER_ID);
		}

		return $this->RenderPage($response, 'manageapikeys', [
			'apiKeys' => $apiKeys,
			'users' => $this->DB->users(),
			'selectedKeyId' => $selectedKeyId,
			'newApiKey' => $newApiKey,
			'newApiKeyDescription' => $newApiKeyDescription,
			'rotatedFromId' => $rotatedFromId,
			'maxLifetimeDays' => (int)VICTUAL_API_KEY_MAX_LIFETIME_DAYS
		]);
	}

	/**
	 * POST /manageapikeys/new - creates a new API key (optional "description" and
	 * "expires_in_days" form parameters) and renders the manage-keys page showing it,
	 * once. A missing or non-numeric "expires_in_days" gets the configured maximum; a
	 * value outside [1, VICTUAL_API_KEY_MAX_LIFETIME_DAYS] is clamped rather than
	 * refused, since this is a view form rather than an API request (ApiKeyService's own
	 * ExpiryFor() does the clamping).
	 */
	public function CreateNewApiKey(Request $request, Response $response, array $args)
	{
		$postParams = $request->getParsedBody();
		$description = null;
		$lifetimeDays = null;

		if (is_array($postParams))
		{
			if (isset($postParams['description']))
			{
				$description = $postParams['description'];
			}

			if (isset($postParams['expires_in_days']) && filter_var($postParams['expires_in_days'], FILTER_VALIDATE_INT) !== false)
			{
				$lifetimeDays = (int)$postParams['expires_in_days'];
			}
		}

		$newApiKey = ApiKeyService::GetInstance()->CreateApiKey(ApiKeyService::API_KEY_TYPE_DEFAULT, $description, $lifetimeDays);
		$newApiKeyId = ApiKeyService::GetInstance()->GetApiKeyId($newApiKey);

		// Rendered here rather than redirected to, because this response is the only place
		// the key can ever be shown: what is stored is a SHA-256 hash (plan 11, question
		// 4), so nothing can produce the plaintext again. The obvious alternative - putting
		// it in the redirect URL - is the query-string key path sweep finding S11 exists to
		// remove, in the one place it would be most durable: browser history.
		return $this->RenderApiKeysPage($response, (int)$newApiKeyId, $newApiKey, $description);
	}

	/**
	 * POST /manageapikeys/{id}/rotate - creates a successor for the given regular API
	 * key and renders the manage-keys page showing its plaintext, once (issue #130).
	 *
	 * Ownership-checked the same way DeleteObject checks it for api_keys: a non-admin may
	 * only rotate their own key, and any other case (missing id, someone else's key, a
	 * special-purpose key type that has its own rotation story) answers the same 404 a
	 * genuinely missing row would, so ids cannot be enumerated and the special-purpose
	 * types are not offered an action that would regress them.
	 *
	 * This creates the successor only. Retiring the predecessor - so rotation has no
	 * silent side effect - stays the existing, separate "Delete" action on its own row.
	 */
	public function RotateApiKey(Request $request, Response $response, array $args)
	{
		if (!isset($args['id']) || filter_var($args['id'], FILTER_VALIDATE_INT) === false)
		{
			throw new \Slim\Exception\HttpNotFoundException($request);
		}

		$apiKeyId = (int)$args['id'];
		$predecessor = $this->DB->api_keys($apiKeyId);

		if ($predecessor === null
			|| $predecessor->key_type !== ApiKeyService::API_KEY_TYPE_DEFAULT
			|| ($predecessor->user_id != VICTUAL_USER_ID && !User::HasPermissions(User::PERMISSION_ADMIN)))
		{
			throw new \Slim\Exception\HttpNotFoundException($request);
		}

		[$newApiKey, $newApiKeyId] = ApiKeyService::GetInstance()->RotateApiKey($apiKeyId);

		return $this->RenderApiKeysPage($response, $newApiKeyId, $newApiKey, $predecessor->description, $apiKeyId);
	}

	/**
	 * GET /api/openapi/specification - returns victual.openapi.json enriched at runtime
	 * with the installed version, the instance server URL and derived ExposedEntity_*
	 * enum variants (including user entities and minus not editable/deletable/listable
	 * entities) used by the Swagger UI.
	 */
	public function DocumentationSpec(Request $request, Response $response, array $args)
	{
		$spec = $this->GetOpenApispec();

		$applicationService = ApplicationService::GetInstance();
		$versionInfo = $applicationService->GetInstalledVersion();
		$spec->info->version = $versionInfo->Version;
		$spec->info->description = str_replace('PlaceHolderManageApiKeysUrl', $this->AppContainer->get('UrlManager')->ConstructUrl('/manageapikeys'), $spec->info->description);
		$spec->servers[0]->url = $this->AppContainer->get('UrlManager')->ConstructUrl('/api');

		$spec->components->schemas->ExposedEntity_IncludingUserEntities = clone $spec->components->schemas->StringEnumTemplate;
		;
		foreach (UserfieldsService::GetInstance()->GetEntities() as $userEntity)
		{
			array_push($spec->components->schemas->ExposedEntity_IncludingUserEntities->enum, $userEntity);
		}
		sort($spec->components->schemas->ExposedEntity_IncludingUserEntities->enum);

		$spec->components->schemas->ExposedEntity_NotIncludingNotEditable = clone $spec->components->schemas->StringEnumTemplate;
		foreach ($spec->components->schemas->ExposedEntity->enum as $value)
		{
			if (!in_array($value, $spec->components->schemas->ExposedEntityNoEdit->enum))
			{
				array_push($spec->components->schemas->ExposedEntity_NotIncludingNotEditable->enum, $value);
			}
		}
		sort($spec->components->schemas->ExposedEntity_NotIncludingNotEditable->enum);

		$spec->components->schemas->ExposedEntity_IncludingUserEntities_NotIncludingNotEditable = clone $spec->components->schemas->StringEnumTemplate;
		foreach ($spec->components->schemas->ExposedEntity_IncludingUserEntities->enum as $value)
		{
			if (!in_array($value, $spec->components->schemas->ExposedEntityNoEdit->enum))
			{
				array_push($spec->components->schemas->ExposedEntity_IncludingUserEntities_NotIncludingNotEditable->enum, $value);
			}
		}
		array_push($spec->components->schemas->ExposedEntity_IncludingUserEntities_NotIncludingNotEditable->enum, 'stock'); // TODO: Don't hardcode this here - stock entries are normally not editable, but the corresponding Userfields are
		sort($spec->components->schemas->ExposedEntity_IncludingUserEntities_NotIncludingNotEditable->enum);

		$spec->components->schemas->ExposedEntity_NotIncludingNotDeletable = clone $spec->components->schemas->StringEnumTemplate;
		foreach ($spec->components->schemas->ExposedEntity->enum as $value)
		{
			if (!in_array($value, $spec->components->schemas->ExposedEntityNoDelete->enum))
			{
				array_push($spec->components->schemas->ExposedEntity_NotIncludingNotDeletable->enum, $value);
			}
		}
		sort($spec->components->schemas->ExposedEntity_NotIncludingNotDeletable->enum);

		$spec->components->schemas->ExposedEntity_NotIncludingNotListable = clone $spec->components->schemas->StringEnumTemplate;
		foreach ($spec->components->schemas->ExposedEntity->enum as $value)
		{
			if (!in_array($value, $spec->components->schemas->ExposedEntityNoListing->enum))
			{
				array_push($spec->components->schemas->ExposedEntity_NotIncludingNotListable->enum, $value);
			}
		}
		sort($spec->components->schemas->ExposedEntity_NotIncludingNotListable->enum);

		return $this->ApiResponse($response, $spec);
	}

	/**
	 * GET /api - renders the interactive API documentation UI (openapiui view).
	 */
	public function DocumentationUi(Request $request, Response $response, array $args)
	{
		return $this->Render($response, 'openapiui');
	}
}
