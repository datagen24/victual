<?php

// Grows a realistic stock ledger by driving StockService, for measuring how the stock_log
// cache rebuild scales with ledger size (issue: stock_edited_entries' quadratic plan).
//
//   php .devtools/pgsql/ledger-generator.php --products=N --days=N [--seed=N] [--start=YYYY-MM-DD]
//
// It books through the same service methods the API calls, so the ledger has the shapes a
// household produces: several purchases per product at varying prices and due dates, FIFO
// consumes that span entries, partial opens that split an entry and record its origin in
// stock_entry_origins, edits of an entry's amount or price, undone bookings, and transfers.
// A fixed seed gives the same sequence of calls; ids differ only if the database was not
// empty to begin with.
//
// The three stock_log cache triggers are disabled while it runs and re-enabled at the end.
// The ledger rows do not depend on the caches, and leaving the triggers on would make
// generating a large ledger cost exactly what is being measured. The caches are therefore
// stale afterwards until reconcile_stock_log_cache() runs, which is itself one of the
// measurements. Run bin/victual-migrate against the database first. The database comes
// from config.php, as for every bin/ script.
//
// Prints one line: the database's stock_log, stock and stock_entry_origins row counts, and
// how many stock_log rows are live edits ("stock-edit-new") and undone bookings.

use Victual\Services\DatabaseService;
use Victual\Services\StockService;

if (PHP_SAPI !== 'cli')
{
	exit('This is a command line script');
}

$options = getopt('', ['products:', 'days:', 'seed::', 'start::']);
$productCount = intval($options['products'] ?? 0);
$dayCount = intval($options['days'] ?? 0);
$seed = intval($options['seed'] ?? 20261005);
$start = $options['start'] ?? '2024-01-01';

if ($productCount < 1 || $dayCount < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start))
{
	fwrite(STDERR, "Usage: php .devtools/pgsql/ledger-generator.php --products=N --days=N [--seed=N] [--start=YYYY-MM-DD]\n");
	exit(1);
}

if (!defined('VICTUAL_DATAPATH'))
{
	define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH') ?: __DIR__ . '/../../data');
}

require_once __DIR__ . '/../../packages/autoload.php';

if (file_exists(VICTUAL_DATAPATH . '/config.php'))
{
	require_once VICTUAL_DATAPATH . '/config.php';
}

require_once __DIR__ . '/../../config-dist.php';

if (!defined('VICTUAL_USER_ID'))
{
	define('VICTUAL_USER_ID', 1);
}

DatabaseService::GetInstance()->SetCurrentUserId(VICTUAL_USER_ID);
$db = DatabaseService::GetInstance()->GetDbConnectionRaw();
$stock = StockService::GetInstance();

mt_srand($seed);

// mt_rand() in [0, 1).
$chance = fn(): float => mt_rand() / (mt_getrandmax() + 1);

$cacheTriggers = 'stock_log_ins, stock_log_upd, stock_log_del';
$db->exec('ALTER TABLE stock_log DISABLE TRIGGER ' . str_replace(', ', ', DISABLE TRIGGER ', $cacheTriggers));

try
{
	$tag = 'ledgergen' . $seed;
	$db->exec("INSERT INTO quantity_units (name, name_plural) VALUES ('{$tag}-unit', '{$tag}-units')");
	$quId = (int)$db->lastInsertId();

	$locationIds = [];
	foreach (['pantry', 'fridge', 'freezer'] as $name)
	{
		$db->exec("INSERT INTO locations (name) VALUES ('{$tag}-{$name}')");
		$locationIds[] = (int)$db->lastInsertId();
	}

	$shoppingLocationIds = [];
	foreach (['market', 'grocer'] as $name)
	{
		$db->exec("INSERT INTO shopping_locations (name) VALUES ('{$tag}-{$name}')");
		$shoppingLocationIds[] = (int)$db->lastInsertId();
	}

	$products = [];
	$insertProduct = $db->prepare('INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock, qu_id_consume, qu_id_price) VALUES (?, ?, ?, ?, ?, ?)');
	for ($i = 0; $i < $productCount; $i++)
	{
		$insertProduct->execute(["{$tag}-product-{$i}", $locationIds[$i % count($locationIds)], $quId, $quId, $quId, $quId]);
		$products[] = [
			'id' => (int)$db->lastInsertId(),
			'basePrice' => round(0.5 + $chance() * 12, 2),
			// Staples are bought and used often; the long tail rarely.
			'activity' => $i % 5 === 0 ? 0.45 : ($i % 3 === 0 ? 0.2 : 0.08)
		];
	}

	$stockRows = $db->prepare('SELECT id, amount, open FROM stock WHERE product_id = ? ORDER BY id');
	$lastBooking = $db->prepare('SELECT MAX(id) FROM stock_log WHERE product_id = ? AND undone = 0');

	$day = new DateTimeImmutable($start);
	for ($d = 0; $d < $dayCount; $d++, $day = $day->modify('+1 day'))
	{
		$date = $day->format('Y-m-d');

		foreach ($products as $product)
		{
			if ($chance() >= $product['activity'])
			{
				continue;
			}

			$productId = $product['id'];
			$stockRows->execute([$productId]);
			$rows = $stockRows->fetchAll(PDO::FETCH_OBJ);
			$inStock = array_sum(array_map(fn($r) => (float)$r->amount, $rows));
			$roll = $chance();

			try
			{
				if ($inStock < 2 || $roll < 0.35)
				{
					$price = round($product['basePrice'] * (0.8 + $chance() * 0.4), 2);
					$due = $day->modify('+' . (5 + mt_rand(0, 360)) . ' days')->format('Y-m-d');
					$stock->AddProduct($productId, mt_rand(1, 6), $due, StockService::TRANSACTION_TYPE_PURCHASE, $date, $price,
						$locationIds[mt_rand(0, count($locationIds) - 1)], $shoppingLocationIds[mt_rand(0, count($shoppingLocationIds) - 1)]);
				}
				elseif ($roll < 0.70)
				{
					// Usually a whole unit or two; sometimes enough to drain several entries.
					$amount = min($inStock, $chance() < 0.85 ? mt_rand(1, 2) : mt_rand(3, 8));
					$stock->ConsumeProduct($productId, $amount, $chance() < 0.05, StockService::TRANSACTION_TYPE_CONSUME);
				}
				elseif ($roll < 0.85)
				{
					// A partial open of an unopened entry holding more than one unit splits it
					// and records the remainder's origin.
					$stock->OpenProduct($productId, 1);
				}
				elseif ($roll < 0.92)
				{
					$row = $rows[mt_rand(0, count($rows) - 1)];
					$entry = $db->query('SELECT * FROM stock WHERE id = ' . intval($row->id))->fetch(PDO::FETCH_OBJ);
					$newAmount = max(1, (float)$entry->amount + ($chance() < 0.5 ? -1 : 1));
					$newPrice = $chance() < 0.5 ? round((float)$entry->price * (0.9 + $chance() * 0.2), 2) : $entry->price;
					$stock->EditStockEntry((int)$entry->id, $newAmount, $entry->best_before_date, $entry->location_id,
						$entry->shopping_location_id, $newPrice, (int)$entry->open, $entry->purchased_date, $entry->note);
				}
				elseif ($roll < 0.96)
				{
					$lastBooking->execute([$productId]);
					$bookingId = $lastBooking->fetchColumn();
					if ($bookingId !== null && $bookingId !== false)
					{
						$stock->UndoBooking((int)$bookingId);
					}
				}
				else
				{
					$row = $rows[mt_rand(0, count($rows) - 1)];
					$from = (int)$db->query('SELECT location_id FROM stock WHERE id = ' . intval($row->id))->fetchColumn();
					$to = $locationIds[($from + 1) % count($locationIds)] ?? $locationIds[0];
					if ($to !== $from)
					{
						$stock->TransferProduct($productId, 1, $from, $to);
					}
				}
			}
			catch (Exception $ex)
			{
				// A refused booking (an undo something depends on, a transfer of an opened
				// remainder) is something a household meets too; skip it and carry on.
			}
		}
	}
}
finally
{
	$db->exec('ALTER TABLE stock_log ENABLE TRIGGER ' . str_replace(', ', ', ENABLE TRIGGER ', $cacheTriggers));
}

echo 'stock_log=' . $db->query('SELECT COUNT(*) FROM stock_log')->fetchColumn()
	. ' stock=' . $db->query('SELECT COUNT(*) FROM stock')->fetchColumn()
	. ' stock_entry_origins=' . $db->query('SELECT COUNT(*) FROM stock_entry_origins')->fetchColumn()
	. ' edits=' . $db->query("SELECT COUNT(*) FROM stock_log WHERE transaction_type = 'stock-edit-new'")->fetchColumn()
	. ' undone=' . $db->query('SELECT COUNT(*) FROM stock_log WHERE undone = 1')->fetchColumn()
	. PHP_EOL;
