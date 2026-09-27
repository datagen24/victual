<?php

// request-subprocess-helper.php, plus the exact call SessionCookie::Set() makes to
// setcookie(), captured by shadowing it - a copy of its own, the way
// bootstrap-subprocess-helper.php and authstack-subprocess-helper.php already are each
// their own copy for their own test file, rather than a change to the shared helper
// every other Pgsql test process uses.
//
// headers_list() cannot be used for this: confirmed empirically (a passing database
// check alongside a failing header check, in the same response) that this CLI SAPI
// reports none of the headers a native setcookie() call queues, native setcookie() being
// exactly how SessionCookie::Set() sets the session cookie - it is not a PSR-7 response
// header, so nothing about the Slim $response object sees it either. PHP resolves an
// unqualified call inside a namespace against a same-named function in that namespace
// first, falling back to the global one only if none exists - so a setcookie() defined
// under Victual\Middleware\Auth, loaded before SessionCookie is, intercepts exactly the
// calls SessionCookie::Set() makes and no others, anywhere else in the app.
//
//   php password-rotation-subprocess-helper.php <base64 of a JSON request description>
//
// Same request description as request-subprocess-helper.php. Output adds "cookies": every
// {name, value, options} SessionCookie::Set()/Clear() called setcookie() with during this
// request, in call order - empty when the request never reached one, which is any refused
// write and any write that did not need a fresh session.

namespace Victual\Middleware\Auth
{
	function setcookie(string $name, string $value = '', $options = []): bool
	{
		$GLOBALS['__capturedCookies'][] = ['name' => $name, 'value' => $value, 'options' => $options];

		return true;
	}
}

namespace
{
	define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
	define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
	require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';

	$spec = json_decode(base64_decode($argv[1] ?? ''), true, flags: JSON_THROW_ON_ERROR);

	// Before config.php, because Setting() freezes a constant the first time it is asked
	// for and ReverseProxyAuthenticator reads REMOTE_ADDR (trusted-proxy check) and, in
	// USE_ENV mode, the proxy-supplied username straight out of $_SERVER rather than out
	// of the PSR-7 request - the same reason authstack-subprocess-helper.php does this
	// (round 5, issue #549 reverse-proxy coverage).
	foreach ($spec['server'] ?? [] as $name => $value)
	{
		$_SERVER[$name] = $value;
	}

	require_once VICTUAL_DATAPATH . '/config.php';
	require_once VICTUAL_ROOT_PATH . '/config-dist.php';

	if (getenv('VICTUAL_TEST_TIMEZONE'))
	{
		date_default_timezone_set(getenv('VICTUAL_TEST_TIMEZONE'));
	}

	$GLOBALS['__capturedCookies'] = [];

	define('VICTUAL_IS_EMBEDDED_INSTALL', false);
	$_SERVER['REQUEST_URI'] = $spec['path'];
	$_SERVER['HTTP_HOST'] = 'localhost';

	$pdo = new PDO(
		'pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
		getenv('PGUSER'),
		getenv('PGPASSWORD'),
		[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
	);
	$pdo->exec('SET search_path TO ' . getenv('RBAC_TEST_SCHEMA') . ', public');
	\Victual\Services\DatabaseService::GetInstance()->GetDialect()->OnConnected($pdo);
	(new ReflectionProperty(\Victual\Services\DatabaseService::class, 'DbConnectionRaw'))->setValue(null, $pdo);
	(new ReflectionProperty(\Victual\Services\DatabaseService::class, 'DbConnection'))->setValue(null, null);

	\Slim\Factory\AppFactory::setContainer(new \DI\Container());
	$app = \Slim\Factory\AppFactory::create();

	$container = $app->getContainer();
	$container->set('view', fn () => new \Victual\Helpers\SlimBladeView(VICTUAL_ROOT_PATH . '/views', VICTUAL_VIEWCACHE_PATH));
	$container->set('UrlManager', fn () => new \Victual\Helpers\UrlManager(VICTUAL_BASE_URL));
	$container->set('ApiKeyHeaderName', fn () => 'VICTUAL-API-KEY');

	require_once VICTUAL_ROOT_PATH . '/routes.php';

	$app->add(new \Victual\Middleware\LocaleMiddleware($container, $app->getResponseFactory()));
	$authMiddlewareClass = VICTUAL_AUTH_CLASS;
	$app->add(new $authMiddlewareClass($container, $app->getResponseFactory()));
	$app->addBodyParsingMiddleware();
	$app->addRoutingMiddleware();
	$app->add(new \Victual\Middleware\SchemaVersionMiddleware($container, $app->getResponseFactory()));
	$errorMiddleware = $app->addErrorMiddleware(false, false, false);
	$errorMiddleware->setDefaultErrorHandler(new \Victual\Controllers\ExceptionController($container, $app->getResponseFactory(), new \Victual\Helpers\StderrLogger()));
	$app->add(new \Victual\Middleware\JsonMiddleware($container, $app->getResponseFactory()));
	$app->add(new \Victual\Middleware\CorsMiddleware($container, $app->getResponseFactory()));

	$request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest($spec['method'], 'http://localhost' . $spec['path']);

	foreach ($spec['headers'] ?? [] as $name => $value)
	{
		$request = $request->withHeader($name, $value);
	}

	if (isset($spec['cookie']))
	{
		$request = $request->withCookieParams([\Victual\Services\SessionService::SESSION_COOKIE_NAME => $spec['cookie']]);
	}

	if (isset($spec['body']))
	{
		$request = $request->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream(json_encode($spec['body'])));

		if (!$request->hasHeader('Content-Type'))
		{
			$request = $request->withHeader('Content-Type', 'application/json');
		}
	}

	$response = $app->handle($request);

	\Victual\Services\DatabaseService::GetInstance()->GetDialect()->FlushDbChangedTime($pdo);

	echo json_encode([
		'status' => $response->getStatusCode(),
		'body' => (string)$response->getBody(),
		'cookies' => $GLOBALS['__capturedCookies'],
	]);
}
