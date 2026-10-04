<?php

namespace Victual\Services\Mqtt;

use Victual\Services\Time\Instant;

use Victual\Services\BaseService;
use Victual\Services\Database\ValueComparison;
use Victual\Services\StockService;

/**
 * Builds the ambient state snapshot that gets published to MQTT: the whole read model,
 * every time, as plain facts.
 *
 * Two rules shape everything here, and both come from
 * docs/plans/18-mqtt-state-publication.md.
 *
 * **Facts, never derived states.** A derived state ("expiring soon", "chore overdue") is a
 * function of the data *and* the current time, so publishing one means something has to be
 * awake to recompute it as the clock moves - which is exactly what this plan exists to
 * avoid. So this emits best_before_date and next_estimated_execution_time and no boolean
 * that depends on now(). Home Assistant renders a "device_class: timestamp" sensor as
 * relative time and compares it against now() locally, at no cost to a server that may be
 * asleep for days.
 *
 * **The wall-tablet test.** Anything with broker credentials reads these topics without
 * authenticating to Victual at all, so the payload carries only what a wall tablet would
 * show. That is enforced twice over: DENIED_COLUMNS names the columns of the views this
 * reads which must never leave (every price, cost and value column, plus notes), and each
 * entity's allow-list names the only keys it may emit. Neither list is trusted on its own -
 * AssertNoForbiddenKeys() walks the finished payload and throws rather than publish a key
 * that looks like money.
 *
 * The views read here are the ones the UI reads, which is deliberate: a divergence between
 * the engines shows up in this payload before it shows up anywhere a person would notice.
 */
class StateSnapshotAssembler extends BaseService
{
	/**
	 * Entity ids, in publication order. These are the object ids in the discovery payload
	 * and the last segment of every state topic, so they are part of the contract with any
	 * subscriber and do not change without a retraction.
	 */
	const ENTITY_STOCK = 'stock';
	const ENTITY_SHOPPING_LIST = 'shopping_list';
	const ENTITY_NEXT_CHORE = 'next_chore';
	const ENTITY_NEXT_BATTERY = 'next_battery';
	const ENTITY_NEXT_TASK = 'next_task';
	const ENTITY_PRODUCTS_DUE_SOON = 'products_due_soon';
	const ENTITY_PRODUCTS_EXPIRED = 'products_expired';
	const ENTITY_LAST_PUBLISHED = 'last_published';

	/**
	 * Prefix of a per-product entity's object id, so "product_42" is product 42's sensor.
	 * Part of the topic contract with any subscriber; see question 2's Response.
	 */
	const PER_PRODUCT_OBJECT_ID_PREFIX = 'product_';

	/**
	 * Columns of the views below which must never reach a topic, by name.
	 *
	 * This is the deny-list half of the guard and it is written out rather than derived,
	 * because the point of it is to be reviewable: a reader can check it against
	 * uihelper_stock_current_overview (migrations/0252.sql) and uihelper_shopping_list
	 * (db/pgsql/baseline/04_views_l1b.sql) by eye. "value", "last_price" and "average_price"
	 * are the three the plan's security note names explicitly - they are in the stock
	 * overview the UI reads and would ship by default if the attributes were assembled from
	 * a bare row. "note" is not money but fails the same wall-tablet test.
	 */
	const DENIED_COLUMNS = [
		'value',
		'last_price',
		'average_price',
		'avg_price',
		'price',
		'last_price_unit',
		'last_price_total',
		'costs',
		'note',
		'api_key'
	];

	/**
	 * The allow-list key under which every per-product entity's attributes are filtered. Not
	 * an ambient entity - there is one of these per opted-in product, named product_<id>.
	 */
	const ENTITY_PER_PRODUCT = 'per_product';

	/**
	 * The only keys each entity's attribute rows may carry. The allow-list half of the
	 * guard: a column added to one of these views in a later migration cannot appear here
	 * by accident, only by being named.
	 */
	const ALLOWED_ROW_KEYS = [
		self::ENTITY_STOCK => ['product_id', 'product_name', 'amount', 'unit', 'best_before_date'],
		self::ENTITY_SHOPPING_LIST => ['shopping_list_id', 'product_id', 'product_name', 'amount', 'unit'],
		self::ENTITY_NEXT_CHORE => ['chore_id', 'chore_name', 'next_estimated_execution_time'],
		self::ENTITY_NEXT_BATTERY => ['battery_id', 'battery_name', 'next_estimated_charge_time'],
		self::ENTITY_NEXT_TASK => ['task_id', 'task_name', 'due_date'],
		self::ENTITY_PER_PRODUCT => ['product_id', 'product_name', 'unit', 'best_before_date']
	];

	/**
	 * Anything matching this in an emitted key is a bug, and a loud one.
	 *
	 * The deny-list catches the columns that exist today; this catches the ones a future
	 * migration invents. Deliberately broad - "value" matches more than money does - because
	 * a false positive here is a five minute rename and a false negative is household
	 * pricing sitting on a retained topic until somebody notices.
	 */
	const FORBIDDEN_KEY_PATTERN = '/price|cost|value/i';

	/**
	 * The date the views use to mean "no due date at all": the stock overview substitutes
	 * 2888-12-31 for a null best before date and batteries_current substitutes 2999-12-31
	 * for a battery with no charge interval. Both are sentinels rather than dates, and
	 * publishing them would put a sensor eight hundred years in the future on a wall.
	 */
	const NO_DUE_DATE_SENTINEL_FROM = '2888-01-01';

	/**
	 * The whole snapshot: entity id => ['state' => scalar|null, 'attributes' => array].
	 *
	 * @param string|null $publishedAt An instant for the last_published sensor; defaults
	 *                                 to now. Passed in only so a test can pin it.
	 * @throws \Exception When the assembled payload carries a forbidden key
	 */
	public function Assemble(?string $publishedAt = null): array
	{
		$snapshot = [
			self::ENTITY_STOCK => $this->AssembleStock(),
			self::ENTITY_SHOPPING_LIST => $this->AssembleShoppingList(),
			self::ENTITY_NEXT_CHORE => $this->AssembleNextChore(),
			self::ENTITY_NEXT_BATTERY => $this->AssembleNextBattery(),
			self::ENTITY_NEXT_TASK => $this->AssembleNextTask(),
			self::ENTITY_PRODUCTS_DUE_SOON => $this->AssembleProductsDueSoon(),
			self::ENTITY_PRODUCTS_EXPIRED => $this->AssembleProductsExpired(),
			self::ENTITY_LAST_PUBLISHED => [
				'state' => self::ToIso8601($publishedAt ?? Instant::Now()),
				'attributes' => []
			]
		];

		self::AssertNoForbiddenKeys($snapshot);

		return $snapshot;
	}

	/**
	 * Products currently in stock: the count as the state, one row per product as
	 * attributes.
	 *
	 * Reads uihelper_stock_current_overview, the same view the stock overview page reads,
	 * filtered to a positive amount - the view also lists products below their minimum
	 * stock amount with an amount of zero, and "in stock" is the fact worth counting.
	 */
	private function AssembleStock(): array
	{
		$rows = [];

		foreach ($this->DB->uihelper_stock_current_overview()->where('amount > 0')->orderBy('product_name') as $row)
		{
			$rows[] = self::FilterRow(self::ENTITY_STOCK, [
				'product_id' => (int)$row['product_id'],
				'product_name' => (string)$row['product_name'],
				'amount' => ValueComparison::Normalise($row['amount']),
				'unit' => self::NullableString($row['qu_stock_name']),
				'best_before_date' => self::AsDateFact($row['best_before_date'])
			]);
		}

		return ['state' => count($rows), 'attributes' => ['products' => $rows]];
	}

	/**
	 * Everything on every shopping list: the item count as the state, one row per item as
	 * attributes.
	 *
	 * uihelper_shopping_list selects sl.* and therefore carries the item's note as well as
	 * three price columns; none of them survives the allow-list.
	 */
	private function AssembleShoppingList(): array
	{
		$rows = [];

		foreach ($this->DB->uihelper_shopping_list()->orderBy('product_name') as $row)
		{
			$rows[] = self::FilterRow(self::ENTITY_SHOPPING_LIST, [
				'shopping_list_id' => (int)$row['shopping_list_id'],
				'product_id' => $row['product_id'] === null ? null : (int)$row['product_id'],
				'product_name' => self::NullableString($row['product_name']),
				'amount' => ValueComparison::Normalise($row['amount']),
				'unit' => self::NullableString($row['qu_name'])
			]);
		}

		return ['state' => count($rows), 'attributes' => ['items' => $rows]];
	}

	/**
	 * The next chore due, as a timestamp, with every scheduled chore as attributes.
	 *
	 * chores_current returns null for a chore with period type "manually", which has no next
	 * execution to publish; those are left out rather than given a made up date.
	 */
	private function AssembleNextChore(): array
	{
		$rows = [];

		foreach ($this->DB->chores_current() as $row)
		{
			$due = self::AsTimestampFact($row['next_estimated_execution_time']);
			if ($due === null)
			{
				continue;
			}

			$rows[] = self::FilterRow(self::ENTITY_NEXT_CHORE, [
				'chore_id' => (int)$row['chore_id'],
				'chore_name' => (string)$row['chore_name'],
				'next_estimated_execution_time' => $due
			]);
		}

		return self::EarliestOf($rows, 'next_estimated_execution_time', 'chores');
	}

	/**
	 * The next battery charge due, as a timestamp, with every active battery as attributes.
	 *
	 * The battery name is not in batteries_current (which groups by id), so it comes from
	 * the batteries table the same way BatteriesService::GetCurrent() gets it.
	 */
	private function AssembleNextBattery(): array
	{
		$names = [];
		foreach ($this->DB->batteries()->where('active = 1') as $battery)
		{
			$names[(int)$battery['id']] = (string)$battery['name'];
		}

		$rows = [];

		foreach ($this->DB->batteries_current() as $row)
		{
			$due = self::AsTimestampFact($row['next_estimated_charge_time']);
			if ($due === null)
			{
				continue;
			}

			$batteryId = (int)$row['battery_id'];

			$rows[] = self::FilterRow(self::ENTITY_NEXT_BATTERY, [
				'battery_id' => $batteryId,
				'battery_name' => $names[$batteryId] ?? null,
				'next_estimated_charge_time' => $due
			]);
		}

		return self::EarliestOf($rows, 'next_estimated_charge_time', 'batteries');
	}

	/**
	 * The next task due, as a timestamp, with every open dated task as attributes.
	 *
	 * A task's due date is a date rather than a timestamp, and the tasks page treats it as
	 * due until the end of that day (TasksController::Overview compares against
	 * "Y-m-d 23:59:59"), so that is the instant published here. A task with no due date has
	 * no fact to publish and is left out.
	 */
	private function AssembleNextTask(): array
	{
		$rows = [];

		foreach ($this->DB->tasks_current() as $row)
		{
			$due = self::AsEndOfDayFact($row['due_date']);
			if ($due === null)
			{
				continue;
			}

			$rows[] = self::FilterRow(self::ENTITY_NEXT_TASK, [
				'task_id' => (int)$row['id'],
				'task_name' => (string)$row['name'],
				'due_date' => $due
			]);
		}

		return self::EarliestOf($rows, 'due_date', 'tasks');
	}

	/**
	 * How many products in stock come due within the household's "due soon" horizon.
	 *
	 * The two count sensors are the one deliberate exception to this class's own
	 * facts-not-derived-states rule, and question 1's Response makes it knowingly: a count of
	 * what is due within N days is a function of the clock, so it is a fact at publish time
	 * and stale afterwards. It is promoted anyway because it is the classic glanceable number
	 * a wall tablet exists to show. The exception is contained: no per-row derived boolean is
	 * published anywhere, so the stock summary's attributes still carry the dates a consumer
	 * needs to re-derive the same number locally whenever it likes, and that derivation
	 * remains the correct one after midnight.
	 *
	 * The semantics are StockService's rather than a second implementation of them -
	 * GetDueProducts(N, true) is what GET /api/stock/volatile answers with and what the stock
	 * overview page colours rows by.
	 */
	private function AssembleProductsDueSoon(): array
	{
		$dueProducts = StockService::GetInstance()->GetDueProducts(self::GetDueSoonDays(), true);

		return ['state' => count($dueProducts), 'attributes' => ['due_soon_days' => self::GetDueSoonDays()]];
	}

	/**
	 * How many products in stock are past an expiration date (due type 2), as opposed to a
	 * mere best before date. StockService::GetExpiredProducts() draws that line; see
	 * AssembleProductsDueSoon() for why a count is published despite being clock-dependent.
	 */
	private function AssembleProductsExpired(): array
	{
		return ['state' => count(StockService::GetInstance()->GetExpiredProducts()), 'attributes' => []];
	}

	/**
	 * The household's "due soon" horizon in days.
	 *
	 * Taken from the configured default rather than from the user who happens to have made
	 * the request: these topics are one household-wide snapshot with no reader identity, and
	 * a count that changed depending on who last wrote would be a worse number than a
	 * slightly arbitrary one.
	 */
	private static function GetDueSoonDays(): int
	{
		global $VICTUAL_DEFAULT_USER_SETTINGS;

		return (int)($VICTUAL_DEFAULT_USER_SETTINGS['stock_due_soon_days'] ?? 5);
	}

	/**
	 * One entity per product the household has opted in, keyed by object id ("product_42").
	 *
	 * Question 2's Response: per-product entities exist, but only for products flagged in
	 * mqtt_product_entities, so the Home Assistant entity count is chosen rather than
	 * inherited from the catalogue. Inactive products and flag rows whose product has been
	 * deleted are excluded here rather than filtered later - that exclusion is exactly what
	 * makes the publisher retract them, since the publisher's diff is "what this returns"
	 * against "what was published last time".
	 *
	 * The state is the amount in stock rather than a count, because being graphable is the
	 * whole reason question 2 promotes anything to its own entity: attributes are not
	 * recorded in Home Assistant's long-term statistics and states are.
	 *
	 * @return array<string, array> object id => ['product_id' => int, 'state' => float, 'attributes' => array]
	 */
	public function AssemblePerProductEntities(): array
	{
		$flagged = [];
		foreach ($this->DB->mqtt_product_entities() as $row)
		{
			$flagged[(int)$row['product_id']] = true;
		}

		if (count($flagged) === 0)
		{
			return [];
		}

		// The stock overview only lists what is in stock or below its minimum, so it cannot
		// be the source of the entity set: a flagged product with none of either is still an
		// entity the household asked for, and it reads zero rather than disappearing
		$stock = [];
		foreach ($this->DB->uihelper_stock_current_overview() as $row)
		{
			$stock[(int)$row['product_id']] = $row;
		}

		$units = [];
		foreach ($this->DB->quantity_units() as $unit)
		{
			$units[(int)$unit['id']] = (string)$unit['name'];
		}

		$entities = [];

		foreach ($this->DB->products()->where('active = 1')->orderBy('id') as $product)
		{
			$productId = (int)$product['id'];

			if (!isset($flagged[$productId]))
			{
				continue;
			}

			$row = $stock[$productId] ?? null;

			$entities[self::PER_PRODUCT_OBJECT_ID_PREFIX . $productId] = [
				'product_id' => $productId,
				'state' => $row === null ? 0.0 : ValueComparison::Normalise($row['amount']),
				'attributes' => self::FilterRow(self::ENTITY_PER_PRODUCT, [
					'product_id' => $productId,
					'product_name' => (string)$product['name'],
					'unit' => $units[(int)$product['qu_id_stock']] ?? null,
					'best_before_date' => $row === null ? null : self::AsDateFact($row['best_before_date'])
				])
			];
		}

		self::AssertNoForbiddenKeys($entities);

		return $entities;
	}

	/**
	 * The product ids of every flag row whose product is gone or inactive, so the publisher
	 * can drop the orphans once it has retracted their topics.
	 *
	 * This is the cascade that migrations/0257.sqlite.sql deliberately does not express as a
	 * foreign key: this schema does its cascades with triggers rather than constraints, and
	 * SQLite would not enforce a REFERENCES clause anyway without a pragma the application
	 * does not set.
	 *
	 * @return int[]
	 */
	public function GetOrphanedFlagProductIds(): array
	{
		$live = [];
		foreach ($this->DB->products()->where('active = 1') as $product)
		{
			$live[(int)$product['id']] = true;
		}

		$orphans = [];
		foreach ($this->DB->mqtt_product_entities() as $row)
		{
			$productId = (int)$row['product_id'];

			if (!isset($live[$productId]))
			{
				$orphans[] = $productId;
			}
		}

		return $orphans;
	}

	/**
	 * Turns a set of dated rows into a timestamp entity: the earliest date as the state, the
	 * rows sorted by date as the attributes.
	 *
	 * A state of null (nothing scheduled) is honest rather than convenient - Home Assistant
	 * shows it as unknown, which is what "there is no next chore" means.
	 */
	private static function EarliestOf(array $rows, string $dateKey, string $attributeKey): array
	{
		usort($rows, function ($a, $b) use ($dateKey)
		{
			return strcmp($a[$dateKey], $b[$dateKey]);
		});

		return [
			'state' => count($rows) === 0 ? null : $rows[0][$dateKey],
			'attributes' => [$attributeKey => $rows]
		];
	}

	/**
	 * Reduces an assembled row to the keys its entity is allowed to emit, after removing
	 * anything on the deny-list.
	 *
	 * Both directions are applied even though the rows above are built by hand: the value of
	 * a guard is that it holds when somebody later builds a row a different way.
	 */
	private static function FilterRow(string $entity, array $row): array
	{
		foreach (self::DENIED_COLUMNS as $denied)
		{
			unset($row[$denied]);
		}

		return array_intersect_key($row, array_flip(self::ALLOWED_ROW_KEYS[$entity]));
	}

	/**
	 * One entity's payload as the JSON that goes on its retained topic.
	 *
	 * The only thing this does beyond json_encode is force "attributes" to be a JSON object
	 * even when it is empty. PHP's empty array encodes as [], and Home Assistant's
	 * json_attributes_template expects a dictionary - an entity whose attributes happened to
	 * be empty would otherwise be the one that fails, which is the worst possible time to
	 * find out.
	 *
	 * Kept here rather than at the call sites so that what is published and what
	 * .devtools/mqtt/assemble.php prints for the both-engine diff are the same bytes.
	 */
	public static function EncodePayload(array $entity): string
	{
		return json_encode([
			'state' => $entity['state'],
			'attributes' => (object)($entity['attributes'] ?? [])
		]);
	}

	/**
	 * Throws when any key anywhere in the payload looks like money.
	 *
	 * The last line of defence, and the one .devtools/mqtt/price-guard.php exercises.
	 * Throwing means nothing is published, which is the right direction to fail in: a
	 * missing snapshot is repaired by the next write, a published price is not.
	 *
	 * @param mixed $payload
	 * @throws \Exception
	 */
	public static function AssertNoForbiddenKeys($payload, string $path = ''): void
	{
		if (!is_array($payload))
		{
			return;
		}

		foreach ($payload as $key => $value)
		{
			if (is_string($key) && preg_match(self::FORBIDDEN_KEY_PATTERN, $key) === 1)
			{
				throw new \Exception('Refusing to publish "' . $path . $key . '": the MQTT payload may not carry price, cost or value fields (docs/plans/18-mqtt-state-publication.md, question 8)');
			}

			self::AssertNoForbiddenKeys($value, $path . $key . '.');
		}
	}

	/**
	 * A date column as a published fact: the stored date, or null for the "never due"
	 * sentinel. Deliberately not converted to a timestamp - the data has no time of day in
	 * it, and inventing one would be publishing something the database does not know.
	 */
	private static function AsDateFact($value): ?string
	{
		$value = self::NullableString($value);

		if ($value === null || $value >= self::NO_DUE_DATE_SENTINEL_FROM)
		{
			return null;
		}

		return substr($value, 0, 10);
	}

	/**
	 * A timestamp column as ISO 8601 with an offset, which is what Home Assistant's
	 * "timestamp" device class requires. Null for the "never due" sentinel.
	 */
	private static function AsTimestampFact($value): ?string
	{
		$value = self::NullableString($value);

		if ($value === null || $value >= self::NO_DUE_DATE_SENTINEL_FROM)
		{
			return null;
		}

		return self::ToIso8601($value);
	}

	/**
	 * A date column as the instant that date stops being "today" in the configured zone.
	 */
	private static function AsEndOfDayFact($value): ?string
	{
		$value = self::NullableString($value);

		if ($value === null || $value >= self::NO_DUE_DATE_SENTINEL_FROM)
		{
			return null;
		}

		return Instant::EndOfServerDay(substr($value, 0, 10));
	}

	/**
	 * An instant in the API's wire rendering (ADR-0027 decision 2), which Home Assistant's
	 * "timestamp" device class accepts: RFC 3339 in UTC. The same string the REST API sends
	 * for the same value, so a template comparing the two compares like with like.
	 *
	 * The value is an instant from the database (already the wire rendering) or, from a
	 * caller that predates migration 0301, a wall clock in the configured zone.
	 */
	private static function ToIso8601(string $value): string
	{
		$instant = Instant::ParseStored($value);
		if ($instant === null)
		{
			throw new \Exception('Not a timestamp: ' . $value);
		}

		return Instant::ToWire($instant);
	}

	/**
	 * PDO hands back strings from one engine where the other gives numbers or nulls; this is
	 * the one place that difference is absorbed for text-shaped columns.
	 */
	private static function NullableString($value): ?string
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		return (string)$value;
	}
}
