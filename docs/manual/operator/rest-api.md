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

A wrong key is throttled the same way a wrong password is — see
[Login throttling](../configuration.md#authentication) — because both are a credential
guess against an account.

## What a key can do

An API key inherits exactly its owning user's permissions; it is not a separate,
narrower-scoped credential. Revoking the user's access, or deleting the user, revokes every
key they hold. See [Roles and permissions](roles-permissions.md) for what a permission
actually gates.

## Comparing against upstream grocy

The [parity suite](../../../.devtools/parity/README.md) exercises Victual against grocy
4.6.0 over HTTP; its accepted-difference records name every intentional contract change and
the decision behind it. It is a manual comparison tool, not a CI gate.
