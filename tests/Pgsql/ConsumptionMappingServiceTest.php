<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\ConsumptionException;
use Victual\Services\ConsumptionMappingService;
use Victual\Services\ConsumptionRecipeService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * ADR-0041 rule 4 as ConsumptionMappingService enforces it: a private mapping per
 * (user, source_system, medication_ref), validated at write, replaced whole, deleted together
 * with its voided and dismissed tombstones.
 */
class ConsumptionMappingServiceTest extends PgsqlSchemaTestCase
{
	private const OWNER = 9201;
	private const OTHER = 9202;
	private const VIEWER = 9203;

	private static PDO $db;
	private static ConsumptionMappingService $service;
	private static int $tablet;
	private static int $box;
	private static int $mystery;
	private static int $organizer;
	private static int $product;
	private static int $inactiveProduct;
	private static int $noBoxProduct;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$service = ConsumptionMappingService::GetInstance();

		foreach ([self::OWNER => 'owner', self::OTHER => 'other', self::VIEWER => 'viewer'] as $id => $name)
		{
			self::$db->exec("INSERT INTO users (id, username, password) VALUES ($id, 'cm-$name', 'fixture')");
		}

		$grant = self::$db->prepare('INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permission_hierarchy WHERE name = ?');
		foreach ([self::OWNER, self::OTHER] as $id)
		{
			$grant->execute([$id, 'STOCK_VIEW']);
			$grant->execute([$id, 'STOCK_CONSUME']);
		}
		$grant->execute([self::VIEWER, 'STOCK_VIEW']);

		self::$tablet = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('CM tablet') RETURNING id")->fetchColumn();
		self::$box = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('CM box') RETURNING id")->fetchColumn();
		self::$mystery = (int)self::$db->query("INSERT INTO quantity_units (name) VALUES ('CM mystery') RETURNING id")->fetchColumn();
		self::$organizer = (int)self::$db->query("INSERT INTO locations (name) VALUES ('CM organizer') RETURNING id")->fetchColumn();
		self::$product = self::product('CM product', true);
		self::$noBoxProduct = self::product('CM no box', false);
		self::$inactiveProduct = self::product('CM inactive', true);
		self::$db->exec('UPDATE products SET active = 0 WHERE id = ' . self::$inactiveProduct);
	}

	private static function product(string $name, bool $boxConversion): int
	{
		$statement = self::$db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?) RETURNING id');
		$statement->execute([$name, self::$organizer, self::$tablet, self::$tablet, self::$tablet, self::$tablet]);
		$id = (int)$statement->fetchColumn();

		if ($boxConversion)
		{
			self::$db->prepare('INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (?, ?, 10, ?)')->execute([self::$box, self::$tablet, $id]);
		}

		return $id;
	}

	private static function ref(): string
	{
		return 'med-' . bin2hex(random_bytes(4));
	}

	private static function productInput(array $overrides = []): array
	{
		return $overrides + ['product_id' => self::$product, 'location' => ['mode' => 'single'], 'effective_from' => '2026-10-09T00:00:00-04:00'];
	}

	private function expectRefusal(callable $work, int $status, string $code): void
	{
		try
		{
			$work();
		}
		catch (ConsumptionException $exception)
		{
			self::assertSame([$status, $code], [$exception->status, $exception->errorCode], $exception->getMessage());
			return;
		}

		self::fail("expected a $status $code refusal");
	}

	private static function recipe(int $owner): int
	{
		return ConsumptionRecipeService::GetInstance()->CreateRecipe('CM recipe ' . bin2hex(random_bytes(3)), null,
			[['product_id' => self::$product, 'amount' => 1, 'qu_id' => self::$tablet]], $owner);
	}

	// --- Create, replace, read, list, delete -------------------------------------------------

	public function testPutCreatesThenReplacesTheWholeMapping(): void
	{
		$ref = self::ref();
		$first = self::$service->Put(self::OWNER, 'victual-kit', $ref, self::productInput([
			'qu_id' => self::$box, 'quantity_factor' => 2, 'unit_labels' => ['tablet', 'pill'], 'default_quantity' => 1.5,
			'location' => ['mode' => 'fixed', 'location_id' => self::$organizer], 'effective_from' => '2026-10-09T00:00:00-04:00']));

		self::assertTrue($first['created']);
		self::assertSame([
			'source_system' => 'victual-kit', 'medication_ref' => $ref, 'recipe_id' => null, 'product_id' => self::$product, 'qu_id' => self::$box,
			'quantity_factor' => 2.0, 'unit_labels' => ['tablet', 'pill'], 'default_quantity' => 1.5,
			'location' => ['mode' => 'fixed', 'location_id' => self::$organizer], 'effective_from' => '2026-10-09T04:00:00.000000Z',
		], $first['mapping']);
		self::assertArrayNotHasKey('id', $first['mapping']);
		self::assertArrayNotHasKey('user_id', $first['mapping']);

		$id = self::$service->FindForUser(self::OWNER, 'victual-kit', $ref)['id'];
		self::$db->exec("UPDATE consumption_mappings SET updated_at = '2000-01-01' WHERE id = $id");

		$second = self::$service->Put(self::OWNER, 'victual-kit', $ref, self::productInput(['unit_labels' => ['capsule']]));
		self::assertFalse($second['created']);
		self::assertSame(['capsule'], $second['mapping']['unit_labels']);
		self::assertNull($second['mapping']['qu_id']);
		self::assertSame(1.0, $second['mapping']['quantity_factor']);
		self::assertNull($second['mapping']['default_quantity']);
		self::assertSame(['mode' => 'single', 'location_id' => null], $second['mapping']['location']);

		$row = self::$service->FindForUser(self::OWNER, 'victual-kit', $ref);
		self::assertSame($id, $row['id']);
		self::assertGreaterThan('2000-01-02', $row['updated_at']);
		self::assertSame($second['mapping'], self::$service->Get(self::OWNER, 'victual-kit', $ref));
	}

	public function testListIsOrderedAndPrivate(): void
	{
		$recipeId = self::recipe(self::OWNER);
		self::$service->Put(self::OWNER, 'ls-b', 'm2', ['recipe_id' => $recipeId, 'location' => ['mode' => 'explicit'], 'effective_from' => '2026-01-01T00:00:00Z']);
		self::$service->Put(self::OWNER, 'ls-a', 'm9', self::productInput());
		self::$service->Put(self::OWNER, 'ls-a', 'm1', self::productInput());
		self::$service->Put(self::OTHER, 'ls-a', 'mx', self::productInput());

		$mine = array_values(array_filter(self::$service->ListMappings(self::OWNER), fn($m) => str_starts_with($m['source_system'], 'ls-')));
		self::assertSame([['ls-a', 'm1'], ['ls-a', 'm9'], ['ls-b', 'm2']], array_map(fn($m) => [$m['source_system'], $m['medication_ref']], $mine));
		self::assertSame($recipeId, $mine[2]['recipe_id']);
		self::assertNull($mine[2]['product_id']);

		$theirs = array_filter(self::$service->ListMappings(self::OTHER), fn($m) => str_starts_with($m['source_system'], 'ls-'));
		self::assertCount(1, $theirs);
	}

	public function testDeleteRemovesTheMapping(): void
	{
		$ref = self::ref();
		self::$service->Put(self::OWNER, 'del-a', $ref, self::productInput());
		self::$service->Delete(self::OWNER, 'del-a', $ref);

		self::assertNull(self::$service->FindForUser(self::OWNER, 'del-a', $ref));
		$this->expectRefusal(fn() => self::$service->Get(self::OWNER, 'del-a', $ref), 404, 'not_found');
		$this->expectRefusal(fn() => self::$service->Delete(self::OWNER, 'del-a', $ref), 404, 'not_found');
	}

	public function testAnotherUserHasAnIndependentMapping(): void
	{
		$ref = self::ref();
		self::$service->Put(self::OWNER, 'iso', $ref, self::productInput(['unit_labels' => ['mine']]));

		$this->expectRefusal(fn() => self::$service->Get(self::OTHER, 'iso', $ref), 404, 'not_found');
		$this->expectRefusal(fn() => self::$service->Delete(self::OTHER, 'iso', $ref), 404, 'not_found');
		self::assertNull(self::$service->FindForUser(self::OTHER, 'iso', $ref));

		$theirs = self::$service->Put(self::OTHER, 'iso', $ref, self::productInput(['unit_labels' => ['theirs']]));
		self::assertTrue($theirs['created']);
		self::assertSame(['mine'], self::$service->Get(self::OWNER, 'iso', $ref)['unit_labels']);

		self::$service->Delete(self::OTHER, 'iso', $ref);
		self::assertSame(['mine'], self::$service->Get(self::OWNER, 'iso', $ref)['unit_labels']);
	}

	// --- Authority and recipe targets --------------------------------------------------------

	public function testPutNeedsStockViewAndConsume(): void
	{
		$this->expectRefusal(fn() => self::$service->Put(self::VIEWER, 'perm', self::ref(), self::productInput()), 403, 'permission_missing');
	}

	public function testRecipeTargetNeedsTheConsumeRight(): void
	{
		$recipes = ConsumptionRecipeService::GetInstance();
		$recipeId = self::recipe(self::OWNER);
		$input = ['recipe_id' => $recipeId, 'location' => ['mode' => 'single'], 'effective_from' => '2026-01-01T00:00:00Z'];
		$ref = self::ref();

		$this->expectRefusal(fn() => self::$service->Put(self::OTHER, 'rec', $ref, $input), 404, 'not_found');

		$recipes->SetShare($recipeId, self::OTHER, ['edit' => true], self::OWNER);
		$this->expectRefusal(fn() => self::$service->Put(self::OTHER, 'rec', $ref, $input), 404, 'not_found');

		$recipes->SetShare($recipeId, self::OTHER, ['consume' => true], self::OWNER);
		$put = self::$service->Put(self::OTHER, 'rec', $ref, $input);
		self::assertTrue($put['created']);
		self::assertSame($recipeId, $put['mapping']['recipe_id']);

		$this->expectRefusal(fn() => self::$service->Put(self::OWNER, 'rec', $ref, ['recipe_id' => $recipeId + 1000] + $input), 404, 'not_found');
	}

	// --- Validation --------------------------------------------------------------------------

	public static function invalidRequests(): array
	{
		$ok = ['product_id' => 1, 'location' => ['mode' => 'single'], 'effective_from' => '2026-10-09T00:00:00Z'];
		$long = str_repeat('x', 65);

		return [
			'both targets' => [$ok + ['recipe_id' => 5]],
			'neither target' => [['location' => ['mode' => 'single'], 'effective_from' => '2026-10-09T00:00:00Z']],
			'string product id' => [['product_id' => '1'] + $ok],
			'qu_id on recipe' => [['product_id' => null, 'recipe_id' => 5, 'qu_id' => 1] + $ok],
			'missing location' => [['location' => null] + $ok],
			'location not array' => [['location' => 'single'] + $ok],
			'unknown mode' => [['location' => ['mode' => 'anywhere']] + $ok],
			'missing effective_from' => [['effective_from' => null] + $ok],
			'effective_from not string' => [['effective_from' => 5] + $ok],
			'labels not list' => [['unit_labels' => 'tablet'] + $ok],
			'labels keyed' => [['unit_labels' => ['a' => 'tablet']] + $ok],
			'labels duplicate' => [['unit_labels' => ['a', 'a']] + $ok],
			'label empty' => [['unit_labels' => ['']] + $ok],
			'label too long' => [['unit_labels' => [$long]] + $ok],
			'label not string' => [['unit_labels' => [5]] + $ok],
			'too many labels' => [['unit_labels' => array_map(fn($i) => "l$i", range(1, 51))] + $ok],
			'factor zero' => [['quantity_factor' => 0] + $ok],
			'factor negative' => [['quantity_factor' => -1] + $ok],
			'factor string' => [['quantity_factor' => '2'] + $ok],
			'factor infinite' => [['quantity_factor' => INF] + $ok],
			'factor nan' => [['quantity_factor' => NAN] + $ok],
			'default zero' => [['default_quantity' => 0] + $ok],
			'default string' => [['default_quantity' => 'one'] + $ok],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('invalidRequests')]
	public function testMalformedInputIsRefused(array $input): void
	{
		if (($input['product_id'] ?? null) === 1)
		{
			$input['product_id'] = self::$product;
		}

		$this->expectRefusal(fn() => self::$service->Put(self::OWNER, 'bad', self::ref(), $input), 400, 'invalid_request');
	}

	public function testMalformedIdentityIsRefused(): void
	{
		foreach (['', 'Upper', '-lead', str_repeat('a', 33), 'has space', 'manual'] as $system)
		{
			$this->expectRefusal(fn() => self::$service->Put(self::OWNER, $system, self::ref(), self::productInput()), 400, 'invalid_request');
		}

		foreach (['', 'has space', 'slash/ed', str_repeat('a', 129)] as $ref)
		{
			$this->expectRefusal(fn() => self::$service->Put(self::OWNER, 'ident', $ref, self::productInput()), 400, 'invalid_request');
		}

		self::assertTrue(self::$service->Put(self::OWNER, 'a' . str_repeat('b', 30) . '.', str_repeat('A', 128), self::productInput())['created']);
		self::assertTrue(self::$service->Put(self::OWNER, 'ident', 'A.b_c:d-e', self::productInput(['unit_labels' => array_map(fn($i) => "l$i", range(1, 50))]))['created']);
	}

	public function testSemanticallyInvalidMappingsAreRefused(): void
	{
		$ref = self::ref();
		$refuse = fn(array $input) => $this->expectRefusal(fn() => self::$service->Put(self::OWNER, 'sem', $ref, $input), 422, 'invalid_mapping');

		$refuse(self::productInput(['product_id' => 999999]));
		$refuse(self::productInput(['product_id' => self::$inactiveProduct]));
		$refuse(self::productInput(['qu_id' => 999999]));
		$refuse(self::productInput(['product_id' => self::$noBoxProduct, 'qu_id' => self::$box]));
		$refuse(self::productInput(['qu_id' => self::$mystery]));
		$refuse(self::productInput(['location' => ['mode' => 'fixed', 'location_id' => 999999]]));
		$refuse(self::productInput(['location' => ['mode' => 'fixed']]));
		$refuse(self::productInput(['location' => ['mode' => 'single', 'location_id' => self::$organizer]]));
		$refuse(self::productInput(['location' => ['mode' => 'explicit', 'location_id' => self::$organizer]]));

		foreach (['2026-10-09', '2026-10-09T00:00:00', '2026-10-09 00:00:00Z', 'yesterday', '2026-02-30T00:00:00Z', '2026-10-09T25:00:00Z', '2026-10-09T00:00:00+24:00'] as $when)
		{
			$refuse(self::productInput(['effective_from' => $when]));
		}

		self::assertNull(self::$service->FindForUser(self::OWNER, 'sem', $ref));

		// The stock unit itself needs no conversion; a unit with an entered one is accepted.
		self::assertSame(self::$tablet, self::$service->Put(self::OWNER, 'sem', $ref, self::productInput(['qu_id' => self::$tablet]))['mapping']['qu_id']);
		self::assertSame(self::$box, self::$service->Put(self::OWNER, 'sem', $ref, self::productInput(['qu_id' => self::$box]))['mapping']['qu_id']);
		self::assertSame('2026-10-09T03:30:00.123000Z', self::$service->Put(self::OWNER, 'sem', $ref, self::productInput(['effective_from' => '2026-10-09T09:00:00.123+05:30']))['mapping']['effective_from']);
	}

	// --- Unit labels -------------------------------------------------------------------------

	public function testUnitLabelsRoundTripAwkwardCharacters(): void
	{
		$labels = ['a,b', 'say "hi"', 'back\\slash', 'trailing\\', ' spaced out ', '{braces}', 'NULL', 'null', 'ünï', "tab\there", 'it\'s', '\\"', 'x'];
		$ref = self::ref();

		$put = self::$service->Put(self::OWNER, 'lbl', $ref, self::productInput(['unit_labels' => $labels]));
		self::assertSame($labels, $put['mapping']['unit_labels']);
		self::assertSame($labels, self::$service->FindForUser(self::OWNER, 'lbl', $ref)['unit_labels']);
		self::assertSame($labels, self::$service->ListMappings(self::OWNER)[array_search($ref, array_column(self::$service->ListMappings(self::OWNER), 'medication_ref'), true)]['unit_labels']);

		self::assertSame($labels, ConsumptionMappingService::ParseTextArray(ConsumptionMappingService::FormatTextArray($labels)));
		self::assertSame([], ConsumptionMappingService::ParseTextArray('{}'));
		self::assertSame(['a', 'b c', 'd"e'], ConsumptionMappingService::ParseTextArray('{a,"b c","d\\"e"}'));
		self::assertSame('{}', ConsumptionMappingService::FormatTextArray([]));
	}

	public function testAddUnitLabelIsIdempotentAndAwkwardSafe(): void
	{
		$ref = self::ref();
		self::$service->Put(self::OWNER, 'add', $ref, self::productInput(['unit_labels' => ['tablet']]));
		$id = self::$service->FindForUser(self::OWNER, 'add', $ref)['id'];

		self::$service->AddUnitLabel($id, 'pill, "large"');
		self::$service->AddUnitLabel($id, 'pill, "large"');
		self::$service->AddUnitLabel($id, 'tablet');
		self::assertSame(['tablet', 'pill, "large"'], self::$service->FindForUser(self::OWNER, 'add', $ref)['unit_labels']);

		$this->expectRefusal(fn() => self::$service->AddUnitLabel($id, ''), 400, 'invalid_request');
		$this->expectRefusal(fn() => self::$service->AddUnitLabel($id, str_repeat('x', 65)), 400, 'invalid_request');
		$this->expectRefusal(fn() => self::$service->AddUnitLabel($id + 100000, 'x'), 404, 'not_found');

		self::$service->Put(self::OWNER, 'add', $ref, self::productInput(['unit_labels' => array_map(fn($i) => "l$i", range(1, 50))]));
		self::$service->AddUnitLabel($id, 'l1');
		$this->expectRefusal(fn() => self::$service->AddUnitLabel($id, 'l51'), 422, 'invalid_mapping');
	}

	// --- Stock amount ------------------------------------------------------------------------

	public function testStockAmountFor(): void
	{
		$ref = self::ref();
		self::$service->Put(self::OWNER, 'amt', $ref, self::productInput(['quantity_factor' => 0.5]));
		$row = self::$service->FindForUser(self::OWNER, 'amt', $ref);
		self::assertEqualsWithDelta(1.5, self::$service->StockAmountFor($row, 3), 1e-9);

		self::$service->Put(self::OWNER, 'amt', $ref, self::productInput(['qu_id' => self::$box, 'quantity_factor' => 2]));
		$row = self::$service->FindForUser(self::OWNER, 'amt', $ref);
		self::assertEqualsWithDelta(60.0, self::$service->StockAmountFor($row, 3), 1e-9);

		self::$service->Put(self::OWNER, 'amt', $ref, self::productInput(['qu_id' => self::$tablet]));
		self::assertEqualsWithDelta(3.0, self::$service->StockAmountFor(self::$service->FindForUser(self::OWNER, 'amt', $ref), 3), 1e-9);

		$this->expectRefusal(fn() => self::$service->StockAmountFor($row, 0.0), 422, 'invalid_mapping');
		$this->expectRefusal(fn() => self::$service->StockAmountFor($row, INF), 422, 'invalid_mapping');

		// The conversion or product disappearing after the mapping was written.
		$broken = ['qu_id' => self::$mystery] + $row;
		$this->expectRefusal(fn() => self::$service->StockAmountFor($broken, 1), 422, 'invalid_mapping');
		$this->expectRefusal(fn() => self::$service->StockAmountFor(['product_id' => self::$inactiveProduct] + $row, 1), 422, 'invalid_mapping');
		$this->expectRefusal(fn() => self::$service->StockAmountFor(['product_id' => null] + $row, 1), 422, 'invalid_mapping');
	}

	// --- Deleting with tombstones ------------------------------------------------------------

	private static function event(string $system, string $ref, string $state, string $suffix): int
	{
		$statement = self::$db->prepare("INSERT INTO consumption_events (user_id, source_system, source_event_id, state, occurred_at, medication_ref, transaction_id, voided_at)
			VALUES (?, ?, ?, ?, now(), ?, ?, CASE WHEN ? = 'voided' THEN now() END) RETURNING id");
		$statement->execute([self::OWNER, $system, "$ref-$suffix", $state, $ref, $state === 'booked' ? 'cm-tx-' . $suffix : null, $state]);

		return (int)$statement->fetchColumn();
	}

	public function testDeleteRemovesTombstonesAndKeepsBookedEvents(): void
	{
		$ref = self::ref();
		$otherRef = self::ref();
		self::$service->Put(self::OWNER, 'tomb', $ref, self::productInput());
		self::$service->Put(self::OWNER, 'tomb', $otherRef, self::productInput());
		$mappingId = self::$service->FindForUser(self::OWNER, 'tomb', $ref)['id'];

		$voided = self::event('tomb', $ref, 'voided', 'v');
		$dismissed = self::event('tomb', $ref, 'dismissed', 'd');
		$booked = self::event('tomb', $ref, 'booked', 'b');
		self::$db->exec("UPDATE consumption_events SET mapping_id = $mappingId WHERE id = $booked");
		$otherVoided = self::event('tomb', $otherRef, 'voided', 'v');
		$otherSystem = self::event('tomb2', $ref, 'dismissed', 'd');

		self::$service->Delete(self::OWNER, 'tomb', $ref);

		$left = self::$db->query("SELECT id, mapping_id FROM consumption_events WHERE id IN ($voided, $dismissed, $booked, $otherVoided, $otherSystem) ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
		self::assertEqualsCanonicalizing([$booked, $otherVoided, $otherSystem], array_keys($left));
		self::assertNull($left[$booked]);
		self::assertNotNull(self::$service->FindForUser(self::OWNER, 'tomb', $otherRef));
	}
}
