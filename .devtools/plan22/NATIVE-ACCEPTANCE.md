# Issue 702: native acceptance status and device procedure

Reconciled 2026-10-11 from read-only inspection of Victual `master` at `d4960138` and
victual-kit `main` at `055553e`. Nothing here was run on a device. No personal health details are
recorded; the only medication data in the sources is simulator test data, which is left out.

## Maintainer decision, 2026-10-11

Issue 702 is accepted on simulator evidence. No physical phone is used: a phone brings its owner's
whole medication history into the test, while the simulator runs against a predictable data set
that is otherwise close to a live environment. The device procedure below is therefore not a gate.
It stays as the procedure for anyone who later wants device evidence.

The maintainer also decided on 2026-10-11 that the current simulator evidence closes issue 702. The
server behind it was a Victual stack hosted on the Talos cluster with a disposable on-cluster
database, which is closer to production than the podman suite. victual-kit's `ui-round-1-phone.md`
case 7c names a Debug "Demo medication server"; the maintainer's account of the environment
supersedes the "in-memory demo backend" reading in the table below.

Consequence for the records: issue 702's criteria and plan 22's release-readiness text say real
device evidence, so their wording changes with this decision. What simulator Health cannot show is
the behaviour of a real Health store: identifier changes on edit and delete, late and background
delivery. The release record names that as a limitation.

Role coverage, corrected 2026-10-11: victual-kit's UI round 1 (`ui-round-1-phone.md`) and the macOS
manual test ran with an administrator, an Adult and a Child key; the live fixture replay ran
fixtures 16 and 17 with two administrators (`live-server-replay.md`, lines 62 to 64). Across the
two, testing used two administrators, one Adult and one Child user.

## What exists

Evidence classes: device, simulator, live server through fixtures, unit test, design.

| Issue 702 criterion | Evidence | Class | Missing |
|---|---|---|---|
| 1. Platform versions, per-medication authorization, real quantity and status | victual-kit matrix rows 1.U and 1.S (`docs/acceptance/plan-22-native.md`); device spike built in victual-kit PR 13 (merge `a7a5da8f`) and never run; `ui-round-1-phone.md` cases 7b and 7c on an iOS 27.0 simulator | unit, simulator | Any physical device. The spike report header (iOS version, device, build, server, date) is blank |
| 2. Mapping and source-location selection | Matrix 2.U, 2.U2, 2.U3, 2.F; simulator case 7c (environment: see the maintainer decision above); server fixtures 02, 09c, 09d, 15 passed live | unit, simulator, live server | Device mapping against a real server; `explicit` location is not offered by the client; non-administrator setup is blocked by issue 742 |
| 3. Anchors, late records, deletions and edits, offline retry, revoked access | Matrix 3.U, 4.U, 5.U, 10.U; live fixtures 03, 05, 06, 07a, 07b, 09a, 09b; a 12-way parallel PUT (1 created, 11 replays, stock down by 1) | unit, live server | Real anchored-query behaviour, what an edit or delete emits, real latency, revocation, offline retry on a phone |
| 4. Manual/import duplicate resolution, direct stock undo | Matrix 7.U, 8.U; live fixtures 10 to 13 | unit, live server | A real client across both projects |
| 5. Refill notices | Matrix 9.U to 11.U (victual-kit PR 18, merge `a91e48d`); the client computes a stand-in status in tests | unit, simulator build | The live replay instance had no refill data and the fixtures do not cover refill routes; local notification delivery never ran |
| 6. Real-device end-to-end scenario | None | none | All of it; procedure below |
| 7. Exact commits, commands, native versus fixture evidence | Matrix records client `743ab1f`, server `6ec4173`, spec 0.3.2 | design | `743ab1f` does not resolve after a rebase (merged equivalent: PR 18 head `89e71eb`); the matrix was not re-pinned after victual-kit PR 21 vendored the v0.4.0 spec (upstream `89cea94`) |

The live replay (victual-kit PR 20, merge `2f77301`, run 4) passed 28 of 28 server fixtures against a
v0.4.0 instance (tag commit `f73d2bec`, database version 307), with both actors holding `ADMIN`.
It is server evidence. Fixtures 16 and 17 need a non-administrator run, which the REST API cannot set
up because no route mints a key for a user without permissions (`ui-round-1-phone.md` case 1c).

The maintainer's list of 2026-10-09 (iOS version, device, real dose-event payload fields,
identifier behaviour on edit and delete, late delivery) has no recorded answer. Plan 03's field
mapping comes from Apple's documentation, and plan 03 states that nothing in it was observed on a
device.

## Contract questions client QA raised with no server issue

- `quantity_factor` next to `qu_id` on a mapping has no defined meaning (matrix difference 7).
- `supplied_days` is nullable in the schema; ADR-0042 section 1 says it is entered per fill. Plan 22
  records the maintainer's approval of optional `supplied_days` on 2026-10-10, so this is a
  documentation gap in the client's matrix, not a server defect.
- `oneOf: [$ref, null]` makes the client generator drop `current_fill` and `open_order` (matrix
  difference 4).
- `info.version` stayed at 0.3.2 while routes were added (matrix difference 6); fixed by the 0.4.0
  release.
- victual-kit QA items 14 ("undo splits lots") and 15 are marked "server behaviour"; item 14 may be
  the behaviour issue 763 documented.

## Device procedure (optional since 2026-10-11)

Prerequisites:

- A physical iPhone on iOS 26 or later with a signing team in `Apps/Local.xcconfig`.
- A Debug build of VictualPhone from victual-kit `main` with the spec pinned to the Victual tag under test.
- A throwaway Victual instance at that tag and an API key holding `STOCK_VIEW`, `STOCK_CONSUME`,
  `STOCK_PURCHASE` and `STOCK_EDIT`. Run steps 2 to 4 a second time with a non-administrator key.
- One dummy medication in Health that is not a real prescription.
- Record without names: iOS version, device hardware identifier, client commit, server tag and spec
  sha256, date.

Record counts, states, unit labels, HTTP statuses and elapsed seconds. Do not record medication
names, doses or real dates.

| Step | Action | Existing run sheet | Evidence |
|---|---|---|---|
| 0 | Connect the phone; confirm the capability list | `ui-round-1-phone.md` cases 1 and 7a; transport sheet in victual-kit PR 40 | Connection result; `features[]` from `GET /api/consumption/capabilities` |
| 1 | Authorize one dummy medication through the spike | README "HealthKit device spike" steps 1 to 3; matrix 1.D | Which payload fields are present; iOS version and device in the report header |
| 2 | Map it to a throwaway product, a unit and a `fixed` organizer | matrix 2.D | Mapping saved; server mapping status |
| 3 | Log a dose as taken; sync | README step 4; matrix 2.D | Seconds to arrival; `doseQuantity` present or not; unit string; server event state; stock delta |
| 4 | Sync twice; then airplane mode, log a dose, reconnect, sync twice; kill the app mid-sync once | matrix 3.D | Stock delta exactly one per dose; server event count; statuses 201 then 200 |
| 5 | Edit the dose in Health; in a second pass delete it | README step 4; matrix 4.D, 5.D | Whether an edit is delete-and-recreate or in place; whether `replaces` was sent; resulting states; net deduction; review reasons |
| 6 | Record 30-day and 90-day fills in the web UI; read Refills on the phone; cross local midnight; acknowledge a notice | matrix 9.D | Reorder date and its provenance label; `as_of_source`; one local notification; acknowledgement cleared |
| 7 | Delete then recreate a taken dose, and the reverse order | matrix 5.D; ADR-0041 sequence 7 and its erratum | States; stock; whether `needs_review` or `undo_refused` appears |
| 8 | Log a dose dated two days ago | README step 4; matrix 4.D | Latency; booking `used_date` equals the event date |
| 9 | Stop sharing the medication in Health; then delete the API key | README step 6; matrix 1.D, 10.D | What the query returns; stock not restored; no private data on the lock screen or app switcher |
| 10 | Close out | matrix header and spike report | One sanitized row per step in the matrix `DEVICE` rows; client and server commits |

No run sheet covers a skipped or snoozed dose beyond matrix 6.D, background delivery beyond spike
step 5, or non-administrator keys for fixtures 16 and 17.
