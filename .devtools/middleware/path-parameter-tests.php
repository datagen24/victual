<?php

// PathParameterMiddleware, driven through a real (throwaway) Slim route dispatch rather
// than by calling its private static methods directly.
//
//   php .devtools/middleware/path-parameter-tests.php
//
// Nothing here touches a database - the middleware needs none (see its constructor's own
// docblock) - so this is a plain PHP script, not a run-tests.sh phase.
//
// Written for two reasons together, not one: PathParameterMiddleware previously had no
// test at all (nothing in this tree boots a real Slim App and dispatches a request
// through it; every controller test calls the controller method directly instead), and
// the path-key rename in victual.openapi.json ({kind:location|product|...} ->
// {kind}) only works because PathParameterMiddleware and
// .devtools/check-path-id-validation.php both strip the same FastRoute constraint syntax
// off the route pattern before using it as a spec lookup key. Registering the route here
// with the exact constrained pattern routes.php uses is what actually exercises that
// stripping - a route registered without the constraint would pass even if the stripping
// were missing entirely.

define('VICTUAL_ROOT_PATH', dirname(__DIR__, 2));

require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Victual\Middleware\PathParameterMiddleware;

$checks = 0;

function Check(bool $ok, string $message): void
{
	global $checks;

	if (!$ok)
	{
		throw new RuntimeException($message);
	}

	$checks++;
}

/**
 * A fresh app with one route group, registered with the same constrained pattern
 * routes.php uses for the generic label routes, and PathParameterMiddleware attached the
 * same way: on the group, added before addRoutingMiddleware() so that (per
 * MiddlewareDispatcher's stack - last added runs first) routing resolves the route and
 * sets its attributes on the request *before* the middleware under test reads them.
 */
function BuildApp(): \Slim\App
{
	AppFactory::setContainer(new DI\Container());
	$app = AppFactory::create();

	$app->group('/api', function (\Slim\Routing\RouteCollectorProxy $group) use ($app)
	{
		$group->post('/labels/{kind:location|product|stock_entry|recipe|chore|battery}/{id:[0-9]+}/print', function (Request $request, Response $response)
		{
			$response->getBody()->write('accepted');

			return $response->withStatus(202);
		});
	})->add(new PathParameterMiddleware($app->getContainer(), $app->getResponseFactory()));

	$app->addRoutingMiddleware();

	// Outermost (added last, so it runs first per MiddlewareDispatcher's stack) so that
	// an unmatched {kind} - a genuine 404 thrown by RoutingMiddleware, not a response
	// PathParameterMiddleware ever produces - comes back as a response instead of an
	// uncaught exception out of handle() below.
	$app->addErrorMiddleware(false, false, false);

	return $app;
}

// A constraint that itself contains braces - a bounded quantifier, {1,9} - which is what
// StripFastRouteConstraints has to get right by reusing FastRoute's own recursive
// placeholder regex rather than a hand-rolled one: a pattern that just stops at the
// first "}" splits this as "{id:[0-9]{1,9}" + a stray trailing "}", not "{id}".
Check(
	PathParameterMiddleware::StripFastRouteConstraints('/labels/{kind:location|product}/{id:[0-9]{1,9}}/print') === '/labels/{kind}/{id}/print',
	'StripFastRouteConstraints mishandled a constraint containing its own braces'
);

// An {id} that FastRoute's own {id:[0-9]+} constraint already lets through - all
// digits, so the route matches - but that overflows what filter_var(..., FILTER_VALIDATE_INT)
// accepts. This is the case only PathParameterMiddleware catches on this route (a
// non-digit string never reaches it at all: FastRoute's own regex 404s it first), and
// it is the exact regression a rename of victual.openapi.json's path key without
// updating the middleware's own normalization would reintroduce - the spec lookup would
// miss, {id} would be read back as untyped, and this request would reach the route
// handler unvalidated instead of being refused here.
$app = BuildApp();
$request = (new ServerRequestFactory())->createServerRequest('POST', '/api/labels/product/99999999999999999999999/print');
$response = $app->handle($request);
Check($response->getStatusCode() === 400, 'Out-of-range {id} on the constrained label route was not refused, got ' . $response->getStatusCode());
$body = json_decode((string)$response->getBody(), true);
Check(($body['error_message'] ?? null) === 'Invalid path parameter: {id} has to be an integer', 'Unexpected 400 body: ' . (string)$response->getBody());

// The same route with a valid integer {id} reaches the handler - proving the spec lookup
// found {id} typed as an integer at all, rather than this route simply never being
// validated (which would make the check above pass for the wrong reason: everything
// refused as "undocumented").
$app = BuildApp();
$request = (new ServerRequestFactory())->createServerRequest('POST', '/api/labels/product/42/print');
$response = $app->handle($request);
Check($response->getStatusCode() === 202, 'Valid integer {id} on the constrained label route was refused, got ' . $response->getStatusCode());
Check((string)$response->getBody() === 'accepted', 'Valid request did not reach the route handler');

// A non-integer {kind} is not this middleware's job - the spec types {kind} as a string
// enum, so PathParameterMiddleware has nothing to say about it and Slim's own routing
// simply fails to match (kind is constrained to the enum values at the Slim level too),
// answering 404 rather than a 400 from this middleware.
$app = BuildApp();
$request = (new ServerRequestFactory())->createServerRequest('POST', '/api/labels/not-a-kind/42/print');
$response = $app->handle($request);
Check($response->getStatusCode() === 404, 'An unconstrained {kind} should 404 from routing, not reach the middleware, got ' . $response->getStatusCode());

echo 'PASS ' . $checks . " assertions\n";
