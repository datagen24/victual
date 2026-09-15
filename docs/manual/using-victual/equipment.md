# Equipment

Requires `FEATURE_FLAG_EQUIPMENT`. A simple register of household equipment — appliances,
tools, anything worth keeping a manual and a note against.

- **`/equipment`** — the equipment list.
- **`/equipment/{id}`** — the edit form: name, notes, and an attached manual (PDF or other
  document), stored the same way as any other upload — see
  [Configuration](../configuration.md#file-storage).

Equipment has no due dates, journal or settings page of its own; it is master data only.
