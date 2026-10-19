<?php

define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
require_once '/app/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once '/app/config-dist.php';
define('VICTUAL_USER_ID', 1);

Victual\Services\DatabaseService::GetInstance()->SetCurrentUserId(1);
$stock = Victual\Services\StockService::GetInstance()->GetProductDetails(1);
$chore = Victual\Services\ChoresService::GetInstance()->GetChoreDetails(1);
$result = [
	'product' => $stock['product']->name,
	'stock_amount' => (float)$stock['stock_amount'],
	'chore' => $chore['chore']->name,
	'tracked_count' => (int)$chore['tracked_count'],
	'average_execution_frequency_hours' => (float)$chore['average_execution_frequency_hours'],
];
if ($result !== [
	'product' => 'Upgrade audit oats',
	'stock_amount' => 3.0,
	'chore' => 'Upgrade audit chore',
	'tracked_count' => 3,
	'average_execution_frequency_hours' => 24.5,
])
{
	throw new RuntimeException('Candidate read mismatch: ' . json_encode($result));
}
echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
