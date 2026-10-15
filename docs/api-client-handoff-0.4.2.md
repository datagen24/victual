# API client handoff for the 0.4.2 candidate

The server wire behavior covered here predates this candidate. The OpenAPI document now
describes that behavior, and the contract tests record it against PostgreSQL. This note is
for maintainers of clients generated from `victual.openapi.json` or the served
`GET /api/openapi/specification` document. The served document substitutes the installed
version and installation-specific entity lists; this candidate does not change the
committed document's OAS 3.2.0 version.

## Client changes

| Contract correction | Client action |
|---|---|
| `stock_label_type` on `POST /stock/products/{productId}/add` and `/inventory` (#953) | Use `0` for no label, `1` for one label on a single entry, and `2` for one entry and label per unit. `3` is accepted but books one entry without a label. Do not reject other numeric values locally as a new server constraint; they also book one unlabelled entry. |
| `ChoreDetailsResponse.average_execution_frequency_hours` (#936) | Decode the non-null value as a JSON number. The PostgreSQL contract fixture records 24.5 hours, so an integer-only decoder fails on a valid response. Keep `null` for a chore without a computed average. |
| Recorded response keys (#893) | Regenerate the client from the corrected schemas. The contract test checks every recorded key against each reached response schema, including nested and inline objects. It does not change a response payload. |
| Error responses (#801) | Decode a production 500 as JSON `ApiError` with `error_message`. Handle 503 from the schema-version gate as UTF-8 plain text, including when no credential was supplied. Its text is operator guidance, not a stable client error code. Development mode may add `error_details` to a 500 and a database driver message to a connection-failure 503. |
| Worker registration (#944, documented under #801) | A NUL byte inside a `capability_document` string can return JSON 400 `ApiError` and roll back the registration. Other driver validation failures use 422 `LabelRefusal`. A NUL in `driver_id` currently returns 200; the stored representation has not been verified. |

An absent credential can receive a JSON 401 before a protected controller runs. Worker and
renderer routes require their own key types; a user key or session cannot call them. The
public `POST /labels/pair` route handles invalid material itself. A caller without a right
to a private resource may receive the same 404 as a nonexistent resource. Route-level 404
and 405 responses are described centrally in the OpenAPI introduction because they do not
identify an operation that ran.

The calendar feed's current 500 message can contain an event title if an internal event
lacks the identity needed for a stable UID. The title-disclosure assessment remains a
separate security review; this contract correction does not alter that behavior.

The 2026-10-15 candidate has 180 documented operations. All 180 declare 401, 500 and
503; 170 declare 403 and 148 declare 400. The [operation/status inventory](../.devtools/issue801/api-operation-status-0.4.2.tsv)
records each row. Ordinary authenticated routes can return middleware 403, including
an untrusted reverse proxy on a read. Typed worker routes bypass those checks; five
routes can instead return 403 when an attempt or printer belongs to another worker.

Public pairing, three other worker operations and six renderer or byte-fetch routes
have no reachable 403. Label validation commonly returns 422. These distinctions come
from `BaseAuthMiddleware`, `LabelWorkerAuthorization`, the label controllers and the
focused PostgreSQL cases. The inventory does not claim every listed branch was exercised.

## Verification for this candidate

The source revision before these edits was `b5532aafd7b91306f7e98dd3361ba82d1ebbe5da`
on 2026-10-15. The OpenAPI structural check validates the committed document. The
PostgreSQL `contract`, `wirecontract`, `stockcoverage`, `authstack`, `errors` and
`openapidocument` phases supply the relevant response and failure-path checks; report
their actual results with the candidate, rather than treating this list as a passed gate.
