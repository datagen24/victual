# Middleware

PSR-15 middleware that runs before a route's controller. Classes are in the
`Victual\Middleware` namespace (`composer.json` maps it to this directory).

## Request order

`app.php` registers most of the stack, and Slim runs the last-added middleware first. A
request passes through these layers from the outside in:

1. `CorsMiddleware` answers an API preflight and adds CORS headers for an allowed origin.
2. `JsonMiddleware` sets `Content-Type: application/json` on an API response that has none.
3. Slim's error middleware, with `ExceptionController` as the error handler.
4. `SchemaVersionMiddleware` refuses to serve when the migrations applied to the database
   differ from the set the code expects.
5. Slim's routing and body-parsing middleware.
6. The authentication middleware named by the `VICTUAL_AUTH_CLASS` setting.
7. `LocaleMiddleware` defines `VICTUAL_LOCALE` for the request.
8. `PathParameterMiddleware`, on the `/api` route group in `routes.php` only, answers 400 when
   an integer path parameter is not an integer.

`CorsMiddleware` and `JsonMiddleware` decide by path whether a request is an API request, so
rendered pages are unaffected. The comments in `app.php` explain why both sit outside
routing and authentication; [plan 11](../docs/plans/11-api-error-handling.md) is the design.

## Authentication

`Auth/BaseAuthMiddleware` handles public routes and the modes that run without
authentication. Otherwise it asks its `Authenticator` objects, in order, to identify the
caller. On success it defines the `VICTUAL_AUTHENTICATED` and `VICTUAL_USER_*` constants.
On failure an API route gets a 401 and a page route gets a redirect to `/login`.

| Class | Purpose |
|---|---|
| `Auth/DefaultAuthMiddleware` | Default `VICTUAL_AUTH_CLASS`: session cookie on every route, API key on API routes. |
| `Auth/ReverseProxyAuthMiddleware` | Trusts the username an upstream proxy passes in a header or `$_SERVER`; API routes try an API key first. |
| `Auth/SessionAuthenticator` | Matches the session cookie against `SessionService`. |
| `Auth/ApiKeyAuthenticator` | Matches the API key header against `ApiKeyService`; also matches the calendar key in the iCal route's `secret` parameter. |
| `Auth/ReverseProxyAuthenticator` | Reads the proxy username and creates a local user the first time it sees one. |
| `Auth/PasswordLogin` | Verifies a submitted username and password and creates the session. |
| `Auth/SessionCookie` | Sets and clears the session cookie for `PasswordLogin` and `LoginController`. |

An authenticator identifies a caller and does nothing else. The class comment on
`Auth/Authenticator` records why the middleware classes no longer construct each other.

## Constraints

- [ADR-0007](../docs/adr/0007-auth-state-outlives-the-process.md): authentication and
  rate-limit state is stored outside the PHP process. Sessions, API keys and login
  throttling are database-backed services in [services/](../services/README.md).
- [ADR-0044](../docs/adr/0044-api-keys-carry-a-subset-of-their-owners-permissions.md):
  an API key can carry a subset of its owner's permissions.
- `BaseMiddleware` gives a middleware the container and the response factory only.
  A middleware that needs a service fetches it when it runs, because
  `bin/victual-warm-cache` registers the routes at image build time without a database.

Permission checks happen in the controllers, not here; see
[controllers/](../controllers/README.md).
