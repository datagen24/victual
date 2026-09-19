# Configuration

Every setting below is declared with `Setting('NAME', default)` in `config-dist.php`, which
is also where the comment for each one lives if this reference is too terse. Copy that file
to `data/config.php` and override what you need there — an unset setting falls back to the
default shown here.

Three ways to set a value, checked in this order:

1. A `<SettingName>.txt` file in `<data path>/settingoverrides`, containing the value.
2. A `VICTUAL_<SettingName>` environment variable.
3. The default in `config.php` (or `config-dist.php` if you have not overridden it).

This is how the Nix-built container images are configured: they carry no `config.php` at
all, and set `VICTUAL_DB_*`, `VICTUAL_FILE_STORAGE` and the rest as environment variables
from a ConfigMap and Secret — see [Deployment](../../deploy/README.md).
`VICTUAL_DATAPATH` moves the data directory itself, before any of the above is read.

Settings are validated at startup by `ConfigurationValidator`; an invalid value refuses
to serve rather than failing on first use. Validation starts with `MODE`, then `AUTH_CLASS`,
then the database settings. The sections below group settings by purpose. Where a
setting's allowed values or cross-setting requirements matter, they are noted below.

## Application mode {: #application-mode }

| Setting | Default | Notes |
|---|---|---|
| `MODE` | `production` | One of `production`, `dev`, `demo`, `prerelease`. Anything but `production` runs in demo mode: authentication is disabled, and demo data is generated on the first request to `/` during schema migration (add `?nodemodata` to skip that). |

## Database {: #database }

| Setting | Default | Notes |
|---|---|---|
| `DB_DRIVER` | `pgsql` | The only accepted value. A `config.php` still naming `sqlite` is refused at startup with the exact `bin/victual-db-import` command that moves the database across. |
| `DB_HOST` | `localhost` | Required when `DB_DRIVER` is `pgsql`. |
| `DB_PORT` | `5432` | |
| `DB_NAME` | `victual` | Required. |
| `DB_USER` | `victual` | Required. |
| `DB_PASSWORD` | *(empty)* | |
| `DB_SSLMODE` | *(empty)* | One of `disable`, `allow`, `prefer`, `require`, `verify-ca`, `verify-full`, or empty for libpq's own default. |

## View cache {: #view-cache }

| Setting | Default | Notes |
|---|---|---|
| `VIEWCACHE_PATH` | `<data path>/viewcache` | Where compiled Blade templates, the route cache and the HTML sanitizer's definition cache go. Everything under this path is derived from the source tree and can be deleted and rebuilt at any time. A container image points this at an image-local path baked at build time with `bin/victual-warm-cache` and mounted read-only, so the data directory is the only writable path left. |

## File storage {: #file-storage }

Where uploaded files — product, recipe and user pictures, user files and equipment
manuals — are kept.

| Setting | Default | Notes |
|---|---|---|
| `FILE_STORAGE` | `filesystem` | `filesystem` puts files below `<data path>/storage`, one folder per group. `database` stores them as `BYTEA` rows instead, so the application directory needs no persistent volume and one `pg_dump` captures a file and the row pointing at it together. Requires `DB_DRIVER` `pgsql`, and is refused in `demo`/`prerelease` mode (those instances share a storage path by suffix, and the files table has no column for it). Switching does **not** move existing files: run `php bin/victual-files-import` once, then `php bin/victual-files-import --verify` before removing the old storage directory. |
| `FILE_STORAGE_MAX_SIZE_MB` | `64` | The largest upload accepted, for either backend. The value actually enforced is the smallest of this setting, PHP's `upload_max_filesize` and `post_max_size` — raise those `php.ini` directives too if you raise this. `GET /api/system/config` reports the effective value, not this one. |

## Database migrations {: #database-migrations }

| Setting | Default | Notes |
|---|---|---|
| `MIGRATE_ON_ROOT_REQUEST` | `false` | Allows a request to `/` to run pending migrations. Off by default: migrating is something a deployment's init step does, not something that happens to whoever loads the page first. Turn this on only if you run from a stock image with no init step. Either way, an application that finds the schema out of date refuses to serve rather than guessing. |

## Localization and display {: #localization-and-display }

| Setting | Default | Notes |
|---|---|---|
| `DEFAULT_LOCALE` | `en` | A folder name under `/localization` (e.g. `de`). Used when neither the browser's preferred locale nor the signed-in user's own setting apply. |
| `CALENDAR_FIRST_DAY_OF_WEEK` | *(empty)* | `0` (Sunday) through `6` (Saturday); empty uses the locale default. |
| `CALENDAR_SHOW_WEEK_OF_YEAR` | `true` | Shows week numbers on calendar views. |
| `MEAL_PLAN_FIRST_DAY_OF_WEEK` | *(empty)* | Same 0–6 scale as above, plus `-1` to start the meal plan week dynamically on "today". Empty follows `CALENDAR_FIRST_DAY_OF_WEEK`. |
| `CURRENCY` | `USD` | An ISO 4217 three-letter code, used only to format money values — Victual does not convert between currencies. |
| `ENERGY_UNIT` | `kcal` | A display label only (e.g. `kcal` or `kJ`); nothing converts between energy units either. |

## URLs {: #urls }

| Setting | Default | Notes |
|---|---|---|
| `BASE_PATH` | *(empty)* | The part of the URL after the document root when Victual runs in a subdirectory. With URL rewriting, e.g. `/victual` for `https://example.com/victual`; without it, the path including `index.php`. |
| `BASE_URL` | `/` | The base URL of the installation — `/` at the root of a (sub)domain, or e.g. `https://example.com/victual` in a subdirectory. |
| `DISABLE_URL_REWRITING` | `false` | Set `true` if the web server cannot rewrite URLs. |

## Barcode lookup {: #barcode-lookup }

| Setting | Default | Notes |
|---|---|---|
| `STOCK_BARCODE_LOOKUP_PLUGIN` | `OpenFoodFactsBarcodeLookupPlugin` | The plugin filename (under `/plugins`, or `/data/plugins` for a user plugin) without `.php`, used by the "External barcode lookup" product-picker workflow. Empty disables external lookups. See `plugins/DemoBarcodeLookupPlugin.php` for a commented example. |

## Entry page {: #entry-page }

| Setting | Default | Notes |
|---|---|---|
| `ENTRY_PAGE` | `stock` | One of `stock`, `shoppinglist`, `recipes`, `chores`, `tasks`, `batteries`, `equipment`, `calendar`, `mealplan` — which page `/` redirects to. A signed-in user who may not view that page (for example, no `STOCK_VIEW` for `stock`) is sent to `/about` instead; a signed-out visitor is sent to the page and from there to the login form. |

## Authentication {: #authentication }

| Setting | Default | Notes |
|---|---|---|
| `DISABLE_AUTH` | `false` | Disables the login screen; anywhere user context is needed uses the default (first existing) user instead. |
| `SESSION_STAY_LOGGED_IN_DAYS` | `90` | How long a login with "Stay logged in permanently" checked stays valid. A login without it always expires after 30 days regardless of this setting; both are enforced server-side. |
| `AUTH_CLASS` | `Victual\Middleware\Auth\DefaultAuthMiddleware` | The authentication middleware class. `Victual\Middleware\Auth\ReverseProxyAuthMiddleware` is the alternative, for a reverse proxy that authenticates for you (see the two settings below); any other class must extend `BaseAuthMiddleware`. There is no LDAP backend — an LDAP directory reaches Victual through a reverse proxy that authenticates against it, the same way as any other identity provider. |
| `REVERSE_PROXY_AUTH_HEADER` | `REMOTE_USER` | The HTTP header your reverse proxy sets to the authenticated username. Only used with `ReverseProxyAuthMiddleware`. |
| `REVERSE_PROXY_AUTH_USE_ENV` | `false` | If `true`, the username comes from the server environment instead of the header above. |
| `REVERSE_PROXY_AUTH_TRUSTED_PROXIES` | *(empty)* | Comma-separated IPs/CIDR ranges allowed to set the username header, e.g. `10.42.0.0/16, 192.168.1.10`. Required in header mode — without it, anyone who can reach Victual directly can authenticate as any user. Not used when `REVERSE_PROXY_AUTH_USE_ENV` is true. Your proxy must also strip this header from inbound requests before setting its own. |
| `LOGIN_THROTTLE_MAX_ATTEMPTS` | `10` | Failed logins allowed against one username inside the window below before further attempts are refused — answered exactly like a wrong password, so hitting the limit tells nothing to whoever is guessing. `0` disables the throttle. The count is per username, not per source address, and lives in the database so it survives the process scaling to zero. |
| `LOGIN_THROTTLE_WINDOW_MINUTES` | `15` | The window the above count resets over; a successful login also clears it. |
| `API_KEY_MAX_LIFETIME_DAYS` | `365` | The longest lifetime a regular API key may be given at creation (issue #130). The manage-keys screen offers a lifetime up to this many days; a value beyond it is clamped rather than refused. Applies to the default API key type only — the calendar sharing key and the label worker/verifier/renderer credentials each already have their own expiry and rotation story and are unaffected. |
| `DEFAULT_PERMISSIONS` | `[]` | Permission constants (see [Roles and permissions](operator/roles-permissions.md)) granted to every newly created user. Empty by default and deliberately so — a nonempty default here grants every new account that set of permissions the moment it exists. |
| `DEFAULT_ROLES` | `[]` | Immutable role codes assigned to new users, e.g. `['CHILD']`. |

## Cross-origin requests (CORS) {: #cross-origin-requests-cors }

| Setting | Default | Notes |
|---|---|---|
| `CORS_ALLOWED_ORIGINS` | *(empty)* | Comma-separated browser origins allowed to call the API cross-origin, e.g. `https://home.example.com, https://tablet.example.com`. Empty (the default) sends no CORS headers at all. Each entry must be a bare origin — scheme, host, optional port, no path and no trailing slash; `https://home.example.com/` never matches anything and silently behaves as if unset. |

## Grocycode {: #grocycode }

| Setting | Default | Notes |
|---|---|---|
| `GROCYCODE_TYPE` | `2D` | `1D` for Code128, `2D` for DataMatrix. See [Barcodes and scanning](operator/barcodes-scanning.md). |

## Thermal printer {: #thermal-printer }

Receipt printers speaking the ESC/POS protocol (see
[mike42/escpos-php](https://github.com/mike42/escpos-php)), used for printing the shopping
list. Requires `FEATURE_FLAG_THERMAL_PRINTER`.

| Setting | Default | Notes |
|---|---|---|
| `TPRINTER_IS_NETWORK_PRINTER` | `false` | `true` for a network printer (uses `TPRINTER_IP`/`TPRINTER_PORT`); `false` for a locally attached one (uses `TPRINTER_CONNECTOR`). |
| `TPRINTER_PRINT_QUANTITY_NAME` | `true` | Print the quantity unit name next to each shopping list line. |
| `TPRINTER_PRINT_NOTES` | `true` | Print shopping list item notes. |
| `TPRINTER_IP` | `127.0.0.1` | Network printer address; ignored unless `TPRINTER_IS_NETWORK_PRINTER` is true. |
| `TPRINTER_PORT` | `9100` | Network printer port; same condition. |
| `TPRINTER_CONNECTOR` | `/dev/usb/lp0` | Local device path — often `/dev/usb/lp0` for USB, `/dev/ttyS0`-like for serial. The web server user needs write access to it (on Linux, add it to the `lp` group). |

## Feature flags {: #feature-flags }

Each hides and disables the matching part of the UI when set `false`. All are reported by
`GET /api/system/config` (feature flags are never treated as sensitive).

| Setting | Default | Notes |
|---|---|---|
| `FEATURE_FLAG_STOCK` | `true` | Stock: purchase, consume, transfer, inventory, the journal, products and locations. |
| `FEATURE_FLAG_SHOPPINGLIST` | `true` | Shopping lists. |
| `FEATURE_FLAG_RECIPES` | `true` | Recipes. |
| `FEATURE_FLAG_CHORES` | `true` | Chores. |
| `FEATURE_FLAG_TASKS` | `true` | Tasks. |
| `FEATURE_FLAG_BATTERIES` | `true` | Batteries. |
| `FEATURE_FLAG_EQUIPMENT` | `true` | Equipment. |
| `FEATURE_FLAG_CALENDAR` | `true` | The calendar overview. |
| `FEATURE_FLAG_LABELS` | `false` | The opaque label subsystem (identities, templates, printers, jobs, the delivery worker) every printable kind — locations, products, stock entries, recipes, chores, batteries — now prints through. Enabling it **requires `FILE_STORAGE` `database`** (checked at startup by `ConfigurationValidator::checkLabelSubsystem()`, refused in `demo`/`prerelease` mode for the same reason `FILE_STORAGE` `database` is). See [Label printing](operator/label-printing.md). |

## Sub feature flags {: #sub-feature-flags }

Finer-grained than the flags above; each narrows one already-enabled feature.

| Setting | Default | Notes |
|---|---|---|
| `FEATURE_FLAG_STOCK_PRICE_TRACKING` | `true` | Price fields on stock bookings. Whether a given *user* sees prices is a separate question - see [Prices](operator/roles-permissions.md#prices). |
| `FEATURE_FLAG_STOCK_LOCATION_TRACKING` | `true` | Per-location stock, rather than one pool per product. |
| `FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_TRACKING` | `true` | Due/best-before dates. |
| `FEATURE_FLAG_STOCK_PRODUCT_OPENED_TRACKING` | `true` | "Opened" state and open-container measurement. |
| `FEATURE_FLAG_STOCK_PRODUCT_FREEZING` | `true` | Freezing/thawing tracking. |
| `FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_FIELD_NUMBER_PAD` | `true` | The numeric keypad for due-date fields on supported mobile browsers. |
| `FEATURE_FLAG_SHOPPINGLIST_MULTIPLE_LISTS` | `true` | More than one shopping list. |
| `FEATURE_FLAG_RECIPES_MEALPLAN` | `true` | The meal plan. |
| `FEATURE_FLAG_CHORES_ASSIGNMENTS` | `true` | Chore assignment rotation. |
| `FEATURE_FLAG_THERMAL_PRINTER` | `false` | The ESC/POS thermal printer path above. |

## Feature settings {: #feature-settings }

| Setting | Default | Notes |
|---|---|---|
| `FEATURE_FLAG_DISABLE_BROWSER_BARCODE_CAMERA_SCANNING` | `false` | Disables scanning a barcode via the device camera (the browser API), leaving a USB/keyboard-wedge scanner as the only input. |
| `FEATURE_FLAG_AUTO_TORCH_ON_WITH_CAMERA` | `true` | Turns the device's torch/flash on automatically when camera scanning starts, if the device has one. |

## Home Assistant and MQTT {: #home-assistant-and-mqtt }

Off by default — see [Home Assistant and MQTT](operator/home-assistant-mqtt.md) for what
gets published and why each of these exists.

| Setting | Default | Notes |
|---|---|---|
| `MQTT_ENABLED` | `false` | Master switch. The remaining settings are validated only when this is true. |
| `MQTT_HOST` | *(empty)* | Broker hostname or IP. Required when enabled. |
| `MQTT_PORT` | `1883` | `8883` is the usual port when `MQTT_TLS` is true. |
| `MQTT_USERNAME` | *(empty)* | Leave empty for an anonymous broker. |
| `MQTT_PASSWORD` | *(empty)* | Never exposed by `GET /api/system/config`. |
| `MQTT_TLS` | `false` | Wrap the broker connection in TLS. |
| `MQTT_CLIENT_ID` | `victual` | Prefix of the MQTT client id; a per-connection suffix is appended so two publishes never collide. |
| `MQTT_TOPIC_PREFIX` | `victual` | Root of every published topic, e.g. `victual/state/stock`. |
| `MQTT_DISCOVERY_PREFIX` | `homeassistant` | Home Assistant's own MQTT discovery prefix. |
| `MQTT_DISCOVERY_MODE` | `device` | `device` publishes one config topic declaring every entity (Home Assistant 2024.11+); `entity` publishes one config topic per sensor. |
| `MQTT_CONNECT_TIMEOUT_SECONDS` | `2` | Connect and socket timeout — bounds how long a write is delayed when the broker is unreachable. Must be at least 1. |
| `MQTT_DEVICE_NAME` | `Victual` | The device name Home Assistant shows for the published entities. |

## InfluxDB {: #influxdb }

The other half of the same split: MQTT carries only facts anything on the broker may read,
so it never carries a price; InfluxDB is where spending history is written, queried with its
own credentials rather than broadcast. See
[Home Assistant and MQTT](operator/home-assistant-mqtt.md#influxdb).

| Setting | Default | Notes |
|---|---|---|
| `INFLUXDB_ENABLED` | `false` | Master switch. |
| `INFLUXDB_URL` | *(empty)* | Base URL of the InfluxDB v2 server, e.g. `http://influxdb:8086`. Required when enabled. |
| `INFLUXDB_TOKEN` | *(empty)* | An API token with write access to the bucket below. Never exposed by `GET /api/system/config`. |
| `INFLUXDB_ORG` | `victual` | The InfluxDB organisation. |
| `INFLUXDB_BUCKET` | `victual` | The bucket points are written to. |
| `INFLUXDB_TIMEOUT_SECONDS` | `2` | Connect and total timeout — bounds how long a write is delayed when InfluxDB is unreachable. Must be at least 1. |

## Configuration outside config.php {: #configuration-outside-configphp }

Every setting above can also be set without editing a file — see the precedence order at
the top of this page. This is how the container images are configured: the ConfigMap and
Secret in [Deployment](../../deploy/README.md) set `VICTUAL_DB_*`,
`VICTUAL_FILE_STORAGE` and the rest as environment variables rather than shipping a
`config.php`.

Three more mechanisms sit outside `config.php` entirely:

- **The first administrator's password.** `VICTUAL_BOOTSTRAP_ADMIN_PASSWORD` is an
  environment variable, not a setting, and is read once: when a migration creates the
  database from nothing and seeds the `admin` account. Set it only on whatever runs
  `bin/victual-migrate` (the migrate container's Secret in [Deployment](../../deploy/README.md)),
  never on the serving containers. Changing or removing it later does nothing — the account
  exists by then and its password is changed like any other. Without it the migration
  generates a password, prints it once to stderr, and the account must change it at first
  login; see [Getting started](getting-started.md#the-first-login).

- **Custom CSS or JS.** If `data/custom_js.html` exists, its contents are inserted just
  before `</body>` on every page; `data/custom_css.html`, just before `</head>`.
- **Embedded mode.** If the file `embedded.txt` exists at the application root, its
  contents must be a valid, writable path, used as the data directory instead of `data`;
  authentication is disabled in this mode.
