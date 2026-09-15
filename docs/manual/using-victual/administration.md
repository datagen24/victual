# Administration

Users, roles, custom data, and the pages that don't belong to any one household task.

## Users

- **`/users`** / **`/user/{id}`** — the user list and edit form. Requires `USERS_READ` to
  view a list, `USERS_CREATE`/`USERS_EDIT` to change one — see
  [Roles and permissions](../operator/roles-permissions.md) for what each permission
  actually gates.
- **`/user/{id}/permissions`** — the individual permission grants for one user, separate
  from the role-bundle grants below.
- **`/roles`** / **`/role/{id}`** — role bundles: a named set of permissions granted or
  revoked together, so a household does not have to tick the same twelve boxes for every
  new account. A role can also be marked immutable and assigned by default to new users
  (`DEFAULT_ROLES`, [Configuration](../configuration.md#authentication)).

## User settings

**`/usersettings`** is where a signed-in user changes their own preferences — night mode,
locale, and the great majority of the per-user defaults referenced throughout this manual
(scan mode, decimal places, due-soon thresholds' personal overrides, print layout, and so
on). Every one of them starts at the value `DefaultUserSetting()` gives it in
`config-dist.php` until a user changes it here; `config-dist.php`'s own comments are the
complete list, since these are personal preferences rather than the household-wide settings
[Configuration](../configuration.md) covers.

Changing your own password is on the user menu (top right) rather than this page: "Change
password" opens `/user/{your id}?changepw=true`.

## Custom fields and custom object types

Two related but distinct extension mechanisms:

- **Userfields** (**`/userfields`**, **`/userfield/{id}`**) attach an extra field to an
  existing entity — a note field on products, say — without a schema migration.
- **Userentities** (**`/userentities`**, **`/userentity/{id}`**) define an entirely new
  object type of your own, with its own set of fields. Once defined, **`/userobjects/{name}`**
  lists its objects and **`/userobject/{name}/{id}`** edits one. A userentity you define
  also gets its own entry in the main navigation automatically.

## Other administration pages

- **`/manageapikeys`** — issue and revoke REST API keys for your own account. See
  [The REST API](../operator/rest-api.md#api-keys).
- **`/api`** — the interactive Swagger UI browser for the REST API itself; useful for
  trying a request by hand.
- **`/barcodescannertesting`** — a diagnostic page that echoes back whatever a barcode
  scanner sends, for confirming a new scanner's prefix/suffix behavior before relying on it
  elsewhere. See [Barcodes and scanning](../operator/barcodes-scanning.md).
- **`/about`** — version and environment information about the running instance.
