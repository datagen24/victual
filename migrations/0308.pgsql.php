<?php

// The MASTER_DATA_ADD permission: the right to create shared master data, below
// MASTER_DATA_EDIT. ADR-0043 decision 1, issue 742.
//
// The statement lives in db/pgsql/master-data-add-seed.sql, which carries the reasoning (why
// the row is a child of MASTER_DATA_EDIT and why no user_permissions backfill is written).
// It is a file because DatabaseImporter replays it after every legacy import, and a file
// read by both callers cannot drift from itself. This is the pattern of 0282.pgsql.php.
//
// A fresh installation runs this too: the baseline's permission rows come from
// InitialDataSeeder, which stops at migration 0110's thirty, and every migration above the
// baseline runs on a new database.

use Victual\Services\DatabaseService;

// PHP migrations own their transaction, the way 0266 (roles-seed.sql) does.
DatabaseService::GetInstance()->InTransaction(function ()
{
	$db = DatabaseService::GetInstance()->GetDbConnectionRaw();
	$db->exec(file_get_contents(__DIR__ . '/../db/pgsql/master-data-add-seed.sql'));
});
