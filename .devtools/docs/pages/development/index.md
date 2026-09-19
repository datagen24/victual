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

- **[Data model](data-model.md)** — 71 tables by cluster, and the path from a request to
  the engine.
- **Diagrams** — [data access](diagrams/orm-stack.md), the
  [schema map](diagrams/schema-map.md), and eight entity-relationship models:
  [stock](diagrams/erd-stock.md), [places](diagrams/erd-locations.md),
  [recipes](diagrams/erd-recipes.md), [identity](diagrams/erd-identity.md),
  [access](diagrams/erd-access.md), [household](diagrams/erd-household.md),
  [labels](diagrams/erd-labels.md), [printing](diagrams/erd-printing.md).
- **[PostgreSQL](postgresql.md)** — the baseline schema, the two halves an installation
  needs, the porting rules, and eighteen hazards found while porting.

Two facts the diagrams are built around: outside the label subsystem only ten foreign keys
are declared, and the 50 views are the read model.

## Decisions

**[The ADR index](adr/README.md)** lists every record and its status. Accepted records are
binding; Proposed records are under review and must not be treated as accepted.

Each record links to the plan it came from. Those links leave this site for the repository,
because plans are not published — see
[ADR-0020](adr/0020-documentation-publication-boundary.md).

### Labels a record uses to cite work

A decision record cites the working documents it came out of, and those documents number
their contents. Reading a record does not require following the citation, but the labels
are worth knowing, because none of them is defined on this site:

| Label | What it names | Where it lives |
|---|---|---|
| **Wave N** | The delivery stage a plan is scheduled into. Lower numbers ship first, and the boundary between shipped and outstanding moves, so a record placing something "outside wave 3b" is describing scope rather than a date. | the [plans index](https://github.com/datagen24/victual/blob/master/docs/plans/README.md) |
| **Piece N** | One plan's own delivery stages, in order. A plan shipping in two pieces says which is which. | that plan |
| **Question N**, **QN** | A plan's numbered open question. An answer is written under its question, so the two read together. | that plan |
| **Verification check N** | One numbered check a plan requires before its work counts as delivered. | that plan |
| **CN** | A cleanup item in [plan 15](https://github.com/datagen24/victual/blob/master/docs/plans/landed/15-deliberate-cleanup.md). | that plan |
| **SN** | A numbered finding in the [security sweep](https://github.com/datagen24/victual/blob/master/docs/security-sweep.md), which records its severity and remediation. | the sweep |

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
