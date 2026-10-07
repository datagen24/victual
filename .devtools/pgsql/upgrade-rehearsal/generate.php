<?php

// Builds the issue #650 upgrade fixture: a household database written by the application at
// the SOURCE revision (v0.2.0-MVP), for rehearse.sh to upgrade through the target revision's
// migrations. See README.md in this directory.
//
//   VICTUAL_DATAPATH=<dir with config.php> php -d date.timezone=<zone> \
//     .devtools/pgsql/upgrade-rehearsal/generate.php --app=<source tree> --manifest=<file> [--invalid] [--seed=N]
//
// It must run against the source tree's code, not this tree's: --app names that tree, and
// everything is loaded from there. The database must already be migrated by the source
// tree's bin/victual-migrate. date.timezone is the configured zone the fixture is written in,
// and the zone the upgrade must then run in; the expectations below are for
// America/New_York only, and the script refuses any other zone.
//
// What it writes, in order:
//
// 1. Household data booked through the source revision's services, so the rows have the
//    shapes that code produces: purchases, consumes, opens, transfers and undone bookings over
//    90 days from 2024-09-20 (so the history spans New York's 2024-11-03 fall-back), chore
//    executions and battery charge cycles at explicit wall clocks, completed tasks, meal plan
//    days, and labels issued through LabelIdentityService for a location, a product and a
//    stock entry, with one retired by deleting its location.
// 2. A pin. Columns the application fills from the clock (row_created_timestamp and the
//    like, and the `now` dates a consume or open records) are overwritten with values derived
//    from the row order, so two runs produce the same data. Wall clocks the configured zone
//    skips are stepped forward an hour so the valid fixture holds none. The columns the
//    services were given explicit times for are not pinned.
// 3. Named fixture values, each with its expected instant written out by hand below. These
//    are the rows the evidence names; the bulk of the data is checked by verify.py's own
//    oracle.
//
// --invalid adds two values the upgrade must refuse: a chore execution in the hour New York
// skipped on 2024-03-10, and an `infinity` task completion.
//
// Prints nothing on success; writes the manifest of named values as JSON.

if (PHP_SAPI !== 'cli')
{
	exit('This is a command line script');
}

$options = getopt('', ['app:', 'manifest:', 'invalid', 'seed::']);
$app = rtrim($options['app'] ?? '', '/');
$manifestPath = $options['manifest'] ?? '';
$invalid = array_key_exists('invalid', $options);
$seed = intval($options['seed'] ?? 20261006);
$zone = date_default_timezone_get();

if ($app === '' || $manifestPath === '' || !is_file("$app/packages/autoload.php"))
{
	fwrite(STDERR, "Usage: php generate.php --app=<source tree> --manifest=<file> [--invalid] [--seed=N]\n");
	exit(1);
}

if ($zone !== 'America/New_York')
{
	fwrite(STDERR, "The named expectations are written for America/New_York; date.timezone is $zone.\n");
	exit(1);
}

define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH') ?: "$app/data");
require_once "$app/packages/autoload.php";
require_once VICTUAL_DATAPATH . '/config.php';
require_once "$app/config-dist.php";
define('VICTUAL_USER_ID', 1);

use Victual\Services\BatteriesService;
use Victual\Services\ChoresService;
use Victual\Services\DatabaseService;
use Victual\Services\Labels\LabelIdentityService;
use Victual\Services\StockService;
use Victual\Services\TasksService;

DatabaseService::GetInstance()->SetCurrentUserId(VICTUAL_USER_ID);
$db = DatabaseService::GetInstance()->GetDbConnectionRaw();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$stock = StockService::GetInstance();
$chores = ChoresService::GetInstance();
$batteries = BatteriesService::GetInstance();
$tasks = TasksService::GetInstance();

if ((int)$db->query('SELECT max(migration) FROM migrations')->fetchColumn() !== 288)
{
	fwrite(STDERR, "Expected a database at migration 288 (v0.2.0-MVP).\n");
	exit(1);
}

mt_srand($seed);
$chance = fn(): float => mt_rand() / (mt_getrandmax() + 1);
$one = function (string $sql, array $params = []) use ($db)
{
	$statement = $db->prepare($sql);
	$statement->execute($params);
	return $statement->fetchColumn();
};
$insert = function (string $table, array $row) use ($db): int
{
	$columns = implode(', ', array_keys($row));
	$marks = implode(', ', array_fill(0, count($row), '?'));
	$statement = $db->prepare("INSERT INTO $table ($columns) VALUES ($marks) RETURNING id");
	$statement->execute(array_values($row));
	return (int)$statement->fetchColumn();
};

// 1. Household data, through the source revision's services.

$quId = $insert('quantity_units', ['name' => 'g650-piece', 'name_plural' => 'g650-pieces']);
$locationIds = [];
foreach (['pantry', 'fridge', 'freezer', 'garage'] as $name)
{
	$locationIds[$name] = $insert('locations', ['name' => "g650-$name"]);
}
$shoppingLocationId = $insert('shopping_locations', ['name' => 'g650-market']);

$products = [];
for ($i = 0; $i < 10; $i++)
{
	$products[] = $insert('products', [
		'name' => "g650-product-$i",
		'location_id' => array_values($locationIds)[$i % 3],
		'qu_id_purchase' => $quId, 'qu_id_stock' => $quId, 'qu_id_consume' => $quId, 'qu_id_price' => $quId
	]);
}

$stockRows = $db->prepare('SELECT id, amount, open, location_id FROM stock WHERE product_id = ? ORDER BY id');
$refused = 0;
$day = new DateTimeImmutable('2024-09-20');
for ($d = 0; $d < 90; $d++, $day = $day->modify('+1 day'))
{
	$date = $day->format('Y-m-d');
	foreach ($products as $index => $productId)
	{
		if ($chance() >= ($index < 3 ? 0.5 : 0.15))
		{
			continue;
		}

		$lastLogId = (int)$one('SELECT coalesce(max(id), 0) FROM stock_log');
		$stockRows->execute([$productId]);
		$rows = $stockRows->fetchAll(PDO::FETCH_OBJ);
		$inStock = array_sum(array_map(fn($r) => (float)$r->amount, $rows));
		$roll = $chance();

		try
		{
			if ($inStock < 2 || $roll < 0.35)
			{
				$bestBefore = $day->modify('+' . (3 + mt_rand(0, 40)) . ' days')->format('Y-m-d');
				$stock->AddProduct($productId, mt_rand(1, 4), $bestBefore, StockService::TRANSACTION_TYPE_PURCHASE, $date, round(0.5 + $chance() * 9, 2), null, $shoppingLocationId);
			}
			elseif ($roll < 0.70)
			{
				$stock->ConsumeProduct($productId, 1, $chance() < 0.1, StockService::TRANSACTION_TYPE_CONSUME);
			}
			elseif ($roll < 0.82)
			{
				$stock->OpenProduct($productId, 1);
			}
			elseif ($roll < 0.92)
			{
				$from = (int)$rows[0]->location_id;
				$to = $from === $locationIds['pantry'] ? $locationIds['fridge'] : $locationIds['pantry'];
				$stock->TransferProduct($productId, 1, $from, $to);
			}
			else
			{
				$booking = $one('SELECT max(id) FROM stock_log WHERE product_id = ? AND undone = 0 AND transaction_type = ?', [$productId, StockService::TRANSACTION_TYPE_CONSUME]);
				if ($booking !== null && $booking !== false)
				{
					$stock->UndoBooking($booking);
				}
			}
		}
		catch (Exception $e)
		{
			$refused++;
		}

		// The services stamp a consume's or an open's date from the clock. Give it the booking day.
		$db->prepare('UPDATE stock_log SET used_date = ? WHERE id > ? AND used_date IS NOT NULL')->execute([$date, $lastLogId]);
		$db->prepare('UPDATE stock_log SET opened_date = ? WHERE id > ? AND opened_date IS NOT NULL')->execute([$date, $lastLogId]);
		$db->prepare('UPDATE stock SET opened_date = ? WHERE opened_date IS NOT NULL AND opened_date > ?')->execute([$date, $date]);
	}
}

$choreIds = [
	'daily' => $insert('chores', ['name' => 'g650-water plants', 'period_type' => 'daily', 'period_interval' => 1, 'start_date' => '2024-09-20 07:00:00', 'track_date_only' => 0]),
	'weekly' => $insert('chores', ['name' => 'g650-vacuum', 'period_type' => 'weekly', 'period_interval' => 1, 'period_config' => 'saturday', 'start_date' => '2024-09-21 10:00:00', 'track_date_only' => 0]),
	'dateonly' => $insert('chores', ['name' => 'g650-bins out', 'period_type' => 'daily', 'period_interval' => 7, 'start_date' => '2024-09-23 00:00:00', 'track_date_only' => 1]),
	'fixture' => $insert('chores', ['name' => 'g650-fixture night chore', 'period_type' => 'manually', 'period_interval' => 1, 'start_date' => '2024-09-20 00:00:00', 'track_date_only' => 0])
];
$batteryId = $insert('batteries', ['name' => 'g650-smoke alarm', 'charge_interval_days' => 30]);

$day = new DateTimeImmutable('2024-09-20');
for ($d = 0; $d < 90; $d++, $day = $day->modify('+1 day'))
{
	$date = $day->format('Y-m-d');
	$chores->TrackChore($choreIds['daily'], sprintf('%s 07:%02d:%02d', $date, mt_rand(0, 59), mt_rand(0, 59)));
	if ($day->format('N') === '6')
	{
		$chores->TrackChore($choreIds['weekly'], "$date 10:30:00");
		$batteries->TrackChargeCycle($batteryId, "$date 21:15:00");
	}
	if ($d % 7 === 3)
	{
		$chores->TrackChore($choreIds['dateonly'], "$date 19:00:00");
	}
}

$taskIds = [];
for ($i = 0; $i < 12; $i++)
{
	$due = (new DateTimeImmutable('2024-10-01'))->modify('+' . ($i * 6) . ' days')->format('Y-m-d');
	$taskIds[] = $insert('tasks', ['name' => "g650-task-$i", 'due_date' => $due]);
	if ($i % 2 === 0)
	{
		$tasks->MarkTaskAsCompleted($taskIds[$i], "$due 18:45:00");
	}
}

for ($i = 0; $i < 14; $i++)
{
	$insert('meal_plan', ['day' => (new DateTimeImmutable('2024-10-28'))->modify("+$i days")->format('Y-m-d'), 'type' => 'note', 'note' => "g650-meal-$i"]);
}

$labels = new LabelIdentityService($db);
$epoch = (int)$one('SELECT epoch FROM label_import_state WHERE id = 1');
$labelledStockId = (int)$one('SELECT min(id) FROM stock WHERE amount > 0');
$db->beginTransaction();
$labels->Issue('location', $locationIds['pantry'], $epoch);
$labels->Issue('product', $products[0], $epoch);
$labels->Issue('stock_entry', $labelledStockId, $epoch);
$labels->Issue('location', $locationIds['garage'], $epoch);
$db->commit();
// Retired through the deletion trigger, the path v0.2.0-MVP retires a label by.
$db->exec("DELETE FROM locations WHERE id = {$locationIds['garage']}");

// The named values the services can book themselves are booked here, before the pin, so
// the pin covers the clock values those bookings stamp. Their expectations are explained
// with the others in section 3. Label uids are random; they are renumbered before the pin
// orders by them.

$manifest = ['source_revision_migration' => 288, 'zone' => $zone, 'seed' => $seed, 'legacy' => [], 'instants' => [], 'refused' => [], 'service_refusals' => $refused];

$fixtureExecution = (int)$chores->TrackChore($choreIds['fixture'], '2024-11-03 01:30:00');
$manifest['legacy'][] = ['table' => 'chores_log', 'id' => $fixtureExecution, 'column' => 'tracked_time', 'wall' => '2024-11-03 01:30:00', 'expected' => '2024-11-03T05:30:00.000000Z', 'later' => '2024-11-03T06:30:00.000000Z', 'why' => 'repeated hour, booked through ChoresService::TrackChore()'];
$fixtureExecution = (int)$chores->TrackChore($choreIds['fixture'], '2024-11-03 01:59:59.250000');
$manifest['legacy'][] = ['table' => 'chores_log', 'id' => $fixtureExecution, 'column' => 'tracked_time', 'wall' => '2024-11-03 01:59:59.25', 'expected' => '2024-11-03T05:59:59.250000Z', 'later' => '2024-11-03T06:59:59.250000Z', 'why' => 'near the end of the repeated hour, with a fraction'];
$fixtureCycle = (int)$batteries->TrackChargeCycle($batteryId, '2024-11-03 01:45:30.5');
$manifest['legacy'][] = ['table' => 'battery_charge_cycles', 'id' => $fixtureCycle, 'column' => 'tracked_time', 'wall' => '2024-11-03 01:45:30.5', 'expected' => '2024-11-03T05:45:30.500000Z', 'later' => '2024-11-03T06:45:30.500000Z', 'why' => 'repeated hour, booked through BatteriesService::TrackChargeCycle()'];
$tasks->MarkTaskAsCompleted($taskIds[1], '2024-11-03 01:05:00');
$manifest['legacy'][] = ['table' => 'tasks', 'id' => $taskIds[1], 'column' => 'done_timestamp', 'wall' => '2024-11-03 01:05:00', 'expected' => '2024-11-03T05:05:00.000000Z', 'later' => '2024-11-03T06:05:00.000000Z', 'why' => 'repeated hour, through TasksService::MarkTaskAsCompleted()'];

if ($invalid)
{
	$skipped = (int)$chores->TrackChore($choreIds['fixture'], '2024-03-10 02:30:00');
	$manifest['refused'][] = ['table' => 'chores_log', 'id' => $skipped, 'column' => 'tracked_time', 'wall' => '2024-03-10 02:30:00', 'why' => 'New York skipped 02:00-03:00 on 2024-03-10'];
}

$labelIndex = 0;
foreach ($db->query('SELECT uid FROM labels ORDER BY kind, coalesce(target_id, 0), uid')->fetchAll(PDO::FETCH_COLUMN) as $uid)
{
	$db->prepare('UPDATE labels SET uid = ? WHERE uid = ?')->execute([sprintf('0G650%08d', ++$labelIndex), $uid]);
}

// 2. The pin. Every value the clock supplied is replaced with one derived from row order.

$keep = ['chores_log.tracked_time', 'battery_charge_cycles.tracked_time', 'tasks.done_timestamp', 'chores.start_date'];
$columns = $db->query(<<<'SQL'
	SELECT c.table_name, c.column_name, c.data_type,
		(SELECT string_agg(quote_ident(a.attname), ', ' ORDER BY a.attnum)
			FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey)
			WHERE i.indrelid = format('public.%I', c.table_name)::regclass AND i.indisprimary) AS pk
	FROM information_schema.columns c
	JOIN information_schema.tables t USING (table_schema, table_name)
	WHERE c.table_schema = 'public' AND t.table_type = 'BASE TABLE'
		AND c.data_type IN ('timestamp without time zone', 'timestamp with time zone')
	ORDER BY c.table_name, c.column_name
	SQL)->fetchAll(PDO::FETCH_ASSOC);

$ordinal = 0;
foreach ($columns as $column)
{
	$qualified = "{$column['table_name']}.{$column['column_name']}";
	if (in_array($qualified, $keep, true) || $qualified === 'system_db_changed_time.changed_time')
	{
		continue;
	}
	$ordinal++;
	$table = $column['table_name'];
	$name = $column['column_name'];
	if ($column['pk'] === null)
	{
		throw new RuntimeException("$table has no primary key to order the pin by");
	}

	if ($column['data_type'] === 'timestamp without time zone')
	{
		// 5 h 7 min 11.123457 s apart, so the fractions carry all six digits.
		$value = "timestamp '2024-01-01 00:00:00' + interval '1 day 1 hour 1 minute' * $ordinal + interval '5 hours 7 minutes 11.123457 seconds' * n.rn";
		$value = "CASE WHEN (($value) AT TIME ZONE current_setting('TimeZone')) AT TIME ZONE current_setting('TimeZone') <> ($value) THEN ($value) + interval '1 hour' ELSE ($value) END";
	}
	else
	{
		$value = "timestamptz '2024-02-01 00:00:00+00' + interval '1 day 1 hour 1 minute' * $ordinal + interval '7 hours 3 minutes 1.000001 seconds' * n.rn";
	}
	$db->exec(<<<SQL
		UPDATE $table t SET $name = $value
		FROM (SELECT {$column['pk']}, row_number() OVER (ORDER BY {$column['pk']}) AS rn FROM $table WHERE $name IS NOT NULL) n
		WHERE (t.{$column['pk']}) = (n.{$column['pk']}) AND t.$name IS NOT NULL
		SQL);
}

// 3. Named fixture values. Each `expected` is written out by hand from the 2024 New York
// rules (EDT, UTC-4, until 2024-11-03 02:00 EDT; EST, UTC-5, after; 2024-03-10 02:00-03:00
// EST skipped). In the repeated hour 01:00-01:59 on 2024-11-03 the earlier instant is the EDT
// one, and `later` records the EST reading the conversion must not pick.

$named = function (string $table, int $id, string $column, string $wall, string $expected, ?string $later, string $why) use (&$manifest, $db)
{
	$db->prepare("UPDATE $table SET $column = ? WHERE id = ?")->execute([$wall, $id]);
	$manifest['legacy'][] = compact('table', 'id', 'column', 'wall', 'expected', 'later', 'why');
};

$ledgerIds = $db->query('SELECT id FROM stock_log ORDER BY id LIMIT 5')->fetchAll(PDO::FETCH_COLUMN);
$named('stock_log', (int)$ledgerIds[0], 'row_created_timestamp', '2024-11-03 01:20:00.000001', '2024-11-03T05:20:00.000001Z', '2024-11-03T06:20:00.000001Z', 'repeated hour on a ledger row, one microsecond');
$named('stock_log', (int)$ledgerIds[1], 'row_created_timestamp', '2024-11-03 00:59:59.999999', '2024-11-03T04:59:59.999999Z', null, 'control: last microsecond before the repeated hour, EDT');
$named('stock_log', (int)$ledgerIds[2], 'row_created_timestamp', '2024-11-03 02:00:00', '2024-11-03T07:00:00.000000Z', null, 'control: first wall clock after the repeated hour, EST');
$named('stock_log', (int)$ledgerIds[3], 'row_created_timestamp', '2024-03-10 01:59:59', '2024-03-10T06:59:59.000000Z', null, 'control: last second before the skipped hour, EST');
$named('stock_log', (int)$ledgerIds[4], 'row_created_timestamp', '2024-03-10 03:00:00', '2024-03-10T07:00:00.000000Z', null, 'control: first wall clock after the skipped hour, EDT');
$named('tasks', $taskIds[3], 'done_timestamp', '2024-07-04 12:00:00', '2024-07-04T16:00:00.000000Z', null, 'ordinary summer timestamp');
$named('tasks', $taskIds[5], 'done_timestamp', '2024-12-25 08:30:15', '2024-12-25T13:30:15.000000Z', null, 'ordinary winter timestamp');

// Existing instants: their meaning must not move. Each is set as a literal UTC instant.
// 0G65000000001 is the retired garage label (ordered first: its target is gone).
foreach ([
	['0G65000000001', 'retired_at', '2024-11-03T05:30:00.123456Z', 'a retirement instant inside the repeated hour, with microseconds'],
	['0G65000000002', 'row_created_timestamp', '2024-06-15T16:34:56.000001Z', 'a live location label created at an instant with one microsecond'],
	['0G65000000004', 'row_created_timestamp', '2024-03-10T07:00:00.5Z', 'a stock entry label created at the instant the 2024 spring gap ended'],
] as [$uid, $column, $instant, $why])
{
	$update = $db->prepare("UPDATE labels SET $column = ?::timestamptz WHERE uid = ?");
	$update->execute([$instant, $uid]);
	if ($update->rowCount() !== 1)
	{
		throw new RuntimeException("No label $uid to set $column on");
	}
	$manifest['instants'][] = ['table' => 'labels', 'uid' => $uid, 'column' => $column, 'instant' => $instant, 'why' => $why];
}

if ($invalid)
{
	$db->prepare("UPDATE tasks SET done = 1, done_timestamp = 'infinity' WHERE id = ?")->execute([$taskIds[7]]);
	$manifest['refused'][] = ['table' => 'tasks', 'id' => $taskIds[7], 'column' => 'done_timestamp', 'wall' => 'infinity', 'why' => 'not a finite wall clock'];
}

// Last, so nothing above moves it again.
$db->exec("UPDATE system_db_changed_time SET changed_time = '2024-12-31 23:59:59'");

file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
