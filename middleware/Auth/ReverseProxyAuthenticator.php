<?php

namespace Victual\Middleware\Auth;

use Victual\Services\DatabaseService;
use Victual\Services\UsersService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;

/**
 * Recognises a request by the username an upstream reverse proxy has already
 * authenticated and passed on, either in the HTTP header named by
 * VICTUAL_REVERSE_PROXY_AUTH_HEADER (default "REMOTE_USER") or, when
 * VICTUAL_REVERSE_PROXY_AUTH_USE_ENV is on, in the equally named $_SERVER entry.
 * A local Victual user is created the first time a username is seen.
 */
class ReverseProxyAuthenticator extends Authenticator
{
	/**
	 * @return mixed The user row
	 * @throws HttpUnauthorizedException When the configured header/env variable is
	 *                                   missing, empty or ambiguous
	 * @throws HttpForbiddenException When the request did not come from a trusted proxy
	 */
	public function Authenticate(Request $request)
	{
		if (VICTUAL_REVERSE_PROXY_AUTH_USE_ENV)
		{
			$username = self::UsernameFromEnvironment($request);
		}
		else
		{
			// The header is client-settable, so it is only worth anything if the request
			// demonstrably came from the proxy that sets it. Sweep finding S4.
			self::CheckRequestCameFromTrustedProxy($request);

			$username = self::UsernameFromHeader($request);
		}

		$db = DatabaseService::GetInstance()->GetDbConnection();
		$user = $db->users()->where('username', $username)->fetch();

		if ($user == null)
		{
			// No creator to compare a grant against, so DEFAULT_PERMISSIONS is the whole
			// of what this user gets - which is why that setting no longer defaults to
			// ADMIN. Sweep finding S5.
			$user = UsersService::GetInstance()->CreateUser($username, '', '', '');
		}

		return $user;
	}

	private static function UsernameFromEnvironment(Request $request): string
	{
		if (!isset($_SERVER[VICTUAL_REVERSE_PROXY_AUTH_HEADER]))
		{
			self::RefuseUnauthenticated($request, VICTUAL_REVERSE_PROXY_AUTH_HEADER . ' env variable is missing (could not be found in $_SERVER array)');
		}

		$username = $_SERVER[VICTUAL_REVERSE_PROXY_AUTH_HEADER];

		if (strlen($username) === 0)
		{
			self::RefuseUnauthenticated($request, VICTUAL_REVERSE_PROXY_AUTH_HEADER . ' env variable is invalid');
		}

		return $username;
	}

	private static function UsernameFromHeader(Request $request): string
	{
		$username = $request->getHeader(VICTUAL_REVERSE_PROXY_AUTH_HEADER);

		if (count($username) !== 1 || strlen($username[0]) === 0)
		{
			// Invalid configuration of the proxy
			self::RefuseUnauthenticated($request, VICTUAL_REVERSE_PROXY_AUTH_HEADER . ' header is missing or invalid');
		}

		return $username[0];
	}

	/**
	 * Refuses the request unless it arrived from an address in
	 * VICTUAL_REVERSE_PROXY_AUTH_TRUSTED_PROXIES.
	 *
	 * Without this the username header is simply believed, so anyone who can reach the
	 * backend directly - on the k3s target, any pod in the namespace - authenticates as
	 * whoever they say they are, and is created as a user if they do not exist. An unset
	 * list refuses everything rather than trusting everything: a header-mode deployment
	 * that has not named its proxy is not one whose header means anything.
	 *
	 * Deliberately not applied to VICTUAL_REVERSE_PROXY_AUTH_USE_ENV mode. There the
	 * username comes from $_SERVER, which the web server populates and a client header
	 * cannot reach (PHP exposes those as HTTP_*), so it is not forgeable the same way -
	 * and that mode covers setups like Apache doing its own authentication, where
	 * REMOTE_ADDR is the end user rather than a proxy and requiring a proxy list would
	 * break a correct configuration. USE_ENV is the mode to prefer.
	 *
	 * @throws HttpUnauthorizedException When the list is unset
	 * @throws HttpForbiddenException When the request did not come from an address on it
	 */
	private static function CheckRequestCameFromTrustedProxy(Request $request): void
	{
		$trustedProxies = trim((string)VICTUAL_REVERSE_PROXY_AUTH_TRUSTED_PROXIES);

		if ($trustedProxies === '')
		{
			// An operator misconfiguration, not a hostile caller - but nothing here can
			// tell those apart, and the caller's request still proved nothing about who
			// it is, which is what 401 reports. 403 is kept for the one case where the
			// caller is specifically who this deployment's trusted-proxy list is about:
			// an address it names as untrusted, below.
			self::RefuseUnauthenticated($request, 'REVERSE_PROXY_AUTH_TRUSTED_PROXIES is not configured, so the ' . VICTUAL_REVERSE_PROXY_AUTH_HEADER . ' header cannot be trusted. Set it to the address or CIDR range of your reverse proxy, or use REVERSE_PROXY_AUTH_USE_ENV instead.');
		}

		$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';

		if (!IsIpInCidrList($remoteAddress, $trustedProxies))
		{
			// Here the caller's own address is the reason for the refusal - the header
			// might be entirely genuine, but this request did not arrive from where the
			// deployment says the proxy lives. 403, not 401: the identity is not in
			// question here, the origin is.
			self::RefuseUntrustedProxy($request, 'request did not come from a trusted proxy (REMOTE_ADDR ' . $remoteAddress . ')');
		}
	}

	/**
	 * Logs the operator-facing detail and refuses the request with a 401 whose body says
	 * only that it was not authenticated.
	 *
	 * The detail names settings and header values that describe this deployment, which is
	 * exactly what must not reach a caller who has not proven who they are - the same rule
	 * SchemaVersionMiddleware::DatabaseUnavailable() applies to a connection failure. It is
	 * written with error_log() rather than through a PSR logger for the same reason that
	 * method is: an Authenticator is constructed with nothing but the DI container, and
	 * has no logger of its own to ask for (see BaseMiddleware, which is exactly as bare).
	 * error_log() lands on the same stderr StderrLogger writes to.
	 */
	private static function RefuseUnauthenticated(Request $request, string $detail): never
	{
		error_log('Victual: ReverseProxyAuthenticator refused a request: ' . $detail);

		throw new HttpUnauthorizedException($request, 'Unauthorized');
	}

	/**
	 * As RefuseUnauthenticated(), but a 403: the caller's address, not its claimed
	 * identity, is why the request is refused.
	 */
	private static function RefuseUntrustedProxy(Request $request, string $detail): never
	{
		error_log('Victual: ReverseProxyAuthenticator refused a request: ' . $detail);

		throw new HttpForbiddenException($request, 'Forbidden');
	}
}
