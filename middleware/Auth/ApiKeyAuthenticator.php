<?php

namespace Victual\Middleware\Auth;

use DI\Container;
use Victual\Services\ApiKeyService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;

/**
 * Recognises a request by an API key, against the keys ApiKeyService manages.
 *
 * The key is read from the configured header (default VICTUAL-API-KEY), and - on the
 * calendar iCal route only - from the "secret" query parameter, checked against
 * special-purpose calendar keys. That second path exists because a calendar application
 * subscribing to the feed cannot set a custom header, and it is scoped to one route and
 * one key type for the same reason.
 *
 * A regular key is stored as a SHA-256 hash, so the lookup hashes what arrives rather than
 * comparing it; a calendar key is stored as issued, because the sharing dialog has to be
 * able to hand its URL back. ApiKeyService::StoredValueOf() is where that distinction and
 * its reasoning live.
 *
 * It resolves that route itself rather than being told, which is sweep finding S17's fix:
 * the branch used to read a RouteName field that only BaseAuthMiddleware::__invoke() ever
 * set, and the instance doing the reading was constructed by another middleware and never
 * invoked - so the field was null, the branch was unreachable and every sharing link
 * answered 401.
 */
class ApiKeyAuthenticator extends Authenticator
{
	public const LABEL_ROUTE_KEY_TYPES = \Victual\Services\Labels\LabelWorkerAuthorization::ROUTE_KEY_TYPES;

	public function AuthenticateLabelWorker(Request $request): ?array
	{
		$route = RouteContext::fromRequest($request)->getRoute()?->getName();
		if ($route === null || !isset(self::LABEL_ROUTE_KEY_TYPES[$route]) || $route === 'labels-pair') return null;
		$key = $request->getHeaderLine($this->ApiKeyHeaderName);
		if ($key === '') return null;
		$service = new \Victual\Services\Labels\LabelWorkerCredentialService(
			\Victual\Services\DatabaseService::GetInstance()->GetDbConnectionRaw());
		foreach (self::LABEL_ROUTE_KEY_TYPES[$route] as $type)
		{
			$identity = $service->Authenticate($key, $route === 'labels-rotate', $type);
			if ($identity !== null) return $identity;
		}
		return null;
	}

	public function __construct(Container $container)
	{
		parent::__construct($container);
		$this->ApiKeyHeaderName = $this->AppContainer->get('ApiKeyHeaderName');
	}

	/** @var string Name of the header carrying the API key */
	private readonly string $ApiKeyHeaderName;

	/**
	 * @return mixed|null The user owning the key, or null when the request carries no
	 *                    valid one
	 */
	public function Authenticate(Request $request)
	{
		$apiKeyService = ApiKeyService::GetInstance();

		$headerKey = $request->getHeaderLine($this->ApiKeyHeaderName);

		if ($headerKey !== '' && $apiKeyService->IsValidApiKey($headerKey))
		{
			return $apiKeyService->GetUserByApiKey($headerKey);
		}

		$calendarKey = $this->CalendarSharingSecret($request);

		if ($calendarKey !== null && $apiKeyService->IsValidApiKey($calendarKey, ApiKeyService::API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL))
		{
			return $apiKeyService->GetUserByApiKey($calendarKey, ApiKeyService::API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL);
		}

		return null;
	}

	/**
	 * The "secret" query parameter, but only on the calendar iCal route.
	 *
	 * @return string|null
	 */
	private function CalendarSharingSecret(Request $request): ?string
	{
		$route = RouteContext::fromRequest($request)->getRoute();

		if ($route === null || $route->getName() !== 'calendar-ical')
		{
			return null;
		}

		$secret = $request->getQueryParams()['secret'] ?? null;

		return (is_string($secret) && $secret !== '') ? $secret : null;
	}
}
