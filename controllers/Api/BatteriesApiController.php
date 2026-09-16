<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Services\BatteriesService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves the /api/batteries endpoints for battery tracking:
 * current battery overview, charge cycle tracking/undo and label printing.
 */
class BatteriesApiController extends BaseApiController
{
	/**
	 * GET /api/batteries/{batteryId} - returns details of the given battery.
	 * Returns the battery details (200) or a 400 error response.
	 */
	public function BatteryDetails(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($args, $response)
		{
			return $this->ApiResponse($response, BatteriesService::GetInstance()->GetBatteryDetails($args['batteryId']));
		});
	}

	/**
	 * GET /api/batteries - returns all batteries with their current charge cycle status,
	 * filterable via the generic query/limit/offset/order query parameters (200).
	 */
	public function Current(Request $request, Response $response, array $args)
	{
		return $this->FilteredApiResponse($request, $response, BatteriesService::GetInstance()->GetCurrent(), $request->getQueryParams());
	}

	/**
	 * POST /api/batteries/{batteryId}/charge - tracks a charge cycle for the given battery.
	 * Requires the BATTERIES_TRACK_CHARGE_CYCLE permission (403 otherwise).
	 * Body field tracked_time (ISO datetime) is optional and defaults to now.
	 * Returns the created battery_charge_cycles row (200) or a 400 error response.
	 */
	public function TrackChargeCycle(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_BATTERIES_TRACK_CHARGE_CYCLE);

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		return $this->HandleApiCall($response, function () use ($args, $requestBody, $response)
		{
			$trackedTime = date('Y-m-d H:i:s');
			if (array_key_exists('tracked_time', $requestBody) && IsIsoDateTime($requestBody['tracked_time']))
			{
				$trackedTime = $requestBody['tracked_time'];
			}

			$chargeCycleId = BatteriesService::GetInstance()->TrackChargeCycle($args['batteryId'], $trackedTime);
			return $this->ApiResponse($response, $this->DB->battery_charge_cycles($chargeCycleId));
		});
	}

	/**
	 * POST /api/batteries/charge-cycles/{chargeCycleId}/undo - undoes a tracked charge cycle.
	 * Requires the BATTERIES_UNDO_CHARGE_CYCLE permission (403 otherwise).
	 * Returns 204 on success or a 400 error response.
	 */
	public function UndoChargeCycle(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_BATTERIES_UNDO_CHARGE_CYCLE);

		return $this->HandleApiCall($response, function () use ($args, $response)
		{
			$this->ApiResponse($response, BatteriesService::GetInstance()->UndoChargeCycle($args['chargeCycleId']));
			return $this->EmptyApiResponse($response);
		});
	}

}
