<?php
// 0.4.4 -> 0.5.0 upgrade rehearsal fixture. Runs against the SOURCE tree (--app=v0.4.4)
// at migration 312 and books household data through that revision's own services.
//   php fixture.php --app=<v0.4.4 tree> [--nan]
$options = getopt('', ['app:', 'nan']);
$app = rtrim($options['app'] ?? '', '/');
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once "$app/packages/autoload.php";
require_once VICTUAL_DATAPATH . '/config.php';
require_once "$app/config-dist.php";
define('VICTUAL_USER_ID', 1);

use Victual\Services\ChoresService;
use Victual\Services\DatabaseService;
use Victual\Services\StockService;

DatabaseService::GetInstance()->SetCurrentUserId(VICTUAL_USER_ID);
$db = DatabaseService::GetInstance()->GetDbConnectionRaw();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$migration = (int)$db->query('SELECT max(migration) FROM migrations')->fetchColumn();
if ($migration !== 312)
{
	fwrite(STDERR, "Expected migration 312, found $migration\n");
	exit(1);
}

$insert = function (string $table, array $row) use ($db): int
{
	$columns = implode(', ', array_keys($row));
	$marks = implode(', ', array_fill(0, count($row), '?'));
	$statement = $db->prepare("INSERT INTO $table ($columns) VALUES ($marks) RETURNING id");
	$statement->execute(array_values($row));
	return (int)$statement->fetchColumn();
};

$qu = $insert('quantity_units', ['name' => 'r050-piece', 'name_plural' => 'r050-pieces']);
$location = $insert('locations', ['name' => 'r050-pantry']);
$product = fn(string $name) => $insert('products', ['name' => $name, 'location_id' => $location,
	'qu_id_purchase' => $qu, 'qu_id_stock' => $qu, 'qu_id_consume' => $qu, 'qu_id_price' => $qu]);
$oats = $product('Upgrade audit oats');
$salt = $product('Upgrade audit salt');
$tea = $product('Upgrade audit tea');

$stock = StockService::GetInstance();
$stock->AddProduct($oats, 3, '2027-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-10-01', 2.5);
$stock->ConsumeProduct($oats, 1, false, StockService::TRANSACTION_TYPE_CONSUME);
$stock->AddProduct($salt, 2, '2027-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-10-01', 0);
$stock->AddProduct($tea, 1, '2027-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-10-01', null);

$chore = $insert('chores', ['name' => 'Upgrade audit chore', 'period_type' => 'daily', 'period_interval' => 1,
	'start_date' => '2026-10-01 07:00:00', 'track_date_only' => 0]);
foreach (['2026-10-01 07:00:00', '2026-10-02 07:30:00', '2026-10-03 08:00:00'] as $at)
{
	ChoresService::GetInstance()->TrackChore($chore, $at);
}

if (array_key_exists('nan', $options))
{
	// A value 0.4.4 could store and 0.5.0's migration 0313 refuses.
	$db->prepare("UPDATE stock SET price = 'NaN' WHERE product_id = ?")->execute([$oats]);
}

echo "fixture written at migration $migration\n";
