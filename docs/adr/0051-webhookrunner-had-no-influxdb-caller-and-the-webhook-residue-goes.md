# ADR-0051: `WebhookRunner` never had an InfluxDB caller, and the webhook's residue is removed

- **Status:** Accepted, 2026-10-20
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request; see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-10-20, against `master` at `0e3a15ff`. The maintainer's direction of
  2026-10-20 on [issue 252](https://github.com/datagen24/victual/issues/252) is its source:
  ADR-0024's statement is wrong, so write a superseding record and propose removing the
  remaining webhook code. Design record only: it changes no code and reserves no migration
  number.
- **Referenced by:** [issue 252](https://github.com/datagen24/victual/issues/252).
- **Relationship:** partly supersedes accepted
  [ADR-0024](0024-the-fork-writes-its-own-clients.md): only its claim that `WebhookRunner`
  stays because plan 18's InfluxDB writer uses it, and the matching correction it placed on
  [ADR-0019](0019-label-printers-are-master-data.md) decision item 7. Every decision in
  ADR-0024 is unchanged, and so is every other part of ADR-0019.

## Context

ADR-0019 decision item 7 said the webhook's full retirement leaves `WebhookRunner` with no
caller. ADR-0024, accepted 2026-09-15, "corrected" that in three places:

1. Its status paragraph says it "corrects that record's statement that step 3 takes
   `WebhookRunner`'s last caller".
2. Its *Consequences* say "`WebhookRunner` itself stays: plan 18's InfluxDB writer uses it".
3. The forward pointer it placed in ADR-0019 decision item 7 says "plan 18's InfluxDB writer
   also calls `WebhookRunner`, so step 3 removes the label call sites and the class stays".
   The ADR-0019 index row repeats it as "its '`WebhookRunner`'s last caller' statement
   superseded by 0024".

The code never matched. `services/Influx/InfluxEventWriter.php` has built its own
`GuzzleHttp\Client` since the commit that added it (`296d99dd`, 2026-09-02). It never
called `WebhookRunner`. It cited the class only in a comment, which borrowed its reason for
catching `GuzzleException` rather than `RequestException`. Plan 18's own record says the
same: its review of outbound clients counts three Guzzle clients and names `WebhookRunner`
as a separate one, "a label printer fired and forgotten by design".

ADR-0019's original statement was therefore right. When plan 32 moved every label kind onto
`POST /api/labels/{kind}/{id}/print`, `WebhookRunner` lost its last caller, and `0ea46c25`
(2026-09-23) deleted it. That commit also rewrote the InfluxDB writer's comment to give its
own reason. Today ADR-0024 says a class that does not exist stays, for a caller it never
had.

### Webhook residue still on `master`

Checked on `master` at `0e3a15ff` with `git grep -i webhook` and a usage count for each
catalogue string over `views/`, `public/viewjs/`, `controllers/`, `services/` and
`helpers/`:

| Residue | Where | Why it is residue |
| --- | --- | --- |
| Three catalogue strings with no caller: "Print on label printer", "Print %s Grocycode on label printer" and "Error while executing WebHook" | `localization/strings.pot` and 32 locale `strings.po` files: 33 files carry each string | Plan 32 (`4f2629c4`) removed the client-side `RunWebhook` path and its error toast. No source file asks for these strings. |
| A docstring that describes the webhook as current | `services/DatabaseService.php:457`, `RegisterBeforeOutermostCommit()` | It says StockService collects "label-webhook payloads" inside a transaction and fires them after it. Plan 32 replaced that: print jobs are enqueued inside the transaction (`StockService.php:452-455`). |
| `WebhookRunner` listed as a current Guzzle caller | [Plan 20](../plans/20-container-infrastructure.md), the `curl` row of the extension table | The class is gone. The callers are now barcode lookup, `StockService` and `InfluxEventWriter`. `nix/php.nix`'s own comment was already corrected in `0ea46c25`. |

Mentions that describe the webhook as history are not residue: the changelog, the landed
plans, `docs/architecture-review.md`, plan 18's dated review, `migrations/0283.pgsql.php`,
and the comments in `config-dist.php`, `StockService.php` and `FieldCatalogue.php` that
explain what replaced the webhook. Those are records of what happened, and
[documentation](../documentation.md) asks that landed work keep its original wording.

## Decision

1. **The record states what the code did.** `WebhookRunner` had no InfluxDB caller at any
   point. ADR-0019 decision item 7's original statement, that step 3 takes `WebhookRunner`'s
   last caller, stands. ADR-0024's claim that the class stays, and the correction it placed
   on ADR-0019, are superseded. The class was deleted on 2026-09-23 in `0ea46c25`.
2. **The residue in the table above is removed in one pull request**, after this record is
   accepted:
   - delete the three orphaned msgids from `strings.pot` and from every locale catalogue
     that carries them;
   - correct the `RegisterBeforeOutermostCommit()` docstring so it no longer cites the
     label webhook's payloads as a current example;
   - correct plan 20's `curl` row to the three remaining Guzzle callers.

   Nothing else changes. No route, setting, response or migration is involved: the
   webhook's routes, settings and class are already gone.
3. **Historical mentions stay as written**, for the reason given under *Context*.

## Options considered

- **An erratum pointer on ADR-0024 instead of a record.** Rejected. The lifecycle rule
  defines no in-place amendment of an accepted record, and the maintainer's ruling on
  [issue 900](https://github.com/datagen24/victual/issues/900) (2026-10-13) applies the same
  rule to a factual correction. [ADR-0049](0049-label-print-job-dead-letter-causes-as-the-code-applies-them.md)
  is the precedent.
- **Correct the record and leave the residue.** Rejected: the residue is small, and each
  item misleads a reader. A translator is asked to translate three strings nothing displays,
  and a reader of `DatabaseService` is pointed at a mechanism that no longer exists.

## Consequences

- ADR-0024's decisions 1 to 5 and its acceptance stand. Only its `WebhookRunner` sentences
  stop being authority.
- The accepting pull request adds forward pointers beside ADR-0024's status-paragraph
  sentence and its *Consequences* bullet, and beside ADR-0019's decision item 7 correction.
  It also updates both records' index rows.
- Removing the msgids from locale catalogues drops their existing translations. That is
  intended: nothing can display them.
- The cleanup pull request changes no executable line apart from the catalogues. It keeps
  docstring coverage at or above 80% in each PHP file it edits, which includes
  `DatabaseService.php`.

## Acceptance prerequisites

None beyond the maintainer's confirmation that the evidence under *Context* is correct: the
InfluxDB writer's history from `296d99dd`, and the deletion in `0ea46c25`.
