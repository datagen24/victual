<?php

namespace Victual\Tests\Pgsql;

use PDO;
use Victual\Services\Database\DatabaseImporter;
use Victual\Services\DatabaseService;
use Victual\Services\Labels\LabelPrintJobService;
use Victual\Tests\Support\PgsqlSchemaTestCase;

/**
 * Regression coverage for issue #487's importer findings, #496 (H7) and #518 (M18).
 *
 * H7(a): a source honestly carrying a multi-level product chain (root -> middle -> leaf) -
 * possible because the *original* nesting guard, unlike migrations/0277.pgsql.sql's fix, was
 * declared BEFORE UPDATE only, so no engine's trigger, past or present, ever refused such a
 * chain on INSERT - used to import without repair, so the very next ordinary write to the
 * middle product hit the target's own (correct, present) guard trigger and failed for a
 * reason nobody involved in that write could see.
 *
 * H7(b), replacement scope: a target's own outbox rows, describing consequences of data an
 * import is about to discard wholesale, used to survive a force import whenever the source
 * predated the migration that introduced `outbox` (0259). Fixed by treating `outbox` as
 * never copied and always surgically cleared (ClearOutbox()) rather than truncated - a first
 * round of this fix used TRUNCATE ... CASCADE, which silently took print_jobs/print_attempts
 * down with it through their foreign keys. Round two also found: `mqtt_published_entities`
 * describes the *target's own broker connection*, not the source's data, and must survive
 * untouched (never copied, never cleared) so MqttStatePublicationService can still retract
 * whatever it owes; `mqtt_product_entities` (the household's per-product opt-in) is correctly
 * cleared when the source predates it, but is real household configuration, not "derived"
 * state; and the price caches (cache__products_average_price,
 * cache__products_last_purchased) need recomputing after the copy, not just copying, because
 * migrations/0267.pgsql.sql's split-entry fix never ran on any SQLite source.
 *
 * M18: AssertSchemaVersionsMatch() used to compare only MAX(migration) on both the source and
 * the target, which a hole below the maximum does not move (migrations/RESERVATIONS.md
 * explains why) - unlike SchemaVersionMiddleware's boot check, which compares the full
 * required and applied sets and catches exactly this.
 */
class ImporterIntegrityTest extends PgsqlSchemaTestCase
{
	private array $files = [];

	/** Restores whatever a test punched into the shared target, so method order cannot matter. */
	private ?int $deletedTargetMigration = null;

	/**
	 * NOT_COPIED_TABLES (outbox, the label/print subsystem, mqtt_published_entities) is
	 * exactly the set of tables Import(true) never truncates - which is the point of this
	 * whole test class, but it also means fixtures this class inserts into them accumulate
	 * across test methods sharing one schema, unlike every table Import(true) itself resets.
	 * Cleaned up here in foreign-key order: print_jobs.current_attempt_id is nulled first
	 * (it points forward at print_attempts, which points back at print_jobs and outbox).
	 */
	protected function tearDown(): void
	{
		foreach ($this->files as $file)
		{
			foreach ([$file, $file . '-wal', $file . '-shm'] as $path)
			{
				if (file_exists($path))
				{
					unlink($path);
				}
			}
		}
		$this->files = [];

		if ($this->deletedTargetMigration !== null)
		{
			self::Pdo()->exec('INSERT INTO migrations (migration) VALUES (' . $this->deletedTargetMigration . ') ON CONFLICT DO NOTHING');
			$this->deletedTargetMigration = null;
		}

		self::Pdo()->exec('UPDATE print_jobs SET current_attempt_id = NULL');
		self::Pdo()->exec('DELETE FROM print_evidence');
		self::Pdo()->exec('DELETE FROM print_attempts');
		self::Pdo()->exec('DELETE FROM print_jobs');
		self::Pdo()->exec('DELETE FROM outbox');
		self::Pdo()->exec('DELETE FROM label_printers');
		self::Pdo()->exec('DELETE FROM label_drivers');
		self::Pdo()->exec('DELETE FROM label_workers');
		self::Pdo()->exec('DELETE FROM mqtt_published_entities');
		self::Pdo()->exec('DELETE FROM mqtt_product_entities');
		self::Pdo()->exec('DELETE FROM cache__products_average_price WHERE product_id >= 8000');
		self::Pdo()->exec('DELETE FROM products WHERE id >= 8000');
	}

	/** A scratch copy of a committed fixture - never the fixture itself, per this workstream's reservation. */
	private function sourceCopy(int $version): PDO
	{
		$file = tempnam(sys_get_temp_dir(), 'importer-integrity-source-');
		$this->files[] = $file;
		copy(VICTUAL_ROOT_PATH . '/.devtools/pgsql/fixtures/import/victual-' . $version . '.db', $file);

		return new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	}

	private function importer(PDO $source, ?callable $progress = null): DatabaseImporter
	{
		return new DatabaseImporter($source, self::Pdo(), DatabaseService::GetInstance()->GetDialect(), $progress ?? static function ($message) {});
	}

	/** The rows a refused import must leave completely alone. */
	private function targetState(): array
	{
		$result = [];

		foreach (['products', 'outbox', 'migrations'] as $table)
		{
			$result[$table] = self::Pdo()->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
		}

		return $result;
	}

	/** A minimal worker -> driver -> printer chain, unique per call so tests sharing this schema cannot collide. */
	private function labelPrinterFixture(): array
	{
		$db = self::Pdo();
		$suffix = bin2hex(random_bytes(4));

		$workerId = (int)$db->query("INSERT INTO label_workers (name, configuration_mode) VALUES ('importer-integrity-worker-$suffix', 'declared') RETURNING id")->fetchColumn();
		$db->exec('INSERT INTO label_drivers (driver_id, schema_version, contract_version, connection_types, discriminator_properties, combination_binding, settings_schemas, capability_document, registered_by_worker_id) VALUES '
			. "('importer-integrity-driver-$suffix', '1', 1, '[]'::jsonb, '{}'::jsonb, '{}'::jsonb, '{}'::jsonb, '{}'::jsonb, $workerId)");
		$printerId = (int)$db->query('INSERT INTO label_printers (name, active, is_default, worker_id, driver_id, driver_schema_version, connection, connection_type, model, settings, settings_validated_at) VALUES '
			. "('importer-integrity-printer-$suffix', 1, 0, $workerId, 'importer-integrity-driver-$suffix', '1', 'tcp://localhost:9100', 'network', 'fixture', '{}'::jsonb, CURRENT_TIMESTAMP) RETURNING id")->fetchColumn();

		return [$workerId, $printerId];
	}

	// --- H7(a): unsupported product nesting is repaired, and the guard works afterward ----

	public function testImportRepairsAMultiLevelProductChainAndReportsIt(): void
	{
		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MAX);
		$source->exec("INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock, parent_product_id)
			VALUES (8001, 'Protein', 1, 2, 2, NULL), (8002, 'Beef', 1, 2, 2, 8001), (8003, 'Chuck roast', 1, 2, 2, 8002)");

		$messages = [];
		$this->importer($source, function ($message) use (&$messages)
		{
			$messages[] = $message;
		})->Import(true);

		$rows = self::Pdo()->query('SELECT id, parent_product_id FROM products WHERE id >= 8001 ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
		self::assertSame([8001 => null, 8002 => null, 8003 => 8002], $rows,
			'the middle product (8002) must have its parent link cleared; the leaf (8003) stays under it; the root (8001) is untouched');

		self::assertNotEmpty(preg_grep('/repaired 1 unsupported product nesting chain.*8002.*was parented under 8001/', $messages),
			'the import output must name the repair and the parent link that was cleared: ' . implode("\n", $messages));

		// The point of the repair: an ordinary write to product 8002 must now succeed rather
		// than hitting trg_enfore_product_nesting_level (migrations/0277.pgsql.sql), which
		// this fixture's schema still enforces on every other product in the target.
		self::Pdo()->exec("UPDATE products SET name = 'Beef (renamed)' WHERE id = 8002");
		self::assertSame('Beef (renamed)', self::Pdo()->query('SELECT name FROM products WHERE id = 8002')->fetchColumn());
	}

	/**
	 * RepairProductNesting()'s own SELECT joins products p_child, so a middle product with
	 * more than one child used to join once per child: count($affected) counted the middle
	 * product twice and the progress line named it twice, both wrong for a single product
	 * that was repaired exactly once. This is the two-child case that regresses without the
	 * SELECT's DISTINCT.
	 */
	public function testImportRepairsAMultiLevelChainWhoseMiddleProductHasTwoChildrenOnce(): void
	{
		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MAX);
		$source->exec("INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock, parent_product_id)
			VALUES (8011, 'Protein', 1, 2, 2, NULL), (8012, 'Beef', 1, 2, 2, 8011), (8013, 'Chuck roast', 1, 2, 2, 8012), (8014, 'Ground beef', 1, 2, 2, 8012)");

		$messages = [];
		$this->importer($source, function ($message) use (&$messages)
		{
			$messages[] = $message;
		})->Import(true);

		$rows = self::Pdo()->query('SELECT id, parent_product_id FROM products WHERE id >= 8011 ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
		self::assertSame([8011 => null, 8012 => null, 8013 => 8012, 8014 => 8012], $rows,
			'the middle product (8012) has its parent link cleared exactly once; both children (8013, 8014) stay under it; the root (8011) is untouched');

		$repairLines = preg_grep('/repaired.*nesting/', $messages);
		self::assertCount(1, $repairLines, 'exactly one repair line, not one per child: ' . implode("\n", $messages));

		// The count and the per-product list must both reflect one distinct product, not
		// one entry per child join row.
		self::assertSame(
			'  repaired 1 unsupported product nesting chain: unset parent_product_id on product 8012 (Beef), was parented under 8011'
				. ' - dependent fields a trigger recomputes from this relationship (e.g. cumulated min_stock_amount) may have changed too',
			reset($repairLines),
			'product 8012 must be counted and named once despite having two children: ' . implode("\n", $messages)
		);
	}

	public function testImportWithNoNestingDefectReportsNothingAndLeavesGuardIntact(): void
	{
		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN);

		$messages = [];
		$this->importer($source, function ($message) use (&$messages)
		{
			$messages[] = $message;
		})->Import(true);

		self::assertEmpty(preg_grep('/repaired.*nesting/', $messages), 'nothing to repair means nothing reported: ' . implode("\n", $messages));

		// The guard trigger this fixture's schema already carries (migrations/0277.pgsql.sql)
		// must still refuse an ordinary write that creates the same unsupported chain the
		// repair above corrects on import - proof the fix above is a repair, not a bypass.
		$rootId = (int)self::Pdo()->query("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES ('Root', 1, 2, 2) RETURNING id")->fetchColumn();
		$middleId = (int)self::Pdo()->query("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, parent_product_id) VALUES ('Middle', 1, 2, 2, $rootId) RETURNING id")->fetchColumn();

		$this->expectException(\PDOException::class);
		$this->expectExceptionMessageMatches('/Unsupported product nesting level/');
		// Middle already has a parent (Root); giving it a child of its own is the same
		// grandchild-arrival case the trigger's first check exists for.
		self::Pdo()->exec("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, parent_product_id) VALUES ('Leaf', 1, 2, 2, $middleId)");
	}

	// --- H7(b): outbox is surgically cleared without disturbing print history -------------

	public function testImportClearsAnUnreferencedOutboxRowEvenWhenTheSourcePredatesTheTable(): void
	{
		self::Pdo()->exec("INSERT INTO outbox (event_type, payload, attempts) VALUES ('stock.transaction_booked', '{\"marker\":\"pre-import\"}', 0)");
		self::assertSame(1, (int)self::Pdo()->query("SELECT count(*) FROM outbox WHERE payload LIKE '%pre-import%'")->fetchColumn());

		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN);
		self::assertFalse(
			(bool)$source->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='outbox'")->fetchColumn(),
			'the minimum-supported fixture must predate `outbox` (0259) - that is the scenario this test covers'
		);

		$messages = [];
		$this->importer($source, function ($message) use (&$messages)
		{
			$messages[] = $message;
		})->Import(true);

		self::assertSame(0, (int)self::Pdo()->query('SELECT count(*) FROM outbox')->fetchColumn(),
			'no outbox row - stale or otherwise - may survive a replace the source could not have contributed to');
		self::assertNotEmpty(preg_grep('/outbox: deleted 1 unreferenced row/', $messages), 'the deletion must be reported: ' . implode("\n", $messages));
	}

	public function testImportKeepsADeliveredPrintJobsOutboxRowUntouched(): void
	{
		$db = self::Pdo();
		[$workerId, $printerId] = $this->labelPrinterFixture();

		$outboxId = (int)$db->query("INSERT INTO outbox (event_type, payload, delivered_at) VALUES ('label.print_requested', '{}', CURRENT_TIMESTAMP) RETURNING id")->fetchColumn();
		$before = $db->query('SELECT delivered_at, dead_lettered_at FROM outbox WHERE id = ' . $outboxId)->fetch(PDO::FETCH_ASSOC);

		$jobId = (int)$db->query('INSERT INTO print_jobs (outbox_id, printer_id, label_uid) VALUES ('
			. $outboxId . ', ' . $printerId . ", '01ARZ3NDEKTSV4RRFFQ69G5FAV') RETURNING id")->fetchColumn();
		$attemptId = (int)$db->query('INSERT INTO print_attempts (outbox_id, job_id, attempt_number, worker_id, lease_expires_at, lease_hard_deadline, acknowledged_on) VALUES ('
			. $outboxId . ', ' . $jobId . ", 1, $workerId, CURRENT_TIMESTAMP + INTERVAL '5 minutes', CURRENT_TIMESTAMP + INTERVAL '10 minutes', 'report') RETURNING id")->fetchColumn();
		$db->exec('UPDATE print_jobs SET current_attempt_id = ' . $attemptId . ' WHERE id = ' . $jobId);

		// A source lacking `outbox` entirely (0255) - the harder case: nothing here could
		// possibly be "the source's own copy", so keeping this row can only be this fix.
		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN);
		$this->importer($source)->Import(true);

		$after = $db->query('SELECT delivered_at, dead_lettered_at FROM outbox WHERE id = ' . $outboxId)->fetch(PDO::FETCH_ASSOC);
		self::assertNotFalse($after, 'a delivered, referenced outbox row must not be deleted');
		self::assertSame($before, $after, 'and must not be touched in any way either - it is already done');
		self::assertSame(1, (int)$db->query('SELECT count(*) FROM print_jobs WHERE id = ' . $jobId)->fetchColumn(),
			'the print job survives because its outbox_id foreign key was never broken');
		self::assertSame(1, (int)$db->query('SELECT count(*) FROM print_attempts WHERE id = ' . $attemptId)->fetchColumn(),
			'the print attempt survives for the same reason');
	}

	/**
	 * Dead-lettering the outbox row is necessary but not sufficient: LabelPrintJobService::Monitor()
	 * (the same query the label print dashboard reads) and LabelOperationsService::Cancel() read
	 * `print_jobs.outcome` directly, not the outbox row it points at. (PrintAttemptService::Claim()
	 * also checks the outbox row's `dead_lettered_at`, so it never offered these jobs again - see
	 * the note in the test body.) Two jobs, matching the two shapes the
	 * `authorization_state` column distinguishes: one with no attempt at all (would otherwise
	 * read `awaiting_artifact`) and one whose only attempt already failed (would otherwise read
	 * `failed`, which a monitor or an operator could reasonably retry) - neither is a terminal
	 * state, and neither job may ever be delivered once its outbox row is dead-lettered.
	 */
	public function testImportDeadLettersAPendingPrintJobsOutboxRowAndFinishesTheJob(): void
	{
		$db = self::Pdo();
		[$workerId, $printerId] = $this->labelPrinterFixture();

		$outboxA = (int)$db->query("INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested', '{}') RETURNING id")->fetchColumn();
		$jobA = (int)$db->query('INSERT INTO print_jobs (outbox_id, printer_id, label_uid) VALUES ('
			. $outboxA . ', ' . $printerId . ", 'PENDING1DEKTSV4RRFFQ69G5F') RETURNING id")->fetchColumn();

		$outboxB = (int)$db->query("INSERT INTO outbox (event_type, payload) VALUES ('label.print_requested', '{}') RETURNING id")->fetchColumn();
		$jobB = (int)$db->query('INSERT INTO print_jobs (outbox_id, printer_id, label_uid, attempts_made) VALUES ('
			. $outboxB . ', ' . $printerId . ", 'PENDING2DEKTSV4RRFFQ69G5F', 1) RETURNING id")->fetchColumn();
		$attemptB = (int)$db->query('INSERT INTO print_attempts (outbox_id, job_id, attempt_number, worker_id, lease_expires_at, lease_hard_deadline, acknowledged_on, ended_at, outcome, error_text) VALUES ('
			. $outboxB . ', ' . $jobB . ", 1, $workerId, CURRENT_TIMESTAMP - INTERVAL '5 minutes', CURRENT_TIMESTAMP, 'report', CURRENT_TIMESTAMP - INTERVAL '6 minutes', 'failed', 'paper out') RETURNING id")->fetchColumn();
		$db->exec('UPDATE print_jobs SET current_attempt_id = ' . $attemptB . ' WHERE id = ' . $jobB);

		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN);

		$messages = [];
		$this->importer($source, function ($message) use (&$messages)
		{
			$messages[] = $message;
		})->Import(true);

		foreach ([$outboxA, $outboxB] as $outboxId)
		{
			$row = $db->query('SELECT delivered_at, dead_lettered_at FROM outbox WHERE id = ' . $outboxId)->fetch(PDO::FETCH_ASSOC);
			self::assertNotFalse($row, "outbox row $outboxId must not be deleted - it is referenced");
			self::assertNull($row['delivered_at'], 'it was never delivered and must not be reported as if it had been');
			self::assertNotNull($row['dead_lettered_at'], 'a pending, referenced row must be dead-lettered rather than left live');
		}

		self::assertSame(1, (int)$db->query('SELECT count(*) FROM print_jobs WHERE id = ' . $jobA)->fetchColumn(), 'job A survives');
		self::assertSame(1, (int)$db->query('SELECT count(*) FROM print_attempts WHERE id = ' . $attemptB)->fetchColumn(), "job B's attempt survives");

		$monitor = [];
		foreach ((new LabelPrintJobService($db))->Monitor() as $row)
		{
			if (in_array((int)$row['id'], [$jobA, $jobB], true))
			{
				$monitor[(int)$row['id']] = $row;
			}
		}
		self::assertArrayHasKey($jobA, $monitor);
		self::assertArrayHasKey($jobB, $monitor);
		self::assertSame('dead_lettered', $monitor[$jobA]['state'],
			'a job with no attempt must be reported dead_lettered, not awaiting_artifact: ' . json_encode($monitor[$jobA]));
		self::assertSame('dead_lettered', $monitor[$jobB]['state'],
			'a job whose only attempt failed must be reported dead_lettered, not failed: ' . json_encode($monitor[$jobB]));
		self::assertSame('dead_lettered', $monitor[$jobA]['outcome']);
		self::assertNotNull($monitor[$jobA]['outcome_at']);
		self::assertSame('dead_lettered', $monitor[$jobB]['outcome']);

		// PrintAttemptService::Claim() is not separately probed here: Claim() has always
		// excluded a dead-lettered outbox row on its own (`o.dead_lettered_at IS NULL`,
		// PrintAttemptService.php:48), so neither of this fixture's jobs was ever at risk
		// of being reclaimed. A job set up to actually pass Claim()'s dispatch checks (a
		// valid payload, a matching worker capability, an attached artifact) belongs to
		// LabelServicesTest.php's fixture apparatus, not this importer-focused class. The
		// Monitor() assertions above already fail against round 2's code (ed283065) and
		// pass only once ClearOutbox() finishes the job, which is what this test covers.
		self::assertNotEmpty(preg_grep('/outbox: deleted 0 unreferenced row.*dead-lettered 2 row/', $messages),
			'the dead-lettering must be reported: ' . implode("\n", $messages));
	}

	public function testNonForceImportRefusesAPreExistingUndeliveredOutboxRowRatherThanSilentlyDiscardingIt(): void
	{
		// Isolate AssertOutboxIsHandleable() from the pre-existing AssertTargetIsEmpty(): the
		// freshly migrated target's ordinary seeded rows (an admin user, api_keys, the
		// default quantity units) would otherwise trip that broader, unrelated check first,
		// on every real target - which is not the gap this test exists to cover. Only the
		// ordinary common tables are emptied; NOT_COPIED_TABLES/TARGET_ONLY_TABLES are left
		// exactly as the migration run seeded them, the way a real import leaves them.
		$excluded = array_merge(DatabaseImporter::NOT_COPIED_TABLES, DatabaseImporter::TARGET_ONLY_TABLES);
		$otherTables = array_diff(self::Pdo()->query("SELECT table_name FROM information_schema.tables
			WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN), $excluded);
		self::Pdo()->exec('TRUNCATE TABLE ' . implode(', ', array_map(fn($t) => '"' . $t . '"', $otherTables)) . ' RESTART IDENTITY CASCADE');

		self::Pdo()->exec("INSERT INTO outbox (event_type, payload, attempts) VALUES ('stock.transaction_booked', '{}', 0)");
		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN);

		$thrown = null;

		try
		{
			$this->importer($source)->Import(false);
		}
		catch (\Exception $ex)
		{
			$thrown = $ex;
		}

		self::assertNotNull($thrown, 'a non-force import must refuse when the target already holds an undelivered outbox row');
		self::assertStringContainsString('outbox', $thrown->getMessage());
		self::assertStringContainsString('--force', $thrown->getMessage());
		self::assertSame(1, (int)self::Pdo()->query('SELECT count(*) FROM outbox')->fetchColumn(), 'refusal must leave the target unchanged');
	}

	// --- H7(b): the target's own MQTT broker state is neither copied nor cleared ----------

	public function testImportKeepsTheMqttPublicationLedgerButClearsTheProductOptIn(): void
	{
		$db = self::Pdo();
		$productId = 8401;
		$objectId = 'product_' . $productId;
		$payloadHash = hash('sha256', 'importer-integrity-fixture');

		// A real location/quantity unit, not a bare literal id: migrations/0295.pgsql.sql's
		// products_location_id_fkey/qu_id_purchase_fkey/qu_id_stock_fkey (issue #552) now
		// enforce that these ids actually exist in the target, and this class's own
		// testNonForceImportRefusesAPreExistingUndeliveredOutboxRowRatherThanSilentlyDiscardingIt
		// TRUNCATEs every common table, including locations and quantity_units, on the shared
		// target this class's methods all reuse - so neither a bare literal nor the target's
		// InitialDataSeeder-seeded default id can be assumed still present here, whichever
		// method order PHPUnit happens to run in.
		$locationId = (int)$db->query("INSERT INTO locations (name) VALUES ('Ledger fixture location') RETURNING id")->fetchColumn();
		$quId = (int)$db->query("INSERT INTO quantity_units (name) VALUES ('Ledger fixture unit') RETURNING id")->fetchColumn();
		$db->exec("INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock) VALUES ($productId, 'Ledger fixture', $locationId, $quId, $quId)");
		$db->exec('INSERT INTO mqtt_product_entities (product_id) VALUES (' . $productId . ')');
		$statement = $db->prepare('INSERT INTO mqtt_published_entities (object_id, payload_hash) VALUES (?, ?)');
		$statement->execute([$objectId, $payloadHash]);

		// A source predating `mqtt_product_entities`/`mqtt_published_entities` (0257) and,
		// separately, not carrying this product at all - the target's opt-in for it can no
		// longer mean anything once this product id belongs to whatever the import wrote
		// there instead (or to nothing at all), but the ledger describes the broker, not the
		// product, and must survive regardless.
		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN);
		$this->importer($source)->Import(true);

		self::assertSame([['object_id' => $objectId, 'payload_hash' => $payloadHash]],
			array_map(fn($row) => ['object_id' => $row['object_id'], 'payload_hash' => $row['payload_hash']],
				$db->query('SELECT object_id, payload_hash FROM mqtt_published_entities WHERE object_id = ' . $db->quote($objectId))->fetchAll(PDO::FETCH_ASSOC)),
			'the ledger row must survive an import untouched - it describes the target broker, not the source data');

		self::assertSame(0, (int)$db->query('SELECT count(*) FROM mqtt_product_entities WHERE product_id = ' . $productId)->fetchColumn(),
			'the opt-in flag, keyed to a product id the import just replaced, must not survive under a stale reference');
	}

	/**
	 * The other half of the property above, proven end to end against the real publication
	 * code and a real import, not seeded state: a ledger entry the target owned *before* an
	 * import - surviving it untouched, exactly as
	 * testImportKeepsTheMqttPublicationLedgerButClearsTheProductOptIn() already proves at the
	 * schema level - must still be retracted by the very next normal publish afterwards, using
	 * the same stand-in broker infrastructure MqttCoverageTest.php's own scenarios do (see that
	 * class for the fuller pattern this borrows a reduced copy of). Uses the `state` scenario
	 * step (MqttStatePublicationService::PublishState(), the request-end publish path) rather
	 * than the separate `retract` step: the normal publish path does its own ledger-diff and
	 * retraction internally, and that - not a caller reaching for retraction explicitly - is
	 * what a real import leaves this installation waiting on.
	 */
	public function testARealImportKeepsTheNextPublishAbleToRetractASurvivingLedgerEntry(): void
	{
		$db = self::Pdo();
		$db->exec("INSERT INTO mqtt_published_entities (object_id, payload_hash) VALUES "
			. "('product_7', 'pre-import-hash-7'), ('product_8601', 'pre-import-hash-8601')");
		$db->exec('INSERT INTO mqtt_product_entities (product_id) VALUES (8601)');

		// 0265 carries both mqtt tables, so a source at that end of the span is the case
		// master and round 1 lost the ledger in - the harder case for this fix to prove.
		$this->importer($this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MAX))->Import(true);

		$ledgerAfterImport = $db->query('SELECT object_id FROM mqtt_published_entities ORDER BY object_id')->fetchAll(PDO::FETCH_COLUMN);
		self::assertSame(['product_7', 'product_8601'], $ledgerAfterImport, 'both ledger rows must survive the import untouched');
		self::assertSame(0, (int)$db->query('SELECT count(*) FROM mqtt_product_entities WHERE product_id = 8601')->fetchColumn(),
			'the opt-in flag must not survive under the stale product id');

		$port = $this->reserveTcpPort();
		$logFile = sys_get_temp_dir() . '/importer-integrity-mqtt-' . uniqid() . '.log';
		file_put_contents($logFile, '');
		$broker = $this->startStandInBroker($port, $logFile);

		try
		{
			$resultFile = sys_get_temp_dir() . '/importer-integrity-mqtt-result-' . uniqid() . '.json';
			$environment = array_merge(array_filter(array_merge($_SERVER, $_ENV), 'is_scalar'), [
				'RBAC_TEST_SCHEMA' => self::Schema(),
				'PHPUNIT_DB_NAME' => getenv('PHPUNIT_DB_NAME'),
				'VICTUAL_DATAPATH' => getenv('VICTUAL_DATAPATH'),
				'PGHOST' => getenv('PGHOST'),
				'PGPORT' => getenv('PGPORT'),
				'PGUSER' => getenv('PGUSER'),
				'PGPASSWORD' => getenv('PGPASSWORD'),
				'VICTUAL_ROOT' => VICTUAL_ROOT_PATH,
				'VICTUAL_MQTT_ENABLED' => 'true',
				'VICTUAL_MQTT_HOST' => '127.0.0.1',
				'VICTUAL_MQTT_PORT' => (string)$port
			]);

			$process = proc_open(
				[PHP_BINARY, __DIR__ . '/mqttcoverage-subprocess-helper.php', 'scenario', 'state', $resultFile],
				[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
				$pipes,
				null,
				$environment
			);

			self::assertIsResource($process, 'could not start the scenario helper');
			$output = stream_get_contents($pipes[1]);
			$errors = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			proc_close($process);

			self::assertFileExists($resultFile, "the scenario helper wrote no result.\nstdout: $output\nstderr: $errors");
			$result = json_decode((string)file_get_contents($resultFile), true);
			@unlink($resultFile);
			self::assertIsArray($result, "the scenario helper wrote no JSON.\nstdout: $output\nstderr: $errors");
			self::assertArrayHasKey('error', $result, 'malformed result: ' . json_encode($result));
			self::assertNull($result['error'], 'the publish must not error: ' . json_encode($result));
			self::assertTrue($result['steps']['0:state'] ?? false, 'the publish reports success');

			$waited = 0;
			while ($waited < 100 && !str_contains((string)@file_get_contents($logFile), '=== end'))
			{
				usleep(50000);
				$waited++;
			}
			self::assertStringContainsString('=== end', (string)@file_get_contents($logFile), 'the stand-in broker never finished the connection');

			$retracted = [];
			foreach (file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line)
			{
				if (str_starts_with($line, '==='))
				{
					continue;
				}
				$fields = explode("\t", $line);
				if ((int)($fields[1] ?? -1) === 0 && preg_match('#product/(7|8601)$#', $fields[0]))
				{
					$retracted[] = $fields[0];
				}
			}

			self::assertContains('victual/state/product/7', $retracted, 'the survived ledger entry for product 7 must be retracted with an empty payload: ' . json_encode($retracted));
			self::assertContains('victual/state/product/8601', $retracted, 'and the one for product 8601, whose opt-in the import correctly cleared: ' . json_encode($retracted));

			self::assertSame([], $db->query('SELECT object_id FROM mqtt_published_entities')->fetchAll(PDO::FETCH_COLUMN),
				'both ledger rows are forgotten once their topics are actually retracted');
		}
		finally
		{
			if (is_resource($broker))
			{
				proc_terminate($broker);
				proc_close($broker);
			}
			@unlink($logFile);
			@unlink($logFile . '.stdout');
			@unlink($logFile . '.stderr');
		}
	}

	private function reserveTcpPort(): int
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

		if ($socket === false)
		{
			self::fail('could not reserve a port: ' . $errorMessage);
		}

		$name = (string)stream_socket_get_name($socket, false);
		fclose($socket);

		return (int)substr($name, strrpos($name, ':') + 1);
	}

	/** @return resource */
	private function startStandInBroker(int $port, string $logFile)
	{
		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/mqttcoverage-subprocess-helper.php', 'broker', (string)$port, $logFile, 'record'],
			[1 => ['file', $logFile . '.stdout', 'a'], 2 => ['file', $logFile . '.stderr', 'a']],
			$pipes,
			null,
			array_filter(array_merge($_SERVER, $_ENV), 'is_scalar')
		);

		if (!is_resource($process))
		{
			self::fail('could not start the stand-in broker on 127.0.0.1:' . $port);
		}

		$waited = 0;

		while ($waited < 100)
		{
			$probe = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.2);

			if ($probe !== false)
			{
				fclose($probe);
				usleep(50000);
				file_put_contents($logFile, '');

				return $process;
			}

			usleep(50000);
			$waited++;
		}

		self::fail('the stand-in broker never bound 127.0.0.1:' . $port);
	}

	// --- H7(b), replacement scope: price caches are recomputed, not just copied -----------

	public function testImportRebuildsThePriceCachesRatherThanCopyingAStaleOne(): void
	{
		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN);
		$productId = 8501;

		$source->exec("INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock) VALUES ($productId, 'Price rebuild fixture', 1, 2, 2)");
		$source->exec("INSERT INTO stock_log (product_id, amount, stock_id, transaction_type, price, undone, user_id, purchased_date) VALUES "
			. "($productId, 4, 'price-fixture-1', 'purchase', 2, 0, 1, '2026-01-01'), "
			. "($productId, 3, 'price-fixture-2', 'purchase', 2, 0, 1, '2026-01-02'), "
			. "($productId, 2, 'price-fixture-3', 'purchase', 3, 0, 1, '2026-01-03')");
		// This fixture's own stock_log triggers already populate cache__products_average_price
		// from these three rows - whatever they compute (a real, older SQLite installation's
		// own cache is exactly this: trigger-maintained, never touched by
		// migrations/0267.pgsql.sql's split-entry fix, which no SQLite source ever ran) is
		// overwritten deliberately here with the validator's own probe value, 2.0, so the
		// assertion below is not at the mercy of whether this fixture's triggers happen to
		// already agree with the correct answer.
		$source->exec("UPDATE cache__products_average_price SET price = 2.0 WHERE product_id = $productId");
		self::assertSame(1, (int)$source->query("SELECT count(*) FROM cache__products_average_price WHERE product_id = $productId")->fetchColumn(),
			"the fixture's own trigger must have created exactly one cache row to overwrite");

		$messages = [];
		$this->importer($source, function ($message) use (&$messages)
		{
			$messages[] = $message;
		})->Import(true);

		$rebuilt = (float)self::Pdo()->query('SELECT price FROM cache__products_average_price WHERE product_id = ' . $productId)->fetchColumn();
		self::assertEqualsWithDelta(20 / 9, $rebuilt, 0.0001,
			'the weighted average of purchases 4@2, 3@2 and 2@3 is 2.2222..., not the 2.0 the source (and a verbatim copy of it) cached');
		self::assertNotEmpty(preg_grep('/rebuilt price cache/', $messages), 'the rebuild must be reported: ' . implode("\n", $messages));
	}

	/**
	 * ADR-0036 acceptance prerequisite 9. The lineage tables are derived state: an import
	 * truncates them with the ledger it replaces, then RebuildStockLineage() runs migration
	 * 0304's backfill over the imported ledger. The source here holds one family of each class
	 * the backfill can prove or must leave unknown: E (a purchase of 4 and a consume of 1, one
	 * row of 3), X (purchases of 3 and 2 merged into one row of 5 under one stock_id) and U (the
	 * same merge followed by a consume of 1).
	 */
	public function testImportTruncatesTheLineageTablesAndRebuildsThemByTheBackfill(): void
	{
		$db = self::Pdo();
		$product = (int)$db->query("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES ('Lineage stale target', 1, 2, 2) RETURNING id")->fetchColumn();
		$staleBooking = (int)$db->query("INSERT INTO stock_log (product_id, amount, stock_id, transaction_type, price, undone, user_id) VALUES ($product, 99, 'stale-lot', 'purchase', 1, 0, 1) RETURNING id")->fetchColumn();
		$staleRow = (int)$db->query("INSERT INTO stock (product_id, amount, stock_id) VALUES ($product, 99, 'stale-lot') RETURNING id")->fetchColumn();
		$db->exec("INSERT INTO stock_row_lots (stock_row_id, lot_id, amount, basis) VALUES ($staleRow, $staleBooking, 99, 'recorded')");
		$db->exec("INSERT INTO stock_booking_lots (booking_id, lot_id, amount, basis) VALUES ($staleBooking, $staleBooking, 99, 'recorded')");

		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MAX);
		$source->exec("INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock) VALUES (8601, 'Lineage import fixture', 1, 2, 2)");
		$source->exec("INSERT INTO stock_log (product_id, amount, stock_id, transaction_type, price, undone, user_id, purchased_date) VALUES "
			. "(8601, 4, 'lin-e', 'purchase', 1, 0, 1, '2026-01-01'), (8601, -1, 'lin-e', 'consume', 1, 0, 1, '2026-01-01'), "
			. "(8601, 3, 'lin-x', 'purchase', 1, 0, 1, '2026-01-01'), (8601, 2, 'lin-x', 'purchase', 1, 0, 1, '2026-01-01'), "
			. "(8601, 3, 'lin-u', 'purchase', 1, 0, 1, '2026-01-01'), (8601, 2, 'lin-u', 'purchase', 1, 0, 1, '2026-01-01'), (8601, -1, 'lin-u', 'consume', 1, 0, 1, '2026-01-01')");
		$source->exec("INSERT INTO stock (product_id, amount, stock_id, purchased_date) VALUES (8601, 3, 'lin-e', '2026-01-01'), (8601, 5, 'lin-x', '2026-01-01'), (8601, 4, 'lin-u', '2026-01-01')");

		$messages = [];
		$this->importer($source, function ($message) use (&$messages)
		{
			$messages[] = $message;
		})->Import(true);

		self::assertSame(0, (int)$db->query('SELECT count(*) FROM stock_row_lots WHERE amount = 99')->fetchColumn(), 'The target\'s own lineage rows went with the ledger they described');
		$lots = $db->query("SELECT s.stock_id, rl.lot_id IS NULL AS pool, rl.amount, rl.basis FROM stock_row_lots rl JOIN stock s ON s.id = rl.stock_row_id
			WHERE s.product_id = 8601 ORDER BY s.stock_id, rl.lot_id NULLS FIRST")->fetchAll(PDO::FETCH_ASSOC);
		self::assertSame([
			['lin-e', false, 3.0, 'derived'],
			['lin-u', true, 4.0, 'unknown'],
			['lin-x', false, 3.0, 'derived'],
			['lin-x', false, 2.0, 'derived'],
		], array_map(fn($row) => [$row['stock_id'], (bool)$row['pool'], (float)$row['amount'], $row['basis']], $lots), 'E and X are attributed; U is one unknown pool');
		self::assertSame([], $db->query('SELECT * FROM stock_lineage_violations()')->fetchAll(PDO::FETCH_ASSOC), 'I1 to I3 hold after the import');
		self::assertNotEmpty(preg_grep('/stock lineage .*rebuilt \(families: .*U \d+, X \d+\)/', $messages), 'The rebuild is reported: ' . implode("\n", $messages));
	}

	/** ADR-0036 acceptance prerequisite 9: a non-forced import into a target with stock, and so with lots, is refused and changes nothing. */
	public function testNonForceImportRefusesATargetHoldingLots(): void
	{
		$db = self::Pdo();
		$product = (int)$db->query("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES ('Lineage refused target', 1, 2, 2) RETURNING id")->fetchColumn();
		$booking = (int)$db->query("INSERT INTO stock_log (product_id, amount, stock_id, transaction_type, price, undone, user_id) VALUES ($product, 2, 'refused-lot', 'purchase', 1, 0, 1) RETURNING id")->fetchColumn();
		$row = (int)$db->query("INSERT INTO stock (product_id, amount, stock_id) VALUES ($product, 2, 'refused-lot') RETURNING id")->fetchColumn();
		$db->exec("INSERT INTO stock_row_lots (stock_row_id, lot_id, amount, basis) VALUES ($row, $booking, 2, 'recorded')");
		$db->exec("INSERT INTO stock_booking_lots (booking_id, lot_id, amount, basis) VALUES ($booking, $booking, 2, 'recorded')");
		$lineage = fn() => [$db->query('SELECT * FROM stock_row_lots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), $db->query('SELECT * FROM stock_booking_lots ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)];
		$before = $lineage();

		try
		{
			$this->importer($this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MAX))->Import(false);
			self::fail('A non-forced import into a target holding stock and lots must be refused');
		}
		catch (\Exception $exception)
		{
			self::assertStringContainsString('already contains data', $exception->getMessage());
		}

		self::assertSame($before, $lineage(), 'The refusal leaves the lots as they were');
		$db->exec('TRUNCATE stock, stock_log CASCADE');
	}

	/**
	 * RebuildPriceCaches() used to only upsert from the views, which cannot remove a row
	 * for a product neither view returns a row for any more - only add or correct one for
	 * a product a view still names. A source that undid its only purchase of a product is
	 * exactly that case: trg_stock_log_UPD (fired by the UPDATE below, marking the purchase
	 * undone) re-selects from the view for that product alone, finds nothing now that
	 * "undone = 0" excludes it, and - an upsert whose SELECT returns no row inserts
	 * nothing - leaves the row the purchase's own INSERT had cached exactly as it was. A
	 * verbatim copy carries that stale row into the target untouched; the rebuild must
	 * remove it, and must leave a product that still has a purchase alone.
	 */
	public function testImportClearsAStaleCacheRowForAProductWithNoPurchaseLeftInTheViews(): void
	{
		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN);
		$undoneProductId = 8502;
		$keptProductId = 8503;

		$source->exec("INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock) VALUES "
			. "($undoneProductId, 'Undone purchase fixture', 1, 2, 2), "
			. "($keptProductId, 'Kept purchase fixture', 1, 2, 2)");

		$source->exec("INSERT INTO stock_log (product_id, amount, stock_id, transaction_type, price, undone, user_id, purchased_date) VALUES "
			. "($undoneProductId, 5, 'undone-cache-fixture', 'purchase', 3, 0, 1, '2026-01-01')");
		$source->exec("UPDATE stock_log SET undone = 1, undone_timestamp = CURRENT_TIMESTAMP WHERE stock_id = 'undone-cache-fixture'");

		$source->exec("INSERT INTO stock_log (product_id, amount, stock_id, transaction_type, price, undone, user_id, purchased_date) VALUES "
			. "($keptProductId, 2, 'kept-cache-fixture', 'purchase', 4, 0, 1, '2026-01-02')");

		self::assertSame(1, (int)$source->query("SELECT count(*) FROM cache__products_average_price WHERE product_id = $undoneProductId")->fetchColumn(),
			"the fixture's own trigger must leave exactly one stale cache row behind for the undone product, not zero or none written in the first place");

		$messages = [];
		$this->importer($source, function ($message) use (&$messages)
		{
			$messages[] = $message;
		})->Import(true);

		self::assertSame(0, (int)self::Pdo()->query('SELECT count(*) FROM cache__products_average_price WHERE product_id = ' . $undoneProductId)->fetchColumn(),
			'a verbatim copy carries the stale row in; the rebuild must remove it, since no view returns a row for this product any more');
		self::assertSame(0, (int)self::Pdo()->query('SELECT count(*) FROM cache__products_last_purchased WHERE product_id = ' . $undoneProductId)->fetchColumn(),
			'the same clear-then-rebuild applies to the last-purchased cache');

		$keptPrice = (float)self::Pdo()->query('SELECT price FROM cache__products_average_price WHERE product_id = ' . $keptProductId)->fetchColumn();
		self::assertSame(4.0, $keptPrice,
			"a product that still has a purchase must carry the view's own value, undisturbed by the other product's row being cleared");

		self::assertNotEmpty(preg_grep('/rebuilt price cache/', $messages), 'the rebuild must be reported: ' . implode("\n", $messages));
	}

	// --- M18: a hole below the maximum is refused on both sides ---------------------------

	public function testImportRefusesASourceWithAMigrationHoleBelowItsClaimedMaximum(): void
	{
		$max = DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MAX;
		$source = $this->sourceCopy($max);

		$holeNumber = (int)$source->query('SELECT migration FROM migrations WHERE migration NOT IN (8888, 9999) AND migration <> ' . $max . ' ORDER BY migration DESC LIMIT 1')->fetchColumn();
		self::assertGreaterThan(0, $holeNumber, 'the fixture must have a migration below its maximum to punch a hole at');
		$source->exec('DELETE FROM migrations WHERE migration = ' . $holeNumber);
		self::assertSame($max, (int)$source->query('SELECT max(migration) FROM migrations')->fetchColumn(),
			'the maximum must be unaffected by the hole - that is exactly the M18 scenario');

		$before = $this->targetState();
		$thrown = null;

		try
		{
			$this->importer($source)->Import(true);
		}
		catch (\Exception $ex)
		{
			$thrown = $ex;
		}

		self::assertNotNull($thrown, 'an import from a source with a recorded hole below its maximum must be refused');
		self::assertStringContainsString((string)$holeNumber, $thrown->getMessage());
		self::assertStringContainsString((string)$max, $thrown->getMessage());
		self::assertSame($before, $this->targetState());
	}

	/**
	 * The regression the previous version of this fix introduced: bounding the source's
	 * required set to SQLITE_REQUIRED_MIGRATION_NUMBERS_ABOVE_BASELINE alone (0256-0265)
	 * silently dropped 1-0255 from the check entirely, so a hole at, say, 0200 - a real,
	 * portable migration every source at or above 0255 must have run - went unnoticed. This
	 * is deliberately the *minimum* fixture (0255): the hole is below the frozen baseline
	 * itself, not merely below the source's own claimed maximum.
	 */
	public function testImportRefusesASourceWithAMigrationHoleBelowTheBaseline(): void
	{
		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN);
		$holeNumber = 200;

		self::assertSame(1, (int)$source->query('SELECT count(*) FROM migrations WHERE migration = ' . $holeNumber)->fetchColumn(),
			'the fixture must actually have this migration recorded, or deleting it proves nothing');
		$source->exec('DELETE FROM migrations WHERE migration = ' . $holeNumber);
		self::assertSame(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN, (int)$source->query('SELECT max(migration) FROM migrations')->fetchColumn(),
			'the maximum must be unaffected by the hole - a hole below it is exactly what a maximum cannot see');

		$before = $this->targetState();
		$thrown = null;

		try
		{
			$this->importer($source)->Import(true);
		}
		catch (\Exception $ex)
		{
			$thrown = $ex;
		}

		self::assertNotNull($thrown, 'a source missing an interior migration below the baseline must be refused, not accepted because its maximum still matches');
		self::assertStringContainsString((string)$holeNumber, $thrown->getMessage());
		self::assertSame($before, $this->targetState());
	}

	public function testImportRefusesATargetWithAMigrationHoleBelowItsOwnMaximum(): void
	{
		$maxBefore = (int)self::Pdo()->query('SELECT max(migration) FROM migrations')->fetchColumn();
		$holeNumber = (int)self::Pdo()->query('SELECT migration FROM migrations WHERE migration NOT IN (8888, 9999) AND migration <> ' . $maxBefore . ' ORDER BY migration DESC LIMIT 1')->fetchColumn();
		self::assertGreaterThan(0, $holeNumber, 'the freshly migrated target must have a migration below its maximum to punch a hole at');

		self::Pdo()->exec('DELETE FROM migrations WHERE migration = ' . $holeNumber);
		$this->deletedTargetMigration = $holeNumber; // tearDown() restores this regardless of outcome below
		self::assertSame($maxBefore, (int)self::Pdo()->query('SELECT max(migration) FROM migrations')->fetchColumn(),
			'the maximum must be unaffected by the hole - that is exactly the M18 scenario');

		$source = $this->sourceCopy(DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MAX);
		$before = $this->targetState();
		$thrown = null;

		try
		{
			$this->importer($source)->Import(true);
		}
		catch (\Exception $ex)
		{
			$thrown = $ex;
		}

		self::assertNotNull($thrown, 'an import into a target with a recorded hole below its maximum must be refused');
		self::assertStringContainsString((string)$holeNumber, $thrown->getMessage());
		self::assertSame($before, $this->targetState());
	}
}
