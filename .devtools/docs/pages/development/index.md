# Development

Documentation for someone changing Victual. Read in this order: the
[constitution](constitution.md) for the standing principles, then
[contributing](contributing.md) for where things go and what a pull request needs, then the
[decision records](adr/README.md) for the choices already in force.

## Orientation

- **[Constitution](constitution.md)** — the standing principles. Short, and binding.
- **[Contributing](contributing.md)** — where code and documents belong, what a pull request
  carries, how to generate the API reference, and the fork's two-layer licensing.
- **[Documentation conventions](documentation-conventions.md)** — which document owns which
  kind of information, and how to write each of them.

## The data model

- **[Data model](data-model.md)** — 46 tables by cluster, and the path from a request to
  the engine.
- **Diagrams** — [data access](diagrams/orm-stack.md), the
  [schema map](diagrams/schema-map.md), and four entity-relationship models:
  [stock](diagrams/erd-stock.md), [recipes](diagrams/erd-recipes.md),
  [identity](diagrams/erd-identity.md), [household](diagrams/erd-household.md).
- **[PostgreSQL](postgresql.md)** — the baseline schema, the two halves an installation
  needs, the porting rules, and eighteen hazards found while porting.

Two facts the diagrams are built around: only four foreign keys are declared in the whole
schema, all of them in the roles tables, and the 44 views are the read model.

## Decisions

**[The ADR index](adr/README.md)** lists every record and its status. Accepted records are
binding; Proposed records are under review and must not be treated as accepted.

Each record links to the plan it came from. Those links leave this site for the repository,
because plans are not published — see
[ADR-0020](adr/0020-documentation-publication-boundary.md).

## Build and deployment

- **[Container images](images.md)** — three images built by Nix from `scratch`.
- **[Deployment](deployment.md)** — the pod manifest and what is applied.
- **[Continuous integration](devtools-ci.md)** — how a Markdown-only change is classified so
  the heavy jobs skip it.
- **[Coverage](devtools-coverage.md)**, **[frontend harness](devtools-frontend.md)**,
  **[parity suite](devtools-parity.md)**, **[diagram generator](devtools-diagrams.md)**,
  **[this site's own build](devtools-docs.md)**.

## Formats and reference

- **[grocycode](grocycode.md)** — the barcode payload format Victual parses.
- **[OpenAPI specification](https://github.com/datagen24/victual/blob/master/victual.openapi.json)**
  — also served by a running instance at `/openapi/specification`.
