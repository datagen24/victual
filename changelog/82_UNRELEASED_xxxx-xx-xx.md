> ⚠️ Authentication middleware was reorganized, review your `AUTH_CLASS` setting (see the default reference in `config-dist.php` as usual). `SessionAuthMiddleware` and `ApiKeyAuthMiddleware` no longer exist as classes - they were never valid `AUTH_CLASS` values, but a configuration naming one is now refused at startup rather than failing on the first request

> ⚠️ The LDAP authentication backend has been removed, along with the six `LDAP_*` settings. An LDAP directory reaches Victual through a reverse proxy that authenticates against it (`AUTH_CLASS = Victual\Middleware\Auth\ReverseProxyAuthMiddleware`), which is the same arrangement every other identity provider uses. An installation still set to `LdapAuthMiddleware` refuses to start, with a message saying so

> ⚠️ `DEFAULT_PERMISSIONS` is now empty rather than `['ADMIN']`. New users - including the ones reverse proxy authentication creates on first sight of a username - are given nothing by merely existing. Set it if you want them to have something, and note that creating a user now also requires the creator to hold everything the default would confer

> ⚠️ Changing your own password now requires your current password

> ⚠️ API keys are now stored as a SHA-256 hash. Your existing keys keep working unchanged - only what is on disk changes - but the manage-keys page can no longer show you a key you already have. It shows the last four characters instead, and a newly created key is displayed once, with its description, on the page that creates it. Copy it then; nothing can produce it again

> ⚠️ A regular API key created from the manage-keys page now expires after `API_KEY_MAX_LIFETIME_DAYS` days (default 365) instead of practically never. Your existing keys are unaffected - only newly created ones get the new default. The page also gained a "Rotate" action per key: it creates a replacement with the same description and does not touch the key being replaced, so both work until you delete the old one yourself. The calendar sharing link and the label printer worker/verifier/renderer credentials are unaffected - they keep their own expiry and rotation

> ⚠️ `/logout` and `/manageapikeys/new` are `POST` routes now, not `GET`. As `GET`s they fired from any page that could get a browser to load a URL - the second one creating an API key with a description of the requester's choosing. The links in the interface were updated; a bookmark or a script calling either as a `GET` gets a `405`

> ⚠️ Failed logins against one username are now rate limited (`LOGIN_THROTTLE_MAX_ATTEMPTS`, default 10, inside `LOGIN_THROTTLE_WINDOW_MINUTES`, default 15). While the limit is reached, even the correct password is refused, and the refusal looks exactly like a wrong one. There is deliberately no per-address limit: behind a reverse proxy every request arrives from the proxy, so one here would lock out the whole installation rather than one client - rate limit a misbehaving address at your proxy instead

> ⚠️ An installation still using the seeded `admin`/`admin` password is sent to the password change form on every page until it is changed

> ⚠️ The API no longer sends `Access-Control-Allow-Origin: *`. Cross-origin browser access is now off unless the new `CORS_ALLOWED_ORIGINS` setting lists the origins that may use it

> ⚠️ Several API failure paths now answer a different status code - `403` for a permission failure, `404` for a missing object on `PUT`/`DELETE`, `400` where a `500` or a `404` was returned for a malformed request. Every changed code is on a failure path and no successful response changed shape; see the API section below for the full list

> ⚠️ The old "tare weight handling" product setting no longer does anything: `enable_tare_weight_handling` and `tare_weight` stay on `/objects/products` at their current values, but it can no longer be newly enabled (`PUT`/`POST` answers `400`), and its purchase/consume/inventory arithmetic is gone - the amount you enter for such a product is now the net amount, not a gross scale reading with the container weight subtracted automatically. Weigh an opened container by recording a measurement on the stock entry when you open it, or when you re-measure it later

### New Feature: xxxx

- xxx

### Stock

- Added measuring the contents of an opened container: opening a single unit can now record how much of it remains (net, or gross with a tare weight), in any quantity unit that converts to the product's stock unit; re-measure it later from the same control. The stock entry list shows what was last measured and when
- The product picker now searches product names accent insensitive
- Optimized the location input on the transfer page: The selected "From Location" is now automatically hidden in the "To Location" dropdown
- Fixed that the product picker workflow dialog was not displayed when the entered value contained double quotes
- Fixed that changing the location on the purchase page re-initialized the due date based on product defaults (if any)
- Fixed that when undoing a product consume or transfer transaction, the store of the corresponding stock entry wasn't restored
  - This will only apply to new consume / transfer transactions, not when undoing transactions made before using this release
- Fixed that the status filter on the master data products page always displayed "All" after selection (only affected Chrome/Edge)
- Fixed that the "This means _n QU_ will be removed/added from stock"-hint on the inventory page wasn't updated when changing the quantity unit only
- Fixed that the product open button on the stock overview page wasn't disabled after opening the last unit
- Fixed that when changing a product name to one that already exists, no corresponding error message was shown on the product edit page

### Shopping list

- Fixed that the shopping list setting (top right corner settings menu) "Round up quantity amounts to the nearest whole number" wasn't applied to shopping list item amounts where a quantity unit conversion was involved
- Fixed that printing the shopping list with "Group by product group" enabled created duplicated product group headlines in some cases
- Fixed that the total value at the top of the shopping list page wasn't updated after removing a shopping list item

### Recipes

- Fixed that the ingredient list showed fixed "Calories" instead of the configured `ENERGY_UNIT`

### Meal plan

- Fixed that "add recipe"-dropdown wasn't sorted alphabetically

### Chores

- Fixed that when tracking a chore via the context/more menu on the chores overview page, the chore name was missing in the confirmation popup

### Calendar

- xxx

### Tasks

- Added a table filter for "Assigned to"

### Batteries

- xxx

### Equipment

- xxx

### Userfields

- Fixed that Userfields of type "Select list (a single item can be selected)" changed by keyboard only were not saved

### General

- Fixed accent insensitive searching using the general table search field was broken
- Fixed that it wasn't possible to log in using passwords containing special escape sequences (e.g. `<<`)
- Fixed that the initially created location and quantity units weren't localized (only applies to new installations)

### API

- Fixed that the endpoints `POST /stock/shoppinglist/add-product` and `POST /stock/shoppinglist/remove-product` truncated decimal product amounts
- An unauthenticated API request is now answered with the usual `{ "error_message": ... }` body and `Content-Type: application/json` instead of a bodyless, untyped `401`
- A CORS preflight (`OPTIONS`) on an API route is now answered `204` instead of `401`, and carries the CORS headers when the request's `Origin` is listed in `CORS_ALLOWED_ORIGINS`
- Cross-origin responses no longer carry `Access-Control-Allow-Origin: *`. Set `CORS_ALLOWED_ORIGINS` to the exact origins that may call the API from a browser; the default is empty, which sends no CORS headers at all
- An unmatched `/api/...` path is now answered `404` instead of an empty `200`
- A state-changing API request authenticated by a session cookie (rather than by an API key) is refused `403` unless its `Origin` - or, when there is none, its `Referer` - is this site. An opaque `Origin: null`, as a sandboxed frame sends, is refused like any other. Requests carrying an API key are unaffected, and so are requests carrying neither header
- A permission failure is now always answered `403`. It was a `400` on `POST /chores/{id}/execute`, `POST /chores/executions/{id}/undo` and `GET /print/shoppinglist/thermal`, where the check happened to run inside the error handling rather than before it
- `POST /chores/executions/calculate-next-assignments` now requires the `CHORES` permission. It had no permission check at all, so any authenticated key could rewrite every chore's next assignment
- `PUT` and `DELETE` on an object that does not exist are now answered `404` instead of `400`, which is what `GET` of the same object already answered
- `POST`, `PUT` and `DELETE` on `/objects/userfields` and `/objects/userentities` now require the `ADMIN` permission
- The generic `POST /objects/{entity}` and `PUT /objects/{entity}/{objectId}` no longer write `id` or `row_created_timestamp` from the request body. Those keys are ignored rather than refused, so a client that reads an object, changes a field and sends the whole thing back is unaffected
- `POST /objects/{entity}` with a body that sets no column of the entity is now answered `400`. It used to answer `200` with a `created_object_id` that identified nothing, because no row was ever inserted
- `GET /files/{group}/{fileName}` now answers `400` for an invalid file group or file name. Every failure used to be answered `404`, so "you asked wrongly" and "it is not here" were the same answer; a file that does not exist is still `404`
- Nine list endpoints no longer document a `500` response, because they no longer return one - an invalid filter or sort parameter has been a `400` since the previous release. The nine are `GET` `/objects/{entity}`, `/users`, `/stock/products/{productId}/locations`, `/stock/products/{productId}/entries`, `/stock/locations/{locationId}/entries`, `/recipes/fulfillment`, `/chores`, `/batteries` and `/tasks`. Clients that read `error_details` off those responses will no longer find it
- The OpenAPI specification now documents the `401`, `403` and `404` responses that the API actually returns

### Users and permissions

- `USERS_EDIT` no longer lets an account edit, delete or re-permission a user who holds permissions it does not hold itself - which included resetting an administrator's password. The same rule covers deleting another user's picture. Note that `USERS_CREATE` resolves to `USERS_EDIT` through the permission hierarchy, so this closed the shorter path too
- Changing your own password requires the current one (body field `current_password` or `current_password_base64`; the user form asks for it)
- Creating a user is now bounded by what the creator holds: `POST /api/users` is refused with `403` when `DEFAULT_PERMISSIONS` would confer something the creator does not have
- `DEFAULT_PERMISSIONS` defaults to empty instead of `['ADMIN']`
- The permission assignment endpoints now refuse a `permission_id` that names no permission, instead of storing a row that grants nothing
- `GET /api/users/{userId}/permissions` now requires `USERS_READ` instead of `ADMIN`, which is what the permissions page itself has always required. The two endpoints that *change* permissions still require `ADMIN`
- `POST /api/users` with an incomplete body is answered `400` naming the missing field, where it used to be a `500` carrying PHP's own type error

### Authentication

- The LDAP backend was removed - see the note at the top
- API keys are stored hashed - see the note at the top
- A regular API key now expires and can be rotated - see the note at the top
- An API key's "last used" time is recorded once a day rather than on every request. A read-only call used to issue a database write every time it was made
- The calendar iCal sharing link works again. The URL the sharing dialog produces answered `401`, because the code path that accepts its `secret` parameter could never be reached
- Each user now gets their own calendar sharing link. There used to be one for the whole installation, created by whoever opened the dialog first and authenticating as them
- An API key is no longer accepted in a `VICTUAL-API-KEY` query parameter. It lands in access logs, browser history and `Referer` headers; send it as the header. The calendar `secret` parameter is unaffected
- Logging out now expires the session cookie as well as deleting the session
- Expired sessions are deleted on login. The table was never pruned
- A login for a username that does not exist now takes the same time as one for a username that does

### Login

- Failed logins are rate limited per username - see the note at the top. The count lives in the database rather than in the process, so it survives a restart, and a successful login clears only its own username's failures
- An account still using the seeded `admin`/`admin` password is redirected to its own password change form from every page until the password is changed. The API is not gated, because that form saves through it

### Server errors and logging

- Uncaught exceptions are now written to `stderr` as one line each, carrying the request method and path, the response status, the exception class and - when error details are enabled - the file, line and stack trace. In production nothing was recorded anywhere before this
- A `4xx` that is neither "not found" nor "not allowed" - a `405`, say - now renders its own page with its own status, instead of the "a server error occured" page with a `500`
- The server error page no longer shows the exception message, file, line, stack trace and system info to everyone. That block is now shown in `dev` mode only, and every value in it is HTML escaped
