<?php

namespace Victual\Controllers\Users;

use PDO;
use Victual\Services\DatabaseService;
use Victual\Services\FilesService;

/**
 * Who may read a file in the userfiles group: a caller every record that references it permits.
 *
 * A userfile has no owner column. The only link from the bytes to a record is the text of a
 * userfield value - the browser writes base64(stored name) + '_' + base64(download name) -
 * so the owners of a file are the (entity, object id) pairs of the values that name it, and
 * the read rule of each is the one that governs the record itself (ADR-0018, ADR-0035).
 * Decision rule, from the maintainer's answers on #905:
 *
 * - every distinct referencing record must admit the caller (F2), so adding a reference to
 *   a file can narrow who may read it and never widen it;
 * - a reference whose record does not exist, and a file nothing references, are readable by
 *   ADMIN only (F1) - permission to delete an orphan does not establish permission to read
 *   what may be sensitive;
 * - a reference on a record that is deliberately not readable (api_keys) admits nobody,
 *   ADMIN included, and so does an entity EntityReadPolicy has no row for (F3);
 * - the definition rows (userfields, userentities) admit ADMIN only, an explicit exception
 *   here that leaves EntityReadPolicy's null - definitions readable by any authenticated
 *   caller - untouched (F3');
 * - the user's own row admits that user, as GetUserfields does (F4);
 * - a view row is resolved by the key GetObject uses and admits whoever the view's read
 *   permission admits; one that no longer exists is unresolved. label_printer_status is
 *   looked up by printer_id, as GetObject reads it; the other label_* and print_* entities
 *   are not looked up, so they are unresolved, which is ADMIN only. Every one of them,
 *   label_printer_status included, has ADMIN as its EntityReadPolicy permission, so the
 *   outcome is ADMIN only either way.
 *
 * The check runs on the original file's name before anything is read or a picture
 * derivative is created, and every refusal is the same exception: it must not tell a
 * caller whether the file exists or what kind of record owns it.
 */
class UserfileOwnerPolicy
{
	/**
	 * The one name every ownership refusal carries. It is not a permission a user can hold:
	 * naming the real leaf that was missing would tell the caller what owns the file.
	 */
	const REFUSAL = 'USERFILE_OWNER_READ';

	/**
	 * Entities whose records are keyed by something other than an integer id column, as
	 * GenericEntityApiController attaches their userfield values: stock values are keyed by
	 * stock_id (text), label_printer_status by printer_id. Every other entity of
	 * OWNER_TABLES is keyed by its integer id.
	 */
	const OWNER_KEYS = [
		'stock' => ['stock_id', false],
		'label_printer_status' => ['printer_id', true]
	];

	/**
	 * The entities whose records this class can look up. The table name is never taken from
	 * a stored value: an entity not listed here or in OWNER_VIEWS is unresolved, whatever
	 * userfields.entity says. Their read permission is EntityReadPolicy::PERMISSIONS.
	 */
	const OWNER_TABLES = [
		'products', 'chores', 'product_barcodes', 'batteries', 'locations', 'storage_classes',
		'quantity_units', 'quantity_unit_conversions', 'shopping_list', 'shopping_lists',
		'shopping_locations', 'recipes', 'recipes_pos', 'recipes_nestings', 'tasks',
		'task_categories', 'product_groups', 'product_location_min_stock', 'product_substitutions',
		'equipment', 'meal_plan', 'meal_plan_sections', 'stock_log', 'chores_log',
		'battery_charge_cycles', 'permission_hierarchy', 'userobjects', 'users',
		'roles', 'mqtt_product_entities', 'stock', 'label_printer_status'
	];

	/**
	 * The read-only views EntityReadPolicy covers. GenericEntityApiController::GetObject looks
	 * their rows up by id like a table's, so a value attached to one resolves the same way: the
	 * row must exist in the view, and the view's own read permission decides (a view row that
	 * disappears is an unresolved reference). Several of them select a constant or a source
	 * row's id as that column, which is what GetObject finds too.
	 */
	const OWNER_VIEWS = [
		'stock_current_locations', 'products_last_purchased', 'products_average_price',
		'quantity_unit_conversions_resolved', 'locations_resolved', 'product_groups_resolved',
		'product_barcodes_view', 'uihelper_shopping_list', 'product_groups_missing',
		'product_location_missing', 'product_substitutions_resolved', 'recipes_pos_resolved'
	];

	/**
	 * Entities whose references admit ADMIN only: the definition rows, which EntityReadPolicy
	 * leaves open as metadata but whose attached files are not metadata (F3').
	 */
	const ADMIN_ONLY_ENTITIES = ['userfields', 'userentities'];

	/**
	 * Entities whose references admit nobody, because the entity is deliberately not
	 * readable through the API at all (ExposedEntityNoListing).
	 */
	const DENIED_ENTITIES = ['api_keys'];

	/**
	 * @throws PermissionMissingException When any record referencing the file refuses the
	 * caller, or nothing references it and the caller is not ADMIN
	 */
	public static function Check($request, string $storedName): void
	{
		if (!self::MayRead($storedName))
		{
			throw new PermissionMissingException($request, self::REFUSAL);
		}
	}

	public static function MayRead(string $storedName): bool
	{
		// A derivative is not a file in its own right: every derivative suffix is stripped to the
		// root original, the caller must be able to read the root, and a value naming any name in
		// the chain can only narrow who may. Otherwise a value that names a thumbnail made for one
		// caller would hand the original's bytes (resized) to whoever may read that value's record.
		$chain = FilesService::DerivativeChain($storedName);
		$references = self::ReferencesTo($chain);

		if (count($references[FilesService::RootOf($storedName)] ?? []) === 0)
		{
			// F1: the root is unreferenced, whatever references its derivatives have
			return User::HasPermissions(User::PERMISSION_ADMIN);
		}

		$checked = [];
		foreach ($references as $namedReferences)
		{
			foreach ($namedReferences as $key => [$entity, $objectId])
			{
				if (isset($checked[$key]))
				{
					continue;
				}
				$checked[$key] = true;

				if (!self::MayReadAsOwner($entity, $objectId))
				{
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Entities whose row deletion deletes userfield values (the trg_cascade_*_removal
	 * triggers), so deleting one of their rows can remove the reference that protects a
	 * file. Deleting any other owner leaves its value behind as an unresolved reference,
	 * which is ADMIN only and so can only narrow. UserfileOwnerWriteTest pins this list
	 * against the triggers.
	 */
	const CASCADING_OWNER_ENTITIES = ['batteries', 'chores', 'products', 'userfields'];

	/**
	 * Runs $work, a write that can change which records reference a userfile, and refuses
	 * it unless the caller may read every owner of each file whose owners it changed, as
	 * those owners stood before the write (issue #920, decision D3). Removing a protected
	 * record's reference, by clearing or replacing the value or by deleting a record whose
	 * values cascade, would otherwise widen the read to the owners that remain; adding a
	 * reference to a file the caller cannot read is refused the same way. The comparison
	 * runs in one transaction around the write, so cascades are seen whatever path causes
	 * them, and a refusal rolls the write back.
	 *
	 * A file nothing referenced before the write can be attached by anyone allowed to make
	 * the write: that is how a client attaches a file it is about to upload.
	 *
	 * @throws PermissionMissingException The same refusal Check() gives, so it says nothing
	 * about which record owns the file
	 */
	public static function GuardOwnershipChange($request, callable $work)
	{
		return DatabaseService::GetInstance()->InTransaction(function () use ($request, $work)
		{
			self::LockOwnership();

			// Read authority is decided on the records as they stand before the write: after
			// it, a deleted owner no longer exists and would admit ADMIN only.
			$before = self::OwnersByRoot();
			$readable = [];
			foreach ($before as $owners)
			{
				foreach ($owners as $key => [$entity, $objectId])
				{
					$readable[$key] ??= self::MayReadAsOwner($entity, $objectId);
				}
			}

			$result = $work();

			$after = self::OwnersByRoot();
			foreach ($before as $root => $owners)
			{
				$remaining = $after[$root] ?? [];
				ksort($remaining);
				ksort($owners);
				if (array_keys($owners) === array_keys($remaining))
				{
					continue;
				}

				foreach (array_keys($owners) as $key)
				{
					if (!$readable[$key])
					{
						throw new PermissionMissingException($request, self::REFUSAL);
					}
				}
			}

			return $result;
		});
	}

	/**
	 * Authorizes creating or deleting a userfile's bytes, before the name is looked up, so
	 * neither the answer nor the error says whether a protected file exists. A referenced
	 * file needs read permission on every owner (D3: delete-then-upload under the same name
	 * replaced a protected file's bytes). An unreferenced name keeps the group-level right:
	 * a client uploads a file after saving the value that names it, and deletes a replaced
	 * file after saving the value that no longer does.
	 *
	 * Must run inside the transaction that holds LockOwnership() until the bytes are written,
	 * so a reference cannot be attached between the check and the write.
	 *
	 * @throws PermissionMissingException
	 */
	public static function CheckByteMutation($request, string $storedName): void
	{
		$references = self::ReferencesTo(FilesService::DerivativeChain($storedName));
		if (count($references) > 0 && !self::MayRead($storedName))
		{
			throw new PermissionMissingException($request, self::REFUSAL);
		}
	}

	/**
	 * Serializes the writes GuardOwnershipChange() and CheckByteMutation() authorize. Only
	 * they take it: a write outside them can delete a non-cascading owner or nothing at all,
	 * and either way only narrows who may read a file.
	 */
	public static function LockOwnership(): void
	{
		$pdo = DatabaseService::GetInstance()->GetDbConnectionRaw();
		if (!$pdo->inTransaction())
		{
			throw new \LogicException('The userfile ownership lock is held for a transaction');
		}
		$pdo->query('SELECT pg_advisory_xact_lock(920, 905)');
	}

	/**
	 * Every referenced file's root original, with the distinct owners whose values name it
	 * or one of its derivatives - the reference set MayRead() checks.
	 *
	 * @return array<string, array<string, array{0: ?string, 1: string}>> By root name, then
	 * by the owner key ReferencesTo() uses
	 */
	private static function OwnersByRoot(): array
	{
		$statement = DatabaseService::GetInstance()->GetDbConnectionRaw()->query(
			"SELECT uf.entity, uv.object_id, uv.value FROM userfield_values uv LEFT JOIN userfields uf ON uf.id = uv.field_id WHERE uv.value <> ''"
		);

		$owners = [];
		while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false)
		{
			$name = base64_decode(explode('_', $row[2])[0]);
			if ($name === '' || !IsValidFileName($name))
			{
				continue;
			}
			$owners[FilesService::RootOf($name)][$row[0] === null ? "\0undefined" : $row[0] . "\0" . $row[1]] = [$row[0], $row[1]];
		}

		return $owners;
	}

	/**
	 * The distinct (entity, object id) pairs holding a value that names $storedName, of any
	 * field type: a field retyped after its values were saved still counts, and counting
	 * more can only narrow access.
	 *
	 * The name is matched the way ServeFile reads the request: the part before the first
	 * '_' is decoded with the same non-strict base64_decode, so every encoding the request
	 * side accepts (padding, a dropped-bits last character, stray characters) is a
	 * reference too. That is also why this cannot be narrowed in SQL: those encodings do
	 * not share a prefix, and there is no index on the value.
	 *
	 * @param string[] $storedNames The names to look for
	 * @return array<string, array<string, array{0: ?string, 1: string}>> By name, then by
	 * "entity NUL object id" (one key for every value with no definition row)
	 */
	private static function ReferencesTo(array $storedNames): array
	{
		// LEFT JOIN: a value whose definition row is gone still names the file, and is an
		// unresolved reference (entity null) rather than no reference at all
		$statement = DatabaseService::GetInstance()->GetDbConnectionRaw()->query(
			"SELECT uf.entity, uv.object_id, uv.value FROM userfield_values uv LEFT JOIN userfields uf ON uf.id = uv.field_id WHERE uv.value <> ''"
		);

		$found = [];
		while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false)
		{
			$name = base64_decode(explode('_', $row[2])[0]);
			if (in_array($name, $storedNames, true))
			{
				$found[$name][$row[0] === null ? "\0undefined" : $row[0] . "\0" . $row[1]] = [$row[0], $row[1]];
			}
		}

		return $found;
	}

	private static function MayReadAsOwner(?string $entity, string $objectId): bool
	{
		if ($entity === null)
		{
			return User::HasPermissions(User::PERMISSION_ADMIN);
		}

		if (in_array($entity, self::DENIED_ENTITIES, true))
		{
			return false;
		}

		if (in_array($entity, self::ADMIN_ONLY_ENTITIES, true))
		{
			return User::HasPermissions(User::PERMISSION_ADMIN);
		}

		if (!str_starts_with($entity, 'userentity-') && !array_key_exists($entity, EntityReadPolicy::PERMISSIONS))
		{
			// No policy at all for what the value claims to hang off: nobody (F3)
			return false;
		}

		if (!self::OwnerExists($entity, $objectId))
		{
			// F1: a reference nobody can resolve is an orphan. That includes an entity with a
			// read policy but no lookup (the label and print tables other than
			// label_printer_status, all ADMIN) whose record this class does not look for, so
			// only ADMIN, who may read all of them, passes
			return User::HasPermissions(User::PERMISSION_ADMIN);
		}

		if (str_starts_with($entity, 'userentity-'))
		{
			return User::HasPermissions(User::PERMISSION_MASTER_DATA_EDIT);
		}

		if ($entity === 'users' && $objectId === (string)VICTUAL_USER_ID)
		{
			return true;
		}

		$permission = EntityReadPolicy::PERMISSIONS[$entity] ?? null;

		// OwnerExists() only passes entities with a policy row; a null here is an entity the
		// policy leaves open and this class has no rule for, and fails closed
		return $permission !== null && User::HasPermissions($permission);
	}

	/**
	 * Whether the record a value hangs off exists, resolved the way
	 * GenericEntityApiController attaches userfield values to it.
	 */
	private static function OwnerExists(string $entity, string $objectId): bool
	{
		$pdo = DatabaseService::GetInstance()->GetDbConnectionRaw();

		if (str_starts_with($entity, 'userentity-'))
		{
			if (!self::IsCanonicalId($objectId))
			{
				return false;
			}
			$statement = $pdo->prepare('SELECT 1 FROM userobjects o JOIN userentities e ON e.id = o.userentity_id WHERE o.id = ? AND e.name = ?');
			$statement->execute([(int)$objectId, substr($entity, strlen('userentity-'))]);

			return $statement->fetchColumn() !== false;
		}

		if ((!in_array($entity, self::OWNER_TABLES, true) && !in_array($entity, self::OWNER_VIEWS, true)) || !isset(EntityReadPolicy::PERMISSIONS[$entity]))
		{
			return false;
		}

		[$column, $integerKey] = self::OWNER_KEYS[$entity] ?? ['id', true];
		if ($integerKey && !self::IsCanonicalId($objectId))
		{
			return false;
		}

		// $entity and $column come from the constants above, never from stored data
		$statement = $pdo->prepare("SELECT 1 FROM $entity WHERE $column = ?");
		$statement->execute([$integerKey ? (int)$objectId : $objectId]);

		return $statement->fetchColumn() !== false;
	}

	/** An integer in the form the id column prints, so '01' and '1 ' name nothing. */
	private static function IsCanonicalId(string $objectId): bool
	{
		return $objectId === (string)(int)$objectId && (int)$objectId >= -2147483648 && (int)$objectId <= 2147483647;
	}
}
