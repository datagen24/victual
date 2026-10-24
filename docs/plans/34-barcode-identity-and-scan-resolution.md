# 34. Barcode identity and scan resolution

**Goal:** A scanned barcode resolves to the same product whatever form the scanner
reports. Stored barcodes are canonical and self-describing. Conflicting mappings are
held for an administrator instead of being decided silently.
**Depends on:** [ADR-0031](../adr/0031-barcode-identity-is-canonical-and-reserved.md)
(**Proposed**, revised 2026-10-24). Implementation waits for its acceptance, which lands
in a separate, bookkeeping-only pull request. Its open questions were answered on
2026-10-24. The plan does not depend on the web setup flow in
[issue 484](https://github.com/datagen24/victual/issues/484).
**Status:** draft; design settled in [issue 483](https://github.com/datagen24/victual/issues/483)
(revision 3, 2026-09-24), which subsumes [issue 481](https://github.com/datagen24/victual/issues/481).

## Problem and outcome

A household stored `012345678905` for a product. An iPhone scan of the same package
reports `0012345678905`, and every `/stock/products/by-barcode/{barcode}/*` route
answers `400 No product with barcode`. The Swift SDK retries a miss in the other width,
which covers UPC-A and EAN-13 but not EAN-8, GTIN-14, UPC-E, GS1 element strings or AIM
prefixes. Every other client would need the same retry.

After this plan:

- one package resolves to one product through every entry point;
- a mistyped check digit is refused at registration;
- a store's scale sticker resolves to the product the store's item number names;
- the stored value says what kind of identifier it is.

## Current behaviour

Measured against `master` at `12b032f8` on 2026-09-24 by reading the source. Re-read
against `master` at `d40f5afb` on 2026-10-24: unchanged except where noted.

### Storage

- `product_barcodes.barcode` is `TEXT NOT NULL` (`db/pgsql/baseline/01_tables.sql`).
- `ix_product_barcodes` is a unique index on the raw value
  (`db/pgsql/baseline/02_indexes.sql`), and it is case-sensitive.
- No foreign key exists. A trigger refuses an unknown `product_id`, and the product
  delete trigger cascades.

### Write paths

No write path trims, normalises or validates a barcode:

- the generic entity routes for `product_barcodes`;
- `public/viewjs/productbarcodeform.js`;
- the add-barcode-to-existing flows in purchase, consume, inventory, transfer, shopping
  list and recipe forms;
- products created from a lookup plugin, in `StockService::ExternalBarcodeLookup`;
- `StockService::MergeProducts`, which rewrites `product_id`;
- `DemoDataGeneratorService`, which seeds three EAN-8 codes. All three have valid
  check digits.

`DatabaseImporter` copies rows verbatim with user triggers disabled. It then re-applies
row-rewriting steps (the purifier, the API key hashing) after proving the copy exact.

### Matchers

| Where | Comparison |
|---|---|
| `StockService::GetProductIdFromBarcode` | `barcode = ? COLLATE NOCASE`, an ICU nondeterministic collation the index cannot serve |
| `BaseApiController` `query[]=barcode=` | exact, case-sensitive |
| `productpicker.js` `FindOptionBySearchdata` | substring `indexOf` over lower-cased, comma-joined barcodes |
| `productpicker.js` pre-create check | exact, input not URL-encoded |
| `OpenFoodFactsBarcodeLookupPlugin` | queries digits only, stores the barcode as scanned |

### Other gaps

- **GS1.** Nothing parses AIM identifiers, AI (01), FNC1 or GS, UPC-E, or
  restricted-circulation prefixes.
- **`helpers/Gtin.php`** pads 8-, 12-, 13- and 14-digit codes to 14 digits, and
  `SameGtin()` compares two codes after padding. It validates no check digit. Its only
  caller is `tests/Pgsql/GtinTest.php` (2026-10-24); nothing in production calls it.
- **MCP sidecar** (ADR-0052, since 2026-10-23). `lookup_barcode` calls the by-barcode
  details route. `add_barcode` and `create_product` write through
  `POST /api/objects/product_barcodes`, and they treat today's 400 as a duplicate. These
  are W3 and W5 clients; see ADR-0031's Consequences.
- **Camera scanner.** `camerabarcodescanner.js` does not list `UPC_A` or `UPC_E`.
- **Route shape.** The by-barcode routes take the code as a path segment, so a code
  containing `/` or the GS separator cannot reach them. This plan keeps those routes
  and routes such codes through `/api/scan` instead; see "Resolver and routes".

## Scope

**Included**

- The identity model, the canonicalisation function, and the restricted-circulation
  patterns.
- Schema changes, the write functions and the locking protocol.
- Quarantine, backfill and import canonicalisation.
- `ScanResolverService`, `GET /api/scan`, and delegation from the six by-barcode routes.
- An administrator conflicts page and pattern administration.
- The product picker's switch to the resolver.
- Adding `UPC_A` and `UPC_E` to the camera formats.

**Excluded**

- **The web setup flow and its import maintenance state**, which belong to
  [issue 484](https://github.com/datagen24/victual/issues/484). If that flow is built,
  it may host the conflicts page as a step.
- **Parsing GS1 application identifiers other than (01)** (expiry, batch). Plan 22 lists
  these as out of scope for medication intake too.
- **Resolving external lookups.** Plan 09 owns lookup sources. This plan changes only
  what the plugins receive and how their `__barcode` is stored.
- **Feeding a decoded sticker price or weight into a booking.** See question 2.

**Client impact**

- The Victual web UI resolves through `/api/scan`.
- The Swift SDK in `datagen24/victual-kit` switches `productDetail(barcode:)` to
  `/api/scan` and removes its width retry.
- Any client that displays `product_barcodes.barcode` shows 14-digit GTINs. `scanned_as`
  holds the original value.

## Design

### Identity model

A product barcode's identity is `(identifier_type, scope_shopping_location_id, barcode)`,
per ADR-0031 decision 1.

| Column | Values | Identity |
|---|---|---|
| `identifier_type` | `gtin`, `restricted`, `opaque` | yes |
| `scope_shopping_location_id` | the issuing store for `restricted`, otherwise null | yes |
| `barcode` | canonical value | yes |
| `carrier` | `ean_upc`, `upc_e`, `gs1_128`, `code128`, `code39`, `qr`, `datamatrix`, `gs1_datamatrix`, `unknown` | no |
| `scanned_as` | the literal first-entered value | no |
| `pattern_id` | the `barcode_patterns` row a `restricted` row was registered under | no |
| `status` | `pending`, `active`, `quarantined` | no |
| `conflict_group_id` | the group of a quarantined row | no |

**Uniqueness:**

```sql
CREATE UNIQUE INDEX ix_product_barcodes_identity
  ON product_barcodes (identifier_type, scope_shopping_location_id, barcode)
  NULLS NOT DISTINCT
  WHERE status = 'active';
```

`NULLS NOT DISTINCT` needs PostgreSQL 15. The application's minimum is 15
(`PostgresDialect::MINIMUM_MAJOR_VERSION`), and CI runs 15 and 16.

The `pending` status exists only for the importer's verbatim copy. The post-copy step
moves every `pending` row to `active` or `quarantined`, so no `pending` row outlives an
import. The resolver treats `pending` like `quarantined`.

### Canonicalisation function

```text
victual_barcode_canonicalize(code text, carrier text, patterns jsonb)
  RETURNS (identifier_type text, canonical text, pattern_id int,
           decoded_value numeric, value_kind text, status text)
```

The function is declared `IMMUTABLE` and `PARALLEL SAFE`, and it reads no table.
`patterns` is the ordered list of candidate patterns the caller supplies:

- for a write, the store's current pattern;
- for a scan with a store, every pattern version the store has bound rows to, plus
  its current pattern;
- the installation default where applicable.

`status` is one of `ok`, `invalid_check_digit` or `malformed`.

The function applies these steps in order:

1. **Reject malformed input.** An empty value, a value over 256 bytes, or control
   characters other than GS (0x1D) are `malformed`.
2. **Strip an AIM symbology identifier.** A leading `]` plus two characters is removed.
   `]C1`, `]e0`, `]d2` and `]Q3` mark GS1 content.
3. **Extract a GS1 GTIN.** For GS1 content, or for a payload starting with `(01)` or with
   FNC1, the 14 digits after AI (01) are extracted. The remaining element string is
   ignored.
4. **Expand UPC-E, only with the `upc_e` carrier.** The data digits `d1…d6` expand to
   UPC-A by the standard table:

   | `d6` | UPC-A body (number system first, check last) |
   |---|---|
   | 0, 1, 2 | `NS d1 d2 d6 0 0 0 0 d3 d4 d5` |
   | 3 | `NS d1 d2 d3 0 0 0 0 0 d4 d5` |
   | 4 | `NS d1 d2 d3 d4 0 0 0 0 0 d5` |
   | 5–9 | `NS d1 d2 d3 d4 d5 0 0 0 0 d6` |

   - Eight digits are NS, `d1…d6` and a check digit. NS must be 0 or 1, and the check
     digit is validated against the expanded UPC-A.
   - Six digits take NS 0, and the check digit is computed.
   - Seven digits are `malformed`.
5. **Recognise GTIN-shaped input.** A payload is GTIN-shaped if it is 8, 12, 13 or 14
   digits, or if step 3 or 4 produced it. The GS1 mod-10 check digit is validated. A
   failure returns `invalid_check_digit` with `identifier_type = gtin`, and the
   function never falls through to `opaque`. Without the `upc_e` hint, 8 digits are
   GTIN-8.
6. **Match restricted-circulation patterns.** Pattern matching works on the 14-digit
   form. The input is left-padded with zeros to 14 digits, and each candidate pattern's
   layout is left-padded with literal `0` positions to 14 characters. A 12-digit UPC-A
   sticker and its 13-digit leading-zero reading therefore match the same layout at the
   same positions. A pattern applies when every literal digit of its padded layout
   matches.
   - A match yields `restricted`. The canonical value is the padded 14-digit code with
     the `P` and `W` positions and the `V` and `C` digits replaced by `0`. Repeat
     stickers, and both widths of one sticker, share a canonical value.
   - `decoded_value` is the value digits divided by `10^decimals`.
   - A failing verifier digit is `invalid_check_digit`.
7. **Otherwise use the other types.** GTIN-shaped input is `gtin`, with its canonical
   value padded to 14 digits. Any other input is `opaque`, and its canonical value is
   the input with surrounding whitespace trimmed. Case is kept, because Code 39 and QR
   payloads can be case-significant.

The function returns one row per candidate pattern that matched, plus the non-pattern
reading. The caller decides what to do with more than one row; see the resolver below.

`helpers/Gtin.php` keeps its public methods as a thin caller of this function:
`Normalize()` returns the canonical value, and `SameGtin()` compares two canonical
values. Plan 09's asked-versus-answered check uses it.

### Restricted-circulation patterns

A pattern is an immutable `barcode_patterns` row with these fields:

| Field | Meaning |
|---|---|
| `layout` | One character per digit position of the 12- or 13-digit form. A literal digit is a required prefix, `I` an item reference digit, `P` a price digit, `W` a weight digit, `V` the price/weight verifier digit, and `C` the check digit. One US random-weight layout is `2IIIIIPPPPPC`; "Country default table" covers which one GS1 US specifies. Matching pads it to 14 characters (canonicalisation step 6). |
| `value_kind` | `price` or `weight` |
| `decimals` | implied decimal places of the value field |
| `weight_unit` | `g`, `kg`, `lb` or `oz`; required for `weight` |
| `currency` | ISO 4217 code for `price` |
| `verifier` | `none`, `four_digit` or `five_digit`, and it must agree with the count of `P` or `W` positions |
| `supersedes_pattern_id` | the version this row replaces, if any |

The following rules apply:

- `shopping_locations.barcode_pattern_id` names the store's current pattern.
- Registering a `restricted` code requires `shopping_location_id` on the barcode row,
  which the product barcode form already has. That value becomes
  `scope_shopping_location_id`.
- A GTIN-shaped code that matches no pattern is `gtin`, including a code with a
  restricted-circulation prefix. This keeps the three demo EAN-8 codes, and similar
  existing data, global.
- An update to a `barcode_patterns` row that `product_barcodes` references is refused.
  A change creates a new row.

**Pattern migration** moves a store's rows from one version to another. It is an
administrator action with two steps. The preview lists every bound row with its old
canonical value, its new canonical value and the product each resolves to. The apply
step runs under the locking protocol. A row whose new identity collides is quarantined,
together with the rows it collides with.

**The installation default pattern** decodes a sticker scanned without a store. A
default-pattern match is `restricted` but has no scope. The resolver accepts it only if
exactly one store's active rows match under that store's own bound patterns.

### Country default table

The installation default is a set of pattern versions, one for each literal prefix in
the installation country's table (ADR-0031 decision 6). The set is looked up at read time
from `InstallationCountry::Get()`. Within one country the prefixes do not overlap, so a
store-less scan matches at most one default pattern. An unset country gets no default,
and so does a country without a row below; a store-less scan then answers
`store_required`.

**How the sources were read.** On 2026-10-24, each source below was fetched and read.
To reproduce, open the URL and read the cited section. "Verified" means the full layout
was read from that primary GS1 source: prefix, field widths, value kind, unit and
verifier. Any other row ships no default.
- The General Specifications citations are to Release 18 (January 2018), read from GS1
  Canada's mirror. Section numbers move between releases, so prerequisite 7 re-checks
  every row against the current release.
- GS1's "Summary of GS1 Prefixes 20-29 by GS1 Member Organisation" answered 403 to every
  automated fetch. The accepting PR should read it by hand:
  <https://www.gs1.org/docs/barcodes/SummaryOfGS1MOPrefixes20-29.pdf>.

**General Specifications.** [Release 18](https://gs1ca.org/documents/standards/GS1-General-Specifications.pdf):
- §2.1.14.2, pp. 72–73, and §2.1.13.4, p. 69: prefixes 02 and 20–29 (GTIN-13 form) and
  2 (UPC-A) are restricted circulation. Each member organisation defines the layout, and
  a price verifier "should" be used.
- §7.9.2–§7.9.4, pp. 436–438: the four- and five-digit price verifier algorithms that
  prerequisite 2 tests.

**Verified rows.** These ship as defaults.

| Country | Prefix | Layout | Value | Verifier | Source |
|---|---|---|---|---|---|
| `GB` | 20 | `20IIIIIVPPPPC` | price, GBP, 2 decimals | `four_digit` | [GS1 UK, "How to calculate variable measure items"](https://test.gs1uk.org/sites/default/files/How_to_calculate_variable_measure_items_0.pdf) (08/16), p. 3, branded layout |
| `DE` | 23 | `23IIIIVPPPPPC` | price, EUR, 2 decimals | `five_digit` | [GS1 Germany, "GTIN in der Anwendung" v1.1](https://www.gs1-germany.de/fileadmin/gs1/fachpublikationen/globale-artikelnummer-gtin-in-der-anwendung.pdf) (2019-08), §5.2, pp. 26–27, Tabelle 9 |
| `AT` | 21, 27 | `21IIIIIWWWWWC`, `27IIIIIWWWWWC` | weight, kg, 3 decimals | `none` | [GS1 Austria, Nationale GS1 Spezifikationen V23](https://www.gs1.at/sites/default/files/2023-04/Nationale-GS1-Spezifikationen-V23-AT.pdf) (2023-04), Abb. 10.2.2-2 and 10.2.2.1-1 to -3, pp. 9–12; [Kurzinfo](https://www.gs1.at/sites/default/files/2020-05/Kurzinfo-GS1-Austria-variable-Endverbrauchereinheiten.pdf), p. 1 |
| `AT` | 22, 23, 24 | `22IIIIIPPPPPC`, `23IIIIIPPPPPC`, `24IIIIIPPPPPC` | price, EUR, 2 decimals | `none` | as above |
| `CH` | 21 | `21IIIIVPPPPPC` | price, CHF, 2 decimals | `five_digit` | [GS1 Switzerland, Nationale Spezifikationen V23](https://www.gs1.ch/de/media/1633) (2023-09), §10.1.1 and §10.1.3, pp. 3–6 |
| `CH` | 22 | `22IIIIIPPPPPC` | price, CHF, 2 decimals | `none` | as above |
| `CH` | 28 | `28IIIIVWWWWWC` | weight, g, 0 decimals | `five_digit` | as above |
| `CH` | 29 | `29IIIIIWWWWWC` | weight, g, 0 decimals | `none` | as above |
| `DK` | 27 | `27IIIIIWWWWWC` | weight, g, 0 decimals | `none` | [GS1 Denmark, GTIN-13 vægt](https://www.gs1.dk/en/gs1-standarder/gtin-13-vaegt); [how-to](https://www.gs1.dk/en/vejledninger/sadan-opretter-du-vaegtvariable-stregkoder-og-produkter) |

In the Austrian, Swiss and Danish rows, a SAN, HPID or pack-type digit is folded into
the `I` positions. Identity takes the item reference whole, so the split inside it does
not matter here. Austria 28–29 and Denmark 26 and 28 encode a piece count or a
six-digit quantity, which `value_kind` cannot express, so they ship no default.

**The US row needs the maintainer.** The maintainer named `2IIIIIPPPPPC` on 2026-10-24.
General Specifications Release 18, Fig. 2.1.14.2-1 row 1, gives "the structure specified
by GS1 US" as `2IIIIIVPPPPC`: a four-digit price with a price verifier. `2IIIIIPPPPPC`
matches only one of that figure's generic alternatives. No gs1us.org document stating
either layout was found. Until the maintainer chooses and a primary GS1 US source
confirms the choice, `US` ships no default (prerequisite 7). The cents interpretation
(USD, 2 decimals) is US convention and also unverified.

**Unverified or not found.** These ship no default until a primary source gives the
whole layout.

| Country | What a primary source gives | Missing | Source |
|---|---|---|---|
| `GB` (02, in-store) | `02` + item + price + check | the cell widths contradict the prose: it computes a five-digit verifier but has no room for one | GS1 UK PDF above, p. 3 |
| `DE` (22, 25, 26, 28, 29) | the meaning of each prefix (price, pieces, weight; retailer or SAN) | field widths, verifier and unit | GS1 Germany above, Tabelle 8 |
| `BE`, `LU` | supplier prefixes 295, 296 and 28; retailer prefixes 02 and 20–27 | the digit layout | [GS1 Belgium & Luxembourg](https://www.gs1belu.org/fr/media/1601/download) (2021-03), p. 1 |
| `SE` | `2X`, where X sets the unit and decimals (SEK 0–2 decimals, kg 1–3 decimals) | the width of the value field | [GS1 Sweden](https://gs1.se/en/support/how-do-i-create-my-variable-weight-numbers/) |
| `NO` | 20–22 carry a price, 23–25 a weight; four-digit value | the decimals | [GS1 Norway](https://gs1.no/gs1-vektvareprefiks/) |
| `IT` | prefix 2 is assigned by GS1 Italy; price for retail, kg for cash and carry | field widths, which appear only in a figure | [GS1 Italy, Guida pratica](https://gs1it.org/files/sharing/1463583138/163), §3.4.1, pp. 18–19 |
| `FI` | weight classes 23–25, from a search snippet only | the whole layout; the page failed TLS | gs1.fi |
| `FR`, `NL`, `ES`, `PL`, `IE`, `CA`, `AU`, `NZ` | no layout found | everything | gs1.fr, gs1.nl, aecoc.es, gs1pl.org, gs1ie.org, gs1ca.org, gs1nz.org |

### Write functions and the locking protocol

Every change to `product_barcodes` goes through one of these PL/pgSQL functions:

- `victual_barcode_insert`
- `victual_barcode_update`, which covers a value correction and a product reassignment
- `victual_barcode_delete`
- `victual_barcode_resolve_conflict`
- `victual_barcode_migrate_pattern`
- `victual_barcode_canonicalize_pending`, used by the backfill and the importer

Each function sets the transaction-local setting `victual.barcode_protocol`. A `BEFORE`
row trigger on `product_barcodes` raises an error when that setting is absent. This makes
a new write path that skips the protocol fail at once. The trigger also re-runs the
checks (canonicalisation, reservation, index), so the functions are not the only guard.

The following callers are moved onto these functions:

- the generic entity routes for `product_barcodes`, through a `ProductBarcodeService`;
- `ExternalBarcodeLookup`;
- `MergeProducts()`;
- the product-delete cascade trigger;
- the demo data generator.

**Lock objects**, which all use transaction-scoped locks so that nothing survives a
commit, a rollback or a pooled connection:

| Object | Lock |
|---|---|
| Registry | `pg_advisory_xact_lock_shared(K_REGISTRY)` or `pg_advisory_xact_lock(K_REGISTRY)` |
| Identity | `pg_advisory_xact_lock(K(identity))`, with `K` defined below |
| Conflict group | `SELECT … FROM barcode_conflict_groups WHERE id = ANY(...) ORDER BY id FOR UPDATE` |
| Barcode rows | the row locks the write statements take |

The identity key is:

```sql
K(identity) = hashtextextended(
  identifier_type || '|' || coalesce(scope_shopping_location_id::text, '') || '|' || canonical,
  K_SEED)
```

`K_REGISTRY` and `K_SEED` are fixed constants, chosen distinct from
`LabelIdentityService::IMPORT_LOCK`. A hash collision between two identities only makes
two unrelated writes wait for each other, so it cannot produce a wrong answer.

**Acquisition order** is the same for every writer:

1. The registry lock.
2. Identity locks, deduplicated, in ascending key order.
3. Conflict-group rows, in ascending id order.
4. `product_barcodes` rows.

No writer takes a lock earlier in this order after one later in it.

| Writer | Registry | Identity locks |
|---|---|---|
| Insert | shared | the new identity |
| Update (value correction or reassignment) | shared | **both the old and the new identity** |
| Delete, including the product-delete cascade | shared | the old identity |
| `MergeProducts()` | shared | the old identity of every row it reassigns (the identity is unchanged, but a quarantined row's group membership may change) |
| Conflict resolution | shared | the group's identity and every identity its corrections produce |
| Pattern migration | exclusive | none beyond the registry |
| Backfill migration, importer post-copy step | exclusive | none beyond the registry |

Bulk writers take the registry lock exclusively for two reasons. One exclusive lock
excludes every single-row writer. It also avoids taking one advisory lock per row, which
on a large catalogue would exhaust `max_locks_per_transaction`.

**Under the locks, a single-row write performs these steps:**

1. Canonicalise the new value.
2. Refuse with 409 `quarantined` if an open conflict group holds the new identity.
3. Refuse with 409 if an active row holds the new identity.
4. Write the row.
5. If the row was quarantined, bump its group's `version`.

The unique index is the final guard.

**Deadlocks.** Barcode-only transactions cannot deadlock under this order. A transaction
that also holds a row lock on another table, such as a product row during
product-delete, can still deadlock with a barcode writer. PostgreSQL detects such a
deadlock and aborts one transaction with SQLSTATE `40P01`. The API maps that code to
409 with a retry hint. Correctness does not depend on deadlock freedom: a single
winning resolution and reservation of a contested identity come from the locks and the
version check.

**Reads take no locks.** `/api/scan` reads a consistent snapshot.

### Quarantine and conflict groups

`barcode_conflict_groups` holds these columns:

- `id`
- `identifier_type`, `scope_shopping_location_id` and `canonical`: the reserved identity
- `state`: `open` or `resolved`
- `version`
- `reason`: `collision` or `invalid_check_digit`
- `created_by`: `backfill`, `import` or `pattern_migration`
- `resolved_at` and `resolved_by`

A partial unique index on the identity, `WHERE state = 'open'`, allows one open group
per identity.

**A group is created in three cases:**

- Two or more rows share an identity. Every row goes into the group.
- A row fails its check digit. Its group's identity is the digits as given, so a later
  correction to the same digits is still reserved.
- A pattern migration produces a collision.

**Resolution** runs through `victual_barcode_resolve_conflict(group_id, expected_version,
actions jsonb)` in this sequence:

1. Read the group and its members without locking. Compute every identity the requested
   actions would produce.
2. Take the locks in protocol order.
3. Take `SELECT … FOR UPDATE` on the group. If its `version` differs from
   `expected_version`, or its membership changed, return a stale 409 with the current
   state.
4. Release the group's reservation by setting `state = 'resolved'` and bumping
   `version`. The identity locks from step 2 and the group row lock from step 3 are
   still held. No other writer can take the identity until commit, and no other
   resolution can pass the version check.
5. Apply the actions. Each resulting active row must satisfy the index check and the
   reservation check against every *other* open group. The group being resolved no
   longer reserves its identity, so the kept row can be activated.
6. Commit. If any action fails, the whole transaction rolls back, including step 4, and
   the group stays open at its old version.

The resolution actions, per ADR-0031 decision 9:

- **keep:** activate one row and delete the others;
- **correct:** change a row's code and re-canonicalise it;
- **reassign:** move a row to another product;
- **edit:** change `qu_id` or `amount`;
- **delete:** remove a row.

**Product merge** is a separate route. It shows a preview before it runs: row counts per
table that `MergeProducts()` would rewrite, plus the unit conversion factor it would
apply. It runs only on a second, explicit request.

### Backfill and import

**Migration.** A PostgreSQL migration (numbers claimed in `migrations/RESERVATIONS.md`
when implementation is scheduled) makes these changes:

- it adds the columns, `barcode_patterns`, `barcode_conflict_groups`,
  `shopping_locations.barcode_pattern_id`, the functions and the trigger;
- it copies `barcode` into `scanned_as`;
- it sets `status = 'pending'` and calls `victual_barcode_canonicalize_pending()` under
  the exclusive registry lock;
- it replaces `ix_product_barcodes` with `ix_product_barcodes_identity`.

The steps run in one transaction. The migration reports the counts it produced: rows
by type, groups by reason.

**Import.** `DatabaseImporter` needs no change to its verbatim copy. The new columns do
not exist in the SQLite source. They take defaults on copy (`status` defaults to
`pending`), and `scanned_as` is filled after the copy. After `AssertRowCountsMatch` and
`AssertValuesMatch`, a new step `StoredBarcodeCanonicalizer` runs beside
`StoredHtmlPurifier` and `StoredApiKeyHasher`, under `$applyRowMigrations`. It calls
`victual_barcode_canonicalize_pending()` in its own transaction, under the exclusive
registry lock.

Between the copy's commit and that step, `pending` rows neither resolve nor reserve.
If a concurrent write creates an active row with an identity a `pending` row will
produce, the step quarantines both rows. Issue 484's import maintenance state closes
this window where it is deployed. This plan does not depend on it.

### Resolver and routes

`ScanResolverService::Resolve(code, carrier, shopping_location_id, user)` is the only
resolver. It runs these steps:

1. `LabelIdentityService::Resolve` for `vctl:` codes.
2. `Grocycode` for `grcy:` codes.
3. Otherwise, canonicalise the code with the candidate patterns and look up the
   resulting identities among active rows. Quarantined or pending rows with the
   identity produce `quarantined`.

**Authorisation happens before the lookup wherever the code itself reveals the kind.**
This avoids a 403-versus-404 difference that would tell a caller whether a code is
registered (CWE-203). The generic entity read routes work the same way:
`EntityReadPolicy::Check` runs before any row is read.

- **Product barcodes.** Every non-label, non-Grocycode code can only resolve to a
  product. A caller with neither `STOCK_VIEW` nor `SHOPPINGLIST_VIEW` receives 403
  before canonicalisation or lookup. The 403 is the same for a registered code, an
  unknown code, a quarantined identity and an invalid check digit.
- **`SHOPPINGLIST_VIEW` without `STOCK_VIEW`** (ADR-0050 decision 1; maintainer
  decision, 2026-10-24). The resolver runs, and the answer is reduced:
  - `matched` carries the product's `id` and `name` and the matched row's `qu_id` and
    `amount`;
  - `ambiguous` lists each candidate by `id` and `name`;
  - nothing carries stock, prices or `GetProductDetails()`.

  This is how the product picker on the shopping-list pages resolves a scan. A
  Grocycode still needs `STOCK_VIEW`.
- **Grocycodes.** The type letter (`p`, `r`, `c`, `b`) names the kind, so its read
  permission is checked before the id is looked up.
- **`vctl:` labels.** The kind is known only after the uid is looked up, so the
  permission for the matched kind is checked after lookup and before any status is
  chosen. A label of a kind the caller can't read answers `unknown` (404). That is the
  same status and body as an unknown uid, and it carries no kind and no retirement
  snapshot. This matches `LabelIdentityService::Resolve`, which plan 32 tests. No label
  answer is a 403. (Maintainer decision, 2026-10-24, revising issue 483's D18 for labels
  only.)

After authorisation, the resolver chooses the status.

**`GET /api/scan?code=&carrier=&shopping_location_id=`** answers:

| Status | HTTP | Body |
|---|---|---|
| `matched` | 200 | `kind`, the kind's payload, and for a product barcode the matched row's `qu_id`, `amount`, `identifier_type`, and for `restricted` the `decoded_value` and `value_kind` |
| `unknown` | 404 | the canonical reading |
| `ambiguous` | 409 | only the candidates the caller may read |
| `quarantined` | 409 | a pointer to the conflicts page |
| `store_required` | 409 | none |
| `retired_label` | 410 | per ADR-0011 |
| `invalid_check_digit` | 422 | none |
| `malformed` | 400 | none |
| denied | 403 | product barcodes and Grocycodes only, returned before lookup: a generic denial with no kind, id, name, contents or candidates, so it says nothing about whether the code exists. A `vctl:` label the caller may not read answers `unknown` instead |

The kind payloads and the read permission each kind requires:

| Kind | Payload | Read permission |
|---|---|---|
| `product` | `GetProductDetails()`; with `SHOPPINGLIST_VIEW` and not `STOCK_VIEW`, only `id`, `name`, and the matched row's `qu_id` and `amount` | `STOCK_VIEW`, or `SHOPPINGLIST_VIEW` for a product-barcode code (ADR-0050) |
| `stock_entry` | the entry plus its product details | `STOCK_VIEW` |
| `location` | the location plus its stock | `STOCK_VIEW` |
| `recipe` | the recipe | `RECIPES_VIEW` |
| `chore` | the chore and its next execution | `CHORES_VIEW` |
| `battery` | the battery and its next charge | authenticated; `EntityReadPolicy` gives `batteries` no read leaf |

Price fields in any payload stay redacted without `STOCK_PRICES_VIEW`, including
`last_price` and a `decoded_value` whose `value_kind` is `price`.

**The six by-barcode routes** call the resolver, then apply their own operation
permission (`STOCK_PURCHASE`, `STOCK_CONSUME`, `STOCK_TRANSFER`, `STOCK_INVENTORY`,
`STOCK_OPEN`, or `STOCK_VIEW` for details). They keep their existing status codes and
response bodies (ADR-0031 W5), so a resolver status maps onto their current 400
responses.

These routes still take the code as a path segment, and that limits which forms they
can accept. A code containing `/`, the GS separator (0x1D) or another control
character cannot be carried in a path segment. That excludes a GS1 element string with
FNC1, for example. Such codes are resolvable **only through `/api/scan`**, whose query
parameter can carry any byte after URL-encoding. The all-forms guarantee is therefore
split:

- **`/api/scan`** resolves every equivalent form.
- **The six by-barcode routes** resolve every equivalent form that fits a path segment:
  plain digits of every GTIN width, AIM-prefixed digits, and `(01)` element strings
  without GS.

Clients that scan GS1 DataMatrix or GS1-128 use `/api/scan`, and then call the
by-barcode operation with the resolved product's canonical barcode.

**Administrator routes:**

| Route | Permission | Purpose |
|---|---|---|
| `GET /api/barcodes/conflicts` | `MASTER_DATA_EDIT` | open groups with their members |
| `POST /api/barcodes/conflicts/{id}/resolve` | `MASTER_DATA_EDIT` | resolve a group with `expected_version` and actions |
| `POST /api/barcodes/conflicts/{id}/merge-preview` | `MASTER_DATA_EDIT` | the product-merge preview |
| `POST /api/shopping-locations/{id}/barcode-pattern/preview` | `MASTER_DATA_EDIT` | the pattern-migration preview |
| `POST /api/shopping-locations/{id}/barcode-pattern/apply` | `MASTER_DATA_EDIT` | apply the previewed migration |

`barcode_patterns` joins the generic entity enums, with `STOCK_VIEW` to read and
`MASTER_DATA_EDIT` to write.

### Frontend

- **Product picker.** `productpicker.js` resolves a typed or scanned value through
  `/api/scan` and drops `FindOptionBySearchdata`'s barcode substring match. Name search
  is unchanged. On the shopping-list pages, a caller with `SHOPPINGLIST_VIEW` but not
  `STOCK_VIEW` gets the reduced product answer, which carries all the picker needs
  (ADR-0050).
- **Pre-create check.** The picker's pre-create check uses the resolver's `unknown`
  answer. The code is URL-encoded.
- **Camera scanner.** It adds `UPC_A` and `UPC_E` and passes the reported format as the
  `carrier` hint.
- **Barcode form.** `productbarcodeform.js` shows a 422 or 409 answer next to the field.
  It requires a store when the value matches the store's pattern.
- **New pages.**
  - A conflicts page under the administrator menu. It is linked from the `quarantined`
    answer and shows the group's version.
  - A pattern editor on the shopping location form.

## Alternatives

ADR-0031's options section records the rejected alternatives. Two design choices inside
this plan also had credible alternatives:

- **The trigger enforcing the write functions, compared with the functions alone.** A
  trigger-only design would take its locks after PostgreSQL has already locked the row
  being updated. That breaks the acquisition order and allows barcode-only deadlocks.
  With functions alone, a new write path could bypass the protocol unnoticed. This plan
  uses both: the functions order the locks, and the trigger refuses anything else.
- **Per-identity locks for bulk writers.** One advisory lock per row makes an upgrade or
  import of a large catalogue depend on `max_locks_per_transaction`. The exclusive
  registry lock gives the same exclusion with one lock.

## Dependencies

- **[ADR-0031](../adr/0031-barcode-identity-is-canonical-and-reserved.md) accepted.** Its
  seven acceptance prerequisites include the concurrency demonstration and the upgrade-load
  measurement this plan's verification describes.
- **[ADR-0011](../adr/0011-label-namespace.md)** (Accepted) fixes the resolution order and
  `LabelIdentityService::Resolve`.
- **[ADR-0023](../adr/0023-taxonomy-is-groups-packaging-is-parent-product.md)** (Accepted)
  §5 is respected: a restricted-circulation code identifies a product through a store's
  mapping, and a lookup plugin never receives one to create a product.
- **[ADR-0025](../adr/0025-three-test-tiers.md)** (Accepted) sets the test tiers.
- **[Plan 09](09-barcode-lookup-sources.md)** (Deferred) consumes `Gtin::SameGtin()`
  after this plan.
- **[Issue 484](https://github.com/datagen24/victual/issues/484)** is not a dependency.

## Delivery pieces

Pieces 2 through 4 form one release unit. A release that quarantines rows must also ship
the page that resolves them.

1. **The canonicalisation function** and its pgTAP tests. There is no schema change.
2. **Schema, write functions, protocol, trigger, backfill, importer step and
   `Gtin.php`.** `GetProductIdFromBarcode` switches to canonical lookup in the same
   change.
3. **`ScanResolverService`, `/api/scan` and the by-barcode delegation**, together with
   the OpenAPI document and the response-contract snapshot.
4. **The conflicts page, the pattern editor, pattern migration and the merge preview.**
5. **Clients:** the product picker, the camera formats, and removal of the
   `victual-kit` retry. The retry removal happens in that repository.

## Open questions

1. **The installation default pattern.** Issue 483 says to seed the default "from the
   GS1 country convention", but Victual has no country setting. The options are:
   - seed from the configured currency (USD → `2IIIIIPPPPPC`);
   - ship it unset;
   - require the administrator to choose one in the pattern editor.

   Leaving it unset means a store-less scan of a sticker answers `store_required` until
   a default exists.

   > **Response:** (datagen24, 2026-09-24) Add an installation country, set during
   > onboarding ([issue 484](https://github.com/datagen24/victual/issues/484)) or by an
   > environment variable. The default pattern comes from that country. Plan 09's US
   > lookup source and the default units of measure use the same setting, tracked in
   > [issue 486](https://github.com/datagen24/victual/issues/486). Until it exists, the
   > default pattern stays unset.

   > **Response:** (datagen24, 2026-10-24)
   > - **Which countries.** Ship `US` (`2IIIIIPPPPPC`) and the common European GS1
   >   member-organisation layouts, each verified against a primary GS1 source. See
   >   "Country default table", which also records that the General Specifications give
   >   GS1 US's layout as `2IIIIIVPPPPC`.
   > - **When it is computed.** At read time, from `InstallationCountry::Get()`.
   >   [PR 1096](https://github.com/datagen24/victual/pull/1096) ships
   >   `VICTUAL_COUNTRY` as an environment setting, and this matches it.
   > - **No match.** An unset or unmapped country gives no default, so a store-less scan
   >   answers `store_required`.

   **Consequence.** The default is computed at read time, so changing the country
   changes how a store-less scan of an already-printed sticker decodes. It changes no
   stored row: every `restricted` row is bound to its issuing store's pattern version,
   never to the installation default (ADR-0031 decision 6).

2. **A decoded sticker value in a booking.** `/api/scan` returns `decoded_value`. Should
   the purchase form use a decoded price as the unit price, and should the consume form
   use a decoded weight as the amount? Both change booking behaviour, and neither is
   needed to fix resolution. This plan proposes returning the value only and leaving
   its use to a follow-up.

## Verification

- **pgTAP, canonicalisation:**
  - every GTIN width;
  - hinted UPC-E, 8-digit and 6-digit, for each `d6` row of the expansion table;
  - an unhinted `01234565` staying GTIN-8;
  - a hinted 7-digit value being `malformed`;
  - `]E0`, `]C1` and `(01)…(17)…` inputs;
  - a failing check digit being `invalid_check_digit` and never `opaque`;
  - a 12-digit restricted sticker and its 13-digit leading-zero reading match the same
    pattern and produce the same canonical value;
  - each pattern field, including the four- and five-digit verifiers and a prefix
    mismatch;
  - `pg_proc.provolatile = 'i'`.
- **pgTAP, schema:**
  - two stores with the same item reference under different products are both active;
  - a quarantined row does not block an active one;
  - a write to a reserved identity is refused;
  - one open group per identity;
  - a direct `INSERT` outside the write functions is refused by the trigger;
  - an update to a referenced `barcode_patterns` row is refused.
- **`tests/Pgsql`, concurrency on two connections:**
  - an insert racing a resolution of the same identity leaves one active row;
  - a `keep` resolution activates the kept row even though the group's identity was
    reserved until step 4, and a failure in a later action rolls the group back to open
    at its old version;
  - two resolutions correcting competing rows to different canonical values leave one
    winner and one stale 409;
  - an update moving row A into identity X racing an insert of X leaves one active row,
    because both lock X;
  - an update's old-identity lock blocks a resolution of the group that held it;
  - many concurrent single-row writes across overlapping identities produce no `40P01`.
- **`tests/Pgsql`, migration and import:**
  - the backfill of a seeded 12-digit and 13-digit pair on two products creates one
    group with both rows quarantined;
  - a seeded failing check digit creates a group with reason `invalid_check_digit`;
  - no `pending` row survives the migration or `DatabaseImporter::Import`;
  - an import of a grocy fixture holding such a pair ends with the pair quarantined;
  - `AssertValuesMatch` still passes on the verbatim copy.
- **`tests/Pgsql`, patterns:**
  - after a pattern change, an existing row keeps its `pattern_id` and still resolves an
    old sticker;
  - a new sticker decodes under the new version;
  - a scan read differently by two bound versions answers `ambiguous`;
  - a pattern migration's apply matches its preview.
- **Contract tests:**
  - every `/api/scan` status and HTTP code;
  - every kind, matched and denied;
  - a caller with neither `STOCK_VIEW` nor `SHOPPINGLIST_VIEW` receives the same 403
    for a registered product barcode, an unknown code, a quarantined identity and an
    invalid check digit;
  - a caller with `SHOPPINGLIST_VIEW` and not `STOCK_VIEW` receives exactly the reduced
    `matched` and `ambiguous` bodies, with no stock or price field;
  - a `vctl:` label of a kind the caller may not read answers `unknown`. The answer is
    byte-identical to the one for a nonexistent uid;
  - a `grcy:r:` code is denied before lookup for a caller without `RECIPES_VIEW`;
  - a denial carries no identity, contents or snapshot, including for retired,
    quarantined and ambiguous results;
  - price redaction without `STOCK_PRICES_VIEW`;
  - the six by-barcode routes keep their permissions and body shapes.
- **OpenAPI and snapshots.** `victual.openapi.json` documents the new route and entities.
  The response-contract snapshot diff contains ADR-0031 W1–W6 and nothing else.
- **Browser (Playwright probes):**
  - a stored `4001234567890` is not matched by typing `1234567890`;
  - the picker resolves a 13-digit scan of a product stored with 12 digits;
  - the conflicts page is walked through keep, correct, reassign, a stale-version 409
    and the merge preview.
- **Parity.** `21-barcodes-and-undo.js` passes with W1–W6 recorded as approved
  differences. Parity supplements the tests above; it does not replace them.
- **End to end.** One package resolves to the same product through `/api/scan` whether
  it is scanned as UPC-A, EAN-13, GTIN-14, hinted UPC-E, `]E0…`, `(01)…(17)…`, or a GS1
  element string containing GS. It also resolves through all six by-barcode routes in
  every one of those forms that fits a path segment.
