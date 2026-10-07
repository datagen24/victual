#!/usr/bin/env bash
#
# Regenerates tests/Pgsql/fixtures/lineage-legacy.json from a tree WITHOUT migration 0304.
#
#   git worktree add /tmp/pre-0304 39aab39aaeb1da6af4f6a365f6fff74ee83f6b8c
#   cp -a packages /tmp/pre-0304/
#   .devtools/pgsql/lineage-legacy/generate.sh /tmp/pre-0304
#
# PGHOST, PGPORT, PGUSER and PGPASSWORD name the server, as for run-tests.sh. The generator
# runs inside the old tree, so the history is written by that tree's StockService.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../../.." && pwd)"
old="${1:?usage: generate.sh <path to a pre-0304 checkout with packages/ installed>}"
db="victual_lineage_fixture"
dropdb --if-exists "$db"
createdb "$db"
data="$(mktemp -d)"
cat > "$data/config.php" <<'PHPCONFIG'
<?php
Setting('DB_DRIVER', 'pgsql');
Setting('DB_HOST', getenv('PGHOST'));
Setting('DB_PORT', intval(getenv('PGPORT')));
Setting('DB_NAME', getenv('PHPUNIT_DB_NAME'));
Setting('DB_USER', getenv('PGUSER'));
Setting('DB_PASSWORD', getenv('PGPASSWORD'));
PHPCONFIG
cp "$here/LegacyLineageFixtureGenerator.php" "$old/tests/Pgsql/LegacyLineageFixtureGenerator.php"
trap 'rm -rf "$data" "$old/tests/Pgsql/LegacyLineageFixtureGenerator.php"; dropdb --if-exists "$db"' EXIT
(cd "$old" && VICTUAL_DATAPATH="$data" PHPUNIT_DB_NAME="$db" \
	LINEAGE_FIXTURE_REVISION="$(git -C "$old" rev-parse HEAD)" \
	LINEAGE_FIXTURE_OUT="$repo/tests/Pgsql/fixtures/lineage-legacy.json" \
	php packages/bin/phpunit --configuration phpunit.xml "$old/tests/Pgsql/LegacyLineageFixtureGenerator.php")
