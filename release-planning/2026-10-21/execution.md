# 0.5.2 execution ledger

Append one row per merged PR that changes a 0.5.2 issue, in the same PR. Record the merge
commit after merge in a follow-up row, not by rewriting an earlier row. Planning decisions
and GitHub writes live in [release-data.json](release-data.json).

| Date | Issue | PR | Change | Validation | Docstring coverage of touched files |
| --- | --- | --- | --- | --- | --- |
| 2026-10-21 | None | Planning PR | Seeded the 0.5.2 dashboard from the 0.5.1 scripts; assessed the six milestone issues; recorded pending decisions D1 to D8. | `refresh.py` then `render.py`. | `refresh.py` and `render.py` unchanged apart from version strings. |
| 2026-10-21 | [#1035](https://github.com/datagen24/victual/issues/1035) | [#1039](https://github.com/datagen24/victual/pull/1039) | `PUT /api/files/{group}/{name}` answers 415 (standard `error_message` body) for any Content-Type other than `application/octet-stream` and stores nothing; a request with no Content-Type is still read as raw bytes. Documented in `victual.openapi.json`. Two tests that sent `application/pdf` now send the documented type. | Phases storagefiles, files, httpboot, openapidocument, wirecontract, contract, householdpages, labelapi pass; `check_openapi_document.py` passes. | `FilesApiController.php` 14/14, `StorageFilesTest.php` 53/53, `OpenApiErrorBodiesTest.php` 57/57, `Adr0035GenericReadsTest.php` 9/9, `ContractTest.php` 40/50 (80%, from 42%). |
