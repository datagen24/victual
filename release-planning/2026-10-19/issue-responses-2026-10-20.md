# 0.5.1 planning responses, 2026-10-20

Drafts of the planning comments for the four open 0.5.1 issues. Each was checked against
`master` at `11ab0244` on 2026-10-20. Nothing here was posted to GitHub until the
maintainer approved it; the GitHub writes table in release-data.json records what was.

## #252 Code that coverage proved nothing can reach

### Planning, 2026-10-20 (against master `11ab0244`)

**The first item is already done, and an accepted ADR now contradicts master.**
`helpers/WebhookRunner.php` was deleted in `0ea46c25` (2026-09-23T19:04Z), 54 minutes
before the review comment above asked for the ADR process first. ADR-0024 still says
"`WebhookRunner` itself stays: plan 18's InfluxDB writer uses it". Both halves are false
on master: the writer never called it (it uses Guzzle directly), and the class is gone.

Proposed handling, item by item. The review comment's direction is applied as written.

| Item | Still on master | Proposal |
| --- | --- | --- |
| `WebhookRunner` | No, deleted in `0ea46c25` | A record correction for ADR-0024's consequence sentence. ADR-0024 is accepted, so this follows the superseding-ADR rule. It needs a maintainer decision on its form: a narrow superseding ADR, or a factual erratum pointer. No code moves. |
| `ConfigurationValidator.php:225-229`, the demo/prerelease label refusal | Yes. Its comment (from `f4095358`, 2026-09-08, before this issue) says it is deliberate. | Remove, per the review comment. Note the conflict: the comment calls this a deliberate operator-facing message. If the maintainer prefers the clearer message, keep it and reword the comment to say it is unreachable on purpose. |
| `SystemController.php:118-121`, the `stock` fallback | Yes | Remove the `else` arm. `Setting()` always defines the constant. |
| `LabelRenderApiController::Dispatch()` byte-route arms | Yes (the `labels-artifact-bytes` target arm) | Remove after re-reading `routes.php` on the PR's base. |
| `FilesApiController.php:155`, no write permission for a group | Yes | Keep. It fails closed for a future group. Add a one-line comment saying so. |
| `CanonicalJson.php` fallbacks (`:168` and the two `json_encode` arms) | Yes | Keep for now. Coverage alone does not prove these unreachable. Removal needs a citation for each supported PHP version (8.4 and 8.5). |
| `StderrLogger.php:101`, the `unencodable` fallback | Yes | The same rule as `CanonicalJson`. |
| `DatabaseStorage.php:110, 138`, `fclose` in `finally` | Yes | Keep. A failure before `execute()` can leave the buffer open. |
| `DatabaseStorage.php:71`, the non-resource `Read` branch | Yes | Assess against `pdo_pgsql` behaviour alone. SQLite import does not use this class. Keep unless the PHP source shows that `BYTEA` always arrives as a stream. |

**Plan.** There are three pull requests, in this order:

1. The ADR-0024 record correction, in the form the maintainer picks.
2. One code PR for the three confirmed removals and the guard comments. It needs the
   pgsql `httpboot` and `helperunits` phases and keeps docstring coverage at 80% or more in
   each touched file.
3. The two PHP-version proofs (`CanonicalJson`, `StderrLogger`). These are optional and
   could move to 0.5.2.

**Decision needed:** the form of the ADR-0024 correction, and whether the
`ConfigurationValidator` message stays as a deliberate guard.

## #900 ADR-0019 does not list the Resolve() validation refusal as a dead-letter cause

### Planning, 2026-10-20 (against master `11ab0244`)

The design work for decision A already exists. ADR-0049 is **Proposed** (PR #932, merged
2026-10-13). Part 1 states the dead-letter causes the claim applies today. This includes
cause 2, every `LabelValidationException` from `Resolve()`: inactive printer or worker,
`unknown_driver_version`, and settings that fail the matched schema. Part 2 lists the
behaviour questions about unclaimable versus dead-lettered jobs and decides none of them.

Its code citations still hold on master. The `catch` is at `PrintAttemptService.php:85-90`,
and the stale comment is at `:56-59`.

**Plan.**

1. **Maintainer:** accept or reject ADR-0049. Per the lifecycle rule, the acceptance PR
   contains only the status line, the index row and the forward pointer on ADR-0019.
2. After acceptance, a small follow-up PR corrects the `PrintAttemptService.php:56-59`
   comment to the accepted list (ADR-0049 *Follow-ups*). It changes no behaviour.
3. Part 2 stays open and out of 0.5.1, unless production label scope needs it (R6 makes no
   cluster label claim for 0.5.1).

This issue closes when steps 1 and 2 land. Part 2 gets its own issue at that point.

**Decision needed:** accept ADR-0049.

## #904 Subprocess test helpers read stdout before stderr and deadlock on more than 64 KB of stderr

### Planning, 2026-10-20 (against master `11ab0244`)

Part 1 (the bounded drain helper) merged in PR #907. The migration work for the rest is
on two branches that **exist only in one local clone and were never pushed**:

- `claude/sonnet5_issue904-subprocess-migration-x5`: `31b3439c` moves 28 read sites, and
  `72fdffef` merges master.
- `claude/sonnet5_issue904-subprocess-migration-x5-b`: everything above, plus `c1c91da0`
  (the remaining sites and the CI-run devtools) and `e532e4f5` (two slips fixed). 91 files.

`-x5-b` is 166 commits behind master. `git merge-tree` against master reports no
conflicts. On master, 96 files still read a pipe with `stream_get_contents` directly.
Some of them are tests added after 2026-10-13, which the branch never saw.

**Plan.** This replaces "migrate only callers required by upcoming validation first". That
scoping made sense when only `-x5` existed. `-x5-b` already covers the whole tree, so
landing it in one PR costs less than splitting it.

1. Push `-x5-b` to origin as it stands, so the work no longer depends on one clone.
2. Merge master into it, then grep again for direct pipe reads. Migrate the stragglers
   (tests added since 2026-10-13) in one more commit. Leave the recovered commits as they
   are.
3. Run the full pgsql suite once. It is the only check that exercises every migrated
   caller. Run one suite at a time on this host.
4. Open one PR that closes #904. Keep docstring coverage at 80% or more in each touched
   file. With 91+ files, this is the costly part, so count before the first push.

## #1010 Backup/restore drill against the v0.5.0 artifacts (release gate R5)

### Planning, 2026-10-20 (against master `11ab0244`)

There is nothing to decide. This is execution work, run in its own session. The inputs are
fixed:

- Images, by digest, from the v0.5.0 release notes. The app is
  `victual-app:0.5.0@sha256:c85a6e40…`, and the migrator is
  `victual-migrate:0.5.0@sha256:0dd53365…`.
- The procedure in `docs/manual/operator/backup-restore.md` (`Restoring`, lines 33-57).
- PostgreSQL in podman directly, on a disposable database. No production data exists.

**Steps** (each is recorded in the report with its command, timing and output):

1. Start PG and a 0.5.0 app with `FILE_STORAGE` set to `database`. Migrate, then seed with
   `.devtools/pgsql/ledger-generator.php` and a few userfiles.
2. Snapshot the source: row counts per table, stock totals per product, a sha256 for each
   userfile.
3. Run `pg_dump -Fc`. Create an empty database and run `pg_restore --exit-on-error` with no
   migrator first.
4. Run the 0.5.0 migrator against the restored database. It must be a no-op.
5. Repeat step 2 on the restore and compare. Then do one purchase and its undo through the
   API.
6. Optional: a second pass with `FILE_STORAGE` set to `filesystem`, which also restores the
   storage directory.

**A likely manual defect to check:** the manual says `php bin/victual-migrate`. The
supported deployment runs the separate `victual-migrate` image. If the command differs in
the images, the manual is fixed in the same PR.

**Output:** a report at `docs/releases/0.5.0-restore-drill.md`, following the upgrade
rehearsal's record shape. The PR also fixes any manual step that turned out wrong and marks
R5 as evidenced in release-data.json.
