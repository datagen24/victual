<?php

namespace Victual\Controllers;

use DI\Container;
use Victual\Controllers\Users\User;
use Victual\Services\ApplicationService;
use Victual\Services\DatabaseService;
use Victual\Services\LocalizationService;
use Victual\Services\UsersService;

/**
 * Base class for all non-API (view) controllers.
 *
 * Provides the shared DI container, the Blade view engine and the database
 * connection, plus the Render()/RenderPage() helpers that populate the
 * template variables common to every Victual page (localization, feature
 * flags, permissions, URL builder etc.).
 */
class BaseController
{
	/**
	 * Wires up the view engine and the LessQL database connection from the DI container.
	 *
	 * @param bool $connectDatabase Whether the connection is opened here. Every controller
	 *                              that serves a route wants it and leaves this alone.
	 *                              ExceptionController passes false, because one of the
	 *                              failures it exists to report is the database being
	 *                              unreachable, and connecting in this constructor made the
	 *                              handler for that failure raise it again - at bootstrap,
	 *                              where the error middleware it is being handed to does not
	 *                              exist yet. The result was an uncaught PDOException and a
	 *                              lost worker instead of a 500 page; see
	 *                              ExceptionController::RenderErrorPage().
	 */
	public function __construct(Container $container, bool $connectDatabase = true)
	{
		$this->AppContainer = $container;
		$this->View = $container->get('view');

		if ($connectDatabase)
		{
			$this->DB = DatabaseService::GetInstance()->GetDbConnection();
		}
	}

	/** @var Container The application DI container */
	protected $AppContainer;

	/** @var \Victual\Helpers\SlimBladeView The shared Blade view engine */
	protected $View;

	/** @var \LessQL\Database|null Fluent database connection (PostgreSQL; see ADR-0008); null only while a controller constructed without one has not obtained it */
	protected $DB;

	/**
	 * Renders the given view with the globally needed template variables set
	 * (version, translation closures, text direction, URL builder, feature flags
	 * and - when authenticated - the current user's permissions).
	 *
	 * @param \Psr\Http\Message\ResponseInterface $response
	 * @param string $viewName Name of the view file (without extension) below the views folder
	 * @param array $data Additional variables passed through to the view
	 * @return \Psr\Http\Message\ResponseInterface
	 */
	protected function Render($response, $viewName, $data = [])
	{
		$container = $this->AppContainer;

		$versionInfo = ApplicationService::GetInstance()->GetInstalledVersion();
		$this->View->set('version', $versionInfo->Version);

		$localizationService = LocalizationService::GetInstance();
		$this->View->set('__t', function (string $text, ...$placeholderValues) use ($localizationService)
		{
			return $localizationService->__t($text, $placeholderValues);
		});
		$this->View->set('__n', function ($number, $singularForm, $pluralForm, $isQu = false) use ($localizationService)
		{
			return $localizationService->__n($number, $singularForm, $pluralForm, $isQu);
		});
		$this->View->set('LocalizationStrings', $localizationService->GetPoAsJsonString());
		$this->View->set('LocalizationStringsQu', $localizationService->GetPoAsJsonStringQu());

		// TODO: Better handle this generically based on the current language (header in .po file?)
		$dir = 'ltr';
		if (VICTUAL_LOCALE == 'he_IL')
		{
			$dir = 'rtl';
		}
		$this->View->set('dir', $dir);

		$this->View->set('U', function ($relativePath, $isResource = false) use ($container)
		{
			return $container->get('UrlManager')->ConstructUrl($relativePath, $isResource);
		});

		$embedded = false;
		if (isset($_GET['embedded']))
		{
			$embedded = true;
		}
		$this->View->set('embedded', $embedded);

		$constants = get_defined_constants();
		foreach ($constants as $constant => $value)
		{
			if (!str_starts_with($constant, 'VICTUAL_FEATURE_FLAG_'))
			{
				unset($constants[$constant]);
			}
		}
		$this->View->set('featureFlags', $constants);

		if (VICTUAL_AUTHENTICATED)
		{
			$this->View->set('permissions', User::PermissionList());

			$decimalPlacesAmounts = UsersService::GetInstance()->GetUserSetting(VICTUAL_USER_ID, 'stock_decimal_places_amounts');
			if ($decimalPlacesAmounts <= 0)
			{
				$defaultMinAmount = 1;
			}
			else
			{
				$defaultMinAmount = '0.' . str_repeat('0', $decimalPlacesAmounts - 1) . '1';
			}
			$this->View->set('DEFAULT_MIN_AMOUNT', $defaultMinAmount);
		}

		$this->View->set('viewName', $viewName);

		return $this->View->Render($response, $viewName, $data);
	}

	/**
	 * Renders a full page: additionally provides the sidebar userentities and the
	 * current user's settings (null when not logged in), then delegates to Render().
	 *
	 * @param \Psr\Http\Message\ResponseInterface $response
	 * @param string $viewName Name of the view file (without extension) below the views folder
	 * @param array $data Additional variables passed through to the view
	 * @return \Psr\Http\Message\ResponseInterface
	 */
	protected function RenderPage($response, $viewName, $data = [])
	{
		$this->View->set('userentitiesForSidebar', $this->DB->userentities()->where('show_in_sidebar_menu = 1')->orderBy('name'));
		try
		{
			$usersService = UsersService::GetInstance();
			if (defined('VICTUAL_USER_ID'))
			{
				$this->View->set('userSettings', $usersService->GetUserSettings(VICTUAL_USER_ID));
			}
			else
			{
				$this->View->set('userSettings', null);
			}
		}
		catch (\Exception $ex)
		{
			// Happens when database is not initialised or migrated...
		}

		return $this->Render($response, $viewName, $data);
	}
}
