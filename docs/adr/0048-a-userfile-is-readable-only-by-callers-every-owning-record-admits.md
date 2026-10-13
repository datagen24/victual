# ADR-0048: A userfile is readable only by callers every owning record admits

- **Status:** Proposed
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-13, against `master` at `33fbec60`. Design record only: it changes
  no code and reserves no migration number. The maintainer's decisions F1 to F5 and F3′ on
  [issue 905](https://github.com/datagen24/victual/issues/905) are the source of every
  decision item below.
- **Referenced by:** [issue 905](https://github.com/datagen24/victual/issues/905),
  [issue 920](https://github.com/datagen24/victual/issues/920), and the draft implementation
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

Each item quotes the maintainer's wording from issue 905, 2026-10-13.

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

## Consequences

**Compatibility.** A caller who could read a userfile before can now receive a 403. Items 1
to 6 authorize this wire change, and it needs a line in the unreleased changelog. Upstream
Grocy serves userfiles openly, so the fork diverges from it here and the parity suite is
expected to report the difference.

**Refusals are indistinguishable.** In the validation of PR 919 on 2026-10-13, refusals
were byte-identical across nine cases. A caller cannot use the endpoint to learn whether a
name exists or which record owns it.

**Cost.** Resolving owners scans `userfield_values` once per userfile request. Irregular
base64 spellings cannot be prefiltered in SQL, so the scan runs in PHP. The validation of
PR 919 measured about 8.7 ms per request at 5,020 values and about 36 ms at 25,020 values
(in-process, 20 cached thumbnails). No index is decided.

**Owner types.** The implementation resolves owners from an explicit table of entities. A
reference to an entity outside that table is unresolved, so the file is ADMIN only.
`roles` resolves through its own read policy.

**Alternatives ruled out.** A blanket `MASTER_DATA_EDIT` or `ADMIN` gate on the whole group
breaks pages that render images for `STOCK_VIEW` holders. Treating orphans as readable
because deleting them is a lesser right was rejected in F1.

## Open questions

1. **Write authorization (issue 920).** The write rule that closes the bypass in decision
   item 7 is undecided. Until it lands, the disclosure issue 905 describes is only partly
   fixed.
2. **Entities with a read policy but no owner lookup.** Draft PR 919 treats such an owner
   as unresolved, so its files are ADMIN only. That is an implementation choice awaiting
   review, not a maintainer decision. Denying everyone is the alternative.
3. **Views that may carry userfields.** PR 919 makes files owned through these views ADMIN
   only. Whether each view should instead admit the permission its page requires is a
   maintainer decision.
4. **An index on the owner lookup.** The measurements under Consequences give no threshold.
   Decide on an index only if a larger install shows the per-request scan matters.

## Implementation

[PR 919](https://github.com/datagen24/victual/pull/919) (draft) implements items 1 to 6.
Implementing this record does not accept it. Acceptance is a separate pull request that
changes only the status line, the index row, a forward pointer on ADR-0035 and this
record's reference to it.
