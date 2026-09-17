<?php

namespace Victual\Tests\Support;

use Slim\Factory\AppFactory;

/**
 * Boots a throwaway Slim App from routes.php purely to read back its route table - the
 * same trick .devtools/check-path-id-validation.php uses, generalised into something a
 * PHPUnit test can call more than once. No middleware is added and handle()/run() is
 * never called, so this never touches the database or the filesystem beyond routes.php
 * itself; it exists to answer "what does the application actually register" from the
 * one place that cannot be wrong about it - Slim's own RouteCollector - rather than from
 * a regex over the source text, which is what plan 14 piece 2 (issue #83) requires: the
 * extractor is a thing that can be wrong, so it is not hand-written twice.
 */
class RouteInventory
{
	/**
	 * @return Operation[] Every registered operation (method + path pair), API and
	 * non-API alike, in registration order.
	 */
	public static function All(): array
	{
		AppFactory::setContainer(new \DI\Container());
		$app = AppFactory::create();
		$container = $app->getContainer();
		require VICTUAL_ROOT_PATH . '/routes.php';

		$operations = [];

		foreach ($app->getRouteCollector()->getRoutes() as $route)
		{
			$callable = $route->getCallable();
			[$controllerClass, $controllerMethod] = is_array($callable) ? $callable : [null, null];

			foreach ($route->getMethods() as $method)
			{
				$operations[] = new Operation($method, $route->getPattern(), $controllerClass, $controllerMethod, $route->getName());
			}
		}

		return $operations;
	}

	/**
	 * @return Operation[] Only the /api group's operations, per All(). "/api" itself is
	 * excluded - it is OpenApiController::DocumentationUi, the HTML Swagger UI page
	 * registered in the main group, not a JSON operation of the /api group despite the
	 * matching prefix.
	 */
	public static function Api(): array
	{
		return array_values(array_filter(self::All(), fn(Operation $o) => str_starts_with($o->Path, '/api/')));
	}
}

/** One HTTP method + path registered in routes.php. */
class Operation
{
	public function __construct(
		public readonly string $Method,
		public readonly string $Path,
		public readonly ?string $ControllerClass,
		public readonly ?string $ControllerMethod,
		public readonly ?string $RouteName
	) {
	}

	/** The path with the leading /api stripped, matching how victual.openapi.json keys its paths. */
	public function SpecPath(): string
	{
		return substr($this->Path, 4);
	}

	/** "GET /objects/{entity}" - the identity a snapshot keys an operation by. */
	public function Key(): string
	{
		return strtoupper($this->Method) . ' ' . $this->Path;
	}
}
