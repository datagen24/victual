# Victual

<img alt="Victual logo" height="50" src="public/img/logo.svg" />

Victual is a self-hosted groceries and household management application, maintained by
Steven Peterson. It is a hard fork of [grocy](https://github.com/grocy/grocy), created by
Bernd Bestel, and retains grocy's stock, shopping list, recipe, meal planning, and chore
features.

## Project goals

This fork adapts grocy to a household running an immutable, scale-to-zero application on
k3s. Its goals are:

- Keep household data and uploaded files in PostgreSQL, independent of application
  containers and their packaging, with a single database backup.
- Run without a persistent application volume, with migrations performed during deployment.
- Publish household state to Home Assistant without polling the application.
- Support pantry locations and product groupings that reflect how the household stores
  and buys things.
- Provide APIs for household assistants and first-party clients, with explicit permissions
  and reliable stock history.

The motivation includes a difficult migration from an abandoned Home Assistant add-on.
Keeping data in a network database makes access and backups independent of whoever
packages the application. Victual will continue to diverge from upstream to support these
goals.

## Current state

**Latest release: [0.2.0-MVP](docs/releases/0.2.0-MVP.md), 2026-09-24**, tagged
`v0.2.0-MVP`, with images on GHCR for amd64 and arm64; the first was
[0.1.0-MVP](docs/releases/0.1.0-MVP.md) on 2026-09-19. The
version line stays at 0.x while the release soaks in the household it was built for; 1.0
follows that, not a date. There is no release schedule, and a tag is placed only on a commit
that was verified working. As of the release:

- The Nix-built application serves under podman and on a kind cluster.
- A location label has been printed and scanned back through the fork's own label subsystem.
- A simulated household year has run against the fork and upstream side by side.

| Area | State |
|---|---|
| PostgreSQL and database file storage | Implemented; the only runtime engine. Import from grocy and Victual SQLite databases, migrations 0256–0287, and an engine comparison suite. |
| Stateless runtime | Implemented: explicit migration command, database-backed state, and read-only application filesystem. |
| MQTT and InfluxDB | State publication and event delivery implemented. Three Home Assistant checks remain and need a running Home Assistant. |
| Production containers | Six Nix-built images (application, web, migrate, label renderer, label worker, MCP sidecar), a podman pod serving since 2026-09-04, k3s manifests applied to a kind cluster on 2026-09-19. The apply to the household's own cluster remains. |
| Hardening | API error contract, hashed and expiring API keys, write transactions, frontend sink discipline, a response-contract snapshot, and a generated first-administrator password. Line coverage is below the 75% floor and ratcheted in CI. |
| Household features | Category minimums, nested locations and product groups, storage classes, open-container measurement, working-container replenishment, directed product substitution, and store-aware shopping lists are implemented. Barcode sources and medication tracking are planned. |
| Labels | Opaque `vctl:` label identities, print jobs, printer configuration, a browser template designer, a headless renderer, and a delivery worker; all six label kinds print through it and the legacy webhook is gone. A worker image reaching the printer from the cluster remains. |
| Assistants and clients | A read-only MCP sidecar with its own key type is built and deployed to kind; write tools wait on real use. First-party clients are planned. |

PostgreSQL is the only runtime engine under
[ADR-0008](docs/adr/0008-postgresql-only-runtime-engine.md), and since that record's
retirement landed `DB_DRIVER` accepts nothing else. SQLite is an import format: an existing
installation moves across with `bin/victual-db-import`, which reads grocy and Victual
databases within a stated migration span and refuses anything outside it. New migrations are
PostgreSQL-only.

Human-confirmed observation proposals have an accepted ADR but no implementation and no
owning plan. The [plan index](docs/plans/README.md) records delivery status and remaining
dependencies; the [ADR index](docs/adr/README.md) records decisions and proposals.

## Getting started

- [Manual](docs/manual/index.md): installation, configuration, day-to-day use, and operator
  topics (the REST API, backup and restore, updating).
- [Deployment](deploy/README.md): pod bootstrap and configuration.
- [Nix builds](nix/README.md): production image builds and local loading.
- [PostgreSQL](db/pgsql/README.md): database setup, import, and engine-specific details.
- [Local development](.agents/skills/run-app/SKILL.md): boot a demo instance.

Production images are built from `flake.nix`. The root `Dockerfile` builds the development
and CI image. Upstream grocy images do not contain Victual's changes.

## Working in this repository

Read these in order before making changes:

1. [AGENTS.md](AGENTS.md): repository rules and development entry points.
2. [Constitution](docs/constitution.md): standing principles.
3. [ADR index](docs/adr/README.md): decisions in force and proposed changes.
4. [Plan index](docs/plans/README.md): work status and dependencies.

See [CONTRIBUTING.md](.github/CONTRIBUTING.md) for contributions, including AI-assisted
work, and [documentation conventions](docs/documentation.md) for writing and editing.

## Support and attribution

Report Victual issues in this repository. Report security issues according to
[SECURITY.md](.github/SECURITY.md); authenticated permission bugs are in scope.
For upstream grocy, use [grocy's website](https://grocy.info) and support resources.
There is no public Victual demo; [grocy's demo](https://demo.grocy.info) shows the shared
feature set.

Most of the original application is Bernd Bestel's work. Code originating from grocy is
MIT-licensed; this fork's changes and additions use BSD 3-Clause. See [LICENSE.md](LICENSE.md)
for both licenses.

## Screenshots

Victual running its demo dataset:

![Stock overview](.github/publication_assets/stock.png)
![Shopping list](.github/publication_assets/shoppinglist.png)
![Meal plan](.github/publication_assets/mealplan.png)
![Chores overview](.github/publication_assets/chores.png)
