# The REST API

An interactive browser is served at **`/api`** (Swagger UI, driven by
`GET /api/openapi/specification` — the same specification `victual.openapi.json` describes,
enriched at runtime with the installed version, this instance's own server URL, and the
per-entity operations actually permitted). The frontend uses this API for every write; a
few pages still read the database directly, so not every report has an API response yet —
see [plan 14](https://github.com/datagen24/victual/blob/master/docs/plans/14-contract-and-regression-scaffolding.md).
Response shapes are governed by
[ADR-0005](../../adr/0005-wire-contract-is-the-invariant.md): they do not change
casually, and existing contracts are stable across releases.

## API keys

**`/manageapikeys`** issues and revokes keys for your own account (an administrator sees
everyone's). A key is shown once, at creation — copy it then, because it is not retrievable
afterward. Send it on every request as the `VICTUAL-API-KEY` header. A request authenticates
either with a valid session cookie (the browser frontend) or a valid API key; there is no
third scheme.

Invalid API keys are rejected but are not throttled by `LoginThrottleService`.
[Login throttling](../configuration.md#authentication) applies to password login only.

## What a key can do

An API key inherits exactly its owning user's permissions; it is not a separate,
narrower-scoped credential. Revoking the user's access, or deleting the user, revokes every
key they hold. See [Roles and permissions](roles-permissions.md) for what a permission
actually gates.

That includes field-level reads, which matters when you are writing a client: a field the
key's owner may not see is **absent from the response object**, not `null`. The distinction
is deliberate — `stock_log.price` is legitimately `null` for a consumption, and "there was
no price" has to stay tellable from "you may not see it" — so read such a field with a
presence check rather than a null check, or arithmetic over it produces `NaN`. Naming one
in `query[]` or `order` is answered `400` rather than applied. Today the only fields this
applies to are prices; [Prices](roles-permissions.md#prices) lists them.

## Comparing against upstream grocy

The [parity suite](../../../.devtools/parity/README.md) exercises Victual against grocy
4.6.0 over HTTP; its accepted-difference records name every intentional contract change and
the decision behind it. It is a manual comparison tool, not a CI gate.
