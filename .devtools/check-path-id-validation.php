<?php

// Every id-shaped API path parameter is typed in victual.openapi.json.
//
//   php .devtools/check-path-id-validation.php
//
// PathParameterMiddleware refuses a non-integer where an endpoint takes an integer id,
// and it reads which parameters those are from victual.openapi.json rather than from a
// list of its own. That is the right source - the spec already knows that {objectId} is
// an integer on /objects/{entity}/{objectId} and a string on
// /userfields/{entity}/{objectId}, which a list keyed on the parameter name could not
// express - but it makes the spec load-bearing at runtime, and an undocumented parameter
// silently means an unvalidated one.
//
// So this asserts the property the middleware depends on: every path parameter of every
// /api route is described by the spec, and described with a type. It is the same idea as
// check-cited-jobs.php - the guard exists because the alternative is a hole nobody looks
// for. Three /recipes parameters were typed "string" while recipes.id is INTEGER when
// this was written, which is exactly the drift it is meant to catch, and one of those
// three routes answered 500 with the failing statement in the body (issue #48).
//
// It runs in the `suite` job rather than in `lint`, because registering the routes means
// booting Slim and `lint` deliberately installs no dependencies. Registering is still the
// right way round: see the comment on the route collector below.
//
// Exit codes: 0 when every parameter is typed, 1 otherwise.

use Slim\Factory\AppFactory;
use Victual\Middleware\PathParameterMiddleware;

if (PHP_SAPI !== 'cli')
{
	exit('This is a command line script');
}

if (!defined('VICTUAL_DATAPATH'))
{
	define('VICTUAL_DATAPATH', __DIR__ . '/../data');
}

require_once __DIR__ . '/../packages/autoload.php';
require_once __DIR__ . '/../config-dist.php';

// The routes are registered rather than parsed out of routes.php with a regular
// expression: what the application actually serves is the route table, and a check that
// reads the file instead would disagree with it the first time a route is written in a
// way the pattern did not anticipate.
AppFactory::setContainer(new DI\Container());
$app = AppFactory::create();
$container = $app->getContainer();
require_once __DIR__ . '/../routes.php';

$specPath = __DIR__ . '/../victual.openapi.json';
$spec = json_decode(file_get_contents($specPath), true);

if (!is_array($spec) || !isset($spec['paths']))
{
	fwrite(STDERR, 'Could not read the paths out of ' . $specPath . PHP_EOL);
	exit(1);
}

// The CORS preflight catch-all is not an endpoint and has no operation to document: it
// answers any unmatched /api path so that a browser's OPTIONS request succeeds.
$ignoredPatterns = ['/api/{routes:.+}'];

$problems = [];
$checked = 0;

foreach ($app->getRouteCollector()->getRoutes() as $route)
{
	$pattern = $route->getPattern();

	if (!str_starts_with($pattern, '/api/') || in_array($pattern, $ignoredPatterns, true))
	{
		continue;
	}

	preg_match_all('/\{([a-zA-Z0-9_]+)/', $pattern, $matches);
	$parameterNames = $matches[1];

	if (empty($parameterNames))
	{
		continue;
	}

	// Slim/FastRoute path parameters can carry a regex constraint -
	// {kind:location|product} - which is not legal OpenAPI path templating (the
	// constraint belongs in the parameter's schema, not the template).
	// victual.openapi.json spells these unconstrained ({kind}); this strips the same
	// suffix PathParameterMiddleware strips at runtime, so both sides land on the same
	// key.
	$specPathKey = PathParameterMiddleware::StripFastRouteConstraints(substr($pattern, strlen('/api')));

	foreach ($route->getMethods() as $method)
	{
		$operation = $spec['paths'][$specPathKey][strtolower($method)] ?? null;

		if ($operation === null)
		{
			$problems[] = $method . ' ' . $pattern . ': takes ' . implode(', ', $parameterNames)
				. ' but the spec does not describe this operation, so no parameter of it can be validated';
			continue;
		}

		$documented = [];
		foreach ($operation['parameters'] ?? [] as $parameter)
		{
			if (($parameter['in'] ?? null) === 'path')
			{
				$documented[$parameter['name']] = $parameter['schema'] ?? [];
			}
		}

		foreach ($parameterNames as $parameterName)
		{
			$checked++;

			if (!array_key_exists($parameterName, $documented))
			{
				$problems[] = $method . ' ' . $pattern . ': {' . $parameterName . '} is not described by the spec';
				continue;
			}

			// A $ref is a declared type too - the entity and file group parameters are
			// enums of strings, and naming the enum is more precise than "string".
			$schema = $documented[$parameterName];
			if (!isset($schema['type']) && !isset($schema['$ref']))
			{
				$problems[] = $method . ' ' . $pattern . ': {' . $parameterName . '} has no type in the spec';
			}
		}
	}
}

// A typed spec is only half of it: the middleware that reads it has to be attached, or
// the whole arrangement is documentation. Checked as text because a middleware stack is
// not introspectable from here without booting the application against a database.
$routesSource = file_get_contents(__DIR__ . '/../routes.php');
if (!str_contains($routesSource, 'new PathParameterMiddleware('))
{
	$problems[] = 'routes.php: the /api group does not attach PathParameterMiddleware, so nothing validates these parameters';
}

if (!empty($problems))
{
	fwrite(STDERR, 'Path parameters that cannot be validated:' . PHP_EOL . PHP_EOL);
	foreach ($problems as $problem)
	{
		fwrite(STDERR, '  ' . $problem . PHP_EOL);
	}
	fwrite(STDERR, PHP_EOL . 'Describe each in victual.openapi.json with a schema type ("integer" for an id,' . PHP_EOL);
	fwrite(STDERR, '"string" or a $ref for anything else). See middleware/PathParameterMiddleware.php.' . PHP_EOL);
	exit(1);
}

// An empty run is a failure. If the route table stopped producing /api routes with
// parameters, this would otherwise report success while checking nothing at all.
if ($checked === 0)
{
	fwrite(STDERR, 'No /api path parameters were checked at all - the route table looks wrong.' . PHP_EOL);
	exit(1);
}

echo 'Every /api path parameter is typed in the spec (' . $checked . ' checked).' . PHP_EOL;
exit(0);
