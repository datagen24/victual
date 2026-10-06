<?php

namespace Victual\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Answers CORS preflight OPTIONS requests on API routes, and adds the CORS response
 * headers when the request's Origin is one of the configured allowed origins.
 *
 * Two things about this are deliberate and are decisions rather than implementation
 * detail (plan 11, its question 3).
 *
 * The allow-list is empty by default, so an installation that has not configured
 * CORS_ALLOWED_ORIGINS sends no CORS headers at all. This replaces an unconditional
 * `Access-Control-Allow-Origin: *` on an API that authenticates with a key
 * (sweep finding S21). A wildcard on an authenticated API was never a feature, the
 * deployment this fork targets has no browser-based third-party client, and an ingress
 * can add the header in an emergency.
 *
 * A preflight is answered 204 whether or not the origin is allowed. It carries no
 * credentials by construction, so authenticating it - which is what used to happen, and
 * answered 401 - can only ever refuse a request that was asking permission rather than
 * doing anything. An origin that is not on the list simply gets no CORS headers with its
 * 204, which is what makes the browser refuse the real request.
 *
 * This middleware runs at application level, outside the authentication middleware, so
 * that a 401 carries CORS headers like any other response. It applies to API paths only:
 * a rendered page is same-origin by construction and has no use for them.
 */
class CorsMiddleware extends BaseMiddleware
{
	public function __invoke(Request $request, RequestHandler $handler): Response
	{
		if (!IsApiRoutePath($request->getUri()->getPath()))
		{
			return $handler->handle($request);
		}

		if ($request->getMethod() === 'OPTIONS')
		{
			$response = $this->ResponseFactory->createResponse(204);
		}
		else
		{
			$response = $handler->handle($request);
		}

		return $this->WithCorsHeaders($request, $response);
	}

	/**
	 * The configured allowed origins, as an exact-match list.
	 *
	 * @return string[]
	 */
	public static function AllowedOrigins(): array
	{
		$configured = array_map('trim', explode(',', (string)VICTUAL_CORS_ALLOWED_ORIGINS));

		return array_values(array_filter($configured, function ($origin)
		{
			return $origin !== '';
		}));
	}

	private function WithCorsHeaders(Request $request, Response $response): Response
	{
		$allowedOrigins = self::AllowedOrigins();

		if (empty($allowedOrigins))
		{
			return $response;
		}

		// Added whether or not this particular origin matched: the answer depends on the
		// request's Origin, and a cache that is not told so serves one origin's answer to
		// another.
		$response = $response->withAddedHeader('Vary', 'Origin');

		$origin = trim($request->getHeaderLine('Origin'));

		if ($origin === '' || !in_array($origin, $allowedOrigins, true))
		{
			return $response;
		}

		return $response
			->withHeader('Access-Control-Allow-Origin', $origin)
			->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
			->withHeader('Access-Control-Allow-Headers', 'Content-Type, ' . $this->AppContainer->get('ApiKeyHeaderName'))
			->withHeader('Access-Control-Max-Age', '600')
			// ADR-0037 section 12a: a cross-origin client may read an undo's label revival counts.
			->withHeader('Access-Control-Expose-Headers', \Victual\Controllers\Api\BaseApiController::LABEL_REVIVAL_HEADER);
	}
}
