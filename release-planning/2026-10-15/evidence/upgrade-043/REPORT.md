# 0.4.2 to 0.4.3 database upgrade rehearsal

Run date: 2026-10-15. Source tag `v0.4.2` resolves to
`b728d0d1a2203523155327b89a03716f915b58c4`. Candidate source was
`5e2e92d8075db170a0701b6cd658aea12e57bd2f` (draft PR #969). The candidate
image was `localhost/victual:0.4.3-release-test`, PHP 8.5.10. The isolated
database ran on PostgreSQL 16.15 in the existing `victual-api042-pg` test
container.

The source and candidate trees differ only in release metadata for this
rehearsal. No files differ under `migrations/` or `db/pgsql/baseline/`.
The 0.4.2 source migrator initialized a uniquely named database at migration
312. The source fixture then created `Upgrade audit oats`, booked three units
through `StockService::AddProduct`, and created `Upgrade audit chore` with
three executions. The candidate migrator ran twice; both invocations exited 0
and reported `Schema is up to date at migration 312.` The candidate read probe
returned:

```json
{"product":"Upgrade audit oats","stock_amount":3,"chore":"Upgrade audit chore","tracked_count":3,"average_execution_frequency_hours":24.5}
```

Schema-only and data-only PostgreSQL dumps were compared before and after the
two candidate runs. Normalization removed only the per-run `\\restrict` and
`\\unrestrict` lines. No application schema or data was excluded. Both
normalized files were byte-identical:

| Dump | SHA-256 before and after |
|---|---|
| Schema | `c7957dfefff22f9893eb57850886dde541a67e33324204cf857afaf873ddbb17` |
| Data | `e3eb64554d3b700698f1d226c1ed3109936a7afae3459248819d52840fe270b7` |

The normalized dumps are `before-schema.norm.sql`, `after-schema.norm.sql`,
`before-data.norm.sql`, and `after-data.norm.sql` beside this report. The
uniquely named audit database was dropped after capture.

This verifies a populated 0.4.2-to-candidate run and repeatability on the
recorded PostgreSQL/PHP runtime. It does not establish production Nix image
rollout, compatibility with arbitrary household data, refusal and recovery
behavior, or backup restoration. The `pg_dump --data-only` commands warned
about circular foreign keys, so these data-only dumps are comparison evidence,
not a restore test. The PostgreSQL 15 CI job remains a separate candidate gate.
