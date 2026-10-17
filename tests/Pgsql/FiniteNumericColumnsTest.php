<?php

namespace Victual\Tests\Pgsql;

use PDO;
use PDOException;
use Victual\Services\ApiKeyService;
use Victual\Services\Database\DatabaseImporter;
use Victual\Services\DatabaseMigrationService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Issue #916: migration 0313's `<table>_<column>_finite` CHECKs, which refuse NaN and ±Infinity
 * in every double precision and numeric column that had no finite-number check. Maintainer
 * decisions D4 (an invalid supplied value is a 400 and changes nothing) and D7 (gate R2:
 * refuse non-finite values, report existing rows without rewriting them).
 *
 * The preflight half follows ChoreConsumeFlagCheckTest: an app upgrade runs the migration over
 * live data, so the test drops the constraints and the migrations row, seeds offenders, and
 * calls MigrateDatabase(). The legacy-import half is ImportFiniteNumbersTest.
 */
class FiniteNumericColumnsTest extends PgsqlSchemaTestCase
{
	private const USER_ID = 9916;

	private static PDO $db;
	private static string $apiKey;
	private static int $locationId;
	private static int $quId;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$db = self::Pdo();
		self::$db->exec('INSERT INTO users(id, username, password) VALUES (' . self::USER_ID . ", 'finite-user', 'fixture')");
		self::$db->exec('INSERT INTO user_permissions (user_id, permission_id) SELECT ' . self::USER_ID . " , id FROM permission_hierarchy WHERE name = 'ADMIN'");
		self::$apiKey = bin2hex(random_bytes(25));
		$statement = self::$db->prepare("INSERT INTO api_keys (api_key, key_hint, user_id, expires, key_type) VALUES (?, ?, ?, now() + interval '30 days', ?)");
		$statement->execute([ApiKeyService::HashKey(self::$apiKey), substr(self::$apiKey, -4), self::USER_ID, ApiKeyService::API_KEY_TYPE_DEFAULT]);

		self::$locationId = (int)self::$db->query("INSERT INTO locations (name) VALUES ('finite-location') RETURNING id")->fetchColumn();
		self::$quId = (int)self::$db->query("INSERT INTO quantity_units (name, name_plural) VALUES ('finite-piece', 'finite-pieces') RETURNING id")->fetchColumn();
	}

	protected function setUp(): void
	{
		// Every test starts from, and the preflight tests end in, a fully migrated schema.
		DatabaseMigrationService::GetInstance()->MigrateDatabase();
	}

	private static function product(string $name, string $minStock = '0'): int
	{
		$statement = self::$db->prepare("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, min_stock_amount) VALUES (?, ?, ?, ?, ?::double precision) RETURNING id");
		$statement->execute([$name . ' ' . uniqid(), self::$locationId, self::$quId, self::$quId, $minStock]);
		return (int)$statement->fetchColumn();
	}

	private static function sqlState(string $sql): string
	{
		try { self::$db->exec($sql); }
		catch (PDOException $ex) { return (string)$ex->getCode(); }
		return 'accepted';
	}

	/** @return string[] "table.column" for every constraint migration 0313 adds, as it stands */
	private static function finiteConstraints(): array
	{
		return self::$db->query("SELECT conname FROM pg_constraint WHERE connamespace = current_schema()::regnamespace AND contype = 'c' AND conname LIKE '%\\_finite' ORDER BY conname")->fetchAll(PDO::FETCH_COLUMN);
	}

	private static function dropFiniteConstraints(): void
	{
		foreach (DatabaseImporter::FINITE_NUMBER_COLUMNS as $table => $columns)
		{
			foreach ($columns as $column)
			{
				self::$db->exec("ALTER TABLE $table DROP CONSTRAINT IF EXISTS {$table}_{$column}_finite");
			}
		}
		self::$db->exec('DELETE FROM migrations WHERE migration=313');
	}

	private static function expectedConstraintNames(): array
	{
		$names = [];
		foreach (DatabaseImporter::FINITE_NUMBER_COLUMNS as $table => $columns)
		{
			foreach ($columns as $column)
			{
				$names[] = "{$table}_{$column}_finite";
			}
		}
		sort($names);
		return $names;
	}

	// -------------------------------------------------------------------------------
	// The constraints
	// -------------------------------------------------------------------------------

	/**
	 * The drift guard: a double precision, real or numeric column added to a base table later
	 * without a finite-number check fails here, by name. The eight columns that had one before
	 * 0313 (a positive or non-zero amount, or a conversion factor) count, because `> 0` refuses
	 * -Infinity just as `> '-Infinity'` does.
	 */
	public function testEveryFloatAndNumericColumnOfABaseTableRefusesNonFiniteValues(): void
	{
		$columns = self::$db->query(
			"SELECT c.table_name, c.column_name FROM information_schema.columns c
			 JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
			 WHERE c.table_schema = current_schema() AND t.table_type = 'BASE TABLE' AND c.data_type IN ('double precision', 'real', 'numeric')
			 ORDER BY 1, 2"
		)->fetchAll(PDO::FETCH_NUM);
		self::assertGreaterThan(40, count($columns));

		$unprotected = [];
		foreach ($columns as [$table, $column])
		{
			$statement = self::$db->prepare("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'c' AND convalidated");
			$statement->execute([$table]);
			$protected = false;
			foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $definition)
			{
				$upper = str_contains($definition, "($column < 'Infinity'::");
				$lower = str_contains($definition, "($column > '-Infinity'::") || str_contains($definition, "($column > (0)::");
				$protected = $protected || ($upper && $lower);
			}
			if (!$protected)
			{
				$unprotected[] = "$table.$column";
			}
		}

		self::assertSame([], $unprotected, 'every one of these columns needs a CHECK refusing NaN and both infinities (see migration 0313)');
	}

	public function testTheMigrationAndTheImporterNameTheSameColumns(): void
	{
		self::assertSame(self::expectedConstraintNames(), self::finiteConstraints());
		self::assertSame(1, (int)self::$db->query('SELECT count(*) FROM migrations WHERE migration=313')->fetchColumn());
		self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM pg_constraint WHERE connamespace = current_schema()::regnamespace AND conname LIKE '%\\_finite' AND NOT convalidated")->fetchColumn(), 'every one is validated, never NOT VALID');
	}

	public function testNonFiniteValuesAreRefusedAndFiniteOnesAndNullAreKept(): void
	{
		$id = self::product('finite kept', '2.5');
		foreach (["'NaN'", "'Infinity'", "'-Infinity'", "'inf'", "'-inf'"] as $literal)
		{
			self::assertSame('23514', self::sqlState("UPDATE products SET min_stock_amount = $literal WHERE id = $id"), "products.min_stock_amount = $literal");
			self::assertSame('23514', self::sqlState("UPDATE products SET calories = $literal WHERE id = $id"), "products.calories = $literal");
			// numeric, the other type the template covers
			self::assertSame('23514', self::sqlState("INSERT INTO storage_classes (name, max_temp_c) VALUES ('finite bad " . uniqid() . "', $literal)"), "storage_classes.max_temp_c = $literal");
		}

		self::assertSame('accepted', self::sqlState("UPDATE products SET calories = NULL, min_stock_amount = -1.7976931348623157e308 WHERE id = $id"));
		self::assertSame('accepted', self::sqlState("INSERT INTO storage_classes (name, max_temp_c, min_temp_c) VALUES ('finite good " . uniqid() . "', 99999.5, NULL)"));
		self::assertSame(-1.7976931348623157e308, (float)self::$db->query("SELECT min_stock_amount FROM products WHERE id = $id")->fetchColumn());
	}

	// -------------------------------------------------------------------------------
	// The API
	// -------------------------------------------------------------------------------

	/**
	 * @return array{status: int, body: string}
	 */
	private static function send(string $method, string $path, ?array $body = null, ?string $rawBody = null): array
	{
		$spec = ['method' => $method, 'path' => $path, 'headers' => ['VICTUAL-API-KEY' => self::$apiKey]];
		if ($rawBody !== null)
		{
			$spec['rawBody'] = $rawBody;
			$spec['headers']['Content-Type'] = 'application/json';
		}
		elseif ($body !== null)
		{
			$spec['body'] = $body;
		}

		$env = array_merge(array_filter(array_merge($_SERVER, $_ENV), 'is_scalar'), [
			'RBAC_TEST_SCHEMA' => self::Schema(),
			'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
			'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
			'PGHOST' => getenv('PGHOST'),
			'PGPORT' => getenv('PGPORT'),
			'PGUSER' => getenv('PGUSER'),
			'PGPASSWORD' => getenv('PGPASSWORD'),
			'VICTUAL_ROOT' => VICTUAL_ROOT_PATH
		]);
		$process = proc_open([PHP_BINARY, __DIR__ . '/request-subprocess-helper.php', base64_encode(json_encode($spec))], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$start = strrpos($output, '{"status"');
		$result = $start === false ? null : json_decode(substr($output, $start), true);
		self::assertIsArray($result, "the request helper printed no JSON for $method $path. stdout: $output\nstderr: $errors");
		return $result;
	}

	/** Raw JSON values, so the out-of-range number reaches the server as written. */
	public static function nonFiniteBodies(): array
	{
		return [
			'"NaN"' => ['"NaN"'],
			'"Infinity"' => ['"Infinity"'],
			'"-Infinity"' => ['"-Infinity"'],
			'"inf"' => ['"inf"'],
			'1e309' => ['1e309'],
			'-1e309' => ['-1e309'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('nonFiniteBodies')]
	public function testTheGenericApiRefusesNonFiniteValuesWith400AndWritesNothing(string $literal): void
	{
		// The issue's first reproduction: POST /api/objects/products with min_stock_amount "NaN"
		$name = 'finite api ' . uniqid();
		$create = self::send('POST', '/api/objects/products', null, sprintf('{"name":"%s","location_id":%d,"qu_id_purchase":%d,"qu_id_stock":%d,"min_stock_amount":%s}', $name, self::$locationId, self::$quId, self::$quId, $literal));
		self::assertSame(400, $create['status'], $create['body']);
		self::assertStringContainsString('The min stock amount must be a finite number', $create['body']);
		self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM products WHERE name = " . self::$db->quote($name))->fetchColumn());

		$id = self::product('finite api edit', '3');
		$before = self::$db->query("SELECT * FROM products WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
		$update = self::send('PUT', '/api/objects/products/' . $id, null, sprintf('{"calories":%s}', $literal));
		self::assertSame(400, $update['status'], $update['body']);
		self::assertStringContainsString('The calories must be a finite number', $update['body']);
		self::assertSame($before, self::$db->query("SELECT * FROM products WHERE id = $id")->fetch(PDO::FETCH_ASSOC));

		$read = self::send('GET', '/api/objects/products/' . $id);
		self::assertSame(200, $read['status'], 'and the record can still be read');
	}

	public function testANumericColumnIsRefusedTheSameWay(): void
	{
		$name = 'finite class ' . uniqid();
		$create = self::send('POST', '/api/objects/storage_classes', null, sprintf('{"name":"%s","max_temp_c":"Infinity"}', $name));
		self::assertSame(400, $create['status'], $create['body']);
		self::assertStringContainsString('The max temp c must be a finite number', $create['body']);
		self::assertSame(0, (int)self::$db->query("SELECT count(*) FROM storage_classes WHERE name = " . self::$db->quote($name))->fetchColumn());
	}

	public function testFiniteValuesStillWrite(): void
	{
		$id = self::product('finite api fine', '1');
		$update = self::send('PUT', '/api/objects/products/' . $id, null, '{"min_stock_amount":"2.5","calories":120}');
		self::assertSame(204, $update['status'], $update['body']);
		self::assertSame(2.5, (float)self::$db->query("SELECT min_stock_amount FROM products WHERE id = $id")->fetchColumn());
	}

	// -------------------------------------------------------------------------------
	// The upgrade
	// -------------------------------------------------------------------------------

	public function testExistingNonFiniteRowsRefuseTheUpgradeWithOneReportAndAreNotRewritten(): void
	{
		$db = self::$db;
		self::dropFiniteConstraints();
		$good = self::product('upgrade good', '1');
		$nan = self::product('upgrade nan', 'NaN');
		$infinite = self::product('upgrade infinite', '0');
		$db->exec("UPDATE products SET calories = 'Infinity' WHERE id = $infinite");
		$classId = (int)$db->query("INSERT INTO storage_classes (name, min_temp_c) VALUES ('upgrade class " . uniqid() . "', '-Infinity') RETURNING id")->fetchColumn();
		$before = [
			$db->query('SELECT id, min_stock_amount::text, calories::text FROM products ORDER BY id')->fetchAll(PDO::FETCH_NUM),
			$db->query('SELECT id, min_temp_c::text FROM storage_classes ORDER BY id')->fetchAll(PDO::FETCH_NUM),
		];

		try
		{
			DatabaseMigrationService::GetInstance()->MigrateDatabase();
			self::fail('The upgrade must refuse while a numeric column holds NaN or an infinity');
		}
		catch (PDOException $ex)
		{
			$message = $ex->getMessage();
			self::assertStringContainsString('Migration refused: 3 value(s) are NaN or infinite', $message);
			// One report for every column, so a repair of one does not reveal the next on rerun
			self::assertStringContainsString("products.calories: 1 row(s), sample (id, value): ($infinite, Infinity)", $message);
			self::assertStringContainsString("products.min_stock_amount: 1 row(s), sample (id, value): ($nan, NaN)", $message);
			self::assertStringContainsString("storage_classes.min_temp_c: 1 row(s), sample (id, value): ($classId, -Infinity)", $message);
			self::assertStringContainsString('No row has been changed', $message);
			self::assertStringContainsString('Choose an explicit repair and rerun migration', $message);
			self::assertStringContainsString("SELECT id, min_stock_amount FROM products WHERE NOT (min_stock_amount > '-Infinity' AND min_stock_amount < 'Infinity') ORDER BY id;", $message);
			self::assertStringNotContainsString("($good,", $message);
		}

		self::assertFalse($db->inTransaction());
		self::assertSame($before, [
			$db->query('SELECT id, min_stock_amount::text, calories::text FROM products ORDER BY id')->fetchAll(PDO::FETCH_NUM),
			$db->query('SELECT id, min_temp_c::text FROM storage_classes ORDER BY id')->fetchAll(PDO::FETCH_NUM),
		], 'no value was rewritten');
		self::assertSame(0, (int)$db->query('SELECT count(*) FROM migrations WHERE migration=313')->fetchColumn());
		self::assertSame([], self::finiteConstraints());

		$db->exec("UPDATE products SET min_stock_amount = 0 WHERE id = $nan; UPDATE products SET calories = NULL WHERE id = $infinite; UPDATE storage_classes SET min_temp_c = NULL WHERE id = $classId");
		DatabaseMigrationService::GetInstance()->MigrateDatabase();

		self::assertSame(self::expectedConstraintNames(), self::finiteConstraints());
		self::assertSame(1, (int)$db->query('SELECT count(*) FROM migrations WHERE migration=313')->fetchColumn());
	}

	public function testTheSampleIsLimitedToTenRowsButTheCountIsComplete(): void
	{
		self::dropFiniteConstraints();
		for ($i = 0; $i < 12; $i++) { self::product('upgrade many ' . $i, 'Infinity'); }

		try
		{
			DatabaseMigrationService::GetInstance()->MigrateDatabase();
			self::fail('The upgrade must refuse');
		}
		catch (PDOException $ex)
		{
			self::assertStringContainsString('products.min_stock_amount: 12 row(s)', $ex->getMessage());
			preg_match('/products\.min_stock_amount: 12 row\(s\), sample \(id, value\): ([^.]*)\./', $ex->getMessage(), $match);
			self::assertSame(10, substr_count($match[1] ?? '', ', Infinity)'));
		}
		finally
		{
			self::$db->exec("UPDATE products SET min_stock_amount = 0 WHERE NOT (min_stock_amount > '-Infinity' AND min_stock_amount < 'Infinity')");
			DatabaseMigrationService::GetInstance()->MigrateDatabase();
		}
	}

	/** ADR-0029's lock-timeout case: a booking holding a product row lock makes the migration give up, not queue. */
	public function testMigrationGivesUpOnALockedRowAndRollsBackCleanly(): void
	{
		$db = self::$db;
		self::dropFiniteConstraints();
		$id = self::product('lock held', '1');

		$other = new PDO('pgsql:host=' . getenv('PGHOST') . ';port=' . getenv('PGPORT') . ';dbname=' . getenv('PHPUNIT_DB_NAME'),
			getenv('PGUSER'), getenv('PGPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$other->exec('SET search_path TO ' . self::Schema() . ', public');
		$other->beginTransaction();

		try
		{
			$other->query('SELECT id FROM products WHERE id = ' . $id . ' FOR UPDATE')->fetchAll();
			$start = microtime(true);
			try
			{
				DatabaseMigrationService::GetInstance()->MigrateDatabase();
				self::fail('The migration must give up while a product row is locked');
			}
			catch (PDOException $ex)
			{
				self::assertSame('55P03', (string)$ex->getCode(), $ex->getMessage());
			}
			self::assertLessThan(20, microtime(true) - $start, 'the wait is bounded by lock_timeout');

			self::assertFalse($db->inTransaction());
			self::assertSame(0, (int)$db->query('SELECT count(*) FROM migrations WHERE migration=313')->fetchColumn());
			self::assertSame([], self::finiteConstraints());
		}
		finally
		{
			$other->rollBack();
			DatabaseMigrationService::GetInstance()->MigrateDatabase();
		}

		self::assertSame(self::expectedConstraintNames(), self::finiteConstraints());
	}
}
