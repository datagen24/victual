<?php

namespace Victual\Controllers;

use Victual\Helpers\Grocycode;
use Victual\Services\BatteriesService;
use Victual\Services\UserfieldsService;
use Victual\Services\UsersService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Slim route controller for the battery tracking views (overview, journal,
 * master data list/edit form, charge cycle tracking and settings).
 * Due date calculations are delegated to BatteriesService.
 */
class BatteriesController extends BaseController
{
	use GrocycodeTrait;

	/**
	 * Serves the battery master data list view (route GET /batteries).
	 *
	 * Query parameter include_disabled (presence only) also lists inactive batteries.
	 */
	public function BatteriesList(Request $request, Response $response, array $args)
	{
		if (isset($request->getQueryParams()['include_disabled']))
		{
			$batteries = $this->DB->batteries()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$batteries = $this->DB->batteries()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		}

		return $this->RenderPage($response, 'batteries', [
			'batteries' => $batteries,
			'userfields' => UserfieldsService::GetInstance()->GetFields('batteries'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('batteries')
		]);
	}

	/**
	 * Serves the batteries settings view (route GET /batteriessettings).
	 */
	public function BatteriesSettings(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'batteriessettings');
	}

	/**
	 * Serves the battery create/edit form (route GET /battery/{batteryId}).
	 *
	 * @param array $args Route arguments; batteryId is either a battery id or the literal 'new' for create mode
	 */
	public function BatteryEditForm(Request $request, Response $response, array $args)
	{
		if ($args['batteryId'] == 'new')
		{
			return $this->RenderPage($response, 'batteryform', [
				'mode' => 'create',
				'userfields' => UserfieldsService::GetInstance()->GetFields('batteries')
			]);
		}
		else
		{
			return $this->RenderPage($response, 'batteryform', [
				'battery' => $this->DB->batteries($args['batteryId']),
				'mode' => 'edit',
				'userfields' => UserfieldsService::GetInstance()->GetFields('batteries'),
				// Only the edit form offers a print action: an unsaved battery has no id to
				// mint a label against.
				'labelPrinters' => VICTUAL_FEATURE_FLAG_LABELS
					? iterator_to_array($this->DB->label_printers()->where('active = 1')->orderBy('is_default', 'DESC')->orderBy('name'))
					: []
			]);
		}
	}

	/**
	 * Serves the battery charge cycle journal view (route GET /batteriesjournal).
	 *
	 * Optional query parameters: months (int, how far back to list; default 24)
	 * and battery (int, filter by battery id).
	 */
	public function Journal(Request $request, Response $response, array $args)
	{
		// Default 2 years
		$months = 24;
		if (isset($request->getQueryParams()['months']) && filter_var($request->getQueryParams()['months'], FILTER_VALIDATE_INT) !== false)
		{
			$months = intval($request->getQueryParams()['months']);
		}

		// The cut-off date is computed here and bound as a parameter instead of being
		// expressed in SQL: SQLite's DATE(x, '-N months') has no PostgreSQL equivalent,
		// so date arithmetic must not leak into the query (see DatabaseDialect)
		$chargeCycles = $this->DB->battery_charge_cycles()->where('tracked_time > :1', date('Y-m-d', strtotime('-' . $months . ' months')));

		if (isset($request->getQueryParams()['battery']) && filter_var($request->getQueryParams()['battery'], FILTER_VALIDATE_INT) !== false)
		{
			$chargeCycles = $chargeCycles->where('battery_id', intval($request->getQueryParams()['battery']));
		}

		return $this->RenderPage($response, 'batteriesjournal', [
			'chargeCycles' => $chargeCycles->orderBy('tracked_time', 'DESC'),
			'batteries' => $this->DB->batteries()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('battery_charge_cycles'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('battery_charge_cycles')
		]);
	}

	/**
	 * Serves the batteries overview view (route GET /batteriesoverview); flags each
	 * current battery as overdue/duetoday/duesoon based on its next estimated
	 * charge time and the user's "due soon" days setting.
	 */
	public function Overview(Request $request, Response $response, array $args)
	{
		$usersService = UsersService::GetInstance();
		$nextXDays = $usersService->GetUserSettings(VICTUAL_USER_ID)['batteries_due_soon_days'];

		$batteries = $this->DB->batteries()->where('active = 1');
		$currentBatteries = BatteriesService::GetInstance()->GetCurrent();
		foreach ($currentBatteries as $currentBattery)
		{
			if (FindObjectInArrayByPropertyValue($batteries, 'id', $currentBattery->battery_id)->charge_interval_days > 0)
			{
				if ($currentBattery->next_estimated_charge_time < date('Y-m-d H:i:s'))
				{
					$currentBattery->due_type = 'overdue';
				}
				elseif ($currentBattery->next_estimated_charge_time <= date('Y-m-d 23:59:59'))
				{
					$currentBattery->due_type = 'duetoday';
				}
				elseif ($nextXDays > 0 && $currentBattery->next_estimated_charge_time <= date('Y-m-d H:i:s', strtotime('+' . $nextXDays . ' days')))
				{
					$currentBattery->due_type = 'duesoon';
				}
			}
		}

		return $this->RenderPage($response, 'batteriesoverview', [
			'batteries' => $batteries,
			'current' => $currentBatteries,
			'nextXDays' => $nextXDays,
			'userfields' => UserfieldsService::GetInstance()->GetFields('batteries'),
			'userfieldValues' => UserfieldsService::GetInstance()->GetAllValues('batteries'),
			'labelPrinters' => VICTUAL_FEATURE_FLAG_LABELS
				? iterator_to_array($this->DB->label_printers()->where('active = 1')->orderBy('is_default', 'DESC')->orderBy('name'))
				: []
		]);
	}

	/**
	 * Serves the battery charge cycle tracking view (route GET /batterytracking).
	 */
	public function TrackChargeCycle(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'batterytracking', [
			'batteries' => $this->DB->batteries()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'userfields' => UserfieldsService::GetInstance()->GetFields('battery_charge_cycles')
		]);
	}

	/**
	 * Serves the Grocycode barcode PNG for a battery (route GET /battery/{batteryId}/grocycode).
	 */
	public function BatteryGrocycodeImage(Request $request, Response $response, array $args)
	{
		$gc = new Grocycode(Grocycode::BATTERY, $args['batteryId']);
		return $this->ServeGrocycodeImage($request, $response, $gc);
	}
}
