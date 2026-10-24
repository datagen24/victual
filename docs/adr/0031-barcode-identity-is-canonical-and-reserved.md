# ADR-0031: A barcode's identity is canonical, scoped, and reserved while contested

- **Status:** Proposed
- **Decider:** datagen24
- **Recorded:** 2026-09-24, revised 2026-10-24 against `master` at `d40f5afb`: the
  context re-measured, and the record reconciled with ADRs 0011, 0021, 0050 and 0052 and
  with issue 486. The decisions the maintainer answered in issue 483 are unchanged.
- **Referenced by:** [plan 34](../plans/34-barcode-identity-and-scan-resolution.md), [issue 483](https://github.com/datagen24/victual/issues/483), [issue 481](https://github.com/datagen24/victual/issues/481)
- **Relationship:** keeps [ADR-0011](0011-label-namespace.md)'s resolution order and its
  `vctl:` canonicalisation (Accepted). Composes with [ADR-0021](0021-label-templates-are-application-data.md)
  decision 3's import refusal and import epoch (Accepted) without changing them; see
  *Relationship to the label records*. Must honour [ADR-0050](0050-shopping-list-workflows-show-barcodes-and-stock-shortfalls-need-stock-view.md)
  decision 1 (Accepted), which open question 3 leaves to the maintainer. Supersedes
  nothing.

## Context

`product_barcodes.barcode` stores whatever text was typed or scanned, with no trimming,
normalisation or validation on any write path. The unique index `ix_product_barcodes`
covers that raw text. It is case-sensitive; the lookup compares with a nondeterministic
ICU collation that the index cannot serve.

The same retail item arrives in more than one form. Apple's VisionKit reports a UPC-A as
a 13-digit EAN-13 with a leading zero. A person typing from the package enters 12 digits.
A GS1 DataMatrix carries the same GTIN inside an element string after `(01)`. A scanner
may prefix an AIM symbology identifier such as `]E0`. Issue 481 records the visible
failure: a product stored as `012345678905` is not found when a phone scans
`0012345678905`. Every `/stock/products/by-barcode/{barcode}/*` route fails the same way.

Five matchers in the tree disagree about equality:

- `StockService::GetProductIdFromBarcode` compares case-insensitively;
- the generic `objects` filter compares exactly;
- the product picker searches for a substring, so `1234567890` matches a stored
  `4001234567890`;
- the picker's pre-create check compares exactly without URL-encoding;
- the Open Food Facts plugin queries by digits and stores the text as scanned.

These facts were measured by reading the source of `master` at `d40f5afb` on 2026-10-24.
To reproduce, read `StockService::GetProductIdFromBarcode`, `productpicker.js`,
`OpenFoodFactsBarcodeLookupPlugin::ExecuteLookup` and the baseline's `ix_product_barcodes`.
None of the five matchers has changed since 2026-09-24. Since then:
- `helpers/Gtin.php` has gained `SameGtin()`, but its only caller is
  `tests/Pgsql/GtinTest.php`. It still has no production caller and validates no check
  digit.
- The MCP sidecar ([ADR-0052](0052-the-mcp-sidecar-builds-the-catalog-and-the-chart-exposes-it.md))
  is a new client of both sides. Its `lookup_barcode` tool calls
  `/stock/products/by-barcode/{barcode}`. Its `add_barcode` and `create_product` tools
  write through `POST /api/objects/product_barcodes`, and they read a duplicate as today's
  400.

Scale and deli labels use GS1 restricted-circulation numbers. These codes start with
fixed prefixes and embed a price or weight. A restricted-circulation number is unique
only inside the company that printed it and carries no GS1 company prefix, per the
[GS1 General Specifications](https://ref.gs1.org/standards/genspecs/24.0.0/). Two stores
can print the same item reference for different products. The code alone never
identifies the store.

A fix limited to a fallback query was considered and rejected in issue 483. It gives one
scan two possible answers, cannot enforce uniqueness across code widths, and repairs one
of the five matchers.

## Decision

1. **Identity.** A product barcode's identity is the triple
   `(identifier_type, scope_shopping_location_id, barcode)`.
   - `identifier_type` is `gtin`, `restricted` or `opaque`.
   - `scope_shopping_location_id` is the issuing store for `restricted`, and null otherwise.
   - `barcode` holds the canonical value: 14 digits for a GTIN; for a restricted number,
     its 14-digit zero-padded form with the value, verifier and check digits masked, so a
     12-digit sticker and its 13-digit leading-zero reading share one value; for an
     opaque code, its trimmed text.

2. **Carrier is recorded and excluded from identity.** `carrier` records what the scanner
   reported, for example `ean_upc`, `upc_e`, `gs1_128`, `qr` or `code39`. The same
   payload through two carriers is one identity. `scanned_as` keeps the literal
   first-entered value.

3. **Classification.**
   - A payload is GTIN-shaped when, after an AIM identifier is removed, it is 8, 12, 13
     or 14 digits, an AI (01) value, or input carrying the `upc_e` hint.
   - A GTIN-shaped payload whose leading digits match the literal prefix of an
     applicable restricted-circulation pattern is `restricted`.
   - A GTIN-shaped payload that matches no such pattern is `gtin`.
   - Any other payload is `opaque`.
   - A GTIN-shaped payload whose check digit fails is never reclassified as `opaque`.
     A write refuses it, a backfill or import quarantines it, and a scan reports it.

4. **UPC-E needs an explicit hint.** Without the `upc_e` hint, an 8-digit value is
   always GTIN-8, at registration and at scan. With the hint:
   - eight digits are number system, six data digits and check digit;
   - six digits take number system 0 and a computed check digit;
   - seven digits are malformed.

5. **One pure function owns the rule.** `victual_barcode_canonicalize` is a PostgreSQL
   function declared `IMMUTABLE`. It reads no table and receives every input
   explicitly, including the restricted-circulation pattern. The write path, the
   backfill, the importer, the resolver and `helpers/Gtin.php` all call it, and no
   second implementation exists in PHP or JavaScript. The rule covers product barcodes
   only. `LabelIdentityService::Canonicalize`, which reads a `vctl:` uid under
   ADR-0011's alphabet, belongs to the label namespace. It stays where it is and is not
   a second implementation of this rule.

6. **Patterns are immutable versions, and bindings are preserved.** A pattern states its
   layout, value kind, implied decimals, weight unit, currency and verifier rule.
   - A shopping location names a current pattern for new registrations.
   - Each `restricted` row stays bound to the pattern it was registered under.
   - Changing a store's pattern never reinterprets existing rows. Moving rows to a new
     version is an explicit administrator action that shows every row's old and new
     reading before it applies.
   - When two versions bound in one store decode a scan to different results, the
     answer is `ambiguous`.
   - An installation default pattern can decode a sticker. It cannot establish which
     store issued it.
   - The installation default is a reference to one immutable pattern version, chosen
     from the installation country ([issue 486](https://github.com/datagen24/victual/issues/486)).
     A change of country or default changes only how store-less scans are decoded. No
     `restricted` row is bound to the installation default, because every such row has
     an issuing store. A change therefore reinterprets no stored row. While no default
     exists, a store-less scan of a restricted-circulation number answers
     `store_required`.

7. **Uniqueness holds at all times.**
   - A partial unique index `NULLS NOT DISTINCT` covers the identity of `active` rows.
   - A collision found by backfill, import or a pattern migration quarantines every row
     in the collision group. The migration picks no winner.
   - An open conflict group reserves its identity. No write may create an active row
     with a reserved identity.

8. **One locking protocol serialises every identity change.** Every writer takes the
   same lock objects in the same order, as specified in plan 34:
   - a barcode-registry advisory lock, shared for single-row writes and conflict
     resolution, exclusive for backfill, import canonicalisation and pattern migration;
   - then transaction-scoped advisory locks on every identity touched, including both the
     old and the new identity of an update, in ascending key order;
   - then conflict-group rows;
   - then `product_barcodes` rows.

   The writers covered are inserts, updates, deletes, reassignments, product merges,
   product-delete cascades, the importer, conflict resolution and pattern migration. A
   trigger refuses a write to `product_barcodes` made outside the protocol's write
   functions.

   Some transactions already hold locks from outside the protocol before they write a
   barcode. These are ADR-0021's import lock (`LabelIdentityService::LockImport`), with
   the importer's `print_jobs` table lock, and the per-product stock-booking locks
   (`DatabaseService::LockProductsStock`, taken by `MergeProducts()`). Such a transaction
   takes them before the registry lock. The barcode locks are always innermost: no
   protocol function acquires either of those locks after it holds a barcode lock.

9. **Conflict resolution is versioned.**
   - Resolving a group locks its row, compares the client's version and membership with
     the stored ones, applies the administrator's choices, and marks the group resolved
     in one transaction.
   - A stale resolution receives 409, so exactly one resolution wins.
   - The resolution actions work on barcode rows: keep, correct, reassign, edit
     `qu_id` or `amount`, or delete. A product merge is a separate action and is never
     the default. It shows the rows it would rewrite before it runs.

10. **One resolver answers inbound scans.** `ScanResolverService` resolves a scan in the
    order `vctl:` label, Grocycode, product barcode, which keeps
    [ADR-0011](0011-label-namespace.md)'s order. Labels resolve through the existing
    `LabelIdentityService::Resolve(string $code, callable $mayRead)`, the per-kind form
    from plan 32; this record adds no second label lookup.
    - `GET /api/scan` exposes the resolver.
    - The six by-barcode routes call the same resolver and keep their own operation
      permissions and response contracts.
    - Authorisation is checked before a status is chosen, and before the lookup
      wherever the code itself determines the kind. A product-barcode code requires
      `STOCK_VIEW`, and a Grocycode requires its type's read permission; both are
      checked before any row is read, so a denial does not reveal whether the code is
      registered. Only a `vctl:` label is authorised after lookup, because its uid does
      not reveal its kind; the uid's 64 random bits make that difference impractical to
      enumerate. A caller without the read permission receives a generic 403 that names
      no entity and carries no contents, retirement snapshot or candidate list.
    - Two Accepted records constrain this item, and the open questions carry them.
      Today `Resolve()` answers `unknown` for a label of a kind the caller may not read,
      the same answer as for a uid that does not exist (open question 2). Under
      ADR-0050, `SHOPPINGLIST_VIEW` alone permits scanning a product barcode within the
      shopping-list workflow (open question 3).
    - Price fields stay subject to `STOCK_PRICES_VIEW`.

11. **Wire changes authorised by this record.** [ADR-0005](0005-wire-contract-is-the-invariant.md)
    makes the wire the invariant. This record authorises the changes below and no others.
    A response or request difference that this list does not name is a defect.
    - **W1:** `barcode` in `product_barcodes` reads, `product_barcodes_view`,
      `product_barcodes_comma_separated`, and the `product_barcodes` values of the
      `uihelper_shopping_list` and `uihelper_stock_current_overview` views, and in
      `GetProductDetails()`, returns the canonical value.
    - **W2:** `product_barcodes` and `product_barcodes_view` gain `identifier_type`,
      `scope_shopping_location_id`, `carrier`, `scanned_as`, `pattern_id` and `status`.
      `shopping_locations` gains `barcode_pattern_id`.
    - **W3:** a `product_barcodes` write can answer 422 for a failed check digit or
      malformed input, and 409 for a collision with an active row or a reserved identity.
    - **W4:** `GET /api/scan` is new, with the statuses and bodies plan 34 specifies.
    - **W5:** the six by-barcode routes resolve every equivalent form of a registered
      code that a URL path segment can carry. Their status codes and body shapes are
      unchanged. A form containing `/`, GS or another control character, such as a GS1
      element string with FNC1, resolves only through `GET /api/scan`.
    - **W6:** a new read-and-write entity `barcode_patterns` is added. New administrator
      routes are added to list and resolve conflict groups and to preview and apply a
      pattern migration.

## Consequences

The same package resolves to the same product whether it is scanned as UPC-A, EAN-13,
GTIN-14, hinted UPC-E, an AIM-prefixed symbol, or a GS1 element string. The by-barcode
routes cover every form a path segment can carry; `/api/scan` covers the rest. Clients
stop matching barcodes themselves, and the 12↔13 retry in `victual-kit` can be removed.

Every stored GTIN reads back as 14 digits (W1). A client that displays the stored value
shows leading zeros that the person did not type; `scanned_as` holds what they typed.
Snapshot tests, the OpenAPI document and the parity scenario `21-barcodes-and-undo.js`
change with W1 through W6.

A code with a failing check digit can no longer be registered. Hand-keyed typos that
the current schema stores are refused. Existing rows with a failing check digit are
quarantined at upgrade, and those codes stop resolving until an administrator corrects
them.

Quarantine makes a collision visible and blocks only the affected codes. It does not
block the rest of the application, so no global setup gate is needed. The upgrade that
introduces this record ships an administrator conflicts page, independently of the web
setup flow in [issue 484](https://github.com/datagen24/victual/issues/484).

Writes to `product_barcodes` stop being generic.
- The generic entity routes delegate to the write functions.
- `StockService::MergeProducts()` and the product-delete cascade use the same functions.
- A new write path that bypasses the functions is refused by the trigger rather than
  silently accepted.

Placing the rule in the database follows the reasoning of proposed
[ADR-0009](0009-database-as-the-logic-layer.md) for this one rule. This record does not
depend on ADR-0009 being accepted. The reason here is local: the unique index, the
reservation check and the importer all need the same canonical value, and only the
database sees all three.

`bin/victual-db-import` disables user triggers during its verbatim copy. The import
therefore canonicalises the copied rows in a post-copy step, beside the existing
purifier and key hashing. That step takes the registry lock exclusively.

The MCP sidecar's write tools must accept W3's 409 for a collision with an active row or
a reserved identity, and its 422 for a failed check digit, in place of today's 400 for a
duplicate. Its `lookup_barcode` gains W5's width-independent matching with no change on
its side. A form that a path segment cannot carry reaches it only once the tool calls
`GET /api/scan`.

### Relationship to the label records

[ADR-0021](0021-label-templates-are-application-data.md) decision 3 makes an import
refuse a target that holds live labels. The refusal runs inside the import transaction,
under `LabelIdentityService::LockImport`'s advisory lock and a `print_jobs` table lock,
and increments the import epoch. Read in `services/Database/DatabaseImporter.php` on
2026-10-24, that is what the code does. This record changes none of it.
- **Order.** The barcode step runs after the refusal and the epoch increment, and after
  the copy has been proven exact. Plan 34 names the step `StoredBarcodeCanonicalizer`.
  An import refused for live labels never reaches it.
- **Locks.** The step takes no label lock. A transaction that does hold the import lock
  takes it before the registry lock (decision 8). Plan 34 fixes the registry and
  identity keys as distinct from `LabelIdentityService::IMPORT_LOCK`. They use the
  single-key form, which is a separate key space from the two-key stock-booking locks.
- **References.** ADR-0021 forbids a label row or retirement snapshot from carrying a
  foreign key to a table the importer truncates. Nothing in this record makes a label
  refer to a barcode row or a conflict group. A product label names a product, and a
  product barcode resolves to a product without passing through the label tables.
- **Reprints.** A reprint replays retained bytes (ADR-0021 decision 2), so W1 does not
  change a label already rendered. A new render that prints a product's barcode reads
  the canonical value. A template that must print the code as the person entered it
  reads `scanned_as`.

ADR-0011 makes Grocycode a read-only input symbology and resolves `vctl:` before it.
Decision 10 keeps that order, and decision 5 leaves the uid canonicalisation in the
label namespace.

### Options considered

- **Fallback query on miss.** Rejected. It cannot enforce uniqueness across widths, and it
  fixes one matcher of five.
- **A PHP canonicaliser.** Rejected. The constraint and the importer need the rule inside
  PostgreSQL, so the rule would exist twice.
- **A scheme prefix inside `barcode`**, for example `gtin:…`. Rejected. Typed columns can
  be constrained and queried without parsing.
- **Deferring the unique constraint until conflicts are resolved.** Rejected. The
  intermediate state would need its own concurrency rules, and ordinary writes could take
  a contested identity.
- **Re-keying existing restricted rows when a pattern changes.** Rejected. An old sticker
  re-decoded under a new layout can yield a different, valid item reference. Nothing
  would detect the wrong answer, because it causes no collision.
- **Looking up both GTIN-8 and UPC-E readings of an unhinted 8-digit scan.** Rejected. The
  answer would depend on what the catalogue happens to hold, and it would change when
  someone registers the other reading.
- **Relying on the partial unique index alone during resolution.** Rejected. Two
  administrators could correct competing rows to different canonical values, and both
  changes would satisfy the index.

## Acceptance prerequisites

1. **Canonicalisation function.** pgTAP covers `victual_barcode_canonicalize`:
   - every GTIN width, and hinted UPC-E in its 8-digit and 6-digit forms;
   - an unhinted 8-digit value staying GTIN-8, and a 7-digit hinted value refused;
   - AIM prefixes and AI (01) element strings;
   - check-digit failures, which are never classified `opaque`;
   - each pattern field.

   The tests run on PostgreSQL 15 and 16. The function's volatility is shown to be
   `IMMUTABLE`, and it reads no table.
2. **Price verifier.** The four- and five-digit price/weight verifier algorithms are
   checked against worked examples from the GS1 General Specifications. The accepting PR
   names the specification section used.
3. **Locking protocol under concurrency.** The protocol is demonstrated on real
   PostgreSQL with two connections:
   - an ordinary insert racing a resolution of the same identity leaves one active row;
   - two resolutions correcting competing rows to different canonical values leave one
     winner and one 409;
   - an insert racing an update that moves a row into the same identity leaves one
     active row;
   - a sustained run of concurrent single-row barcode writes produces no deadlock
     (SQLSTATE 40P01) among barcode-only transactions.
4. **Upgrade load.** The backfill is measured against the demo dataset and against the
   maintainer's pre-fork grocy backup. The record gives the row counts by identifier
   type, the number of conflict groups and the number of failed check digits. The
   measurement is dated and reproducible, so the quarantine an upgrade would create is
   known before release.
5. **Wire changes reconciled.** The response-contract snapshot diff and
   `victual.openapi.json` diff contain W1 through W6 and nothing else.
6. **Maintainer decision.** The maintainer confirms decisions 1 through 11. Issue 483
   records the answers these decisions restate.

## Open questions

1. **Installation default pattern.** Issue 483 says to seed the default "from the country
   convention", and on 2026-10-24 the tree has no country setting to seed it from. The
   maintainer answered plan 34's question 1 with an installation country, set during
   onboarding or by an environment variable. That setting is
   [issue 486](https://github.com/datagen24/victual/issues/486): open, in milestone
   0.5.5, and being built in parallel with this revision. It proposes
   `Setting('COUNTRY', '')`, overridable as `VICTUAL_COUNTRY`, holding an ISO 3166-1
   alpha-2 code. The default pattern stays unset until the setting exists, and then
   comes from the country.

   This record's decisions do not depend on issue 486, because decision 6 already treats
   the default as optional. Two things remain open here:
   - **The country-to-pattern table.** Which pattern each country maps to, starting with
     `US` (`2IIIIIPPPPPC`), and what an unmapped country yields.
   - **Issue 486 question 2.** Whether a derived default is computed when it is read or
     written once. Either answer satisfies decision 6, because no stored row is bound to
     the default. But if the default is computed at read time, a change of country also
     changes the answer to an already printed sticker scanned without a store. Plan 34
     should say which behaviour it expects.
2. **A `vctl:` label of a kind the caller may not read.** Decision 10 and plan 34 answer
   with a generic 403 after lookup, so a denied label is distinguishable from an unknown
   uid. That follows the maintainer's answer D18 in issue 483. Today
   `LabelIdentityService::Resolve` answers `{"status":"unknown"}` for both cases, and
   plan 32 tests that they are indistinguishable. Before acceptance, the maintainer
   either:
   - has `/api/scan` adopt `unknown` for labels, so the two resolvers agree; or
   - confirms the 403, with the reason the two endpoints differ.

   The recommendation is `unknown`. One answer for one code on two routes is the
   simpler contract, and the 403 adds no protection that the uid's 64 bits do not
   already give.
3. **Scanning on a shopping-list page without `STOCK_VIEW`.** ADR-0050 decision 1
   (Accepted 2026-10-18) lets `SHOPPINGLIST_VIEW` alone display, scan and search product
   barcodes within the shopping-list workflow. Decision 10 requires `STOCK_VIEW` for a
   product-barcode code, and plan 34 moves the product picker onto `/api/scan`. A
   shopping-only member would then receive 403 where ADR-0050 guarantees a working scan.
   Before acceptance, the record names one of these:
   - `SHOPPINGLIST_VIEW` suffices for a product-barcode code with a reduced payload.
     That is the product's id and name, its matched row's `qu_id` and `amount`, and no
     stock or price.
   - The shopping-list picker keeps its own ADR-0050-scoped path.

   Either way, the permission is still checked before canonicalisation or lookup.
