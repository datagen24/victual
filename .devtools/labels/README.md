# Label identity regressions

Run from the repository with Composer dependencies installed, PHP with `pdo_pgsql` and
`pdo_sqlite`, and PostgreSQL 16:

```sh
LABEL_TEST_DSN='pgsql:host=127.0.0.1;port=5432;dbname=victual' \
PGUSER=victual PGPASSWORD=victual php .devtools/labels/identity-tests.php
```

The database user must be able to create schemas. Each run creates a randomly named
schema and drops it afterwards; it does not use the application's configured database
tables. Child processes use separate connections, and the test observes PostgreSQL lock
waits before releasing the competing transaction. Timeouts fail the test.

The first assertion sequence reproduces the unsafe precheck window: issue a label after
the empty-label check, replace its location, and observe the label resolve to the replacement.
The subsequent checks exercise the implemented guard through `DatabaseImporter`, including
an import holding the lock when an issuance request arrives with the previous epoch.
Deletion races, rollback, collision recovery, canonicalization, authorization, and the
existing location response shapes are also covered. The `suite` CI job runs this script.

The existing `.devtools/pgsql/import-tests.php` additionally exercises both committed
SQLite fixtures through `bin/victual-db-import`, including refusal with and without
`--force` and survival of retired label history. Run it through the PostgreSQL suite's
`import` phase; it requires a disposable target database.
