<?php

define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once '/app/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once '/app/config-dist.php';
define('VICTUAL_USER_ID', 1);

$service = Victual\Services\DatabaseService::GetInstance();
$service->SetCurrentUserId(1);
$db = $service->GetDbConnectionRaw();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id = (int)$db->query("INSERT INTO products (name, location_id, qu_id_purchase, qu_id_stock) VALUES ('Upgrade audit oats', 2, 2, 2) RETURNING id")->fetchColumn();
$stock = Victual\Services\StockService::GetInstance();
$stock->AddProduct($id, 3.0, '2027-01-31', $stock::TRANSACTION_TYPE_PURCHASE, '2026-10-15', 2.5, 2);

$choreId = (int)$db->query("INSERT INTO chores (name, period_type) VALUES ('Upgrade audit chore', 'manually') RETURNING id")->fetchColumn();
$log = $db->prepare('INSERT INTO chores_log (chore_id, tracked_time, done_by_user_id) VALUES (?, ?, 1)');
foreach (['2026-01-01 12:00:00+00', '2026-01-02 12:00:00+00', '2026-01-03 13:00:00+00'] as $time)
{
	$log->execute([$choreId, $time]);
}

echo "Populated with old source: product $id, chore $choreId, one stock purchase and three chore executions.\n";
