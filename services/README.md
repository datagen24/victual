# Services

Business logic and data access. Controllers in [controllers/](../controllers/README.md)
check permissions and then call a service; the CLI tools in `bin/` call services directly.
Classes are in the `Victual\Services` namespace.

## Conventions

- Most services extend `BaseService`, which provides the shared LessQL connection
  as `$this->DB` and a per-class singleton through `GetInstance()`. Controllers
  reach a service as `StockService::GetInstance()`.
- `DatabaseService` owns the single PDO connection, the LessQL wrapper around it, and the
  `DatabaseDialect` for the configured engine. It also runs work in a transaction
  (`InTransaction()`).
- Raw SQL outside the migrations lives in a service, never in a controller.
- Business rules with an ADR behind them cite it in the class comment. Read that comment
  before changing the class.

## Contents

| Path | Contents |
|---|---|
| `StockService.php` | Stock bookings (`AddProduct`, `ConsumeProduct`, `TransferProduct`, `InventoryProduct`, `OpenProduct`, `UndoBooking`), shopping lists, product details and stock queries. |
| `StockLineageService.php` | Attribution of stock quantities to the bookings that added them ([ADR-0036](../docs/adr/0036-stock-quantities-are-attributed-to-the-bookings-that-added-them.md)). |
| `StockReportsService.php` | Spending reports built with raw SQL over the price history. |
| `RecipesService.php`, `ChoresService.php`, `BatteriesService.php`, `TasksService.php`, `CalendarService.php` | One service per domain. |
| `Consumption*Service.php`, `RefillEstimator.php`, `EventRefusal.php`, `ConsumptionException.php` | Consumption recipes, external consumption events and prescription refills ([ADR-0040](../docs/adr/0040-consumption-recipes-are-private-rows-with-scoped-shares.md), [ADR-0041](../docs/adr/0041-consumption-events-have-a-source-identity-and-explicit-reconciliation.md), [ADR-0042](../docs/adr/0042-refill-dates-are-calendar-dates-derived-from-recorded-fills.md)). |
| `UsersService.php`, `RolesService.php`, `ApiKeyService.php`, `SessionService.php`, `LoginThrottleService.php` | Users, roles, API keys, sessions and login rate limiting. The authentication middleware in [middleware/](../middleware/README.md) uses the last three. |
| `FieldPolicy.php` | Which entity fields the current user must not see, read from the `permission_fields` table. Controllers apply it when writing a response. |
| `UserfieldsService.php` | Userfields and user-defined entities. |
| `FilesService.php`, `Storage/` | Uploaded files. `Storage/FileStorage` is the abstract base; `FilesystemStorage` and `DatabaseStorage` implement it. |
| `PrintService.php` | Prints the shopping list on an ESC/POS thermal printer. |
| `Labels/` | The label subsystem: identities, templates, rendering, print jobs, printers and the worker API. These classes extend `Labels\LabelService`, which takes a PDO connection, not `BaseService`. |
| `Outbox/OutboxService.php` | Transactional outbox: an event is written inside the transaction that produced it and acknowledged after a consumer takes it. |
| `Influx/` | Drains the outbox into InfluxDB booking events. |
| `Mqtt/` | MQTT state publication and Home Assistant discovery ([plan 18](../docs/plans/18-mqtt-state-publication.md)). |
| `Time/Instant.php` | The one place that reads and writes an API timestamp. |
| `WireBooleans.php` | Converts integer flags to JSON booleans where the OpenAPI document says boolean. |
| `DatabaseMigrationService.php` | Loads the PostgreSQL baseline and applies `migrations/` on start. |
| `Database/` | Engine dialects (`PostgresDialect`, and `SqliteDialect` for the differential test suite and import), the SQLite importer behind `bin/victual-db-import`, initial seed data, and stored-value helpers such as `StoredHtmlPurifier` and `StoredApiKeyHasher`. |
| `ApplicationService.php`, `LocalizationService.php`, `DemoDataGeneratorService.php` | Version and changelog data, translations, and demo data. |

## Constraints

- [ADR-0008](../docs/adr/0008-postgresql-only-runtime-engine.md): PostgreSQL is the only
  runtime engine. `SqliteDialect` exists for the import path and the differential suite;
  [AGENTS.md](../AGENTS.md) explains why it is kept.
- [ADR-0007](../docs/adr/0007-auth-state-outlives-the-process.md): no state is held in
  process memory between requests. Sessions, API keys and login attempts are tables.
- [ADR-0010](../docs/adr/0010-workload-standard.md): consumers of `Outbox/` are
  at-least-once, so each side effect must be idempotent.
- [ADR-0005](../docs/adr/0005-wire-contract-is-the-invariant.md) and
  [ADR-0027](../docs/adr/0027-timestamps-are-local-strings-documented-booleans-are-booleans.md):
  a service that builds response data must keep the documented wire shape. `Time/Instant`
  and `WireBooleans` implement the encoding rules.
- [ADR-0011](../docs/adr/0011-label-namespace.md),
  [ADR-0019](../docs/adr/0019-label-printers-are-master-data.md) and
  [ADR-0021](../docs/adr/0021-label-templates-are-application-data.md) govern `Labels/`.

Tests for this layer are the PHPUnit phases under `tests/Pgsql/`, run against a real
PostgreSQL schema by `.devtools/pgsql/run-tests.sh`
([ADR-0025](../docs/adr/0025-three-test-tiers.md)).
