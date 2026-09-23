<?php

namespace Victual\Middleware\Auth;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Active when VICTUAL_AUTH_CLASS is set to Victual\Middleware\Auth\ReverseProxyAuthMiddleware:
 * authenticates against the username supplied by an upstream reverse proxy that
 * already performed authentication, either via the HTTP header named by
 * VICTUAL_REVERSE_PROXY_AUTH_HEADER (default "REMOTE_USER") or, when
 * VICTUAL_REVERSE_PROXY_AUTH_USE_ENV is enabled, the equally named entry in
 * $_SERVER (see config-dist.php). A matching local Victual user is created on
 * first sight of a username. API routes still fall back to regular API key
 * authentication first, for reverse proxy setups that bypass the proxy for them.
 *
 * ProcessLogin() is inherited and answers false: there is no login form to process when
 * the credentials never reach this application.
 */
class ReverseProxyAuthMiddleware extends BaseAuthMiddleware
{
	/**
	 * Authenticates via API key (API routes only, as a proxy-bypass fallback), or
	 * otherwise trusts the username supplied by the reverse proxy.
	 *
	 * @return mixed The user row
	 * @throws \Slim\Exception\HttpUnauthorizedException When the configured header/env
	 *                    variable is missing, empty or ambiguous, or the trusted-proxy list
	 *                    is unconfigured
	 * @throws \Slim\Exception\HttpForbiddenException When the request did not come from a
	 *                    trusted proxy
	 */
	protected function AuthenticateRequest(Request $request)
	{
		define('VICTUAL_EXTERNALLY_MANAGED_AUTHENTICATION', true);

		// Try regular API key authentication first (applies when the reverse proxy is
		// configured to be bypassed for API routes)
		if ($this->IsApiRoute)
		{
			$user = (new ApiKeyAuthenticator($this->AppContainer))->Authenticate($request);

			if ($user !== null)
			{
				return $user;
			}
		}

		// A proxy-supplied identity travels with the request the same way a cookie does -
		// the browser sends it on a cross-site request without the page asking - so it is
		// ambient in the sense the Origin check cares about
		$this->AuthenticatedByCookie = true;

		return (new ReverseProxyAuthenticator($this->AppContainer))->Authenticate($request);
	}
}
