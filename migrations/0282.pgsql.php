<?php

// The price-visibility policy becomes a re-appliable seed file, and gains the two
// product_barcodes rows migration 0281 missed. Issue #176 items 1 and 3.
//
// WHY A MIGRATION AT ALL WHEN 0281 ALREADY SEEDED THESE ROWS. Two reasons, and neither is
// the file move on its own. The first is the two new rows: product_barcodes.last_price is a
// purchase price that /objects/product_barcodes and /objects/product_barcodes_view hand to
// anyone holding STOCK_VIEW, and an installation that already ran 0281 has no policy row for
// it. The second is that db/pgsql/prices-seed.sql has to be the file this policy is read
// from - DatabaseImporter re-applies it after every import, because TRUNCATE ... CASCADE on
// permission_hierarchy empties permission_fields - and a file that only the importer ever
// runs is a file whose drift from the migration nothing notices. Running it here as well
// means a fresh installation and an imported one converge on the same rows through the same
// statements.
//
// WHY 0281 IS NOT EDITED INSTEAD. It is applied history: an installation that ran it records
// 281 and never looks at the file again, so rewriting it would change what a fresh database
// gets without changing what an existing one has. The seed file's statements are idempotent
// (see its header), so this migration is a no-op on a database that ran 0281 except for the
// two rows that are new.
//
// WHY 0282 AND NOT 0284. migrations/RESERVATIONS.md holds 0282 and 0283 for plan 22, which is
// a draft with no delivery slot and no file on disk. Its own rule - "the numbers that get
// written take the lowest free slots, and claims without files behind them yield" - puts this
// migration at 0282 and moves plan 22 up to 0283-0284, which is what that table now says.

use Victual\Services\DatabaseService;

// PHP migrations own their transaction, the way 0266 (roles-seed.sql) does.
DatabaseService::GetInstance()->InTransaction(function ()
{
	$db = DatabaseService::GetInstance()->GetDbConnectionRaw();
	$db->exec(file_get_contents(__DIR__ . '/../db/pgsql/prices-seed.sql'));
});
