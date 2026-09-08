<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Services\DatabaseService;
use Victual\Services\Labels\LabelIdentityService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class LabelsApiController extends BaseApiController
{
	public function Resolve(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($response, $args)
		{
			return $this->ApiResponse($response, $this->Identity()->Resolve($args['code'], User::HasPermissions(User::PERMISSION_STOCK_VIEW)));
		});
	}

	public function LocationContext(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);
		return $this->HandleApiCall($response, function () use ($response, $args)
		{
			$context = $this->Identity()->LocationContext((int)$args['locationId']);
			return $context === null ? $this->GenericErrorResponse($response, 'Location not found', 404)
				: $this->ApiResponse($response, $context);
		});
	}

	public function PrintLocation(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		return $this->HandleApiCall($response, function () use ($request, $response, $args)
		{
			$body = $request->getParsedBody();
			if (!is_array($body) || !is_int($body['import_epoch'] ?? null) || !is_int($body['printer_id'] ?? null) || $body['printer_id'] < 1)
				return $this->ApiResponse($response->withStatus(422), ['field' => 'body', 'code' => 'value_out_of_range', 'error_message' => 'Integer import_epoch and positive printer_id required']);
			$db = DatabaseService::GetInstance()->GetDbConnectionRaw();
			$db->beginTransaction();
			try
			{
				$id = (new \Victual\Services\Labels\LabelPrintJobService($db))->Enqueue((int)$args['locationId'], $body['import_epoch'], $body['printer_id']);
				$db->commit();
				return $this->ApiResponse($response->withStatus(202), ['job_id' => $id, 'state' => 'awaiting_artifact']);
			}
			catch (\Victual\Services\Labels\LabelValidationException $error)
			{
				$db->rollBack();
				return $this->ApiResponse($response->withStatus(422), ['field' => $error->field, 'code' => $error->errorCode, 'error_message' => $error->getMessage()]);
			}
			catch (\Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
		});
	}

	private function Identity(): LabelIdentityService
	{
		return new LabelIdentityService(DatabaseService::GetInstance()->GetDbConnectionRaw());
	}
}
