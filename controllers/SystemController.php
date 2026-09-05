<?php

namespace Victual\Controllers;

use Victual\Services\ApplicationService;
use Victual\Controllers\Users\User;
use Victual\Services\DatabaseMigrationService;
use Victual\Services\DemoDataGeneratorService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Slim route controller for application level pages: the root entry point
 * (which also runs database schema migrations), the about page, the PWA
 * manifest and the barcode scanner testing page.
 */
class SystemController extends BaseController
{
	/**
	 * Serves the about view with version, system info and changelog (route GET /about).
	 */
	public function About(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'about', [
			'systemInfo' => ApplicationService::GetInstance()->GetSystemInfo(),
			'versionInfo' => ApplicationService::GetInstance()->GetInstalledVersion(),
			'changelog' => ApplicationService::GetInstance()->GetChangelog()
		]);
	}

	/**
	 * Serves the barcode scanner testing view (route GET /barcodescannertesting).
	 */
	public function BarcodeScannerTesting(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'barcodescannertesting');
	}

	/**
	 * Handles the application root (route GET /): populates demo data in
	 * dev/demo/prerelease mode and redirects to the configured entry page.
	 *
	 * It also migrates the schema when MIGRATE_ON_ROOT_REQUEST says so, which is off by
	 * default. Migrating inside a request made this route special - it was the only one
	 * that could bring a database up to date, which is why the cold start path used to
	 * redirect every request here first, answering an API call with an HTML page. The
	 * setting keeps the old behaviour available for an installation with no init step to
	 * run bin/victual-migrate from, and makes it a choice rather than the only way.
	 */
	public function Root(Request $request, Response $response, array $args)
	{
		if (VICTUAL_MIGRATE_ON_ROOT_REQUEST)
		{
			DatabaseMigrationService::GetInstance()->MigrateDatabase();
		}

		if (VICTUAL_MODE === 'dev' || VICTUAL_MODE === 'demo' || VICTUAL_MODE === 'prerelease')
		{
			// This used to run on SQLite alone, because the generator's raw SQL was SQLite
			// flavoured - so a PostgreSQL demo instance silently stayed empty and said so on
			// stderr. ADR-0008's retirement left that branch with no engine to be true on,
			// and DemoDataGeneratorService is portable now, so there is nothing left to
			// decide here.
			DemoDataGeneratorService::GetInstance()->PopulateDemoData(isset($request->getQueryParams()['nodemodata']));
		}

		return $response->withRedirect($this->AppContainer->get('UrlManager')->ConstructUrl($this->GetEntryPageRelative()));
	}

	/**
	 * Serves the dynamic PWA web app manifest as JSON (route GET /manifest).
	 *
	 * Query parameter data is a base64 encoded '#'-separated pair of
	 * app name suffix and start URL.
	 */
	public function Manifest(Request $request, Response $response, array $args)
	{
		$data = explode('#', base64_decode($request->getQueryParams()['data']));

		$manifest = [
			'name' => 'Victual ' . $data[0],
			'short_name' => 'Victual ' . $data[0],
			'icons' => [[
				'src' => './img/icon-1024.png',
				'sizes'=> '1024x1024',
				'type' => 'image/png'
			]],
			'start_url' => $data[1],
			'background_color' => '#174B3A',
			'theme_color' => '#174B3A',
			'display' => 'standalone'
		];

		$response->getBody()->write(json_encode($manifest));
		return $response->withHeader('Content-Type', 'application/json');
	}

	/**
	 * Resolves the relative URL of the configured entry page (VICTUAL_ENTRY_PAGE),
	 * falling back to /about when the corresponding feature is disabled.
	 *
	 * @return string Relative URL, e.g. '/stockoverview'
	 */
	private function GetEntryPageRelative()
	{
		if (defined('VICTUAL_ENTRY_PAGE'))
		{
			$entryPage = constant('VICTUAL_ENTRY_PAGE');
		}
		else
		{
			$entryPage = 'stock';
		}

		// Stock
		if ($entryPage === 'stock' && constant('VICTUAL_FEATURE_FLAG_STOCK') && User::HasPermissions(User::PERMISSION_STOCK_VIEW))
		{
			return '/stockoverview';
		}

		// Shoppinglist
		if ($entryPage === 'shoppinglist' && constant('VICTUAL_FEATURE_FLAG_SHOPPINGLIST') && User::HasPermissions(User::PERMISSION_SHOPPINGLIST_VIEW))
		{
			return '/shoppinglist';
		}

		// Recipes
		if ($entryPage === 'recipes' && constant('VICTUAL_FEATURE_FLAG_RECIPES') && User::HasPermissions(User::PERMISSION_RECIPES_VIEW))
		{
			return '/recipes';
		}

		// Chores
		if ($entryPage === 'chores' && constant('VICTUAL_FEATURE_FLAG_CHORES') && User::HasPermissions(User::PERMISSION_CHORES_VIEW))
		{
			return '/choresoverview';
		}

		// Tasks
		if ($entryPage === 'tasks' && constant('VICTUAL_FEATURE_FLAG_TASKS') && User::HasPermissions(User::PERMISSION_TASKS_VIEW))
		{
			return '/tasks';
		}

		// Batteries
		if ($entryPage === 'batteries' && constant('VICTUAL_FEATURE_FLAG_BATTERIES'))
		{
			return '/batteriesoverview';
		}

		if ($entryPage === 'equipment' && constant('VICTUAL_FEATURE_FLAG_EQUIPMENT'))
		{
			return '/equipment';
		}

		// Calendar
		if ($entryPage === 'calendar' && constant('VICTUAL_FEATURE_FLAG_CALENDAR'))
		{
			return '/calendar';
		}

		// Meal Plan
		if ($entryPage === 'mealplan' && constant('VICTUAL_FEATURE_FLAG_RECIPES_MEALPLAN'))
		{
			return '/mealplan';
		}

		return '/about';
	}
}
