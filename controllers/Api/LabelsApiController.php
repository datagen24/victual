<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Services\DatabaseService;
use Victual\Services\Labels\FieldCatalogue;
use Victual\Services\Labels\LabelIdentityService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class LabelsApiController extends BaseApiController
{
	public function Resolve(Request $request, Response $response, array $args)
	{
		return $this->HandleApiCall($response, function () use ($response, $args)
		{
			// The permission a code resolves under depends on the kind its label names, which
			// is not known until the row is read - so the check is a callback keyed by kind
			// rather than a single flag, the same shape LabelCaptureService's own
			// $permissionCheck already takes.
			$mayRead = static fn (string $kind): bool => User::HasPermissions(FieldCatalogue::DomainPermission($kind));
			return $this->ApiResponse($response, $this->Identity()->Resolve($args['code'], $mayRead));
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

	/** The generic context read for the five kinds plan 32 added; locations keep their own route and method. */
	public function Context(Request $request, Response $response, array $args)
	{
		$kind = (string)$args['kind'];
		User::CheckPermission($request, FieldCatalogue::DomainPermission($kind));
		return $this->HandleApiCall($response, function () use ($response, $kind, $args)
		{
			$context = $this->Identity()->Context($kind, (int)$args['id']);
			return $context === null ? $this->GenericErrorResponse($response, ucfirst(str_replace('_', ' ', $kind)) . ' not found', 404)
				: $this->ApiResponse($response, $context);
		});
	}

	/**
	 * The four print operations, plus cancellation.
	 *
	 * **Issue and revised print** check `MASTER_DATA_EDIT` **plus the relevant domain read** -
	 * per the maintainer's answer to plan 27 question 2, generalised across kinds by plan 32
	 * (`FieldCatalogue::DomainPermission()`). Both are checked: the edit grant is what makes
	 * printing an administrative act on master data, and the read grant is what makes
	 * capturing the target's value something this caller is allowed to do. A caller holding
	 * only one of them gets neither the label nor the value.
	 *
	 * **Reprint, promotion and cancellation** check only `MASTER_DATA_EDIT`. None of the three
	 * performs a fresh authorized capture - a reprint replays stored bytes, a promotion
	 * promotes a capture already taken, a cancellation reads nothing - so the domain read
	 * grant a capture needs does not apply to them, and requiring one would refuse a caller
	 * who may administer a chore's or a recipe's labels but does not separately hold
	 * `STOCK_VIEW`.
	 *
	 * Asynchronous creation answers 202 with the job and its state, because an issue or a
	 * revised print is not finished when the response is written - it is finished when a
	 * renderer has produced bytes Victual verified. A reprint answers 202 as well even though
	 * its artifact already exists, so a client has one shape to handle.
	 */
	public function Operate(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);

		$route = \Slim\Routing\RouteContext::fromRequest($request)->getRoute()->getName();
		[$kind, $targetId] = match ($route)
		{
			'label-op-print', 'label-op-revised-print' => ['location', (int)$args['locationId']],
			'label-op-print-generic', 'label-op-revised-print-generic' => [(string)$args['kind'], (int)$args['id']],
			default => [null, null],
		};
		if ($kind !== null)
		{
			User::CheckPermission($request, FieldCatalogue::DomainPermission($kind));
		}

		return $this->HandleApiCall($response, function () use ($request, $response, $args, $route, $kind, $targetId)
		{
			$body = $request->getParsedBody() ?? [];
			if (!is_array($body))
				return $this->ApiResponse($response->withStatus(422), ['field' => 'body', 'code' => 'invalid_body', 'error_message' => 'Object required']);

			$db = DatabaseService::GetInstance()->GetDbConnectionRaw();
			$user = defined('VICTUAL_USER_ID') ? (int)VICTUAL_USER_ID : 0;
			$key = $request->getHeaderLine('Idempotency-Key');
			$key = $key === '' ? null : $key;
			$operation = str_replace('label-op-', '', str_replace('-generic', '', $route));

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
					'label-op-print', 'label-op-print-generic' => $operations->IssueLocation($kind, $targetId, $this->Integer($body, 'import_epoch'), $this->Integer($body, 'printer_id'), isset($body['template_id']) ? (int)$body['template_id'] : null, isset($body['template_version_id']) ? (int)$body['template_version_id'] : null, (string)($body['locale'] ?? 'en'), (string)($body['timezone'] ?? 'UTC')),
					'label-op-revised-print', 'label-op-revised-print-generic' => $operations->RevisedPrint($kind, $targetId, $this->Integer($body, 'import_epoch'), $this->Integer($body, 'printer_id'), isset($body['template_id']) ? (int)$body['template_id'] : null, isset($body['template_version_id']) ? (int)$body['template_version_id'] : null, (string)($body['locale'] ?? 'en'), (string)($body['timezone'] ?? 'UTC')),
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
