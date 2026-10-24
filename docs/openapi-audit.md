# OpenAPI 3.2 audit — 2026-10-24

Re-audit of `victual.openapi.json` and the document served by `GET /api/openapi/specification`
against [OpenAPI 3.2.0](https://spec.openapis.org/oas/v3.2.0.html), run on `master` at
`d40f5afb` (committed 2026-10-24) for [issue 796](https://github.com/datagen24/victual/issues/796).

**Summary.** The document is structurally valid OAS 3.2.0 at `d40f5afb`. Two validators agree,
and negative controls show both read 3.2 semantics. No normative violation was found, and no
3.1→3.2 migration step is required. The contract findings are:

- A1: `ConsumptionMapping` declares non-null integer IDs that the server sends as `null`.
- A2: `GET /userfields/{entity}/{objectId}` is typed `object` but sends `[]` for an entity
  without userfield definitions.
- A3: the contract snapshot cannot detect A2's class of defect.
- A4: 44 of the 57 listable entities on `GET /objects/{entity}` have no member schema, by
  design. Three of those schemas already exist but are not in the union.

Everything else is style. Proposed remediation issues are listed under
[Remediation breakdown](#remediation-breakdown); none is filed.

This record does not change `victual.openapi.json`.

## Relation to the 2026-10-12 audit

The first audit ran on `8fd7660` and is recorded in the
[issue 796 comments](https://github.com/datagen24/victual/issues/796). It declared
`openapi: 3.1.0` and found eight defects (#799–#806); #787 and #648 were reused for two more.
All of them, and the follow-ups #888–#894, are closed. This audit checks that the remediation
still holds on current `master` and covers what was added since.

| Measure | `8fd7660` (2026-10-12) | `d40f5afb` (2026-10-24) |
|---|---|---|
| `openapi` | 3.1.0 | 3.2.0 |
| Paths / operations / component schemas | 148 / 180 / 103 | 150 / 182 / 107 |
| Errors against the official OAS 3.2 schema | 1 (the version string) | 0 |
| Schema `example` values that fail their schema | 17 of 40 | 0 of 43 |
| Error responses with no body schema | 93 | 0 |
| Error bodies typed only `{"type": "object"}` | not counted | 0 |
| Operations declaring a 5XX response | 0 | 182 (`500` and `503` on every operation) |
| Local `$ref` occurrences | 544 | 1,201, all resolving |

The two added operations are `POST /labels/assets/starter-fonts` and
`POST /labels/locations/{locationId}/print-subtree`.

The external scan cited in the issue reported a 45% score, 208 gaps and 12 TDS scenarios.
Those figures are still unreconciled because its report and counting rules were not supplied.
The counts above measure different things, overlap, and must not be summed into a substitute
score.

## Method and environment

Run on macOS (Darwin 27.0.0) with no containers and no PHP. PHP is not installed on this host,
so the application was not booted.

| Tool | Version | Used for |
|---|---|---|
| `.devtools/ci/check_openapi_document.py` | `master` at `d40f5afb` | Official OAS 3.2 schema (2025-09-17, SHA-256 pinned), `$ref` targets, placeholder enums |
| `@redocly/cli` | 2.11.1, via `npx` | `spec` ruleset (structural) and `recommended` ruleset (style) |
| Python / `jsonschema` | 3.13.9 / 4.23.0 | Inventory, example validation, snapshot-shape validation |
| Node | 26.10.0 | Runs Redocly |

CI pins `jsonschema==4.25.1` in `.github/workflows/tests.yml`. These results come from 4.23.0
and were not re-run on the pinned version.

### Validator negative controls

A validator that ignores the declared version would accept 3.1 and 3.2 documents alike. Two
in-memory variants of the document were linted with Redocly's `spec` ruleset:

| Variant | Expected | Result |
|---|---|---|
| Unknown key added to `info` and to a Response Object | rejected | 2 errors, both `Property bogus is not expected here` |
| `query` operation (new in 3.2) added to `/system/info`, `openapi: 3.2.0` | accepted | valid |
| The same `query` operation, `openapi` changed to `3.1.0` | rejected | `Property query is not expected here` |

`test_check_openapi_document.py` already covers the CI gate's own controls: the document
forced to `3.1.0` fails, along with the other fixtures in `.devtools/ci/fixtures/`.

### Served document

The served document is the committed file with five changes made by
`OpenApiController::DocumentationSpec`. It sets `info.version` and `servers[0].url`,
replaces `PlaceHolderManageApiKeysUrl` in `info.description`, and rebuilds five
`ExposedEntity_*` enums from `StringEnumTemplate`, `ExposedEntity*` and
`UserfieldsService::GetEntities()`.

- **CI evidence.** On `d40f5afb`, the `suite` job of `tests` run
  [40106689800](https://github.com/datagen24/victual/actions/runs/40106689800)
  (2026-10-24) passed its "Check the served OpenAPI document" step. That step runs
  `check_openapi_document.py` on the dump that `OpenApiDocumentTest` writes from a live
  PostgreSQL instance.
- **Local emulation.** This is not the served document. A Python reimplementation of
  `DocumentationSpec` for an install with no user entities passes
  `check_openapi_document.py` (exit 0). In that emulation the
  `ExposedEntity_IncludingUserEntities_NotIncludingNotEditable` enum has 28 members and no
  duplicates, even though `stock` is removed with the not-editable entities and then
  re-added.

Redocly's `no-unused-components` reports ten schemas, and none of them is dead. Each is
listed in `WireContractTest::UNREFERENCED_SCHEMAS` along with the test that proves its use:

- `runtime`: PHP reads the schema from the document. This covers `StringEnumTemplate`, the
  four `ExposedEntity*` enums `DocumentationSpec` uses, and `ExposedEntityEditRequiresAdmin`,
  which `GenericEntityApiController` reads.
- `served:<entity>`: `GET /objects/<entity>` answers rows of this shape. This covers `Task`,
  `StorageClass` and `ProductGroupResolved` (see A4).
- `dev-only`: `Error500` is the development-mode 500 body.

### Contract checks

The checks below use only artifacts already in the repository:

- **Route table.** `routes.php` registers 182 `/api` operations, and the document declares
  the same 182 in both directions. The match is case-insensitive because `RecipesApiController::CopyRecipe`
  is registered with `$group->Post(...)`. `ContractTest::testRouteTableMatchesSpecification`
  enforces this on every suite run.
- **Recorded statuses.** In `tests/Pgsql/snapshots/contract-admin.json` (208 entries) and
  `contract-restricted.json` (102 entries), every recorded HTTP status is declared for its
  operation.
- **Recorded shapes.** For each snapshot entry with a JSON body (169 admin, 101 restricted),
  a value was synthesized from the recorded shape and validated against the documented
  response schema. The script is under [Reproduction](#reproduction). The variants cover
  each alternative of every `a|b` union type and each `missing` key.

The shape check cannot see the following:

- Values. Each string is synthesized as `"x"`, so errors from `enum`, `pattern`, `format`,
  length, `const` and numeric bounds are discarded. A `oneOf` that matches more than once is
  discarded too.
- A shape recorded as `object`. `JsonShape` writes `object` when it merges a list item that
  is an object with one that is not, so nothing under that key is checked.
- The difference between `{}` and `[]`. `ContractTest` decodes with
  `json_decode($raw, true)`, which turns both into an empty PHP array, and `JsonShape`
  records both as `__empty_list__` (A3).
- 39 admin entries and 1 restricted entry with a non-JSON or empty body.
- Request schemas and error-response bodies. The snapshot records successful reads and the
  writes that build its fixture.

## Findings

Findings fall into four classes:

- **Normative violation:** the document breaks a MUST in OAS 3.2.0.
- **Migration requirement:** a 3.1 construct whose meaning or validity changed in 3.2.
- **Contract gap:** the document disagrees with what the server sends or accepts.
- **Style:** optional OAS features, linter preferences, or deprecated-but-valid usage.

Under [ADR-0005](adr/0005-wire-contract-is-the-invariant.md), the wire is the invariant. For
each contract gap, this record names both possible corrections and does not choose between
them.

### Normative violations

None found. The official OAS 3.2 schema reports 0 errors, Redocly's `spec` ruleset reports 0
problems, and all 1,201 `$ref` occurrences resolve. The checks found no missing
path-template parameter, no duplicate `operationId`, no schema example that fails its schema,
and no enum containing `""` outside `StringEnumTemplate`.

### Migration requirements

None outstanding. The only 3.1→3.2 change that affected this document was
[#805](https://github.com/datagen24/victual/issues/805), raw binary as an empty schema, and it
is merged. The document uses no 3.0 constructs: no `nullable`, no boolean
`exclusiveMinimum`/`exclusiveMaximum` and no `format: binary`. It also has no
`jsonSchemaDialect` and no `$self`. Both are optional, and the default dialect applies.

### Contract gaps

**A1. `ConsumptionMapping` declares non-null integers for IDs the server sends as `null`.**
`ConsumptionMappingService::ToWire` sends `recipe_id` and `product_id` as `null` for whichever
target the mapping does not use. It also sends `location.location_id` as `null` for the
`single` and `explicit` location modes. `ConsumptionMapping` is
`allOf: [ConsumptionMappingInput, …]`, so it inherits the input's `{"type": "integer"}` for
all three.

The snapshot records `recipe_id: null` on `GET /consumption/mappings`,
`GET /consumption/mappings/{source_system}/{medication_ref}` and the `PUT` of the same path.
A strict decoder generated from the document rejects those responses. The `location_id` case
comes from reading the code and is not in the snapshot, because the fixture uses `fixed` mode.

There are two possible corrections. The document can type the response's three fields as
`["integer", "null"]` in a response schema that no longer inherits the input. Alternatively,
the server can omit the keys, which is a wire change under ADR-0005.

**A2. `GET /userfields/{entity}/{objectId}` sends `[]` where the document declares an object.**
`GenericEntityApiController::GetUserfields` returns the PHP array from
`UserfieldsService::GetValues`, and `BaseApiController::ApiResponse` passes it to
`json_encode` without `JSON_FORCE_OBJECT`. For an entity with no userfield definitions the
array is empty, so the body is `[]`. The response schema is `{"type": "object"}`.

`GetObject` avoids the same problem for single-object reads with `(object)$userfields`. The
list read attaches `userfields` only when definitions exist, so it always sends a non-empty
map.

This finding comes from reading the code. The snapshot cannot confirm it (see A3), and no
running instance was available. To reproduce, request
`GET /api/userfields/locations/{id}` on an install that defines no userfields for
`locations`.

There are two possible corrections. The document can accept `[]` for the empty case.
Alternatively, `GetUserfields` can cast its result to `(object)` as `GetObject` does. That
changes the wire from `[]` to `{}` and needs an ADR-0005 decision.

**A3. The contract snapshot cannot tell `{}` from `[]`.** `ContractTest` decodes response
bodies with `json_decode($raw, true)`, and `JsonShape::Of` records any empty PHP array as
`__empty_list__`. An empty-map response that regresses to `[]`, or the reverse, therefore
leaves the snapshot unchanged. A2 is in this blind spot. This is a gap in the test tooling,
not in the document.

**A4. 44 of the 57 listable entities have no member schema in the `/objects` unions.** The
`GET /objects/{entity}` schema describes 13 entities through `x-victual-entity-schemas`. Its
description says rows of any other listable entity "match no member and will not decode".
That is the deliberate outcome of [#648](https://github.com/datagen24/victual/issues/648),
and the shape check confirms it for every unmapped entity in the snapshot, including
`recipes`, `tasks`, `roles` and `product_groups`.

Every mapped entity's list rows validate against its own member. The single-object reads of
the mapped entities fail only on `userfields`, and only because of the A3 blind spot.
`GetObject` sends `{}`, the snapshot records it as `__empty_list__`, and the synthesized `[]`
fails `type: object`.

`Task`, `StorageClass` and `ProductGroupResolved` are classified `served:` in
`WireContractTest::UNREFERENCED_SCHEMAS`, and that test proves the route answers rows of
their shape. Adding them to `x-victual-entity-schemas` would document `tasks`,
`storage_classes` and `product_groups_resolved` without writing a new schema. Each one must
first be checked for overlaps with the existing members. The 44 unmapped entities are:

`battery_charge_cycles`, `chores_log`, `equipment`, `label_assets`, `label_drivers`,
`label_media_profiles`, `label_printer_status`, `label_printers`, `label_render_requests`,
`label_template_versions`, `label_templates`, `label_worker_capabilities`, `label_workers`,
`meal_plan`, `meal_plan_sections`, `mqtt_product_entities`, `permission_hierarchy`,
`print_attempts`, `print_evidence`, `print_jobs`, `product_groups`, `product_groups_missing`,
`product_groups_resolved`, `product_location_min_stock`, `product_location_missing`,
`product_substitutions`, `product_substitutions_resolved`, `products_average_price`,
`products_last_purchased`, `quantity_unit_conversions`, `quantity_unit_conversions_resolved`,
`recipes`, `recipes_nestings`, `recipes_pos`, `recipes_pos_resolved`, `roles`,
`shopping_lists`, `shopping_locations`, `stock_current_locations`, `storage_classes`,
`task_categories`, `tasks`, `userentities`, `userobjects`.

**A5. Possible route shadowing between two product paths, not measured.** Redocly's
`no-ambiguous-paths` flags `/stock/products/{productId}/locations` and
`/stock/products/by-barcode/{barcode}`. Both match `/stock/products/by-barcode/locations`.
`routes.php` registers the `{productId}` route first. If Slim's FastRoute dispatcher matches
the first registered route, a product whose barcode is literally `locations` cannot be looked
up by barcode.

OAS 3.2 leaves the choice between ambiguous templated paths to tooling, so this is not a
normative violation. No instance was booted, so the dispatch was not observed. To reproduce,
request `GET /api/stock/products/by-barcode/locations` on a running instance.

### Style

Each item in this section is optional under OAS 3.2.

| ID | Item | Count | Source |
|---|---|---|---|
| A6 | Operations without `summary`, all under `/consumption/` | 10 | Redocly `operation-summary` (an error in `recommended`) |
| A7 | Tags used by operations but missing from top-level `tags`: `Labels`, `Label printing`, `Label rendering`, `Label templates` | 4 | Inventory |
| A8 | Declared tags without `description` | 11 of 16 | Redocly `tag-description` |
| A9 | Operations without `operationId`. Generated clients derive names from it, so adding names changes `victual-kit`'s generated API | 170 of 182 | Redocly `operation-operationId` |
| A10 | Schema Objects using `example`, which OAS 3.1 and later deprecate in favour of JSON Schema `examples` | 43 | Inventory |

Redocly's `no-required-schema-properties-undefined` reports five problems. All are false
positives: each `then.required` names a property defined in the enclosing schema's
`properties`. This covers `tare` in the measure and open requests, and `medication_ref` and
`occurred_at` in `ConsumptionEventSubmission`.

## Remediation breakdown

The issues below are proposed and not filed. Each one names its acceptance evidence.

| Proposed issue | Covers | Class | Needs a decision? | Acceptance evidence |
|---|---|---|---|---|
| `ConsumptionMapping` response nullability | A1 | Contract gap | Yes: document `null`, or omit the keys (ADR-0005) | The shape check above passes for the three mapping operations; a `single`-mode mapping read validates |
| Empty userfield map sent as `[]` | A2 | Contract gap | Yes: document `[]`, or cast to `{}` (ADR-0005) | A request against an entity with no definitions returns a body the schema accepts, with a test recording the raw body |
| Contract snapshot distinguishes `{}` from `[]` | A3 | Test tooling | No | Changing one empty map to `[]` in a response makes `testAdminSnapshotMatchesGolden` fail |
| Wire the three existing schemas into the `/objects` unions | A4 (part) | Contract gap | No | `tasks`, `storage_classes` and `product_groups_resolved` validate against their mapped members; the overlap pins in `x-victual-entity-schemas` are updated |
| Member schemas for the remaining 41 listable entities, split by domain (labels, recipes, product groups and substitutions, meal plan, other) | A4 | Contract gap | No; ordering is a maintainer choice | Each mapped entity's snapshot rows validate against its own member, and no new overlap goes unpinned |
| Observe by-barcode shadowing on a running instance | A5 | Contract gap (unconfirmed) | Only if confirmed | A recorded request and response for a barcode named `locations` |
| Document hygiene: summaries and tags | A6, A7, A8 | Style | No | Redocly `recommended` reports no `operation-summary` errors and no `tag-description` warnings; every tag an operation uses is declared |
| Decide whether to add `operationId`s | A9 | Style | Yes: generated client names change | A recorded decision; if adopted, a `victual-kit` regeneration diff |
| `example` → `examples` in Schema Objects | A10 | Style | No | 0 singular `example` in Schema Objects, and the `contract` phase's example count stays at 43 |

Open from issue 796's completion criteria and not addressed here:

- Reconciling the external scan's totals.
- Defining stateful workflows.
- Verifying the served Swagger UI and `victual-kit` against the 3.2 document beyond the
  2026-10-12 render check.

## Reproduction

Check out `d40f5afb` and run from the repository root:

```sh
python3 .devtools/ci/check_openapi_document.py victual.openapi.json   # exit 0, no output
npx -y @redocly/cli@2.11.1 lint victual.openapi.json --config <(printf 'extends:\n  - spec\n')
npx -y @redocly/cli@2.11.1 lint victual.openapi.json --format=json --max-problems=5000
```

The first Redocly command prints "Your API description is valid". The second reports 10
errors and 197 warnings: 170 `operation-operationId`, 11 `tag-description`,
10 `operation-summary`, 10 `no-unused-components`, 5 `no-required-schema-properties-undefined`
and 1 `no-ambiguous-paths`.

<details>
<summary>Snapshot-shape validation script</summary>

Usage: `python3 shapevalidate.py tests/Pgsql/snapshots/contract-admin.json`. It requires
`jsonschema` and `referencing`. On `d40f5afb` it reports A1 and A2 and, for every unmapped
`/objects` entity, a failed `required` check against some union member (A4).

```python
"""Synthesize a value from each contract-snapshot shape and validate it against the
documented response schema. Errors that synthesized values cause (enum, pattern, format,
bounds, const, multiply-matched oneOf) and errors under a shape recorded as 'object'
are discarded."""
import json, re, sys, collections
from jsonschema import Draft202012Validator
from referencing import Registry, Resource
from referencing.jsonschema import DRAFT202012

s = json.load(open('victual.openapi.json')); snap = json.load(open(sys.argv[1]))
uri = 'urn:v'
reg = Registry().with_resource(uri, Resource.from_contents(s, default_specification=DRAFT202012))
SAMPLE = {'integer': 1, 'number': 1.5, 'boolean': True, 'null': None, 'string': 'x',
          'string(date)': '2026-01-01', 'string(date-time)': '2026-01-01T00:00:00Z',
          'string(date-time-offset)': '2026-01-01T00:00:00+02:00', 'object': {}}
FILTER = {'enum', 'pattern', 'format', 'minLength', 'maxLength', 'const', 'minimum',
          'maximum', 'exclusiveMinimum', 'oneOf'}
ABSENT = object()

def build(shape, choice=None, path=()):
    if isinstance(shape, str):
        alts = shape.split('|')
        pick = (choice or {}).get(path, [a for a in alts if a != 'missing'][0])
        return ABSENT if pick == 'missing' else SAMPLE.get(pick, 'x')
    if isinstance(shape, list):
        return [build(shape[0], choice, path + (0,))] if shape else []
    if shape.get('__empty_list__'):
        return []
    out = {}
    for k, v in shape.items():
        if not k.startswith('__'):
            val = build(v, choice, path + (k,))
            if val is not ABSENT:
                out[k] = val
    return out

def variants(shape, path=()):
    if isinstance(shape, str):
        for alt in shape.split('|')[1:]:
            yield {path: alt}
    elif isinstance(shape, list) and shape:
        yield from variants(shape[0], path + (0,))
    elif isinstance(shape, dict):
        for k, v in shape.items():
            if not k.startswith('__'):
                yield from variants(v, path + (k,))

def shape_at(shape, path):
    for k in path:
        if isinstance(shape, list):
            shape = shape[0] if shape else None
        elif isinstance(shape, dict):
            shape = shape.get(k)
        else:
            return None
    return shape

def real(e, shape):
    at = shape_at(shape, list(e.absolute_path))
    if isinstance(at, str) and 'object' in at.split('|'):
        return None
    if not e.context:
        return None if e.validator in FILTER else e
    branches = collections.defaultdict(list)
    for c in e.context:
        branches[c.relative_schema_path[0]].append(c)
    found = []
    for cs in branches.values():
        r = [x for x in (real(c, shape) for c in cs) if x]
        if not r:
            return None   # some branch fails only on synthesized values
        found.append(r[0])
    return found[0] if found else None

pat = {p: re.compile('^/api' + re.sub(r'\\\{[^}]+\\\}', '[^/]+', re.escape(p)) + '$') for p in s['paths']}
def op_for(key):
    m, path = key.split(' ', 1); path = re.sub(r' \(.*\)$', '', path)
    c = [p for p, rx in pat.items() if rx.match(path) and m.lower() in s['paths'][p]]
    c = [p for p in c if '/api' + p == path] or sorted(c, key=lambda p: -len(re.sub(r'\{[^}]+\}', '', p)))
    return (c[0], m.lower()) if c else None
enc = lambda parts: '/'.join(x.replace('~', '~0').replace('/', '~1') for x in parts)

fails, checked = {}, 0
for key, v in snap.items():
    p, m = op_for(key)
    resp = s['paths'][p][m]['responses'][str(v['status'])]
    base = ['paths', p, m, 'responses', str(v['status'])]
    if '$ref' in resp:
        base = [x.replace('~1', '/').replace('~0', '~') for x in resp['$ref'][2:].split('/')]
        resp = s
        for k in base:
            resp = resp[k]
    shape = v.get('shape')
    if not isinstance(shape, (dict, list)) or (isinstance(shape, dict) and shape.get('__non_json__')) \
            or 'content' not in resp:
        continue
    mt = [k for k in resp['content'] if 'json' in k]
    if not mt:
        continue
    val = Draft202012Validator({'$ref': uri + '#/' + enc(base + ['content', mt[0], 'schema'])}, registry=reg)
    checked += 1
    for choice in [None] + list(variants(shape)):
        errs = [x for x in (real(e, shape) for e in val.iter_errors(build(shape, choice))) if x]
        if errs:
            fails[key] = (p, choice, list(errs[0].absolute_path), errs[0].message[:120])
            break
print('entries', len(snap), 'validated', checked, 'failing', len(fails))
for k, f in fails.items():
    print(' ', k, '|', *f)
```

</details>
