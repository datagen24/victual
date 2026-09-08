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

	/**
	 * The four print operations, plus cancellation.
	 *
	 * `MASTER_DATA_EDIT` **plus the relevant domain read** - `STOCK_VIEW` for locations - per
	 * the maintainer's answer to plan 27 question 2. Both are checked: the edit grant is what
	 * makes printing an administrative act on master data, and the read grant is what makes
	 * capturing the location's name something this caller is allowed to do. A caller holding
	 * only one of them gets neither the label nor the value.
	 *
	 * Asynchronous creation answers 202 with the job and its state, because an issue or a
	 * revised print is not finished when the response is written - it is finished when a
	 * renderer has produced bytes Victual verified. A reprint answers 202 as well even though
	 * its artifact already exists, so a client has one shape to handle.
	 */
	public function Operate(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		User::CheckPermission($request, User::PERMISSION_STOCK_VIEW);

		return $this->HandleApiCall($response, function () use ($request, $response, $args)
		{
			$route = \Slim\Routing\RouteContext::fromRequest($request)->getRoute()->getName();
			$body = $request->getParsedBody() ?? [];
			if (!is_array($body))
				return $this->ApiResponse($response->withStatus(422), ['field' => 'body', 'code' => 'invalid_body', 'error_message' => 'Object required']);

			$db = DatabaseService::GetInstance()->GetDbConnectionRaw();
			$user = defined('VICTUAL_USER_ID') ? (int)VICTUAL_USER_ID : 0;
			$key = $request->getHeaderLine('Idempotency-Key');
			$key = $key === '' ? null : $key;
			$operation = str_replace('label-op-', '', $route);

			$db->beginTransaction();
			try
			{
				// The permission the capture needs is checked against this caller rather
				// than assumed from the route, so a field whose catalogue entry names a
				// grant this caller lacks refuses the capture instead of reading it.
				$permissions = static fn (string $permission): bool => User::HasPermissions($permission);
				$operations = new \Victual\Services\Labels\LabelOperationsService($db, $permissions, $user);
				$keys = new \Victual\Services\Labels\IdempotencyService($db);

				$fingerprint = ['route' => $route, 'args' => $args, 'body' => $body];
				$begun = $keys->Begin($user, $operation, $key, $fingerprint);
				if ($begun['replay'])
				{
					$db->commit();
					return $this->ApiResponse($response->withStatus(200), $begun['row']['response']);
				}

				$job = match ($route)
				{
					'label-op-print' => $operations->IssueLocation((int)$args['locationId'], $this->Integer($body, 'import_epoch'), $this->Integer($body, 'printer_id'), isset($body['template_id']) ? (int)$body['template_id'] : null, isset($body['template_version_id']) ? (int)$body['template_version_id'] : null, (string)($body['locale'] ?? 'en'), (string)($body['timezone'] ?? 'UTC')),
					'label-op-revised-print' => $operations->RevisedPrint((int)$args['locationId'], $this->Integer($body, 'import_epoch'), $this->Integer($body, 'printer_id'), isset($body['template_id']) ? (int)$body['template_id'] : null, isset($body['template_version_id']) ? (int)$body['template_version_id'] : null, (string)($body['locale'] ?? 'en'), (string)($body['timezone'] ?? 'UTC')),
					'label-op-reprint' => $operations->Reprint((int)$args['jobId'], isset($body['printer_id']) ? (int)$body['printer_id'] : null),
					'label-op-promote' => $operations->PromotePreview((int)$args['artifactId'], $this->Integer($body, 'printer_id')),
					'label-op-cancel' => $operations->Cancel((int)$args['jobId'], (string)($body['reason'] ?? 'Cancelled by an operator')),
					default => throw new \LogicException('Unknown label operation')
				};

				$payload = ['job_id' => (int)$job['id'], 'label_uid' => $job['label_uid'],
					'operation' => $job['operation'] ?? $operation,
					'artifact_id' => isset($job['artifact_id']) && $job['artifact_id'] !== null ? (int)$job['artifact_id'] : null,
					'render_request_id' => isset($job['render_request_id']) && $job['render_request_id'] !== null ? (int)$job['render_request_id'] : null,
					'state' => ($job['cancelled_at'] ?? null) !== null ? 'cancelled'
						: (($job['artifact_id'] ?? null) === null ? 'awaiting_artifact' : 'queued')];

				$keys->Record($user, $operation, $key, 'print_job', (int)$job['id'], $payload);
				$db->commit();
				return $this->ApiResponse($response->withStatus($route === 'label-op-cancel' ? 200 : 202), $payload);
			}
			catch (\Victual\Services\Labels\LabelValidationException $error)
			{
				if ($db->inTransaction()) $db->rollBack();
				$status = in_array($error->errorCode, ['idempotency_conflict', 'idempotency_in_progress', 'already_claimed'], true) ? 409 : 422;
				return $this->ApiResponse($response->withStatus($status), ['field' => $error->field, 'code' => $error->errorCode, 'error_message' => $error->getMessage()]);
			}
			catch (\Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
		});
	}

	/** Keeps the route from turning a missing integer into a zero nobody asked for. */
	private function Integer(array $body, string $field): int
	{
		if (!is_int($body[$field] ?? null))
		{
			throw new \Victual\Services\Labels\LabelValidationException($field, 'value_out_of_range', 'An integer ' . $field . ' is required');
		}
		return (int)$body[$field];
	}

	private function Identity(): LabelIdentityService
	{
		return new LabelIdentityService(DatabaseService::GetInstance()->GetDbConnectionRaw());
	}
}
