# Initial status inventory review

GPT-6 Luna, medium reasoning; read-only source inspection over
`1b21984e935e69db76eaa1f7d7117a42f3f2b1df`, with a changing OpenAPI working diff.
This report is an initial source inventory, not final validation.

Candidates identified included generic object/userfield reads
(`GenericEntityApiController.php:505,550,631`), stock read and by-barcode booking routes
(`StockApiController.php:658,675,902,1033,1175,1195,1317,1346,1374`), recipe fulfillment
(`RecipesApiController.php:75`), chores (`ChoresApiController.php:73`), batteries
(`BatteriesApiController.php:22`), tasks (`TasksApiController.php:19`), calendar
(`CalendarApiController.php:67,237`), label operations (`LabelsApiController.php:73`),
and label administration (`LabelTemplatesApiController.php:28`). All refer to issue #801.

Two findings required correction before use:

- Worker registration and claiming cannot return 403. Existing
  `LabelWorkerRoute403Test` pins that negative contract. The first reader report inferred
  too much from a shared worker authorization helper.
- Ordinary self-service and system routes can return 403 through
  `ReverseProxyAuthenticator`, even without a controller permission check. The initial
  reader report omitted that middleware path. The coordinator found an existing test
  using `GET /api/user` as a positive 403 control.

No additional missing reachable 400 was confirmed in the bounded pass. That is not an
exhaustive proof. The implementation agent owns the exact final operation inventory and
live PostgreSQL validation. Provisional reader counts are superseded by that inventory.
These changes document existing responses and do not authorize runtime permission changes.
