# ADR-0048: A userfile is readable only by callers every owning record admits

- **Status:** Proposed
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-13, against `master` at `33fbec60`. Design record only: it changes
  no code and reserves no migration number. The maintainer's decisions F1 to F5 and F3′ on
  [issue 905](https://github.com/datagen24/victual/issues/905), and the view-owner decision
  of 2026-10-13 recorded in decision item 8, are the source of every decision item below.
- **Referenced by:** [issue 905](https://github.com/datagen24/victual/issues/905),
  [issue 920](https://github.com/datagen24/victual/issues/920), and the implementation
  in [PR 919](https://github.com/datagen24/victual/pull/919).
- **Relationship:** would partly supersede accepted
  [ADR-0035](0035-batteries-equipment-calendar-and-custom-entities-require-view-permissions.md):
  only the sentence in its 2026-10-13 amendment that leaves the `userfiles` file group
  "deliberately left open for now" and defers to issue 905. Every other part of ADR-0035 is
  unchanged. Compatible with accepted
  [ADR-0018](0018-role-grants-and-domain-reads.md), which gives separately addressable
  userfields and pictures the same policy as the record that owns them.

## Context

`GET /api/files/userfiles/{fileName}` served any userfile to any authenticated caller who
knew its stored name, and `force_serve_as=picture` also wrote a thumbnail for that caller
([issue 905](https://github.com/datagen24/victual/issues/905)). The stored name was the only
secret. It survived the removal of a permission and the deletion of the owning record.

The file group could not be gated as one unit. File and image user fields render through
`/api/files/userfiles/` on pages that need only `STOCK_VIEW`, `EQUIPMENT_VIEW` and similar,
so a blanket `MASTER_DATA_EDIT` gate would break them. The right gate depends on the record
that owns the file.

Nothing in the schema links a file to its owner. A userfile is referenced only by the text
of `userfield_values.value`. Two reference forms exist in the code:

1. `base64(stored name) + "_" + base64(display name)`, written by the user-field form.
2. A bare `base64(stored name)`, which `PUT /api/userfields` accepts as any other string.

The file endpoint decodes the name in the URL with a non-strict base64 decoder, so
padding-less and otherwise irregular spellings of one stored name reach the same file. A
reference check must resolve every spelling the endpoint resolves.

A picture request can also name a derivative (a thumbnail) of the stored original. The
derivative has its own stored name and no reference of its own.

## Decision (proposed)

Each item quotes the maintainer's wording from issue 905, 2026-10-13. Item 8 quotes the
maintainer's answer on PR 919, recorded in a comment on issue 905 the same day.

### 1. Every owning record must admit the caller

A userfile is readable only by a caller whom every distinct owning record admits. Count each
`(entity, object id)` pair once, however many values or reference forms point at it. An
owner that cannot be resolved, or that denies the caller, prevents access. Adding a
reference to a file therefore never widens who can read it.

Source, F2: "every referencing owner must permit access. Deduplicate references. An
unresolved or denied owner prevents access."

### 2. An owner admits what its read policy admits

The owner's read rule is the owning record's `EntityReadPolicy` entry. The existing
self-read exception for `users` applies: a user can read a file that references their own
record, subject to item 1 when the file has other owners. Custom entities
(`userentity-<name>`) keep the `MASTER_DATA_EDIT` requirement that ADR-0035's amendment set.

Source, F4: "Apply the existing self-read exception to the user's own files, subject to F2
for shared references."

### 3. A file with no resolvable owner is readable by ADMIN only

A file with no reference, or whose references all fail to resolve, is an orphan. Only
`ADMIN` reads an orphan. Treat an owner as unresolved only after every supported reference
form in the Context has been checked.

Source, F1: "ADMIN only. Permission to delete orphaned files does not establish permission
to read potentially sensitive contents. Treat an unresolved owner as an orphan only after
checking all supported reference forms."

### 4. Definition rows are an explicit ADMIN-only exception

A file referenced from a `userfields` or `userentities` definition row is readable by ADMIN
only. This is an exception for file reads. The definitions' own metadata keeps its existing
authenticated-read policy, because ordinary pages read it to render inputs and the sidebar.
`EntityReadPolicy`'s `null` (open to any authenticated caller) is not reinterpreted for any
other purpose.

Source, F3′: "ADMIN only. Make this an explicit exception for files attached to userfields
and userentities definition rows. Preserve their existing authenticated-read policy for
definition metadata. Do not reinterpret EntityReadPolicy::null globally."

### 5. A file referenced from an API key row is readable by nobody

A file referenced from an `api_keys` row is readable by no caller, ADMIN included. The same
holds when the referenced key row no longer exists. An owner entity with no
`EntityReadPolicy` entry at all also admits nobody. This follows from F3's "deny unless an
explicit policy permits access".

Source, F3 and F3′: "deny unless an explicit policy permits access ... Preserve intentional
non-exposure, including for administrators"; "api_keys files remain deny-all."

### 6. Authorize the original before any read

Authorization runs on the original file before the server reads any byte, and before it
reads or creates a picture derivative. A request that names a derivative is authorized
through the references of its original. References to the derivative's own name also
count, intersected with the original's, so a reference to a derivative can only narrow
access. All ownership refusals are one response: the
status and body do not reveal the owner type, whether the file exists, or which owner
denied the request.

Source, F2: "Authorize the original file before reading or creating a picture derivative."

### 7. Writes are out of scope, and the read rule alone does not close the disclosure

This record does not change who can write userfield values or upload files. Writes stay at
the group-level `MASTER_DATA_EDIT` check. Owner-based write authorization is tracked
separately in [issue 920](https://github.com/datagen24/victual/issues/920).

That leaves a demonstrated bypass. A `MASTER_DATA_EDIT` holder can clear the value that
references a protected file, or delete the owning record. The file then follows only its
remaining owners, so the read widens to whoever those owners admit. The read rule alone
therefore does not close the issue 905 disclosure.

Source, F5: "Keep the read fix bounded and file owner-based write authorization separately.
Check whether the existing write paths can bypass the new read rule." The maintainer
recorded on 2026-10-13 that the deferral of F5 is not acceptance of this bypass.

Item 9 closes this bypass.

### 8. A view row is resolved by the view's key and admits what the view admits

A userfile reference may name a row of one of the read-only views that
`EntityReadPolicy` covers, because any exposed entity view can carry userfields. The owner
is looked up in the view by the same key `GetObject` uses: `id` (the decision also names
`stock_id` for `stock`, a table already keyed that way). The view's `EntityReadPolicy` permission decides whether the caller is admitted.

A view row that no longer exists makes the reference unresolved. The file then follows the
orphan rule in item 3 and is readable by ADMIN only. The `label_*` and `print_*` entities
other than `label_printer_status` (looked up by `printer_id`) are not looked up, so they
are unresolved too. All of them, `label_printer_status` included, have the
`EntityReadPolicy` permission `ADMIN`, so the outcome is the same as their policy gives.

Source, maintainer decision of 2026-10-13. It answers a question raised during review of PR 919.
The answer is recorded in
[a comment on issue 905](https://github.com/datagen24/victual/issues/905#issuecomment-6153663817):
"**resolve via the view key** — look the owner up in the view by the same key GetObject
uses (`id`; `stock_id` for `stock`) and apply the view's `EntityReadPolicy` permission. A
view row that no longer exists makes the reference unresolved, so ADMIN only."

## Consequences

**Compatibility.** A caller who could read a userfile before can now receive a 403. Items 1
to 6 and 8 authorize this wire change, and it needs a line in the unreleased changelog. Upstream
Grocy serves userfiles openly, so the fork diverges from it here and the parity suite is
expected to report the difference.

**Refusals are indistinguishable.** In the validation of PR 919 on 2026-10-13, refusals
were byte-identical across nine cases. A caller cannot use the endpoint to learn whether a
name exists or which record owns it.

**Cost.** Resolving owners scans `userfield_values` once per userfile request. Irregular
base64 spellings cannot be prefiltered in SQL, so the scan runs in PHP. The validation of
PR 919 measured about 8.7 ms per request at 5,020 values and about 36 ms at 25,020 values
(in-process, 20 cached thumbnails). No index is decided.

**Owner types.** The implementation resolves owners from an explicit table of entities
and, under item 8, twelve views. A reference to an entity outside both lists is
unresolved, so the file is ADMIN only. `roles` resolves through its own read policy.

**Degenerate view ids.** Seven of the twelve views do not expose a unique row id. Six
select the constant `1 AS id`, `quantity_unit_conversions_resolved` selects -1 or 1, and
`product_barcodes_view` combines barcode and product ids. For these views the check in
PR 919 amounts to "the caller holds the view's permission and the view has a row with that
id". It does not tie the reference to one underlying record.

**Alternatives ruled out.** A blanket `MASTER_DATA_EDIT` or `ADMIN` gate on the whole group
breaks pages that render images for `STOCK_VIEW` holders. Treating orphans as readable
because deleting them is a lesser right was rejected in F1.

### 9. A write that changes a file's owners needs read permission on every owner it had

A write that removes, replaces or adds a reference to a userfile is refused with the same
`USERFILE_OWNER_READ` refusal as a read, unless the caller may read every record that
referenced the file before the write. The caller must also hold the write's existing
permission. Those writes are:

- clearing or changing a userfield value;
- deleting a product, battery, chore or userfield definition, whose values cascade;
- merging away a product or chore;
- changing a userfield definition.

The check compares the file's owners before and after the write, in the write's own
transaction, so a cascade counts whichever path caused it. A refusal rolls the write back.

The same rule covers the bytes. Deleting a referenced userfile, or uploading under a name
that some record references, needs read permission on every owner. The check runs before
the name is looked up, so the refusal does not say whether the file exists. A file that
nothing references keeps the group-level `MASTER_DATA_EDIT` right. The browser deletes a
replaced file after saving the value that no longer names it, and uploads a new one after
saving the value that does.

A name in the form of a picture derivative is refused at upload in every group, and as a
file or image userfield value. Only `FilesService` makes such names.

Source, D3 (2026-10-15): "Require existing operation rights and read authorization for every
current owner, checked atomically with the mutation."

**Not covered.** A caller who may make the write can attach a file that nothing references.
That is how an upload is attached, and nothing records whether an unreferenced file was
uploaded a moment ago or left behind by an earlier owner. An upload under an existing
unreferenced name still answers 400, so it tells the caller that the name exists.

## Open questions

1. **Entities with a read policy but no owner lookup.** With item 8 in place, only the
   `label_*` and `print_*` entities other than `label_printer_status` are in this group.
   PR 919 treats such an owner as unresolved, so its files are ADMIN only, which equals
   their own `EntityReadPolicy` permission. This is an implementation fact, not a maintainer decision. Denying everyone
   is the alternative.
2. **An index on the owner lookup.** The measurements under Consequences give no threshold.
   Decide on an index only if a larger install shows the per-request scan matters.

## Implementation

[PR 919](https://github.com/datagen24/victual/pull/919) implements items 1 to 6 and 8.
The pull request for [issue 920](https://github.com/datagen24/victual/issues/920)
implements item 9.
Implementing this record does not accept it. Acceptance is a separate pull request that
changes only the status line, the index row, a forward pointer on ADR-0035 and this
record's reference to it.
