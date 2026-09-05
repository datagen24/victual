#!/usr/bin/env bash
#
# The differential test suite: does this fork behave identically on SQLite and
# PostgreSQL?
#
# That question outlives the engine, deliberately. ADR-0008 retired SQLite as a runtime
# engine and its option C keeps this harness through the transition, as the check that the
# retirement itself changed nothing - until plan 14 piece 2's response snapshot replaces it.
# So the suite still builds a SQLite side, through an escape hatch no installation has (see
# DIFFTEST_SQLITE_RUNTIME below), and everything here goes when that snapshot lands.
#
#   .devtools/pgsql/run-tests.sh [migrate|views|triggers|rollback|filter|schema|richtext|files|mqtt|import|rbac|chores]
#
# Eleven kinds of check, for eleven reasons. Views are compared by what they return, because
# that is all a view is. Triggers cannot be compared that way — what a trigger does is
# change other rows — so those scripts are applied to both engines and every table is
# compared afterwards.
#
# Before either of those means anything, though, the two engines have to start from the
# same place. The migration check compares two databases that have been migrated and
# nothing else, which is precisely what the other three cannot see: every one of them
# populates PostgreSQL by copying an already-migrated SQLite database, so all of them
# start from a state that was constructed rather than migrated. That blind spot hid a
# real defect — the PostgreSQL baseline being schema only, so a fresh database had no
# admin user and no quantity units — so this phase runs first.
#
# The fourth asks something none of the others can. The first three drive SQL straight at
# each engine and never enter the application, so none would notice if a write path
# stopped being transactional. The rollback tests go through StockService, fail an
# operation halfway, and check the ledger is where it started — on each engine in turn
# rather than against the other.
#
# The fifth closes the gap the other four leave between them: application code that
# builds SQL differently per engine. The rollback phase enters the application but asks
# one engine at a time; the first three compare engines but never enter the application.
# Hazard 16 lived in exactly that hole — the "~" filter operator meant "case insensitive"
# on SQLite and "case sensitive" on PostgreSQL for as long as the controller spelled LIKE
# itself, with an identical response shape either way. The filter phase asks both engines
# the same question through the dialects and compares the rows.
#
# The sixth asks what the application believes about the schema it is sitting on. The boot
# check refuses to serve when the migrations the code ships and the migrations the database
# recorded are not the same set, and it has to tell a database that has never been migrated
# apart from a database it cannot reach — a distinction the two engines spell completely
# differently, since SQLite reports nearly every failure as HY000. Nothing else here ever
# asks the application that question.
#
# The seventh is not a comparison at all, and is here because there is nowhere better. The
# files table exists on PostgreSQL only — the configuration that reads it cannot exist on
# SQLite — so the one command that writes to it, bin/victual-files-import, has no engine
# to be compared against and had no coverage anywhere. What it needs asserting is that it
# can tell an imported file from a stale one, since an operator deletes the source volume
# on its say-so. Like the rollback phase it asks one engine a question.
#
# The eighth is about rows rather than schema, and it is a differential check because the
# routine it exercises quotes identifiers through the dialect and writes through PDO - the
# two things that differ per engine. Five columns are rendered as HTML rather than escaped,
# so their boundary is the purifier every API write goes through; a row that arrived any
# other way never met it. An in-place upgrade from a database predating the purifier is one
# such way, and DatabaseImporter - which copies verbatim, as it should - is the other. The
# rich text phase plants payloads with a direct write, the way the gap does, and asserts
# that migration 0260 and the importer both clean them up. Two of its cases are controls
# rather than assertions about danger: real summernote formatting has to survive, and a
# column that is *not* HTML-rendered has to be left exactly as typed.
#
# The ninth is not a differential check at all, and it is here because the alternative
# was worse. Plan 18's published-state and outbox probes guard eight defects that produce
# no error of any kind - a stale retained topic, an event lost after a commit, a
# redelivered point that duplicates instead of overwriting, an MQTT client id that lost its
# randomness, a malformed payload written out as zeros and acknowledged, a rewound
# db-changed-time that hides a committed change from every polling client, an event marked
# delivered on a redirect to a login page, and a boot publish that skips the per-product
# topics the broker no longer has. Probes that nothing runs are documentation, so they run
# here, where the fixes are protected by the same green light everything else is held to.
# Two of them run against stand-ins rather than the real thing - a PHP built-in server for
# InfluxDB, a PHP stream socket for the broker - which is what keeps the phase dependency
# free, and is also the limit of what it proves.
#
# The tenth is what ADR-0008's retirement left behind. SQLite is an import format now, so
# bin/victual-db-import reads something no engine here produces and nothing else in this
# suite would notice it drifting. The import phase drives that command against committed
# fixtures at both ends of the supported migration span and asserts what the target holds
# afterwards - including the two row transformations the target's own migration run cannot
# see, because it migrates an empty database and the rows arrive after it. It is the only
# phase whose input is a file in the repository rather than a database this script built,
# and that is the point of it.
#
# The eleventh asks one engine at a time, like the rollback and schema phases, and it is
# here because the alternative was nowhere. ChoresService::CalculateNextExecutionAssignment()
# picks the next user for a chore, and two of its four strategies pick from an array PHP
# built out of assignment_config - so a group that resolves to nobody is a pure-PHP hazard
# no view comparison could see. "random" picked with array_rand() in the branch an empty
# group fell into, which is a ValueError and reached a client as a 500;
# "in-alphabetical-order" read ->id off the null array_shift() returns, which is a warning
# and reached one as the null it should have answered with in the first place. A third
# strategy, "who-least-did-first", reads chores_execution_users_statistics instead, which is
# why the phase runs on both engines rather than one: what an empty group means to that view
# is a per-engine answer. Each empty case is paired with a populated control, so a guard that
# answered null to everything fails here rather than passing.
#
# This script is deliberately thin: it builds the databases, loops, and collects exit
# codes. Everything that has to decide whether two result sets are the same is PHP, in
# difftest.php, trigdifftest.php and migratedifftest.php, which share their normalisation
# rules with the application through Victual\Services\Database\ValueComparison.
#
# Connection settings come from the environment. The two PHP tools were written with
# disjoint variable namespaces (DIFFTEST_* and TRIGTEST_*) and this is where they are
# reconciled onto one set, so that running the suite is a command rather than a recipe.
#
#   PGHOST, PGPORT, PGUSER, PGPASSWORD   PostgreSQL connection (libpq's own names)
#   SUITE_PGSQL_VIEW_DB                  database for the view tests    (default victual_full)
#   SUITE_PGSQL_TRIGGER_DB               database for the trigger tests (default victual_trig)
#   SUITE_PGSQL_MIGRATE_DB               database for the migration test (default victual_migrate)
#   SUITE_PGSQL_ROLLBACK_DB              database for the rollback tests (default victual_rollback)
#   SUITE_PGSQL_FILTER_DB                database for the filter tests  (default victual_filter)
#   SUITE_PGSQL_SCHEMA_DB                database for the schema gate   (default victual_schema)
#   SUITE_PGSQL_RICHTEXT_DB              database for the rich text phase (default victual_richtext)
#   SUITE_PGSQL_FILES_DB                 database for the file import tests (default victual_files)
#   SUITE_PGSQL_MQTT_DB                  database for the mqtt tests    (default victual_mqtt)
#   SUITE_PGSQL_IMPORT_DB                database for the import tests  (default victual_import)
#   SUITE_PGSQL_CHORES_DB                database for the chore assignment tests (default victual_chores)
#   SUITE_MQTT_STANDIN_PORT              port for the stand-in InfluxDB (default 8390)
#   SUITE_MQTT_BROKER_PORT               port for the recording MQTT stand-in (default 8391)
#   SUITE_SCRATCH                        where the throwaway databases go
#   SUITE_ALLOW_RESERVED_HOLES           set to 1 to waive a migration number that
#                                        migrations/RESERVATIONS.md says an unmerged branch
#                                        owns; never set in CI
#   SUITE_COVERAGE                       set to 1 to measure line coverage of the run
#   SUITE_COVERAGE_DIR                   where the coverage data goes (default under SUITE_SCRATCH)
#   SUITE_COVERAGE_CLOVER                also write a Clover XML report to this path
#
# The coverage variables are documented in full in .devtools/coverage/README.md.
#
# Under docker compose all of these are already set; see docker-compose.yml.

set -euo pipefail

VICTUAL_ROOT="${VICTUAL_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
export VICTUAL_ROOT

SUITE_DIR="$VICTUAL_ROOT/.devtools/pgsql"
SUITE_SCRATCH="${SUITE_SCRATCH:-${TMPDIR:-/tmp}/victual-suite}"

PGHOST="${PGHOST:-127.0.0.1}"
PGPORT="${PGPORT:-5432}"
PGUSER="${PGUSER:-victual}"
PGPASSWORD="${PGPASSWORD:-victual}"
export PGHOST PGPORT PGUSER PGPASSWORD

VIEW_DB="${SUITE_PGSQL_VIEW_DB:-victual_full}"
TRIGGER_DB="${SUITE_PGSQL_TRIGGER_DB:-victual_trig}"
MIGRATE_DB="${SUITE_PGSQL_MIGRATE_DB:-victual_migrate}"
ROLLBACK_DB="${SUITE_PGSQL_ROLLBACK_DB:-victual_rollback}"
FILTER_DB="${SUITE_PGSQL_FILTER_DB:-victual_filter}"
SCHEMA_DB="${SUITE_PGSQL_SCHEMA_DB:-victual_schema}"
RICHTEXT_DB="${SUITE_PGSQL_RICHTEXT_DB:-victual_richtext}"
FILES_DB="${SUITE_PGSQL_FILES_DB:-victual_files}"
MQTT_DB="${SUITE_PGSQL_MQTT_DB:-victual_mqtt}"
IMPORT_DB="${SUITE_PGSQL_IMPORT_DB:-victual_import}"
CHORES_DB="${SUITE_PGSQL_CHORES_DB:-victual_chores}"
MQTT_STANDIN_PORT="${SUITE_MQTT_STANDIN_PORT:-8390}"
MQTT_BROKER_PORT="${SUITE_MQTT_BROKER_PORT:-8391}"

# ADR-0008 retired SQLite as a runtime engine: DB_DRIVER no longer accepts it and
# DatabaseDialect::Create() refuses to construct the dialect. This suite is the one caller
# that still has to, because what it measures is that the retirement changed nothing - see
# DatabaseDialect::SQLITE_TOOLING_ENV, and ADR-0008's option C for why the harness outlives
# the engine it compares against. Exported once, here, so that every phase and every child
# process the phases start inherits it and no phase has to remember.
export DIFFTEST_SQLITE_RUNTIME=1

WHICH="${1:-all}"

say() { printf '%s\n' "$*"; }

fail() { printf '%s\n' "$*" >&2; exit 1; }

command -v php >/dev/null || fail 'php not found on PATH'
[ -f "$VICTUAL_ROOT/packages/autoload.php" ] || fail 'packages/ is missing — run composer install first'

mkdir -p "$SUITE_SCRATCH"

# --- Coverage ---------------------------------------------------------------------
#
# The suite is a dozen short-lived PHP processes, so it is hooked at the interpreter
# rather than at each call site: an extra ini directory sets auto_prepend_file, and
# .devtools/coverage/prepend.php starts a driver in every process that then runs. Nothing
# below this point knows coverage exists, which is the point — a phase added later is
# measured without being told to be.
#
# The leading colon in PHP_INI_SCAN_DIR means "the usual directory, and then this one", so
# the platform's own extension ini files still load.

COVERAGE_DIR=""

if [ "${SUITE_COVERAGE:-0}" = "1" ]; then
	COVERAGE_DIR="${SUITE_COVERAGE_DIR:-$SUITE_SCRATCH/coverage}"

	php -r 'exit(extension_loaded("pcov") || extension_loaded("xdebug") ? 0 : 1);' \
		|| fail 'SUITE_COVERAGE=1 but neither pcov nor xdebug is loaded'

	# Cleared, not appended to: merging this run's data with the last one would report
	# lines as covered that this run never reached.
	rm -rf "$COVERAGE_DIR"
	mkdir -p "$COVERAGE_DIR"

	COVERAGE_INI_DIR="$SUITE_SCRATCH/coverage-ini"
	rm -rf "$COVERAGE_INI_DIR"
	mkdir -p "$COVERAGE_INI_DIR"

	cat > "$COVERAGE_INI_DIR/99-victual-coverage.ini" <<-INI
		auto_prepend_file=$VICTUAL_ROOT/.devtools/coverage/prepend.php
		pcov.directory=$VICTUAL_ROOT
		pcov.enabled=1
	INI

	export PHP_INI_SCAN_DIR=":$COVERAGE_INI_DIR"
	export VICTUAL_COVERAGE_DIR="$COVERAGE_DIR"

	say "measuring coverage into $COVERAGE_DIR"
fi

# --- The pristine SQLite database -------------------------------------------------
#
# Built here rather than expected at an operator-known path, so that the suite has no
# prerequisite a clean checkout cannot satisfy. bin/victual-migrate creates the schema;
# fixtures/00_base.sql adds the rows the tests refer to.

PRISTINE="$SUITE_SCRATCH/pristine.db"

# The same database one step earlier: migrated, and nothing else. That is what the
# migration phase compares against, and it has to be taken before the fixture goes in.
MIGRATED_ONLY="$SUITE_SCRATCH/migrated-only.db"

build_pristine() {
	local datapath="$SUITE_SCRATCH/pristine-data"

	rm -rf "$datapath"
	write_sqlite_config "$datapath"

	VICTUAL_DATAPATH="$datapath" php "$VICTUAL_ROOT/bin/victual-migrate" --quiet \
		|| fail 'could not migrate the pristine SQLite database'

	cp "$datapath/victual.db" "$MIGRATED_ONLY"

	php "$SUITE_DIR/apply-sql.php" "sqlite:$datapath/victual.db" "$SUITE_DIR/fixtures/00_base.sql" \
		|| fail 'could not apply the base fixture to the pristine database'

	mv "$datapath/victual.db" "$PRISTINE"
	rm -rf "$datapath"
}

# --- The PostgreSQL side ----------------------------------------------------------
#
# Each target database is dropped and rebuilt from the migrations, so a run never
# inherits state from the last one. A suite that can pass because of what a previous
# run left behind is not measuring anything.

build_pgsql() {
	local dbname="$1"
	local datapath="$SUITE_SCRATCH/pg-data-$dbname"

	dropdb --if-exists "$dbname" || fail "could not drop $dbname"
	createdb "$dbname" || fail "could not create $dbname"

	rm -rf "$datapath"
	mkdir -p "$datapath"

	# The connection settings are read from the environment rather than interpolated
	# into the file. A password with a quote in it would otherwise produce a config.php
	# that is either broken or executing something it should not be, and the database
	# name is the only value this function actually chooses.
	cat > "$datapath/config.php" <<-'PHPCONFIG'
		<?php
		Setting('DB_DRIVER', 'pgsql');
		Setting('DB_HOST', getenv('PGHOST'));
		Setting('DB_PORT', intval(getenv('PGPORT')));
		Setting('DB_NAME', getenv('DIFFTEST_DB_NAME'));
		Setting('DB_USER', getenv('PGUSER'));
		Setting('DB_PASSWORD', getenv('PGPASSWORD'));
	PHPCONFIG

	VICTUAL_DATAPATH="$datapath" DIFFTEST_DB_NAME="$dbname" php "$VICTUAL_ROOT/bin/victual-migrate" --quiet \
		|| fail "could not migrate $dbname"

	rm -rf "$datapath"
}

failures=0

# PostgreSQL-only role and read model, alongside the frozen differential contract.
run_rbac_tests() {
	local dbname="victual_rbac"
	build_pgsql "$dbname"
	local datapath="$SUITE_SCRATCH/rbac-data"
	mkdir -p "$datapath"
	cat > "$datapath/config.php" <<-'PHPCONFIG'
		<?php
		Setting('DB_DRIVER', 'pgsql');
		Setting('DB_HOST', getenv('PGHOST'));
		Setting('DB_PORT', intval(getenv('PGPORT')));
		Setting('DB_NAME', 'victual_rbac');
		Setting('DB_USER', getenv('PGUSER'));
		Setting('DB_PASSWORD', getenv('PGPASSWORD'));
	PHPCONFIG
	if ! VICTUAL_DATAPATH="$datapath" php "$SUITE_DIR/rbac-tests.php"; then
		failures=$((failures + 1))
	fi
}

# --- Migration tests --------------------------------------------------------------
#
# Both databases are built by bin/victual-migrate and then left alone. Nothing is seeded
# into either side, because the question is what migrating alone produces — the state
# every other phase, and every real installation, starts from.

run_migration_tests() {
	build_pgsql "$MIGRATE_DB"

	export MIGRATEDIFF_SQLITE_PATH="$MIGRATED_ONLY"
	export MIGRATEDIFF_PGSQL_DSN="pgsql:host=$PGHOST;port=$PGPORT;dbname=$MIGRATE_DB"
	export MIGRATEDIFF_PGSQL_USER="$PGUSER"
	export MIGRATEDIFF_PGSQL_PASSWORD="$PGPASSWORD"

	say ""
	if ! php "$SUITE_DIR/migratedifftest.php"; then
		failures=$((failures + 1))
	fi
}

# --- View tests -------------------------------------------------------------------
#
# Each seed declares the views it exercises in a leading "-- @views" comment, parsed in
# PHP rather than with grep so that the header is read the same way every time.

run_view_tests() {
	local seeds=("$SUITE_DIR"/view-tests/*.sql)

	if [ ! -e "${seeds[0]}" ]; then
		say 'no view seeds found'
		return 0
	fi

	build_pgsql "$VIEW_DB"

	export DIFFTEST_PGSQL_DSN="pgsql:host=$PGHOST;port=$PGPORT;dbname=$VIEW_DB"
	export DIFFTEST_PGSQL_USER="$PGUSER"
	export DIFFTEST_PGSQL_PASSWORD="$PGPASSWORD"

	for seed in "${seeds[@]}"; do
		local views
		views="$(php "$SUITE_DIR/seed-header.php" "$seed")" \
			|| fail "$(basename "$seed") has no @views header"

		# Every seed starts from the same pristine state, so a seed cannot pass
		# because of what the seed before it inserted.
		local sqlite_db="$SUITE_SCRATCH/difftest.db"
		cp "$PRISTINE" "$sqlite_db"
		export DIFFTEST_SQLITE_DSN="sqlite:$sqlite_db"

		say ""
		say "== $(basename "$seed")"

		# shellcheck disable=SC2086 -- the view list is deliberately word-split
		if ! php "$SUITE_DIR/difftest.php" "$seed" $views; then
			failures=$((failures + 1))
		fi
	done
}

# --- Rollback tests ---------------------------------------------------------------
#
# Unlike the three phases above, this one runs against one engine at a time: the question
# is whether a failed operation leaves that engine's ledger intact, which has no
# cross-engine comparison in it.

run_rollback_tests() {
	local datapath="$SUITE_SCRATCH/rollback-sqlite"
	local sqlite_db="$SUITE_SCRATCH/rollback-source.db"

	# SQLite first, from a fresh database with the base fixture, exactly as the pristine
	# database is built.
	rm -rf "$datapath"
	write_sqlite_config "$datapath"

	VICTUAL_DATAPATH="$datapath" php "$VICTUAL_ROOT/bin/victual-migrate" --quiet \
		|| fail 'could not migrate the rollback test database'
	php "$SUITE_DIR/apply-sql.php" "sqlite:$datapath/victual.db" "$SUITE_DIR/fixtures/00_base.sql" \
		|| fail 'could not apply the base fixture for the rollback tests'

	# Kept aside before the tests run, so PostgreSQL starts from the same rows rather
	# than from whatever the SQLite cases left behind.
	cp "$datapath/victual.db" "$sqlite_db"

	say ""
	if ! VICTUAL_DATAPATH="$datapath" php "$SUITE_DIR/rollback-tests.php"; then
		failures=$((failures + 1))
	fi

	rm -rf "$datapath"

	# Then PostgreSQL, which is the half that was never covered before: the injector has
	# to be written twice because RAISE(ABORT) has no PostgreSQL equivalent outside a
	# function, and a rollback is exactly the sort of thing two engines can differ on.
	build_pgsql "$ROLLBACK_DB"

	local pgdatapath="$SUITE_SCRATCH/rollback-pgsql"
	rm -rf "$pgdatapath"
	mkdir -p "$pgdatapath"

	cat > "$pgdatapath/config.php" <<-'PHPCONFIG'
		<?php
		Setting('DB_DRIVER', 'pgsql');
		Setting('DB_HOST', getenv('PGHOST'));
		Setting('DB_PORT', intval(getenv('PGPORT')));
		Setting('DB_NAME', getenv('DIFFTEST_DB_NAME'));
		Setting('DB_USER', getenv('PGUSER'));
		Setting('DB_PASSWORD', getenv('PGPASSWORD'));
	PHPCONFIG

	# The PostgreSQL side is populated by importing the SQLite database just used, rather
	# than by applying the fixture again. That is the supported way an existing
	# installation's data reaches PostgreSQL, so it is the state worth testing against —
	# and it keeps this phase's subject to rollback alone rather than also to whether
	# inserts behave identically on both engines, which the other three phases answer.
	#
	# An earlier version of this comment said applying the fixture straight to PostgreSQL
	# fails, and blamed products_ins for leaving cache__quantity_unit_conversions_resolved
	# empty. Both halves were wrong. The trigger was a faithful port; what was missing was
	# the seed data the PostgreSQL baseline never inserted, so quantity_units was empty and
	# the view the trigger copies from had nothing in it for any product. With that fixed
	# the fixture does apply directly. The import stays because it is the more
	# representative state, not because the alternative is broken.
	#
	# --force because build_pgsql above ran bin/victual-migrate, which seeds a fresh database
	# with the initial data of a new installation, and the import refuses a target that
	# holds rows unless told. Those particular rows are exactly what this import replaces —
	# it truncates before it copies — so overwriting them is the intent, not a risk.
	DIFFTEST_DB_NAME="$ROLLBACK_DB" VICTUAL_DATAPATH="$pgdatapath" \
		php "$VICTUAL_ROOT/bin/victual-db-import" "$sqlite_db" --force > /dev/null \
		|| fail 'could not import the rollback fixture into PostgreSQL'

	say ""
	if ! VICTUAL_DATAPATH="$pgdatapath" DIFFTEST_DB_NAME="$ROLLBACK_DB" php "$SUITE_DIR/rollback-tests.php"; then
		failures=$((failures + 1))
	fi

	rm -rf "$pgdatapath"
	rm -f "$sqlite_db"
}

# --- Filter operator tests --------------------------------------------------------
#
# The one phase that compares application behaviour rather than SQL. It asks each dialect
# for the condition it would emit for the API's "~" and "!~" operators, runs both against
# their own engine, and compares the rows - so it fails if the two ever stop meaning the
# same thing again, which is what hazard 16 was.
#
# Both databases are migrated ones rather than bare scratch databases, deliberately:
# PostgreSQL's ILIKE folds case according to the database's collation, so the answer
# depends on how the database was created, and the database this suite creates the way
# bin/victual-migrate creates one is the honest thing to measure.

run_filter_tests() {
	build_pgsql "$FILTER_DB"

	# A copy, not the pristine database itself: this phase creates and drops a scratch
	# table, and the pristine database is the template every other phase starts from.
	local sqlite_db="$SUITE_SCRATCH/filter-source.db"
	cp "$PRISTINE" "$sqlite_db" || fail 'could not copy the pristine database for the filter tests'

	export FILTERDIFF_SQLITE_PATH="$sqlite_db"
	export FILTERDIFF_PGSQL_DSN="pgsql:host=$PGHOST;port=$PGPORT;dbname=$FILTER_DB"
	export FILTERDIFF_PGSQL_USER="$PGUSER"
	export FILTERDIFF_PGSQL_PASSWORD="$PGPASSWORD"

	if ! php "$SUITE_DIR/filterdifftest.php"; then
		failures=$((failures + 1))
	fi

	rm -f "$sqlite_db"
}

# --- Schema gate tests ------------------------------------------------------------
#
# One engine at a time, like the rollback phase: the question is what the application
# believes about the database in front of it, which has no cross-engine comparison in it.
# The database is built by bin/victual-migrate and nothing else — the gate is about
# migration bookkeeping, so a fixture would only add rows it does not read.
#
# The script mutates the migrations table and puts it back; the databases here are
# throwaway either way, but SQLite's is a copy rather than the pristine database itself,
# because the pristine one is the template every other phase starts from.

run_schema_tests() {
	local datapath="$SUITE_SCRATCH/schema-sqlite"

	rm -rf "$datapath"
	write_sqlite_config "$datapath"

	VICTUAL_DATAPATH="$datapath" php "$VICTUAL_ROOT/bin/victual-migrate" --quiet \
		|| fail 'could not migrate the schema gate test database'

	say ""
	if ! VICTUAL_DATAPATH="$datapath" php "$SUITE_DIR/schemagatetest.php"; then
		failures=$((failures + 1))
	fi

	rm -rf "$datapath"

	build_pgsql "$SCHEMA_DB"

	local pgdatapath="$SUITE_SCRATCH/schema-pgsql"
	rm -rf "$pgdatapath"
	mkdir -p "$pgdatapath"

	cat > "$pgdatapath/config.php" <<-'PHPCONFIG'
		<?php
		Setting('DB_DRIVER', 'pgsql');
		Setting('DB_HOST', getenv('PGHOST'));
		Setting('DB_PORT', intval(getenv('PGPORT')));
		Setting('DB_NAME', getenv('DIFFTEST_DB_NAME'));
		Setting('DB_USER', getenv('PGUSER'));
		Setting('DB_PASSWORD', getenv('PGPASSWORD'));
	PHPCONFIG

	say ""
	if ! VICTUAL_DATAPATH="$pgdatapath" DIFFTEST_DB_NAME="$SCHEMA_DB" php "$SUITE_DIR/schemagatetest.php"; then
		failures=$((failures + 1))
	fi

	rm -rf "$pgdatapath"
}

# --- Stored rich text -------------------------------------------------------------
#
# The five columns rendered as HTML are guarded by the API's purifier, which covers every
# row the API wrote and nothing that arrived another way - an in-place upgrade from a
# database that predates the purifier, or an import, which copies rows verbatim. Migration
# 0260 and DatabaseImporter both run StoredHtmlPurifier for that; this asserts they work,
# by planting payloads with a direct write the way the gap does.
#
# Both engines, because the routine quotes identifiers through the dialect and issues its
# UPDATEs through PDO - the two things that differ. The PostgreSQL half also gets --source,
# which adds the import case: PostgreSQL is the only target bin/victual-db-import has.

run_richtext_tests() {
	local datapath="$SUITE_SCRATCH/richtext-sqlite"

	rm -rf "$datapath"
	write_sqlite_config "$datapath"

	VICTUAL_DATAPATH="$datapath" php "$VICTUAL_ROOT/bin/victual-migrate" --quiet \
		|| fail 'could not migrate the rich text test database'

	# The base fixture, for the same reason the trigger and view phases apply it: the
	# script fails loudly when one of the five tables is empty, and a freshly migrated
	# database only has shopping_lists.
	php "$SUITE_DIR/apply-sql.php" "sqlite:$datapath/victual.db" "$SUITE_DIR/fixtures/00_base.sql" \
		|| fail 'could not apply the base fixture to the rich text test database'

	say ""
	if ! VICTUAL_DATAPATH="$datapath" php "$SUITE_DIR/richtext-tests.php"; then
		failures=$((failures + 1))
	fi

	local sourcedb="$datapath/victual.db"

	build_pgsql "$RICHTEXT_DB"

	local pgdatapath="$SUITE_SCRATCH/richtext-pgsql"
	rm -rf "$pgdatapath"
	mkdir -p "$pgdatapath"

	cat > "$pgdatapath/config.php" <<-'PHPCONFIG'
		<?php
		Setting('DB_DRIVER', 'pgsql');
		Setting('DB_HOST', getenv('PGHOST'));
		Setting('DB_PORT', intval(getenv('PGPORT')));
		Setting('DB_NAME', getenv('DIFFTEST_DB_NAME'));
		Setting('DB_USER', getenv('PGUSER'));
		Setting('DB_PASSWORD', getenv('PGPASSWORD'));
	PHPCONFIG

	say ""
	if ! VICTUAL_DATAPATH="$pgdatapath" DIFFTEST_DB_NAME="$RICHTEXT_DB" \
		php "$SUITE_DIR/richtext-tests.php" --source "$sourcedb"; then
		failures=$((failures + 1))
	fi

	rm -rf "$pgdatapath" "$datapath"
}

# --- Trigger tests ----------------------------------------------------------------

run_trigger_tests() {
	local scripts=("$SUITE_DIR"/trigger-tests/*.sql)

	if [ ! -e "${scripts[0]}" ]; then
		say 'no trigger scripts found'
		return 0
	fi

	build_pgsql "$TRIGGER_DB"

	export TRIGTEST_SQLITE_PATH="$SUITE_SCRATCH/trigtest.db"
	export TRIGTEST_PRISTINE_PATH="$PRISTINE"
	export TRIGTEST_PGSQL_DSN="pgsql:host=$PGHOST;port=$PGPORT;dbname=$TRIGGER_DB"
	export TRIGTEST_PGSQL_USER="$PGUSER"
	export TRIGTEST_PGSQL_PASSWORD="$PGPASSWORD"

	if ! php "$SUITE_DIR/trigdifftest.php" "${scripts[@]}"; then
		failures=$((failures + 1))
	fi
}

# --- MQTT and outbox probes -------------------------------------------------------
#
# The one phase that is not a comparison between engines. Everything it runs guards a
# defect that fails silently, which is exactly the kind a suite has to hold rather than a
# reviewer:
#
#   client-id-check   a client id that lost its random suffix as the configured prefix
#                     grew, so two overlapping requests knock each other off the broker
#   price-guard       a price, cost or value field reaching a retained topic anything on
#                     the broker can read without authenticating to Victual
#   lock-check        two requests interleaving a read of the published state with a write
#                     of it, leaving the older snapshot retained until the next write
#   outbox-check      an event lost after its booking committed, or surviving a booking
#                     that rolled back
#   idempotency-check a redelivered event writing a second point instead of overwriting the
#                     first, or a drained backlog giving every queued transaction the same
#                     latest stock snapshot
#   write-ack-check   an event marked delivered on something that was not an acknowledgement
#                     from the write endpoint - a redirect to a login page, a proxy's own
#                     200 - which sets delivered_at with no attempt and no retry, ever
#   full-refresh-check a boot or CLI publish skipping the per-product topics because the
#                     ledger says they were sent, so entities stay missing from Home
#                     Assistant after the broker loses its retained messages
#   engine-diff       the assembled payload differing between SQLite and PostgreSQL, which
#                     is the one differential question this feature raises
#
# No real broker and no node: the lock needs only PostgreSQL, InfluxDB is stood in for by
# PHP's own built-in server (influx-standin.php), and the broker by a PHP stream socket that
# speaks the little of MQTT 3.1.1 MqttPublisher uses and records what was published
# (broker-standin.php). A probe that only runs where somebody installed extra software is a
# probe CI skips. What is *not* covered by either stand-in is stated plainly: no real Mosquitto
# or Home Assistant, and no real InfluxDB, so protocol-level acceptance by those two is still
# only verified by hand.

run_mqtt_tests() {
	local mqtt_scratch="$SUITE_SCRATCH/mqtt"
	rm -rf "$mqtt_scratch"
	mkdir -p "$mqtt_scratch"

	MQTT_STANDIN_LOG="$mqtt_scratch/standin.log"
	MQTT_STANDIN_CONTROL="$mqtt_scratch/standin-control.txt"
	MQTT_BROKER_LOG="$mqtt_scratch/broker.log"
	export MQTT_STANDIN_LOG MQTT_STANDIN_CONTROL MQTT_BROKER_LOG

	# Rejecting to start with: most of the probes exercise the failure path, and an address
	# that times out would spend the configured timeout doing it on every run. The control
	# file lets backlog-check.php flip a running server to accepting without restarting it,
	# which it has to do because restarting would lose the request log it counts.
	echo reject > "$MQTT_STANDIN_CONTROL"

	local standin_pid=""

	VICTUAL_STANDIN_LOG="$MQTT_STANDIN_LOG" VICTUAL_STANDIN_CONTROL="$MQTT_STANDIN_CONTROL" \
		php -S "127.0.0.1:$MQTT_STANDIN_PORT" "$VICTUAL_ROOT/.devtools/mqtt/influx-standin.php" \
		> "$mqtt_scratch/standin-server.log" 2>&1 &
	standin_pid=$!

	# The recording broker stand-in, which is how full-refresh-check.php can see which topics
	# a publish actually put on the wire. It has no control file and no failure modes: the
	# question it answers is what was sent, not what happens when sending fails.
	: > "$MQTT_BROKER_LOG"

	local broker_pid=""

	php "$VICTUAL_ROOT/.devtools/mqtt/broker-standin.php" "$MQTT_BROKER_PORT" "$MQTT_BROKER_LOG" \
		> "$mqtt_scratch/broker-server.log" 2>&1 &
	broker_pid=$!

	# Both killed however this function ends, including a probe exiting non-zero
	trap '[ -n "$standin_pid" ] && kill "$standin_pid" 2>/dev/null; [ -n "$broker_pid" ] && kill "$broker_pid" 2>/dev/null; true' RETURN

	# Both take a moment to bind, and a probe that raced one would report a connection
	# failure as an outbox or publication defect
	local waited=0
	while [ "$waited" -lt 50 ] && ! php -r 'exit(@fsockopen("127.0.0.1", (int)$argv[1], $e, $m, 0.2) ? 0 : 1);' "$MQTT_STANDIN_PORT"; do
		sleep 0.1
		waited=$((waited + 1))
	done

	waited=0
	while [ "$waited" -lt 50 ] && ! php -r 'exit(@fsockopen("127.0.0.1", (int)$argv[1], $e, $m, 0.2) ? 0 : 1);' "$MQTT_BROKER_PORT"; do
		sleep 0.1
		waited=$((waited + 1))
	done

	say ""
	say "MQTT and outbox probes"
	say ""

	# --- No database at all -------------------------------------------------------
	if ! php "$VICTUAL_ROOT/.devtools/mqtt/client-id-check.php"; then
		failures=$((failures + 1))
	fi

	if ! php "$VICTUAL_ROOT/.devtools/mqtt/price-guard.php"; then
		failures=$((failures + 1))
	fi

	# --- PostgreSQL: the publication lock -----------------------------------------
	build_pgsql "$MQTT_DB"

	local lock_data="$mqtt_scratch/lock"
	write_pgsql_config "$lock_data"

	say ""
	if ! VICTUAL_DATAPATH="$lock_data" DIFFTEST_DB_NAME="$MQTT_DB" \
		php "$VICTUAL_ROOT/.devtools/mqtt/lock-check.php"; then
		failures=$((failures + 1))
	fi

	# --- The outbox, on both engines ----------------------------------------------
	#
	# Run twice rather than once, because the outbox is the one part of this feature whose
	# behaviour could plausibly differ between engines: it turns on transaction semantics,
	# on what a rolled back INSERT leaves behind, and on how each driver reports a failure
	# mid-transaction. Asserting it only on SQLite - which ADR-0008 makes a development
	# engine - would leave the deployment engine untested for exactly the properties this
	# whole mechanism exists to provide.
	#
	# Each probe gets its own database on each engine. They book stock, rename tables out
	# from under bookings and queue hundreds of rows, so sharing one would make a failure in
	# the first indistinguishable from contamination of the second.
	run_mqtt_probe_on_both_engines outbox-check "$mqtt_scratch"
	# Ahead of backlog-check, which flips the stand-in to accepting and leaves it there:
	# both of these need the rejecting stand-in, since what they assert is what a row that
	# was *not* delivered looks like.
	run_mqtt_probe_on_both_engines payload-validation-check "$mqtt_scratch"
	run_mqtt_probe_on_both_engines changed-time-check "$mqtt_scratch"
	run_mqtt_probe_on_both_engines write-ack-check "$mqtt_scratch"
	run_mqtt_probe_on_both_engines full-refresh-check "$mqtt_scratch"
	run_mqtt_probe_on_both_engines idempotency-check "$mqtt_scratch"
	run_mqtt_probe_on_both_engines event-identity-check "$mqtt_scratch"
	run_mqtt_probe_on_both_engines backlog-check "$mqtt_scratch"

	# --- Both engines: the assembled payload --------------------------------------
	say ""
	if ! SUITE_SCRATCH="$mqtt_scratch" MQTTDIFF_PGSQL_DB="${MQTT_DB}_diff" \
		bash "$VICTUAL_ROOT/.devtools/mqtt/engine-diff.sh"; then
		failures=$((failures + 1))
	fi
}

# Runs one probe against a fresh SQLite database and a fresh PostgreSQL one.
#
# The SQLite side is a copy of the pristine database, which already has the fixture rows the
# probes book against. The PostgreSQL side is built from that same copy through
# bin/victual-db-import - the real migration command - so both engines start from identical
# data rather than from two independently seeded databases that might not be.
run_mqtt_probe_on_both_engines() {
	local probe="$1"
	local scratch="$2/$1"

	rm -rf "$scratch"
	mkdir -p "$scratch/sqlite"

	cp "$PRISTINE" "$scratch/sqlite/victual.db" || fail "could not copy the pristine database for $probe"
	write_sqlite_config "$scratch/sqlite"
	write_influx_config "$scratch/sqlite" append

	say ""
	if ! VICTUAL_DATAPATH="$scratch/sqlite" \
		VICTUAL_STANDIN_LOG="$MQTT_STANDIN_LOG" VICTUAL_STANDIN_CONTROL="$MQTT_STANDIN_CONTROL" \
		VICTUAL_BROKER_STANDIN_PORT="$MQTT_BROKER_PORT" VICTUAL_BROKER_STANDIN_LOG="$MQTT_BROKER_LOG" \
		php "$VICTUAL_ROOT/.devtools/mqtt/$probe.php"; then
		failures=$((failures + 1))
	fi

	local dbname="${MQTT_DB}_$(printf '%s' "$probe" | tr -c 'a-z0-9' '_')"

	dropdb --if-exists "$dbname" || fail "could not drop $dbname"
	createdb "$dbname" || fail "could not create $dbname"

	write_pgsql_config "$scratch/pgsql"

	VICTUAL_DATAPATH="$scratch/pgsql" DIFFTEST_DB_NAME="$dbname" php "$VICTUAL_ROOT/bin/victual-migrate" --quiet \
		|| fail "could not migrate $dbname"
	VICTUAL_DATAPATH="$scratch/pgsql" DIFFTEST_DB_NAME="$dbname" \
		php "$VICTUAL_ROOT/bin/victual-db-import" "$scratch/sqlite/victual.db" --force > /dev/null \
		|| fail "could not import into $dbname"

	# The InfluxDB settings on top of the connection ones, appended so the file keeps both
	write_influx_config "$scratch/pgsql" append

	say ""
	if ! VICTUAL_DATAPATH="$scratch/pgsql" DIFFTEST_DB_NAME="$dbname" \
		VICTUAL_STANDIN_LOG="$MQTT_STANDIN_LOG" VICTUAL_STANDIN_CONTROL="$MQTT_STANDIN_CONTROL" \
		VICTUAL_BROKER_STANDIN_PORT="$MQTT_BROKER_PORT" VICTUAL_BROKER_STANDIN_LOG="$MQTT_BROKER_LOG" \
		php "$VICTUAL_ROOT/.devtools/mqtt/$probe.php"; then
		failures=$((failures + 1))
	fi
}

# The SQLite side needs a config.php of its own now. It used to need none: "sqlite" was
# config-dist.php's default, so a data directory with nothing in it produced a SQLite
# database. ADR-0008's retirement made "pgsql" the default and DB_DRIVER stopped accepting
# "sqlite" at all, so each SQLite data directory has to say so explicitly - and is only
# accepted because DIFFTEST_SQLITE_RUNTIME is exported at the top of this file.
write_sqlite_config() {
	mkdir -p "$1"

	cat > "$1/config.php" <<-'PHPCONFIG'
		<?php
		Setting('DB_DRIVER', 'sqlite');
	PHPCONFIG
}

# The PostgreSQL connection settings, read from the environment rather than interpolated for
# the reason build_pgsql() gives: a password with a quote in it would otherwise produce a
# config.php that is either broken or executing something it should not be.
write_pgsql_config() {
	mkdir -p "$1"

	cat > "$1/config.php" <<-'PHPCONFIG'
		<?php
		Setting('DB_DRIVER', 'pgsql');
		Setting('DB_HOST', getenv('PGHOST'));
		Setting('DB_PORT', intval(getenv('PGPORT')));
		Setting('DB_NAME', getenv('DIFFTEST_DB_NAME'));
		Setting('DB_USER', getenv('PGUSER'));
		Setting('DB_PASSWORD', getenv('PGPASSWORD'));
	PHPCONFIG
}

# The probes need InfluxDB switched on to do anything at all - RecordTransaction() writes
# nothing when it is off, which is deliberate (an outbox nobody drains is a leak). The
# endpoint is the stand-in, which rejects, so the failure path is what gets exercised.
write_influx_config() {
	mkdir -p "$1"

	# Appended when asked, so a PostgreSQL data directory keeps its connection settings and
	# gains these; the opening tag comes from the file it is appended to.
	if [ "${2:-}" = "append" ]; then
		cat >> "$1/config.php" <<-PHPCONFIG
			Setting('INFLUXDB_ENABLED', true);
			Setting('INFLUXDB_URL', 'http://127.0.0.1:$MQTT_STANDIN_PORT');
			Setting('INFLUXDB_TOKEN', 'suite');
			Setting('INFLUXDB_ORG', 'suite');
			Setting('INFLUXDB_BUCKET', 'suite');
			Setting('INFLUXDB_TIMEOUT_SECONDS', 2);
		PHPCONFIG

		return 0
	fi

	cat > "$1/config.php" <<-PHPCONFIG
		<?php
		Setting('INFLUXDB_ENABLED', true);
		Setting('INFLUXDB_URL', 'http://127.0.0.1:$MQTT_STANDIN_PORT');
		Setting('INFLUXDB_TOKEN', 'suite');
		Setting('INFLUXDB_ORG', 'suite');
		Setting('INFLUXDB_BUCKET', 'suite');
		Setting('INFLUXDB_TIMEOUT_SECONDS', 2);
	PHPCONFIG
}

# --- File import tests ------------------------------------------------------------
#
# PostgreSQL only, because the files table is: ConfigurationValidator refuses
# FILE_STORAGE = "database" on any other driver, so there is no SQLite side to compare
# with and nothing for the other phases to have caught. The phase needs a data directory
# of its own rather than a bare database, because the command under test reads
# <data path>/storage and the FILE_STORAGE setting lives in the same config.php — which
# is also why this is the one config.php in this file that sets more than the connection.

run_files_import_tests() {
	build_pgsql "$FILES_DB"

	local datapath="$SUITE_SCRATCH/files-import"
	rm -rf "$datapath"
	mkdir -p "$datapath"

	cat > "$datapath/config.php" <<-'PHPCONFIG'
		<?php
		Setting('DB_DRIVER', 'pgsql');
		Setting('DB_HOST', getenv('PGHOST'));
		Setting('DB_PORT', intval(getenv('PGPORT')));
		Setting('DB_NAME', getenv('DIFFTEST_DB_NAME'));
		Setting('DB_USER', getenv('PGUSER'));
		Setting('DB_PASSWORD', getenv('PGPASSWORD'));
		Setting('FILE_STORAGE', 'database');
	PHPCONFIG

	say ""

	# Both variables are exported into the child rather than set here, because the test
	# starts bin/victual-files-import as a further process and that one reads the same
	# config.php: an unexported value would reach the test and not the command it drives.
	if ! VICTUAL_DATAPATH="$datapath" DIFFTEST_DB_NAME="$FILES_DB" php "$SUITE_DIR/files-import-tests.php"; then
		failures=$((failures + 1))
	fi

	rm -rf "$datapath"
}

# --- SQLite import tests ----------------------------------------------------------
#
# The one phase whose source is a file in the repository. bin/victual-db-import reads a
# format nothing here produces any more, so the fixtures under fixtures/import/ are what
# stands in for the engine that used to be the check - one at each end of the supported
# span, plus the refusals outside it.
#
# The target is migrated by bin/victual-migrate first, which is what an operator's target
# is: a freshly created database with a fresh installation's seed rows in it. That is why
# the phase imports with --force.

run_import_tests() {
	build_pgsql "$IMPORT_DB"

	say ""
	if ! IMPORTTEST_DB_NAME="$IMPORT_DB" php "$SUITE_DIR/import-tests.php"; then
		failures=$((failures + 1))
	fi
}

# --- Chore assignment tests -------------------------------------------------------
#
# One engine at a time, like the rollback and schema phases: the question is what the
# service does with an assignment group that resolves to nobody, and only one of the four
# strategies asks the database anything at all.
#
# No fixture on either side. The phase creates the users and chores it needs, because the
# rows it wants - a chore assigned to a user that has since been deleted, among others -
# are not rows any shared fixture should carry.

run_chores_assignment_tests() {
	local datapath="$SUITE_SCRATCH/chores-sqlite"

	rm -rf "$datapath"
	write_sqlite_config "$datapath"

	VICTUAL_DATAPATH="$datapath" php "$VICTUAL_ROOT/bin/victual-migrate" --quiet \
		|| fail 'could not migrate the chore assignment test database'

	say ""
	if ! VICTUAL_DATAPATH="$datapath" php "$SUITE_DIR/chores-assignment-tests.php"; then
		failures=$((failures + 1))
	fi

	rm -rf "$datapath"

	build_pgsql "$CHORES_DB"

	local pgdatapath="$SUITE_SCRATCH/chores-pgsql"
	rm -rf "$pgdatapath"
	write_pgsql_config "$pgdatapath"

	say ""
	if ! VICTUAL_DATAPATH="$pgdatapath" DIFFTEST_DB_NAME="$CHORES_DB" \
		php "$SUITE_DIR/chores-assignment-tests.php"; then
		failures=$((failures + 1))
	fi

	rm -rf "$pgdatapath"
}

# Before anything is built: a migration numbering mistake means the two engines are not
# running the same set of changes, which would make every comparison below meaningless
# rather than merely wrong. The same script also refuses a hole in the sequence above the
# baseline — a tree carrying 0259 while 0258 sits in an unmerged branch migrates a database
# that records 259 and never ran 258 — so this is where the merge order recorded in
# migrations/RESERVATIONS.md is enforced.
#
# SUITE_ALLOW_RESERVED_HOLES=1 passes the script's --allow-reserved-holes waiver, which
# downgrades exactly one failure - a missing number that RESERVATIONS.md says belongs to a
# branch that has not merged - to a printed warning. It exists because otherwise a branch
# holding the higher of two in-flight numbers cannot run its own suite at all, which trades
# one unverifiable claim for another. CI does not set it, and a branch that needs it is
# saying out loud that it is not yet mergeable.
say "checking migration numbering"

migration_check_args=()
if [ "${SUITE_ALLOW_RESERVED_HOLES:-0}" = "1" ]; then
	migration_check_args+=(--allow-reserved-holes)
fi

php "$SUITE_DIR/check-migrations.php" "${migration_check_args[@]+"${migration_check_args[@]}"}" \
	|| fail 'migration numbering check failed'

say ""
say "building the pristine SQLite database"
build_pristine

case "$WHICH" in
	rbac) run_rbac_tests ;;
	migrate) run_migration_tests ;;
	views) run_view_tests ;;
	triggers) run_trigger_tests ;;
	rollback) run_rollback_tests ;;
	filter) run_filter_tests ;;
	schema) run_schema_tests ;;
	richtext) run_richtext_tests ;;
	files) run_files_import_tests ;;
	mqtt) run_mqtt_tests ;;
	import) run_import_tests ;;
	chores) run_chores_assignment_tests ;;
	all) run_migration_tests; run_view_tests; run_trigger_tests; run_rollback_tests; run_filter_tests; run_schema_tests; run_richtext_tests; run_files_import_tests; run_mqtt_tests; run_import_tests; run_rbac_tests; run_chores_assignment_tests ;;
	*) fail "unknown target: $WHICH (expected migrate, views, triggers, rollback, filter, schema, richtext, files, mqtt, import, rbac, chores or all)" ;;
esac

if [ -n "$COVERAGE_DIR" ]; then
	say ""
	say "== coverage"

	# Reported whether or not the suite passed: when a phase fails, what it did and did
	# not reach is part of reading the failure.
	report_args=("$COVERAGE_DIR")

	if [ -n "${SUITE_COVERAGE_CLOVER:-}" ]; then
		report_args+=("--clover=$SUITE_COVERAGE_CLOVER")
	fi

	# The report is itself a PHP process, and hooking it would have it measure its own
	# run and write a further .cov into the directory it is reading. Unsetting the
	# variable is enough — prepend.php returns immediately without it — and is what has
	# to be done rather than clearing PHP_INI_SCAN_DIR, which would also drop the
	# platform's own ini directory and with it every extension the report needs.
	env -u VICTUAL_COVERAGE_DIR php "$VICTUAL_ROOT/.devtools/coverage/report.php" "${report_args[@]}" \
		|| failures=$((failures + 1))
fi

say ""
if [ "$failures" -eq 0 ]; then
	say "SUITE PASSED"
	exit 0
fi

say "SUITE FAILED — $failures case(s) differ"
exit 1
