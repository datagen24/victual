# API contract verification for the 0.4.2 candidate

Measured 2026-10-15 from source base `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`.
The candidate changes the OpenAPI document, contract fixtures and tests. It does not
change application runtime code or migrations.

The host has no PHP executable, and its `docker compose` wrapper is x86-only. An
isolated `victual-api042-pg` PostgreSQL container and `victual-api042` network ran
the existing test harness through the `localhost/victual:dev` image. For each phase
below, the command was:

```sh
docker run --rm --network victual-api042 -v /Users/speterson/src/grocy:/app -v /app/packages -v victual-api042-scratch:/scratch -w /app -e VICTUAL_ROOT=/app -e PGHOST=victual-api042-pg -e PGPORT=5432 -e PGUSER=victual -e PGPASSWORD=victual -e SUITE_SCRATCH=/scratch localhost/victual:dev .devtools/pgsql/run-tests.sh PHASE
```

`PHASE` was replaced by the literal value in each table row. No full suite or
coverage ratchet was run for this document-only change.

| Phase | Result |
|---|---|
| `contract` | Passed: 29 tests, 80 assertions, 4 deprecations. The fixture includes a 24.5-hour chore average. |
| `stockcoverage` | Passed: 433 tests, 5,050 assertions. Modes 0, 1, 2 and 3 and both OpenAPI field descriptions are checked. |
| `labelapi` | Passed: 177 tests, 4,826 assertions. Includes 400 rollback for a NUL in `capability_document`, 403 permission checks, and the 180-operation status partition. |
| `authstack` | Passed: 89 tests, 459 assertions. Includes 503 text media and pre-auth checks. |
| `errors` | Passed: 17 unreachable-path assertions and 17 reachable-path assertions. Production 500 has JSON media and only `error_message`. |
| `wirecontract` | Passed before the final 403 description edit: 175 tests, 6,756 assertions, 8 skipped. The edit changed only the `Forbidden` response description. |
| `openapidocument` | Passed: 15 tests, 36 assertions, after the final OpenAPI edit. |

The Python OpenAPI document check exited 0:

```sh
/tmp/victual-oas-audit-env/bin/python .devtools/ci/check_openapi_document.py victual.openapi.json
```

The baseline document had 180 operations. It lacked 403 on 73, 500 on 180, and
503 on 180. The candidate declares 403 on 170 operations, with ten source-backed
exceptions, and declares 500 and 503 on all 180. The baseline stock descriptions
failed the new mapping substring assertion for both add and inventory; the
candidate descriptions passed. Python `jsonschema.Draft202012Validator` rejected
24.5 under the baseline chore-average schema and accepted it under the candidate.

For a controlled negative check of issue #893, `database_engine` was temporarily
removed from the inline `GET /system/info` response schema. The real PostgreSQL
`contract` phase failed at `ContractTest.php:1982` with:

```text
GET /api/system/info: inline response.database_engine is sent (at database_engine) but not declared
```

The document was restored byte-for-byte from its backup. Both files had SHA-256
`6bda4d60de39263054181c34ea768cb36d4836f281bdd4dbeccbe0018180a6c7`,
and the normal `contract` phase passed again. A baseline-spec comparison found the
missing 403, 500 and 503 declarations; no separate negative PHPUnit phase was run
for those omissions.
