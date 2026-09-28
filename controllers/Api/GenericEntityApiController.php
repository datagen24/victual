<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Controllers\Users\EntityReadPolicy;
use Victual\Services\DatabaseService;
use Victual\Services\FieldPolicy;
use Victual\Services\WireBooleans;
use Victual\Services\StockService;
use Victual\Services\UserfieldsService;
use Victual\Services\UsersService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves the generic CRUD endpoints /api/objects/{entity}[/{objectId}] and the
 * Userfields endpoints /api/userfields/{entity}/{objectId} for all entities
 * exposed through the OpenAPI spec (ExposedEntity* enums also control which
 * entities can be listed, edited, deleted or require admin rights).
 */
class GenericEntityApiController extends BaseApiController
{
	/**
	 * POST /api/objects/{entity} - creates a new object from the JSON request body.
	 * Requires an entity-dependent permission (shopping list, recipes, meal plan,
	 * equipment or MASTER_DATA_EDIT as fallback; some entities additionally ADMIN),
	 * answered with 403 when missing. As a side effect, creating a product may add
	 * below-min-stock products to the shopping list (per user setting).
	 * The columns the server owns (id, row_created_timestamp) are dropped from the body
	 * before it is written - see WithoutServerOwnedColumns().
	 * Returns { "created_object_id": int } (200) or a 400 error response
	 * (unknown/not exposed/not editable entity, invalid body, or a body that sets no
	 * column at all and would therefore create nothing).
	 */
	public function AddObject(Request $request, Response $response, array $args)
	{
		if ($args['entity'] == 'shopping_list' || $args['entity'] == 'shopping_lists')
		{
			User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_ITEMS_ADD);
		}
		elseif ($args['entity'] == 'recipes' || $args['entity'] == 'recipes_pos' || $args['entity'] == 'recipes_nestings')
		{
			User::CheckPermission($request, User::PERMISSION_RECIPES);
		}
		elseif ($args['entity'] == 'meal_plan')
		{
			User::CheckPermission($request, User::PERMISSION_RECIPES_MEALPLAN);
		}
		elseif ($args['entity'] == 'equipment')
		{
			User::CheckPermission($request, User::PERMISSION_EQUIPMENT);
		}
		else
		{
			User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		}

		if ($this->IsValidExposedEntity($args['entity']) && !$this->IsEntityWithNoEdit($args['entity']))
		{
			if ($this->IsEntityWithEditRequiresAdmin($args['entity']))
			{
				User::CheckPermission($request, User::PERMISSION_ADMIN);
			}

			$requestBody = $this->GetParsedAndFilteredRequestBody($request, $args['entity']);

			return $this->HandleApiCall($response, function () use ($args, $requestBody, $response, $request)
			{
				if ($requestBody === null)
				{
					throw new \Exception('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
				}

				$requestBody = self::WithoutServerOwnedColumns($requestBody);

				if ($args['entity'] === 'products')
				{
					// A new product is never already tare-enabled, so any truthy value here
					// is an enable. See RefuseTareEnable().
					$this->RefuseTareEnable($requestBody, false);
				}

				if (empty($requestBody))
				{
					// LessQL skips the insert for a row with no modified columns, so the
					// endpoint used to ask the driver for the id of an insert that never
					// happened and answer 200 with whatever it said - "0" on SQLite, null
					// on PostgreSQL, an object id on neither. See issue #47.
					throw new EInvalidApiQuery('The request body sets no column of this entity, so there is nothing to create');
				}

				// The insert and its post-save side effect (AddMissingProductsToShoppingList()
				// below) commit or roll back together - audit finding H5 / issue #494. Before
				// this transaction existed, a misconfigured shopping-list id made that call
				// throw after the row was already saved, so the response answered 400 while
				// the insert stayed committed. The transaction is opened *inside* this
				// HandleApiCall() closure rather than around it, deliberately:
				// InRequestTransaction() rolls back on a throw and rethrows - true here
				// because AddObject() is always this request's outermost transaction (nothing
				// above it holds one open); a nested InTransaction() call instead just joins
				// the one already open and leaves rollback to whichever caller opened it, per
				// InTransaction()'s own docblock - so the failure still reaches
				// HandleApiCall()'s own catch and is still answered exactly as before.
				// Wrapping the other way - a transaction around the whole HandleApiCall()
				// call - would see only the 400 Response it returns, a normal return rather
				// than a throw, and would commit it; that is precisely the failure mode audit
				// correction 8 describes ("an outer transaction that sees a normal return can
				// still commit").
				return $this->InRequestTransaction($request, function () use ($args, $requestBody, $response)
				{
					if ($args['entity'] === 'locations')
					{
						// Derived inside the transaction, locked FOR SHARE - see
						// ReadStorageClassTreatsAsFreezerForShare()'s docblock for why a read
						// taken before the transaction opens would not protect against a
						// concurrent edit to the same class.
						$requestBody = $this->WithDerivedIsFreezer($requestBody);
					}

					$newRow = $this->DB->{$args['entity']}()->createRow($requestBody);
					$newRow->save();

					// The id off the saved row, not $this->DB->lastInsertId().
					//
					// lastInsertId() with no sequence name is SELECT lastval() on PostgreSQL,
					// which returns the last value generated by *any* sequence in this
					// session - including one an AFTER INSERT trigger touched. products has
					// such a trigger: it writes to cache__quantity_unit_conversions_resolved,
					// whose own identity column then owns lastval(). So creating a product
					// answered with the cache table's id rather than the product's, and the
					// two only agree on a virgin database where the sequences happen to move
					// in step. Measured on PostgreSQL 16.13: a product actually created as
					// id 5 was reported as created_object_id "3", and a client that then
					// asked for product 3 got somebody else's product or a 404.
					//
					// LessQL's Row::save() already asks the right question - it looks the id
					// up as lastInsertId($db->getSequence($table)) and leaves it on the row -
					// so the value is there to be read. SQLite is unaffected either way. save()
					// would leave this null for a row with no modified columns (the difference
					// from upstream that issue #47 records), but the empty($requestBody) check
					// above already refuses that request with 400 before execution reaches
					// here, so this line does not see it today - the response below still
					// treats a null defensively rather than depending on that guard being the
					// only way here.
					$newObjectId = $newRow->id;

					// TODO: This should be better done somehow in StockService
					if ($args['entity'] == 'products' && boolval(UsersService::GetInstance()->GetUserSetting(VICTUAL_USER_ID, 'shopping_list_auto_add_below_min_stock_amount')))
					{
						StockService::GetInstance()->AddMissingProductsToShoppingList(UsersService::GetInstance()->GetUserSetting(VICTUAL_USER_ID, 'shopping_list_auto_add_below_min_stock_amount_list_id'));
					}

					// PDO::lastInsertId() - what LessQL's Row::save() reads $newObjectId from,
					// per the comment above - always returns a string in PHP, whatever the
					// column's own type. victual.openapi.json has documented this property
					// integer on every route that carries it since before this cast existed
					// (RolesApiController::AddRole() already did the same cast); audit finding
					// H10 / issue #499 is the wire catching up to the document, not the other
					// way around. Guarded rather than a blind cast: (int)null is 0, not null,
					// and a fabricated id that looks like a real answer would be worse than the
					// type this fixes - see $newObjectId's own comment above for why null is
					// not believed to be reachable here today, and why the guard stays anyway.
					return $this->ApiResponse($response, [
						'created_object_id' => $newObjectId === null ? null : (int)$newObjectId
					]);
				});
			});
		}
		else
		{
			return $this->GenericErrorResponse($response, 'Entity does not exist or is not exposed');
		}
	}

	/**
	 * DELETE /api/objects/{entity}/{objectId} - deletes the given object.
	 * Requires an entity-dependent permission (as in AddObject), answered with 403
	 * when missing; api_keys need no such permission, but non-admins can only delete
	 * their own keys.
	 * Returns 204 on success, 404 when the object does not exist, or a 400 error
	 * response for an invalid/undeletable entity or a location that still has child
	 * locations.
	 */
	public function DeleteObject(Request $request, Response $response, array $args)
	{
		if ($args['entity'] == 'shopping_list' || $args['entity'] == 'shopping_lists')
		{
			User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_ITEMS_DELETE);
		}
		elseif ($args['entity'] == 'recipes' || $args['entity'] == 'recipes_pos' || $args['entity'] == 'recipes_nestings')
		{
			User::CheckPermission($request, User::PERMISSION_RECIPES);
		}
		elseif ($args['entity'] == 'meal_plan')
		{
			User::CheckPermission($request, User::PERMISSION_RECIPES_MEALPLAN);
		}
		elseif ($args['entity'] == 'equipment')
		{
			User::CheckPermission($request, User::PERMISSION_EQUIPMENT);
		}
		elseif ($args['entity'] == 'api_keys')
		{
			// No permission needed, ownership is checked below
		}
		else
		{
			User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		}

		if ($this->IsValidExposedEntity($args['entity']) && !$this->IsEntityWithNoDelete($args['entity']))
		{
			if ($this->IsEntityWithEditRequiresAdmin($args['entity']))
			{
				User::CheckPermission($request, User::PERMISSION_ADMIN);
			}

			$row = $this->DB->{$args['entity']}($args['objectId']);
			if ($row == null)
			{
				return $this->GenericErrorResponse($response, 'Object not found', 404);
			}

			// API keys can only be deleted by their owner (or by any admin), otherwise
			// anybody could delete anybody's keys, as the ids are sequential.
			// Keys of other users are answered with the same "not found" response as
			// non-existing ones, so that this can't be used to enumerate valid ids.
			if ($args['entity'] == 'api_keys' && $row->user_id != VICTUAL_USER_ID)
			{
				if (!User::HasPermissions(User::PERMISSION_ADMIN))
				{
					return $this->GenericErrorResponse($response, 'Object not found', 404);
				}
			}

			// A location that has children is refused, per plan 08 question 2: reparenting
			// silently rewrites where things were and cascading deletes the location stock
			// rows point at. The database refuses it too - migrations/0273.pgsql.sql's
			// `guard_location_children` covers every other write path - but a trigger's text
			// can never reach a client, because GenericErrorResponse() replaces any message
			// beginning `SQLSTATE[` before rendering it. So the message a person reads has to
			// be raised here, and the two are worded identically on purpose.
			if ($args['entity'] == 'locations' && $this->DB->locations()->where('parent_location_id', $row->id)->fetch() != null)
			{
				return $this->GenericErrorResponse($response, 'Location has child locations', 400);
			}

			// Same reasoning as the location check above, for the same kind of tree: plan 30
			// blocks deleting a group with children rather than reparenting or cascading, and
			// migrations/0278.pgsql.sql's `guard_product_group_children` is the backstop for
			// every write path that does not come through here.
			if ($args['entity'] == 'product_groups' && $this->DB->product_groups()->where('parent_product_group_id', $row->id)->fetch() != null)
			{
				return $this->GenericErrorResponse($response, 'Product group has child groups', 400);
			}

			if ($args['entity'] == 'locations' && $this->DB->stock()->where('location_id', $row->id)->fetch() !== null)
			{
				return $this->GenericErrorResponse($response, \Victual\Services\Database\StockLocationConstraint::DELETE_MESSAGE);
			}

			try
			{
				$row->delete();
			}
			catch (\PDOException $ex)
			{
				if ($args['entity'] == 'locations' && \Victual\Services\Database\StockLocationConstraint::IsViolation($ex))
				{
					return $this->GenericErrorResponse($response, \Victual\Services\Database\StockLocationConstraint::DELETE_MESSAGE);
				}

				// Any other foreign key violation is an ordinary reference refusal - some
				// other row still points at the one being deleted - and is a client error
				// like the named case above, not a server fault. Audit finding M15 / issue
				// #515: DELETE /api/objects/products/{id} for a product still named by
				// product_location_min_stock.product_id (migrations/0276.pgsql.sql, no
				// ON DELETE clause) reached here uncaught and answered 500.
				//
				// REFERENCE_REFUSAL_MESSAGE, not $ex->getMessage() run through
				// GenericErrorResponse()'s usual WithoutDriverText() sanitisation: that
				// fallback ("check that every value it carries suits the field it is for")
				// was written for a request body, and a DELETE carries none. Review round 2
				// found that exact text reaching the entity-list delete dialog verbatim -
				// ShowApiError() (public/js/victual.js) shows error_message for every 4xx -
				// and sending an operator looking for a value problem that does not exist.
				//
				// A single failed DELETE statement leaves the row untouched with nothing
				// beyond it to roll back, which is true today because DeleteObject() runs in
				// autocommit - it is not wrapped in InRequestTransaction() the way
				// AddObject()/EditObject() are (#494/#548). If that ever changes, this catch
				// has to stay outside whatever transaction wraps the delete: a transaction
				// that saw this method return a normal GenericErrorResponse() rather than a
				// thrown exception would commit right through the refusal, the same failure
				// mode audit correction 8 describes for AddObject()/EditObject().
				if (($ex->errorInfo[0] ?? $ex->getCode()) === '23503')
				{
					return $this->GenericErrorResponse($response, self::REFERENCE_REFUSAL_MESSAGE);
				}

				throw $ex;
			}

			return $this->EmptyApiResponse($response);
		}
		else
		{
			return $this->GenericErrorResponse($response, 'Invalid entity');
		}
	}

	/**
	 * PUT /api/objects/{entity}/{objectId} - updates the given object with the JSON
	 * request body. Requires an entity-dependent permission (as in AddObject),
	 * answered with 403 when missing. As a side effect, editing a product may add
	 * below-min-stock products to the shopping list (per user setting).
	 * The columns the server owns (id, row_created_timestamp) are dropped from the body
	 * before it is written - see WithoutServerOwnedColumns().
	 * Returns 204 on success, 404 when the object does not exist, or a 400 error
	 * response (invalid/not editable entity or invalid body).
	 */
	public function EditObject(Request $request, Response $response, array $args)
	{
		if ($args['entity'] == 'shopping_list' || $args['entity'] == 'shopping_lists')
		{
			User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_ITEMS_ADD);
		}
		elseif ($args['entity'] == 'recipes' || $args['entity'] == 'recipes_pos' || $args['entity'] == 'recipes_nestings')
		{
			User::CheckPermission($request, User::PERMISSION_RECIPES);
		}
		elseif ($args['entity'] == 'meal_plan')
		{
			User::CheckPermission($request, User::PERMISSION_RECIPES_MEALPLAN);
		}
		elseif ($args['entity'] == 'equipment')
		{
			User::CheckPermission($request, User::PERMISSION_EQUIPMENT);
		}
		else
		{
			User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		}

		if ($this->IsValidExposedEntity($args['entity']) && !$this->IsEntityWithNoEdit($args['entity']))
		{
			if ($this->IsEntityWithEditRequiresAdmin($args['entity']))
			{
				User::CheckPermission($request, User::PERMISSION_ADMIN);
			}

			$requestBody = $this->GetParsedAndFilteredRequestBody($request, $args['entity']);

			return $this->HandleApiCall($response, function () use ($args, $requestBody, $response, $request)
			{
				if ($requestBody === null)
				{
					throw new \Exception('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
				}

				$row = $this->DB->{$args['entity']}($args['objectId']);
				if ($row == null)
				{
					throw new EObjectNotFound('Object not found');
				}

				$requestBody = self::WithoutServerOwnedColumns($requestBody);

				if ($args['entity'] === 'products')
				{
					$this->RefuseTareEnable($requestBody, boolval($row->enable_tare_weight_handling));
				}

				// Same reasoning as AddObject(): the update and its post-save side effects
				// (AddMissingProductsToShoppingList() below, and the locations sync a storage
				// class edit triggers) commit or roll back together - audit finding H5 / issue
				// #494, and M9 / issue #509 for the class-edit propagation specifically.
				// EditObject() is likewise always this request's outermost transaction.
				return $this->InRequestTransaction($request, function () use ($args, $requestBody, $response, $row)
				{
					if ($args['entity'] === 'locations')
					{
						// Derived inside the transaction, locked FOR SHARE - see
						// ReadStorageClassTreatsAsFreezerForShare()'s docblock for why a read
						// taken before the transaction opens would not protect against a
						// concurrent edit to the same class.
						$requestBody = $this->WithDerivedIsFreezer($requestBody, $row->storage_class_id);
					}

					$row->update($requestBody);

					if ($args['entity'] === 'storage_classes' && array_key_exists('treats_as_freezer', $requestBody))
					{
						$this->PropagateFreezerFlagToLocations((int)$row->id);
					}

					// TODO: This should be better done somehow in StockService
					if ($args['entity'] == 'products' && boolval(UsersService::GetInstance()->GetUserSetting(VICTUAL_USER_ID, 'shopping_list_auto_add_below_min_stock_amount')))
					{
						StockService::GetInstance()->AddMissingProductsToShoppingList(UsersService::GetInstance()->GetUserSetting(VICTUAL_USER_ID, 'shopping_list_auto_add_below_min_stock_amount_list_id'));
					}

					return $this->EmptyApiResponse($response);
				});
			});
		}
		else
		{
			return $this->GenericErrorResponse($response, 'Entity does not exist or is not exposed');
		}
	}

	/**
	 * GET /api/objects/{entity}/{objectId} - returns a single object including its
	 * Userfield values under the "userfields" key (an empty object when none exist).
	 * Returns 400 for an unknown/not listable entity and 404 when the object does not exist.
	 */
	public function GetObject(Request $request, Response $response, array $args)
	{
		if (!EntityReadPolicy::Covers($args['entity']) || !$this->IsValidExposedEntity($args['entity']) || $this->IsEntityWithNoListing($args['entity']))
		{
			return $this->GenericErrorResponse($response, 'Entity does not exist or is not exposed');
		}
		EntityReadPolicy::Check($request, $args['entity']);
		$this->AssertWholeObjectReadable($request, $args['entity']);

		$object = $args['entity'] === 'label_printer_status'
			? $this->DB->label_printer_status()->where('printer_id', $args['objectId'])->fetch()
			: $this->DB->{$args['entity']}($args['objectId']);
		if ($args['entity'] === 'locations')
		{
			$object = $this->DB->locations()->select('id, name, description, row_created_timestamp, is_freezer, active, parent_location_id, storage_class_id, tare_weight, tare_qu_id')->where('id', $args['objectId'])->fetch();
		}
		if ($object == null)
		{
			return $this->GenericErrorResponse($response, 'Object not found', 404);
		}

		// TODO: Handle this somehow more generically
		$referencingId = $args['objectId'];
		if ($args['entity'] == 'stock')
		{
			$referencingId = $object->stock_id;
		}
		$userfields = UserfieldsService::GetInstance()->GetValues($args['entity'], $referencingId);
		$object['userfields'] = (object)$userfields;

		$object = FieldPolicy::GetInstance()->RedactRow($args['entity'], $object);
		$object = WireBooleans::Coerce($args['entity'], $object);
		if ($args['entity'] === 'storage_classes')
		{
			self::NormalizeStorageClassTemperatures($object);
		}

		return $this->ApiResponse($response, $object);
	}

	/**
	 * GET /api/objects/{entity} - lists all objects of the given entity, filterable via
	 * the generic query/limit/offset/order query parameters; when Userfields exist for
	 * the entity, each object gets a "userfields" key/value map attached.
	 * Returns 400 for an unknown or not listable entity.
	 */
	public function GetObjects(Request $request, Response $response, array $args)
	{
		if (!EntityReadPolicy::Covers($args['entity']) || !$this->IsValidExposedEntity($args['entity']) || $this->IsEntityWithNoListing($args['entity']))
		{
			return $this->GenericErrorResponse($response, 'Entity does not exist or is not exposed');
		}
		EntityReadPolicy::Check($request, $args['entity']);
		$this->AssertWholeObjectReadable($request, $args['entity']);

		$queryParams = $request->getQueryParams();
		$source = $this->DB->{$args['entity']}();
		if ($args['entity'] === 'locations')
		{
			// The generation is exposed only by the additive label context route.
			$source = $source->select('id, name, description, row_created_timestamp, is_freezer, active, parent_location_id, storage_class_id, tare_weight, tare_qu_id');
		}
		$objects = $this->MaterialiseFiltered($request, $this->QueryData($request, $source, $queryParams), $queryParams);

		$userfields = UserfieldsService::GetInstance()->GetFields($args['entity']);
		if (count($userfields) > 0)
		{
			$allUserfieldValues = UserfieldsService::GetInstance()->GetAllValues($args['entity']);

			foreach ($objects as $object)
			{
				$userfieldKeyValuePairs = null;
				foreach ($userfields as $userfield)
				{
					// TODO: Handle this somehow more generically
					$userfieldReference = 'id';
					if ($args['entity'] == 'stock')
					{
						$userfieldReference = 'stock_id';
					}

					$value = FindObjectInArrayByPropertyValue(FindAllObjectsInArrayByPropertyValue($allUserfieldValues, 'object_id', $object->{$userfieldReference}), 'name', $userfield->name);
					if ($value)
					{
						$userfieldKeyValuePairs[$userfield->name] = $value->value;
					}
					else
					{
						$userfieldKeyValuePairs[$userfield->name] = null;
					}
				}

				$object->userfields = $userfieldKeyValuePairs;
			}
		}

		$objects = FieldPolicy::GetInstance()->RedactRows($args['entity'], $objects);
		$objects = WireBooleans::CoerceRows($args['entity'], $objects);
		if ($args['entity'] === 'storage_classes')
		{
			foreach ($objects as $object)
			{
				self::NormalizeStorageClassTemperatures($object);
			}
		}

		return $this->ApiResponse($response, $objects);
	}

	/** PostgreSQL NUMERIC arrives as a string; these two documented fields are numbers. */
	private static function NormalizeStorageClassTemperatures(object $row): void
	{
		foreach (['min_temp_c', 'max_temp_c'] as $column)
		{
			if (isset($row->$column))
			{
				$row->$column = (float)$row->$column;
			}
		}
	}

	/**
	 * GET /api/userfields/{entity}/{objectId} - returns the Userfield values of the
	 * given object as a key/value map (200) or a 400 error response.
	 */
	public function GetUserfields(Request $request, Response $response, array $args)
	{
		// The current-user profile remains readable without household user-directory access.
		if (!EntityReadPolicy::Covers($args['entity']))
		{
			return $this->GenericErrorResponse($response, 'Entity does not exist or is not exposed');
		}
		if ($args['entity'] !== 'users' || (string)$args['objectId'] !== (string)VICTUAL_USER_ID)
		{
			EntityReadPolicy::Check($request, $args['entity']);
		}
		return $this->HandleApiCall($response, function () use ($args, $response)
		{
			return $this->ApiResponse($response, UserfieldsService::GetInstance()->GetValues($args['entity'], $args['objectId']));
		});
	}

	/**
	 * PUT /api/userfields/{entity}/{objectId} - sets the Userfield values of the given
	 * object from the JSON request body (a key/value map).
	 * Requires the MASTER_DATA_EDIT permission (403 otherwise).
	 * Returns 204 on success or a 400 error response.
	 */
	public function SetUserfields(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		return $this->HandleApiCall($response, function () use ($args, $requestBody, $response)
		{
			if ($requestBody === null)
			{
				throw new \Exception('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
			}

			UserfieldsService::GetInstance()->SetValues($args['entity'], $args['objectId'], $requestBody);
			return $this->EmptyApiResponse($response);
		});
	}

	/**
	 * Whether editing the given entity additionally requires the ADMIN permission (per OpenAPI spec enum).
	 */
	/**
	 * The request body with the columns the server owns removed.
	 *
	 * AddObject() and EditObject() hand the parsed body straight to LessQL's createRow()
	 * and update(), which write whatever keys it contains - so any account that may edit
	 * master data could rewrite a row's primary key and its creation timestamp on every
	 * exposed entity (sweep finding S16). Rewriting an id relocates a row out from under
	 * every foreign key that names it; rewriting row_created_timestamp rewrites history.
	 *
	 * A blocklist of two rather than a per-entity allowlist derived from the spec's entity
	 * schemas, deliberately and for now: the allowlist is only correct if those schemas are
	 * complete, which has never been tested. Plan 11 question 5 parks it behind
	 * plan 14 piece 2, which is what will make them trustworthy.
	 *
	 * The keys are dropped rather than refused, so a client that reads an object, edits one
	 * field and PUTs the whole thing back - which is what the fork's own forms do - keeps
	 * working.
	 *
	 * @param array $requestBody The parsed and purified request body
	 * @return array
	 */
	private static function WithoutServerOwnedColumns(array $requestBody): array
	{
		foreach (self::SERVER_OWNED_COLUMNS as $column)
		{
			unset($requestBody[$column]);
		}

		// "userfields" is not a column of any entity table - UserfieldsService keeps them in
		// their own table, keyed by entity name and object id (see GetObject()'s and
		// GetObjects()'s own docblocks for the key this drops). A body that still carries it
		// - what a client sends back after reading an object, since GetObject()/GetObjects()
		// attach it to every response - made LessQL answer "SQLSTATE[42703]: undefined
		// column" when writing back, which HandleApiCall() turns into a 400: exactly the
		// read-edit-write round trip this function's own docblock says a client gets to rely
		// on. A body that means to change Userfield values uses SetUserfields()
		// (PUT /api/userfields/{entity}/{objectId}), the only path that writes them. Audit
		// finding H10 / issue #499.
		unset($requestBody['userfields']);

		return $requestBody;
	}

	/**
	 * Columns of an exposed entity that no client may write through the generic CRUD
	 * endpoints, whatever permission it holds. See WithoutServerOwnedColumns().
	 */
	private const SERVER_OWNED_COLUMNS = ['id', 'row_created_timestamp', 'import_epoch'];

	/**
	 * DeleteObject()'s answer to an ordinary foreign-key delete refusal (audit finding M15 /
	 * issue #515) that is not the named stock-location case. Deliberately generic - it names
	 * no table or column, the way StockLocationConstraint::DELETE_MESSAGE names "Location"
	 * because it is only ever raised for one - and deliberately not the driver's own message:
	 * see DeleteObject()'s catch block for why a DELETE's refusal cannot reuse
	 * WithoutDriverText()'s usual "check that every value it carries" fallback.
	 */
	private const REFERENCE_REFUSAL_MESSAGE = 'Object is still referenced by other objects; remove those references before deleting it';

	/**
	 * Plan 23 questions 1 and 2: is_freezer is derived from the chosen storage class, and
	 * the derivation lives here rather than in a trigger, because the importer - the only
	 * other writer of this table - never sets a class at all (bin/victual-db-import reads
	 * upstream grocy SQLite, which has no storage_classes concept), so a trigger would fire
	 * on no rows.
	 *
	 * A request body that sets storage_class_id (to anything but null) has is_freezer
	 * overwritten from that class's treats_as_freezer, regardless of what the same request
	 * otherwise submits for is_freezer - the class becomes the sole writer from that point
	 * on, which is what keeps the two from silently disagreeing about the same physical
	 * fact.
	 *
	 * A body that leaves storage_class_id absent consults $persistedStorageClassId instead -
	 * the class already on the row, for EditObject() - and derives from that one the same
	 * way. Audit finding M9 / issue #509: a location that already carried a freezer class
	 * accepted PUT {"is_freezer":0} with no storage_class_id in the body, because this
	 * method previously only derived when the request itself named a class, treating an
	 * absent key the same as a location with no class at all. AddObject() has no persisted
	 * row to consult and always passes null here, so this parameter only ever applies to
	 * EditObject() - a location keeps whatever is_freezer it is given at creation unless the
	 * same request also names a class.
	 *
	 * A body that sets storage_class_id to null, or one that names no class at all (neither
	 * in the request nor already on the row), leaves is_freezer exactly as submitted: an
	 * unclassified location keeps that flag independently editable, per question 3's answer
	 * that a location may have no class.
	 *
	 * An unresolvable storage_class_id (deleted between the picker loading and the request
	 * landing, or simply invalid) is left alone here and falls through to the write itself,
	 * which the FOREIGN KEY on locations.storage_class_id refuses.
	 *
	 * Callers must run this inside their own transaction: it takes FOR SHARE on the class row
	 * (see ReadStorageClassTreatsAsFreezerForShare()) and that lock only holds for as long as
	 * a transaction is open around it.
	 *
	 * @param array $requestBody The parsed, purified, server-owned-column-stripped request body
	 * @param int|string|null $persistedStorageClassId The row's current storage_class_id
	 *   before this request (EditObject()), or null (AddObject(), or when it is unclassified)
	 */
	private function WithDerivedIsFreezer(array $requestBody, $persistedStorageClassId = null): array
	{
		if (array_key_exists('storage_class_id', $requestBody))
		{
			if ($requestBody['storage_class_id'] === null)
			{
				return $requestBody;
			}

			$classId = $requestBody['storage_class_id'];
		}
		elseif ($persistedStorageClassId !== null)
		{
			$classId = $persistedStorageClassId;
		}
		else
		{
			return $requestBody;
		}

		$treatsAsFreezer = $this->ReadStorageClassTreatsAsFreezerForShare((int)$classId);
		if ($treatsAsFreezer !== null)
		{
			$requestBody['is_freezer'] = $treatsAsFreezer;
		}

		return $requestBody;
	}

	/**
	 * storage_classes.treats_as_freezer for $classId, or null when the id does not resolve -
	 * left for the write itself to refuse via the FOREIGN KEY, per WithDerivedIsFreezer()'s
	 * own docblock.
	 *
	 * FOR SHARE, and a raw statement rather than $this->DB->storage_classes($id): this must be
	 * called from inside the caller's InRequestTransaction() closure for the lock to be real -
	 * one taken before a transaction opens, or with none open at all, releases the instant the
	 * statement finishes and protects nothing, the same point
	 * DatabaseService::LockProductStock()'s own docblock makes. Held until the caller's
	 * transaction commits or rolls back, it blocks a concurrent edit to the same class -
	 * whether another WithDerivedIsFreezer() caller or PropagateFreezerFlagToLocations() -
	 * from changing treats_as_freezer between this read and the write this request makes with
	 * it, which is what would otherwise let a location and its class disagree the moment the
	 * other transaction commits.
	 *
	 * @return int|null
	 */
	private function ReadStorageClassTreatsAsFreezerForShare(int $classId): ?int
	{
		$statement = DatabaseService::GetInstance()->GetDbConnectionRaw()
			->prepare('SELECT treats_as_freezer FROM storage_classes WHERE id = ? FOR SHARE');
		$statement->execute([$classId]);
		$value = $statement->fetchColumn();

		return $value === false ? null : (int)$value;
	}

	/**
	 * Rewrites is_freezer on every location currently carrying $storageClassId, from the
	 * class's own just-updated row rather than from the request body's value for it. Audit
	 * finding M9 / issue #509, and a defect in this method's own first version found by
	 * review: (int)(bool) on the request's treats_as_freezer disagreed with what PostgreSQL
	 * actually stored for a value the two parse differently - "00", " 0" and "+0" are all 0 to
	 * PostgreSQL's SMALLINT cast, the same cast $row->update() applies two lines above when it
	 * writes this same column, but PHP's (bool) treats every one of those strings as true. So
	 * (int)(bool)$treatsAsFreezer produced 1 immediately after the class itself had been
	 * stored as 0 - the exact contradiction this method exists to prevent, now produced by an
	 * *accepted* edit rather than by a stale one. Reading treats_as_freezer back from
	 * storage_classes inside this one UPDATE removes PHP's interpretation of the request from
	 * this path entirely: the value every location receives is, by construction, the value the
	 * class row holds, whatever type the request sent it as.
	 *
	 * Called from EditObject()'s InRequestTransaction() closure, after $row->update(), so this
	 * UPDATE commits or rolls back with the class edit that triggered it.
	 *
	 * No migration and no trigger: an application write path is question 2's own answer for
	 * WithDerivedIsFreezer(), for the same reason it applies here - nothing about editing a
	 * class needs a trigger to see it, since every location referencing the class is already
	 * reachable from this one UPDATE.
	 *
	 * @param int $storageClassId
	 */
	private function PropagateFreezerFlagToLocations(int $storageClassId): void
	{
		$statement = DatabaseService::GetInstance()->GetDbConnectionRaw()->prepare(
			'UPDATE locations SET is_freezer = (SELECT treats_as_freezer FROM storage_classes WHERE id = ?) WHERE storage_class_id = ?'
		);
		$statement->execute([$storageClassId, $storageClassId]);
	}

	/**
	 * ADR-0022 decisions 4 and 7 (2026-09-14), question 1's decided answer: the product-level
	 * tare mechanism is retired, `enable_tare_weight_handling` and `tare_weight` stay on the
	 * wire at their current values, and *enabling* the flag from here on answers 400 naming
	 * the per-entry (docs/plans/landed/28-open-container-measurement.md) or per-location
	 * (docs/plans/landed/29-working-container-replenishment.md) tare that replaced it.
	 *
	 * Only the 0 -> 1 transition is refused. A product that already has the flag enabled can
	 * still be saved unchanged - this is a business rule about what a client may newly
	 * choose, not a constraint on data that predates the retirement, and `products` carries
	 * no CHECK of its own for the same reason plan 28's spike gives for convertibility
	 * (.spike-adr22/RESULTS.md#prerequisite-7-conversion-failure): the fact being refused is
	 * not a property of the row being written, it is a property of the write itself.
	 *
	 * @param array $requestBody The parsed, purified, server-owned-column-stripped request body
	 * @param bool $currentlyEnabled Whether the row already has the flag enabled (false for AddObject)
	 * @throws EInvalidApiQuery When the body sets the flag to a truthy value it was not already at
	 */
	private function RefuseTareEnable(array $requestBody, bool $currentlyEnabled): void
	{
		if (!array_key_exists('enable_tare_weight_handling', $requestBody))
		{
			return;
		}

		if (boolval($requestBody['enable_tare_weight_handling']) && !$currentlyEnabled)
		{
			throw new EInvalidApiQuery('enable_tare_weight_handling can no longer be enabled - weigh an opened purchased container on the stock entry instead (docs/plans/landed/28-open-container-measurement.md), or a refillable vessel on its location (docs/plans/landed/29-working-container-replenishment.md)');
		}
	}

	private function IsEntityWithEditRequiresAdmin($entity)
	{
		return in_array($entity, $this->GetOpenApispec()->components->schemas->ExposedEntityEditRequiresAdmin->enum);
	}

	/**
	 * Whether the given entity is excluded from listing/reading (per OpenAPI spec enum).
	 */
	private function IsEntityWithNoListing($entity)
	{
		return in_array($entity, $this->GetOpenApispec()->components->schemas->ExposedEntityNoListing->enum);
	}

	/**
	 * Whether the given entity cannot be created/edited through this API (per OpenAPI spec enum).
	 */
	private function IsEntityWithNoEdit($entity)
	{
		return in_array($entity, $this->GetOpenApispec()->components->schemas->ExposedEntityNoEdit->enum);
	}

	/**
	 * Whether the given entity cannot be deleted through this API (per OpenAPI spec enum).
	 */
	private function IsEntityWithNoDelete($entity)
	{
		return in_array($entity, $this->GetOpenApispec()->components->schemas->ExposedEntityNoDelete->enum);
	}

	/**
	 * Whether the given entity is exposed through this API at all (per OpenAPI spec enum).
	 */
	private function IsValidExposedEntity($entity)
	{
		return in_array($entity, $this->GetOpenApispec()->components->schemas->ExposedEntity->enum);
	}
}
