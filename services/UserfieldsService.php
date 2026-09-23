<?php

namespace Victual\Services;

/**
 * Custom fields ("userfields") that users can attach to entities: field definitions
 * (userfields table) and per-object values (userfield_values table). An entity here is
 * an exposed API entity name, "userentity-<name>" for user-defined entities, or "users".
 */
class UserfieldsService extends BaseService
{
	const USERFIELD_TYPE_CHECKBOX = 'checkbox';
	const USERFIELD_TYPE_DATE = 'date';
	const USERFIELD_TYPE_DATETIME = 'datetime';
	const USERFIELD_TYPE_NUMBER_INT = 'number-integral';
	const USERFIELD_TYPE_NUMBER_DECIMAL = 'number-decimal';
	const USERFIELD_TYPE_NUMBER_CURRENCY = 'number-currency';
	const USERFIELD_TYPE_FILE = 'file';
	const USERFIELD_TYPE_IMAGE = 'image';
	const USERFIELD_TYPE_LINK = 'link';
	const USERFIELD_TYPE_LINK_WITH_TITLE = 'link-with-title';
	const USERFIELD_TYPE_PRESET_CHECKLIST = 'preset-checklist';
	const USERFIELD_TYPE_PRESET_LIST = 'preset-list';
	const USERFIELD_TYPE_SINGLE_LINE_TEXT = 'text-single-line';
	const USERFIELD_TYPE_SINGLE_MULTILINE_TEXT = 'text-multi-line';

	/** @var object|null Lazily decoded victual.openapi.json (see GetOpenApispec()) */
	protected $OpenApiSpec = null;

	/**
	 * All userfield definitions across all entities, ordered case insensitively by name.
	 */
	public function GetAllFields()
	{
		return $this->DB->userfields()->orderBy('name', 'COLLATE NOCASE')->fetchAll();
	}

	/**
	 * All userfield values of the entity across all of its objects
	 * (userfield_values_resolved rows).
	 *
	 * @param string $entity
	 * @throws \Exception When the entity is unknown/not exposed
	 */
	public function GetAllValues($entity)
	{
		if (!$this->IsValidExposedEntity($entity))
		{
			throw new \Exception('Entity does not exist or is not exposed');
		}

		$userfields = $this->GetFields($entity);
		return $this->DB->userfield_values_resolved()->where('entity', $entity)->orderBy('name', 'COLLATE NOCASE')->fetchAll();
	}

	/**
	 * All entity names userfields can be attached to, sorted: the ExposedEntity enum
	 * from the OpenAPI spec, "userentity-<name>" for each user-defined entity, and "users".
	 *
	 * @return string[]
	 */
	public function GetEntities()
	{
		$exposedDefaultEntities = $this->GetOpenApispec()->components->schemas->ExposedEntity->enum;
		$userEntities = [];
		$specialEntities = ['users'];

		foreach ($this->DB->userentities()->orderBy('name', 'COLLATE NOCASE') as $userentity)
		{
			$userEntities[] = 'userentity-' . $userentity->name;
		}

		$entitiesSorted = array_merge($exposedDefaultEntities, $userEntities, $specialEntities);
		sort($entitiesSorted);
		return $entitiesSorted;
	}

	/**
	 * A single userfield definition row by id.
	 */
	public function GetField($fieldId)
	{
		return $this->DB->userfields($fieldId);
	}

	/**
	 * All USERFIELD_TYPE_* constants of this class, keyed by constant name.
	 *
	 * @return array<string, string>
	 */
	public function GetFieldTypes()
	{
		return GetClassConstants('\Victual\Services\UserfieldsService');
	}

	/**
	 * The userfield definitions of one entity, ordered by sort number, then name.
	 *
	 * @param string $entity
	 * @throws \Exception When the entity is unknown/not exposed
	 */
	public function GetFields($entity)
	{
		if (!$this->IsValidExposedEntity($entity))
		{
			throw new \Exception('Entity does not exist or is not exposed');
		}

		return $this->DB->userfields()->where('entity', $entity)->orderBy('sort_number')->orderBy('name', 'COLLATE NOCASE')->fetchAll();
	}

	/**
	 * The userfield values of one object as [field name => value], with null for every
	 * field of the entity that has no stored value.
	 *
	 * @param string $entity
	 * @param int $objectId Id of the row within the entity's table
	 * @return array<string, string|null>
	 * @throws \Exception When the entity is unknown/not exposed
	 */
	public function GetValues($entity, $objectId)
	{
		if (!$this->IsValidExposedEntity($entity))
		{
			throw new \Exception('Entity does not exist or is not exposed');
		}

		$userfields = $this->GetFields($entity);
		$userfieldValues = $this->DB->userfield_values_resolved()->where('entity = :1 AND object_id = :2', $entity, $objectId)->orderBy('name', 'COLLATE NOCASE')->fetchAll();

		$userfieldKeyValuePairs = [];
		foreach ($userfields as $userfield)
		{
			$value = FindObjectInArrayByPropertyValue($userfieldValues, 'name', $userfield->name);
			if ($value)
			{
				$userfieldKeyValuePairs[$userfield->name] = $value->value;
			}
			else
			{
				$userfieldKeyValuePairs[$userfield->name] = null;
			}
		}

		return $userfieldKeyValuePairs;
	}

	/**
	 * Stores userfield values for one object, inserting or updating per field.
	 *
	 * @param string $entity
	 * @param int $objectId Id of the row within the entity's table
	 * @param array<string, mixed> $userfields [field name => value]
	 * @throws \Exception When the entity is unknown/not exposed, or a key is not a
	 * userfield of that entity
	 */
	public function SetValues($entity, $objectId, $userfields)
	{
		if (!$this->IsValidExposedEntity($entity))
		{
			throw new \Exception('Entity does not exist or is not exposed');
		}

		// Resolve every key to its field row before writing any value: a submitted key
		// that is not a userfield of this entity must refuse the whole request rather
		// than leave the keys before it written.
		$fieldIdsByKey = [];
		foreach ($userfields as $key => $value)
		{
			$fieldRow = $this->DB->userfields()->where('entity = :1 AND name = :2', $entity, $key)->fetch();

			if ($fieldRow === null)
			{
				throw new \Exception("Field $key is not a valid userfield of the given entity");
			}

			$fieldIdsByKey[$key] = $fieldRow->id;
		}

		DatabaseService::GetInstance()->InTransaction(function () use ($userfields, $fieldIdsByKey, $objectId)
		{
			foreach ($userfields as $key => $value)
			{
				$fieldId = $fieldIdsByKey[$key];

				$alreadyExistingEntry = $this->DB->userfield_values()->where('field_id = :1 AND object_id = :2', $fieldId, $objectId)->fetch();

				if ($alreadyExistingEntry) // Update
				{$alreadyExistingEntry->update([
					'value' => $value
				]);
				}
				else // Insert
				{$newRow = $this->DB->userfield_values()->createRow([
					'field_id' => $fieldId,
					'object_id' => $objectId,
					'value' => $value
				]);
					$newRow->save();
				}
			}
		});
	}

	/**
	 * The decoded victual.openapi.json, loaded once per instance (source of the
	 * ExposedEntity enum used by GetEntities()).
	 */
	protected function GetOpenApispec()
	{
		if ($this->OpenApiSpec == null)
		{
			$this->OpenApiSpec = json_decode(file_get_contents(__DIR__ . '/../victual.openapi.json'));
		}

		return $this->OpenApiSpec;
	}

	private function IsValidExposedEntity($entity)
	{
		return in_array($entity, $this->GetEntities());
	}
}
