<?php

// Issue #650, ADR-0027 decision 2 (revised 2026-10-04): every legacy TIMESTAMP column
// becomes TIMESTAMPTZ. The work is in services/Database/TimestampMigration.php, whose
// docblock states the conversion rule, the preflight and what the data cannot tell it.
//
// A PHP migration because the conversion needs the configured time zone, which is PHP's
// default zone and is not something a .sql file can name, and because the preflight's
// refusal has to say what it found in terms an operator can act on.
//
// The runner wraps this include and its version row in one transaction, so a refusal or a
// failure leaves the schema exactly as it was.

use Victual\Services\Database\TimestampMigration;
use Victual\Services\DatabaseService;

// The report is kept on the class for bin/victual-migrate to print, unless it was asked to be
// quiet; a migration has no business writing to a request's output.
TimestampMigration::$LastReport = (new TimestampMigration(DatabaseService::GetInstance()->GetDbConnectionRaw()))
	->Apply(date_default_timezone_get());
