<?php

// PHP migrations own their transaction. SQLite remains frozen at 0265.
use Victual\Services\DatabaseService;

DatabaseService::GetInstance()->InTransaction(function ()
{
	$db = DatabaseService::GetInstance()->GetDbConnectionRaw();
	$db->exec(file_get_contents(__DIR__ . '/../db/pgsql/roles-schema.sql'));
	$db->exec(file_get_contents(__DIR__ . '/../db/pgsql/roles-seed.sql'));
});
