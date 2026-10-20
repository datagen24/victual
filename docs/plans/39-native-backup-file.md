# 39. Native backup file export and import

Design a UI-based workflow that exports a native Victual backup file and imports it
later. This is separate, unscheduled work. Database dumps remain the operational backup
and restore approach, and [the equipment rebuild](38-household-inventory.md) does not
depend on this feature.

Status: draft design, 2026-10-19. The maintainer requested a separately schedulable design
for a native backup file. No implementation, delivery date, migration number or accepted
architectural decision is established by this document.

## Current behavior and boundary

The [operator backup procedure](../manual/operator/backup-restore.md) uses `pg_dump` and
`pg_restore`. With database file storage, the dump includes uploaded bytes. Filesystem
storage requires the storage directory alongside the dump. Restoration stops the
application and label workers, uses an empty database, then runs required migrations.

This baseline was read on 2026-10-19 in working copy
`7c81b1b9b99259b12f7e03b59f4db3e13b88d4f3`; the linked manual and
[file-storage configuration](../manual/configuration.md#file-storage) are the sources.
No restore or performance measurement was performed for this draft.

The proposed native file packages the information needed for its supported restoration
scope and exposes export/import through the application. CSV exchanges selected fields;
PDF reports present records for reading. Neither is a native backup. The initial design
should target a complete application backup; selected-domain import or merging into a
populated installation requires a separate scope decision.

## Proposed file contract

The format remains open. Compare a versioned container around a PostgreSQL dump with a
logical record-and-attachment archive. The former can reuse existing recovery tools;
the latter needs explicit serialization and migration for every supported entity.
Neither approach is selected by this plan.

The design must specify:

- A manifest containing format version, application/schema version, creation time,
  included domains, file-storage mode and required compatibility information.
- Records and relationships, custom-field definitions and values, uploaded files,
  durable identifiers and the checksums needed to detect incomplete or damaged content.
- Consistency between database records and attachment bytes when writes occur during
  export, including both supported file-storage modes.
- Treatment of users, permissions, credentials, API keys, sharing records, deployment
  configuration and installation-specific identities. Inclusions and exclusions must
  be visible before export and before import.
- Supported source and target versions, migrations, size limits, encryption if offered,
  and the behavior for unsupported or incomplete files.

Checksums detect corruption; they do not establish that an uploaded file is trustworthy.
An archive reader must bound extraction and reject invalid paths. If the format includes
a database dump, review how restoration prevents embedded database definitions from
executing with application or deployment privileges against the live system.

## Proposed user workflow

An authorized operator requests an export, sees its scope and progress, and downloads
the completed file. Failure must not present a partial download as a usable backup.
Generated backups contain sensitive records; define protected access and expiry for
temporary artifacts.

For import, the operator uploads a file and receives a validation report showing its
source version, scope, compatibility and proposed effect on the target. Destructive
replacement requires explicit confirmation of the target and data loss. Validation
must complete before modifying live data.

Prefer restoring into an isolated empty target and verifying it before activation.
The transition must account for concurrent writes, application availability, workers
and restart recovery. The web application cannot acquire unrestricted database DDL
rights merely to provide this UI; the existing application/migrator credential split
remains a constraint. A dedicated restore workload would need its own declared identity.

The existing operator procedure remains available if the application UI cannot run.
Decide whether a documented command can consume the native file too, or whether the
container exposes components that the existing recovery tools can restore.

## Identity and side effects

A full recovery and a merge import have different effects on identity. Printed labels
must not resolve to unrelated objects after either operation. Review
[ADR-0021](../adr/0021-label-templates-are-application-data.md), including the existing
importer's refusal of live labels, before specifying native import behavior.
Any exception requires an explicit architectural decision.

Restoring users and grants may restore access that was removed after the backup.
The import preview must explain the selected access policy. Define whether credentials
are preserved, revoked or replaced. Restored print jobs and other queued work must not
resume external side effects before the chosen recovery policy permits them.

## Verification

1. Export and restore a representative fixture. Compare records, relationships, custom
   fields, attachment checksums and durable identities under the declared file contract.
2. Cover database and filesystem file storage. Concurrent writes must not produce an
   archive with references to missing or inconsistent attachment bytes.
3. Reject damaged, truncated, unsupported and malicious archives before live mutation.
   Verify resource limits and that failed validation exposes no protected records.
4. Interrupt export, upload, restoration and activation. Demonstrate the documented
   recovery path and absence of a falsely successful partial restore.
5. Verify authorization for requests, status and downloads. Test restored permissions,
   credentials, labels and queued work against the selected policies.
6. Demonstrate recovery without the application UI. Record fixture size, source/target
   versions, duration and temporary storage requirements when implementation is tested.

## Open questions

1. Is the initial native file a container around a database dump or a logical archive?
   Engineering and maintainer decision; blocks the format ADR.
2. Does the first release restore only a full installation into an empty target?
   Recommendation: defer selective merge imports. Maintainer decision; blocks import scope.
3. Which permissions authorize exporting all application data and restoring it? Which
   users, secrets and configuration belong in the file? Maintainer decision; blocks the
   authorization and contents contract.
4. Which versions and file-storage modes must interoperate, and is encryption required?
   Engineering and maintainer decision; blocks compatibility commitments.
5. Which workload performs restoration, how does activation occur, and how are access
   state, labels and pending side effects reconciled? Engineering and maintainer decision;
   blocks restoration architecture.
6. What deployment and release should own implementation? Unscheduled; this decision
   neither delays plan 38 nor replaces database-dump operations.
