<?php

// Mints an API key for the admin account (user 1) with v0.5.0's own ApiKeyService and prints
// its plaintext, so the restore drill can call the API without logging in. drill.sh mounts
// this file into the migrate image beside the application's code, so it runs under the
// artifact's PHP and against the artifact's code, as victual_migrate.

use Victual\Services\ApiKeyService;
use Victual\Services\DatabaseService;

if (!defined('VICTUAL_DATAPATH'))
{
	define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH') ?: __DIR__ . '/../data');
}

require_once __DIR__ . '/../packages/autoload.php';
require_once __DIR__ . '/../config-dist.php';

define('VICTUAL_USER_ID', 1);
DatabaseService::GetInstance()->SetCurrentUserId(VICTUAL_USER_ID);

echo ApiKeyService::GetInstance()->CreateApiKey(ApiKeyService::API_KEY_TYPE_DEFAULT, 'restore drill', null, null, 1) . PHP_EOL;
