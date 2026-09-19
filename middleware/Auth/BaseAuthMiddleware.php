<?php

namespace Victual\Middleware\Auth;

use Victual\Middleware\BaseMiddleware;
use Victual\Services\DatabaseService;
use Victual\Services\SessionService;
use Victual\Services\UsersService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Routing\RouteContext;

/**
 * Base class for all authentication middlewares (the concrete class is selected
 * via the VICTUAL_AUTH_CLASS setting). Handles the common flow: public routes
 * (root/login - root still identifies the caller when it can), authentication-less modes
 * (dev/demo/prerelease, embedded install, DISABLE_AUTH) and, otherwise, delegating to
 * AuthenticateRequest().
 * On success the VICTUAL_AUTHENTICATED / VICTUAL_USER_* constants are defined;
 * on failure API routes get a 401 response and other routes a redirect to /login.
 *
 * What a subclass supplies is which Authenticator objects recognise a request, and in
 * what order. It does not supply another middleware: the middlewares used to construct
 * each other and call AuthenticateRequest() across instances, which left half the
 * constructed object's state unset and made one branch of the API key path unreachable
 * (sweep finding S17). Plan 15-C1 is why that is gone.
 */
abstract class BaseAuthMiddleware extends BaseMiddleware
{
	/** @var bool True when the request addresses the JSON API rather than a rendered page */
	protected bool $IsApiRoute = false;

	/**
	 * @var bool True when the request was recognised by a credential the browser attaches
	 * on its own - a session cookie, or a header a reverse proxy adds - rather than by an
	 * API key the caller had to put there. Set by the subclass that did the recognising;
	 * read by the Origin check below.
	 */
	protected bool $AuthenticatedByCookie = false;

	/**
	 * Authenticates the request as described in the class docblock and
	 * either passes it on to the next handler or short-circuits with a
	 * 401 / login redirect response.
	 */
	public function __invoke(Request $request, RequestHandler $handler): Response
	{
		$routeContext = RouteContext::fromRequest($request);
		$route = $routeContext->getRoute();
		$routeName = $route === null ? null : $route->getName();
		$this->IsApiRoute = IsApiRoutePath($request->getUri()->getPath());

		// Worker routes never inherit browser/admin authentication or development bypasses.
		if ($routeName !== null && isset(ApiKeyAuthenticator::LABEL_ROUTE_KEY_TYPES[$routeName]) && $routeName !== 'labels-pair')
		{
			$worker = (new ApiKeyAuthenticator($this->AppContainer))->AuthenticateLabelWorker($request);
			if ($worker === null)
			{
				$response = $this->ResponseFactory->createResponse(401);
				$response->getBody()->write(json_encode(['error_message' => 'Unauthorized']));
				return $response;
			}
			define('VICTUAL_AUTHENTICATED', true);
			if (!defined('VICTUAL_USER_ID'))
			{
				define('VICTUAL_USER_ID', (int)$worker['user_id']);
			}
			return $handler->handle($request->withAttribute('label_worker_id', (int)$worker['worker_id']));
		}

		if ($routeName === 'root')
		{
			// Public, but not anonymous-only: a login lands here (LoginController redirects
			// to /), and Root() chooses the entry page by what the caller may view, which
			// needs to know who that is. So the caller is identified when they can be, and
			// an unidentified one simply is not - Root() then redirects by feature flag
			// alone, as upstream does, and the page it names sends them to /login.
			//
			// Two cases leave it alone. The modes that fix a user up front (dev, demo,
			// prerelease, embedded, DISABLE_AUTH) have already defined VICTUAL_USER_ID. And
			// with MIGRATE_ON_ROOT_REQUEST on, Root() is what creates the schema, so there
			// may be no sessions table to ask yet; SchemaVersionMiddleware lets this one
			// route through unchecked for the same reason.
			$user = (VICTUAL_MIGRATE_ON_ROOT_REQUEST || defined('VICTUAL_USER_ID')) ? null : $this->AuthenticateRequest($request);

			if ($user === null)
			{
				define('VICTUAL_AUTHENTICATED', false);
			}
			else
			{
				$this->DefineUserContext($user);
			}

			return $handler->handle($request);
		}

		if ($routeName === 'login' || $routeName === 'labels-pair')
		{
			// Login and label pairing are public/unauthenticated

			define('VICTUAL_AUTHENTICATED', false);
			return $handler->handle($request);
		}

		if (VICTUAL_MODE === 'dev' || VICTUAL_MODE === 'demo' || VICTUAL_MODE === 'prerelease' || VICTUAL_IS_EMBEDDED_INSTALL || VICTUAL_DISABLE_AUTH)
		{
			// These modes use default user context (without authentication) only

			$sessionService = SessionService::GetInstance();
			$user = $sessionService->GetDefaultUser();

			define('VICTUAL_AUTHENTICATED', true);
			define('VICTUAL_USER_USERNAME', $user->username);
			define('VICTUAL_USER_PICTURE_FILE_NAME', $user->picture_file_name);
			self::SyncDatabaseUserContext();

			return $handler->handle($request);
		}
		else
		{
			// Normal authentication flow (up to specific middleware implementation)

			$user = $this->AuthenticateRequest($request);

			if ($user === null)
			{
				define('VICTUAL_AUTHENTICATED', false);
				$response = $this->ResponseFactory->createResponse();

				if ($this->IsApiRoute)
				{
					// The body is written here rather than left to the caller because
					// nothing downstream of this point runs: this is a short circuit, and
					// a bodyless 401 was what a client had to guess at. JsonMiddleware,
					// which now wraps this middleware, supplies the Content-Type.
					$response->getBody()->write(json_encode(['error_message' => 'Unauthorized']));

					return $response->withStatus(401);
				}
				else
				{
					return $response->withStatus(302)->withHeader('Location', $this->AppContainer->get('UrlManager')->ConstructUrl('/login'));
				}
			}
			else
			{
				$this->DefineUserContext($user);

				$crossOrigin = $this->CrossOriginRefusal($request);

				if ($crossOrigin !== null)
				{
					return $crossOrigin;
				}

				$forcedChange = $this->PasswordChangeRequired($request, (int)$user->id);

				if ($forcedChange !== null)
				{
					return $forcedChange;
				}

				return $handler->handle($request);
			}
		}
	}

	/**
	 * Makes the authenticated user the acting user for the rest of the request.
	 */
	private function DefineUserContext($user): void
	{
		define('VICTUAL_AUTHENTICATED', true);
		define('VICTUAL_USER_ID', $user->id);
		define('VICTUAL_USER_USERNAME', $user->username);
		define('VICTUAL_USER_PICTURE_FILE_NAME', $user->picture_file_name);
		self::SyncDatabaseUserContext();
	}

	/**
	 * A 403 when a state-changing API call was authenticated by a credential the browser
	 * attached on its own and came from another origin, and null otherwise.
	 *
	 * Sweep finding S8. Most API writes are incidentally protected by the
	 * `Content-Type: application/json` check, because a browser cannot send that
	 * cross-origin from a plain form without a preflight - but the routes that act on path
	 * parameters alone parse no body and so were never protected by it:
	 * `POST /stock/bookings/{id}/undo`, the two merge endpoints, `POST /recipes/{id}/copy`
	 * and their neighbours. `SameSite=Lax` closes most of the rest; this is the check that
	 * does not depend on the browser having that default.
	 *
	 * An API key request is exempt, and that is the point of the distinction rather than an
	 * exception to it: a key has to be put in a header deliberately, so a page on another
	 * origin cannot cause one to be sent. The forgery being refused is of the ambient kind.
	 *
	 * **An absent Origin is allowed; a present one that is not ours is not**, and the
	 * distinction is the whole of the rule. Browsers send `Origin` on every cross-origin
	 * request and on same-origin non-GET requests too, so refusing every header that does
	 * not resolve to this origin closes the browser case; refusing an *absent* one would
	 * also refuse a script or a command-line client driving the API with a session cookie,
	 * which is a legitimate if unusual thing to do. `Referer` is consulted only when
	 * `Origin` is missing, so a browser that sends only the older header is still covered.
	 *
	 * **`Origin: null` is a refusal, not an absence.** It is what a sandboxed iframe, a
	 * `data:` document and some redirect chains send, and it is precisely not this origin -
	 * treating it as "no header" was a bypass, and one a page can produce deliberately.
	 * Found in review of PR #68. A header that is present and does not parse into an origin
	 * is refused for the same reason: something sent it, and it is not us.
	 */
	private function CrossOriginRefusal(Request $request): ?Response
	{
		if (!$this->IsApiRoute || !$this->AuthenticatedByCookie)
		{
			return null;
		}

		if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true))
		{
			return null;
		}

		// Origin first and alone when it is there: a request that carries one has said what
		// it is, and falling through to Referer would let a weaker header overrule it
		$header = trim($request->getHeaderLine('Origin'));

		if ($header === '')
		{
			$header = trim($request->getHeaderLine('Referer'));
		}

		if ($header === '')
		{
			return null;
		}

		if (self::OriginOf($header) === self::OwnOrigin($request))
		{
			return null;
		}

		$response = $this->ResponseFactory->createResponse();
		$response->getBody()->write(json_encode(['error_message' => 'Cross-origin request refused for a session-authenticated write - send an API key instead']));

		return $response->withStatus(403);
	}

	/**
	 * The scheme://host[:port] of a URL, or null when there is not one to read.
	 *
	 * Null is "this is not an origin", which the caller treats as a refusal rather than as
	 * an absence - the opaque literal `null` and anything unparseable both land here, and
	 * neither is this origin. Whether the header was sent at all is the caller's question
	 * and is asked before this is called.
	 */
	private static function OriginOf(string $url): ?string
	{
		$url = trim($url);

		if ($url === '')
		{
			return null;
		}

		$parts = parse_url($url);

		if ($parts === false || empty($parts['scheme']) || empty($parts['host']))
		{
			return null;
		}

		return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
	}

	/**
	 * The origin this request was addressed to, as the client sees it.
	 *
	 * X-Forwarded-Proto is honoured because the scheme the PHP process sees behind a
	 * reverse proxy is http while the browser used https, and comparing those would refuse
	 * every write on a correctly deployed instance. The host is the request's own, which is
	 * the same value the browser put in Origin, so nothing here has to be configured.
	 */
	private static function OwnOrigin(Request $request): string
	{
		$uri = $request->getUri();
		$scheme = SessionCookie::IsHttpsRequest() ? 'https' : strtolower($uri->getScheme());
		$origin = $scheme . '://' . strtolower($uri->getHost());
		$port = $uri->getPort();

		if ($port !== null && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)))
		{
			$origin .= ':' . $port;
		}

		return $origin;
	}

	/**
	 * API routes an account that must change its password may still call: what the
	 * change-password page itself needs, and nothing that reads or writes household data.
	 * Method, then the route pattern as routes.php declares it (group prefix included).
	 *
	 * - `PUT /api/users/{userId}` is the save; OwnAccountOnly below restricts it to the
	 *   caller's own id, because the flag is about this account's credential and editing
	 *   somebody else's account is not how it gets resolved.
	 * - `GET /api/user` answers "who am I", which is how a script learns the id to PUT to.
	 * - `GET /api/system/db-changed-time` is polled by every rendered page, the forced
	 *   one included (victual_dbchangedhandling.js); refusing it only fills the console.
	 */
	const PASSWORD_CHANGE_API_ALLOWLIST = [
		['PUT', '/api/users/{userId}'],
		['GET', '/api/user'],
		['GET', '/api/system/db-changed-time'],
	];

	/**
	 * The refusal for an account that has to change its password first, or null when it
	 * does not have to or this request is one it may make anyway. Sweep finding S12's
	 * second half, and the bootstrap issue CodeRabbit raised on PR #211.
	 *
	 * The account is flagged when it logged in with the publicly known "admin" password,
	 * or when it is the first administrator and its password was generated and printed to
	 * the migrate log (see UsersService::RecordPasswordUsedAtLogin()).
	 *
	 * **Rendered pages** redirect to the account's own edit form with the password fields
	 * already open - the `changepw` parameter userform.js already understands. Logging out
	 * is left reachable, because trapping somebody on one page with no way off it is a
	 * worse answer than the problem.
	 *
	 * **API routes** answer 403, except the short list in PASSWORD_CHANGE_API_ALLOWLIST.
	 * Until this they were exempt altogether, because the form saves through the API - and
	 * that exemption meant anybody who logged into a new deployment with admin/admin before
	 * its operator did had the whole API, `POST /api/users` included: a second
	 * administrator account nobody would notice, which survives the password change the
	 * redirect then forced. The allowlist keeps the form working; nothing else gets
	 * through. What it cannot do is stop the first arrival from changing the password
	 * themselves - on an installation still on admin/admin whoever logs in first owns the
	 * account, and only seeding without a known password (InitialDataSeeder) prevents
	 * that. What it does do is make the takeover visible: the operator's password stops
	 * working, rather than the operator carrying on unaware beside a planted account.
	 *
	 * An API key belonging to a flagged account is refused the same way. A key is a
	 * credential of its own, but a flagged account is by definition one whose password
	 * somebody else may know, and a key minted in that state is no more the operator's
	 * than the session that minted it. Changing the password lifts the flag and the key
	 * works again.
	 *
	 * Not applied when authentication is managed outside the application (a reverse
	 * proxy): there is no password here to change.
	 *
	 * It costs one row read rather than a password hash - see
	 * UsersService::RecordPasswordUsedAtLogin() for why that distinction is the whole
	 * design, and why the flag is a column on `users` rather than a setting the account
	 * could delete.
	 */
	private function PasswordChangeRequired(Request $request, int $userId): ?Response
	{
		if (defined('VICTUAL_EXTERNALLY_MANAGED_AUTHENTICATION'))
		{
			return null;
		}

		if (!UsersService::GetInstance()->MustChangePassword($userId))
		{
			return null;
		}

		if ($this->IsApiRoute)
		{
			if ($this->IsAllowedWhilePasswordChangeIsPending($request, $userId))
			{
				return null;
			}

			$response = $this->ResponseFactory->createResponse();
			$response->getBody()->write(json_encode([
				'error_message' => 'This account must change its password before it can use the API: PUT /api/users/' . $userId . ' with the new password and current_password'
			]));

			return $response->withStatus(403);
		}

		$path = $request->getUri()->getPath();

		if (string_ends_with($path, '/logout') || string_ends_with($path, '/user/' . $userId))
		{
			return null;
		}

		return $this->ResponseFactory->createResponse()
			->withStatus(302)
			->withHeader('Location', $this->AppContainer->get('UrlManager')->ConstructUrl('/user/' . $userId . '?changepw=true'));
	}

	/**
	 * Whether an API request is on PASSWORD_CHANGE_API_ALLOWLIST - matched on the route
	 * Slim resolved, not on the raw path, so a base path, a trailing slash or an encoded
	 * character cannot make one route look like another.
	 */
	private function IsAllowedWhilePasswordChangeIsPending(Request $request, int $userId): bool
	{
		$route = RouteContext::fromRequest($request)->getRoute();

		if ($route === null)
		{
			return false;
		}

		$method = strtoupper($request->getMethod());
		$pattern = $route->getPattern();

		foreach (self::PASSWORD_CHANGE_API_ALLOWLIST as [$allowedMethod, $allowedPattern])
		{
			if ($method !== $allowedMethod || $pattern !== $allowedPattern)
			{
				continue;
			}

			// OwnAccountOnly: the one parameterised entry must name the caller
			if ($route->getArgument('userId') !== null && (string)$route->getArgument('userId') !== (string)$userId)
			{
				return false;
			}

			return true;
		}

		return false;
	}

	/**
	 * Passes the acting user down to the database connection. Engines which resolve user
	 * settings in SQL rather than via a PHP callback (PostgreSQL) need this to make
	 * victual_user_setting() work; on SQLite it does nothing.
	 */
	protected static function SyncDatabaseUserContext()
	{
		if (defined('VICTUAL_USER_ID'))
		{
			DatabaseService::GetInstance()->SetCurrentUserId(VICTUAL_USER_ID);
		}
	}

	/**
	 * Processes a login form submission and, on success, creates a session and sets the
	 * session cookie.
	 *
	 * The default is that there is nothing to process: an authentication backend that
	 * delegates to a reverse proxy has no credentials of its own to check, and a login
	 * form submitted to it is answered "invalid" rather than with an exception. It used
	 * to be an abstract static that three of five subclasses satisfied by throwing, so a
	 * login form posted on such an installation was a 500 (plan 15-C1).
	 *
	 * @param array $postParams The POST parameters of the login form (username, password, stay_logged_in)
	 * @return bool True/False if the provided credentials were valid
	 */
	public static function ProcessLogin(array $postParams)
	{
		return false;
	}

	/**
	 * Authenticates the given request (implementation-specific).
	 *
	 * @param Request $request
	 * @return mixed|null the user row or null if the request is not authenticated
	 * @throws \Exception Throws an \Exception if authentaction config is invalid
	 */
	abstract protected function AuthenticateRequest(Request $request);
}
