<?php

// Settings can also be overwritten in two ways:
//
// First priority:
// A .txt file with the same name as the setting in /data/settingoverrides
// the content of the file is used as the setting value
//
// Second priority:
// An environment variable with the same name as the setting and prefix "VICTUAL_"
// so for example "VICTUAL_BASE_URL"
//
// Third priority:
// The settings defined here below

// Either "production", "dev", "demo" or "prerelease"
// When not "production", the application will work in a demo mode which means
// authentication is disabled and some demo data will be generated during the database schema migration
// (pass the query parameter "nodemodata", e.g. https://victual.example.com/?nodemodata to skip that)
Setting('MODE', 'production');

// The database engine to use. "pgsql" is the only value there is: ADR-0008 made
// PostgreSQL the sole runtime engine and turned SQLite into an import format, so a
// config.php still saying "sqlite" is refused at startup with the command that moves the
// database across ("php bin/victual-db-import /path/to/victual.db").
// PostgreSQL 13 or newer, configured with the settings below.
Setting('DB_DRIVER', 'pgsql');

// Connection settings
Setting('DB_HOST', 'localhost');
Setting('DB_PORT', 5432);
Setting('DB_NAME', 'victual');
Setting('DB_USER', 'victual');
Setting('DB_PASSWORD', '');
Setting('DB_SSLMODE', ''); // One of disable, allow, prefer, require, verify-ca, verify-full - leave empty for the libpq default

// Where the compiled Blade templates, the route cache and the HTMLPurifier definition
// cache go. Everything here is derived from the source tree and can be thrown away and
// rebuilt at any time - nothing in it is data.
// It defaults to a subdirectory of the data directory, which is what an ordinary
// installation wants. A container image points it somewhere image-local instead (e.g.
// /app/viewcache), bakes it at build time with bin/victual-warm-cache and mounts it
// read-only, so that the data directory is the only writable path left
Setting('VIEWCACHE_PATH', VICTUAL_DATAPATH . '/viewcache');


// --- File storage -----------------------------------------------------------------
// Where uploaded files - product, recipe and user pictures, user files and equipment
// manuals - are kept.
//
// "filesystem" (the default) puts them below <data path>/storage, one folder per group,
// which is what this fork has always done and what an ordinary installation wants.
//
// "database" stores them as BYTEA rows instead, so that the application directory needs
// no persistent volume at all and one pg_dump captures a file and the row that points at
// it together rather than in two backup streams that can disagree. It requires
// DB_DRIVER = "pgsql" and is refused in demo/prerelease mode; both are checked at
// startup. Files already on disk are NOT read after the switch - run
// "php bin/victual-files-import" once to move them in, and
// "php bin/victual-files-import --verify" before you remove the old storage directory:
// that compares every file on disk against the bytes stored for it and exits non-zero if
// any of them is missing or different
Setting('FILE_STORAGE', 'filesystem');

// The largest file that may be uploaded, in megabytes, for both storage backends.
// A raw PUT body is not subject to PHP's own post_max_size, so without this there is no
// bound on an upload at all.
// The effective limit is the smallest of this setting, upload_max_filesize and
// post_max_size: setting 64 here on a PHP that accepts 2 MB gives you 2 MB, a line in the
// error log saying so, and the effective value (not this one) reported by
// GET /api/system/config. Raise the php.ini directives as well if you raise this
Setting('FILE_STORAGE_MAX_SIZE_MB', 64);
// --- End of file storage ----------------------------------------------------------


// Whether a request to "/" is allowed to migrate the database schema.
// Migrating is something you do - "php bin/victual-migrate" - rather than something that
// happens to whoever sends the first request after a deployment, so this is off by
// default: the default is the one an immutable deployment wants, where an init step runs
// the migration before any pod serves traffic.
// Set it to true when running this fork from a stock container image with no init step
// and you would rather hit the page once after an update. Either way, an application that
// finds the schema out of date refuses to serve rather than guessing
Setting('MIGRATE_ON_ROOT_REQUEST', false);

// The directory name of one of the available localization folders
// in the "/localization" directory (e.g. "en" or "de")
// Victual uses the first available locale / setting in this order
// 1. Browser prefered locale
// 2. The one set in user settings
// 3. The one defined here below
Setting('DEFAULT_LOCALE', 'en');

// This is used to define the first day of a week for calendar views,
// leave empty to use the locale default
// Needs to be a number where Sunday = 0, Monday = 1 and so forth
Setting('CALENDAR_FIRST_DAY_OF_WEEK', '');

// If calendars should show week numbers
Setting('CALENDAR_SHOW_WEEK_OF_YEAR', true);

// Set this if you want to have a different start day for the weekly meal plan view,
// leave empty to use CALENDAR_FIRST_DAY_OF_WEEK (see above)
// Needs to be a number where Sunday = 0, Monday = 1 and so forth
// Can also be set to -1 to dynamically start the meal plan week on "today"
Setting('MEAL_PLAN_FIRST_DAY_OF_WEEK', '');

// To keep it simple: Victual does not handle any currency conversions,
// this here is used to format all money values,
// so doesn't really matter, but needs to be the
// ISO 4217 code of the currency ("USD", "EUR", "GBP", etc.)
Setting('CURRENCY', 'USD');

// Your preferred unit for energy
// E.g. "kcal" or "kJ" or something else (doesn't really matter, it's only used to display energy values)
Setting('ENERGY_UNIT', 'kcal');

// When running Victual in a subdirectory, this should be set to the relative path, otherwise empty
// It needs to be set to the part (of the URL) AFTER the document root,
// if URL rewriting is disabled, including index.php
// Example with URL Rewriting support:
//  Root URL = https://example.com/victual
//  => BASE_PATH = /victual
// Example without URL Rewriting support:
//  Root URL = https://example.com/victual/public/index.php/
//  => BASE_PATH = /victual/public/index.php
Setting('BASE_PATH', '');

// The base URL of your installation,
// should be just "/" when running directly under the root of a (sub)domain
// or for example "https://example.com/victual" when using a subdirectory
Setting('BASE_URL', '/');

// The plugin to use for external barcode lookups,
// must be the filename (folder "/plugins" for built-in plugins or "/data/plugins" for user plugins) without the .php extension,
// see /plugins/DemoBarcodeLookupPlugin.php for a commented example implementation
// Leave empty to disable external barcode lookups
Setting('STOCK_BARCODE_LOOKUP_PLUGIN', 'OpenFoodFactsBarcodeLookupPlugin');

// If, however, your webserver does not support URL rewriting, set this to true
Setting('DISABLE_URL_REWRITING', false);

// Specify an custom homepage if desired, by default the homepage will be set to the stock overview page
// This needs to be one of the following values:
// stock, shoppinglist, recipes, chores, tasks, batteries, equipment, calendar, mealplan
Setting('ENTRY_PAGE', 'stock');

// Set this to true if you want to disable authentication / the login screen,
// places where user context is needed will then use the default (first existing) user
Setting('DISABLE_AUTH', false);

// How many days a login that ticked "Stay logged in permanently" stays valid for.
// A login without it always expires after 30 days. Both are enforced server side;
// the session cookie mirrors whichever applies
Setting('SESSION_STAY_LOGGED_IN_DAYS', 90);

// A valid fully qualified class name of the authentication middlware to use:
//  Victual\Middleware\Auth\DefaultAuthMiddleware: The default which uses the users you create in Victual
//  Victual\Middleware\Auth\ReverseProxyAuthMiddleware: When your reverse proxy handles authentication (see options below)
// or any other class that extends Victual\Middleware\Auth\BaseAuthMiddleware, which is
// checked at startup - a value naming a class that does not, or that does not exist, is
// refused rather than fataling on the first request
//
// The LDAP backend was removed in this release. An LDAP directory reaches this
// application through a reverse proxy that authenticates against it, which is the same
// arrangement for every other identity provider and one this fork does not have to
// maintain a second implementation of
Setting('AUTH_CLASS', 'Victual\Middleware\Auth\DefaultAuthMiddleware');

// Options when using ReverseProxyAuthMiddleware
Setting('REVERSE_PROXY_AUTH_HEADER', 'REMOTE_USER'); // The name of the HTTP header which your reverse proxy uses to pass the username (on successful authentication)
Setting('REVERSE_PROXY_AUTH_USE_ENV', false); // Set to true if the username is passed as an environment variable
// Which addresses may set the header above, as a comma separated list of IPs and/or CIDR
// ranges, e.g. '10.42.0.0/16, 192.168.1.10'. Required in header mode: the header is
// client-settable, so without this anyone who can reach this application directly can
// authenticate as any user. Not used when REVERSE_PROXY_AUTH_USE_ENV is true, where the
// username comes from the server environment rather than from the request.
// Your proxy must also be configured to strip this header from inbound requests
Setting('REVERSE_PROXY_AUTH_TRUSTED_PROXIES', '');

// How many failed logins are allowed against one username inside the window below.
// Further attempts are refused - answered exactly like a wrong password, so that hitting
// the limit tells a guesser nothing. A successful login clears that username's count.
// Set MAX_ATTEMPTS to 0 to turn the throttle off entirely.
//
// The count lives in the database rather than in memory, because the deployment this fork
// targets scales to zero: a counter held in the process is reset for free by anyone willing
// to wait out an idle window, which is the same as having no throttle.
//
// This limit is per username and says nothing about where the request came from, which is
// deliberate. Behind a reverse proxy every request arrives from the proxy, so a per-address
// count here would be a whole-instance lockout wearing a per-address name. Rate limiting a
// misbehaving client address needs the real address, which only your proxy knows - do it
// there (fail2ban, nginx limit_req, an ingress middleware)
Setting('LOGIN_THROTTLE_MAX_ATTEMPTS', 10);
Setting('LOGIN_THROTTLE_WINDOW_MINUTES', 15);

// Default permissions for new users
// the array needs to contain the technical/constant names
// See the file controllers/Users/User.php for possible values
//
// Empty by default, and deliberately: this used to be ['ADMIN'], which made every user
// created by the reverse proxy backend an administrator on first sight of their username,
// and let an account holding only USERS_CREATE create an administrator and log in as it.
// A new user is given what whoever created them chose to give them; nothing is granted by
// merely existing
Setting('DEFAULT_PERMISSIONS', []);

// Immutable role codes assigned to new users (for example ['CHILD']).
Setting('DEFAULT_ROLES', []);

// Which browser origins may call the API cross-origin, as a comma separated list of
// exact origins, e.g. 'https://home.example.com, https://tablet.example.com'.
// Empty (the default) means no CORS response headers are sent at all, which is what an
// installation with no browser based third party client wants. This used to be an
// unconditional 'Access-Control-Allow-Origin: *' on an API that authenticates with a key
// (sweep finding S21); a preflight is still answered 204, it just carries no permission
// for an origin that is not listed here
Setting('CORS_ALLOWED_ORIGINS', '');

// "1D" (=> Code128) or "2D" (=> DataMatrix)
Setting('GROCYCODE_TYPE', '2D');


// Label printer settings
Setting('LABEL_PRINTER_WEBHOOK', ''); // The URI that Victual will POST to when asked to print a label
Setting('LABEL_PRINTER_RUN_SERVER', true); // Whether the webhook will be called server- or client-side
Setting('LABEL_PRINTER_PARAMS', ['font_family' => 'Source Sans Pro (Regular)']); // Additional parameters supplied to the webhook
Setting('LABEL_PRINTER_HOOK_JSON', true); // TRUE to use JSON or FALSE to use normal POST request variables


// Thermal printer options
// Thermal printers are receipt printers, not regular printers,
// the printer must support the ESC/POS protocol, see https://github.com/mike42/escpos-php
Setting('TPRINTER_IS_NETWORK_PRINTER', false); // Set to true if it's a network printer
Setting('TPRINTER_PRINT_QUANTITY_NAME', true); // Set to false if you do not want to print the quantity names (related to the shopping list)
Setting('TPRINTER_PRINT_NOTES', true); // Set to false if you do not want to print notes (related to the shopping list)
Setting('TPRINTER_IP', '127.0.0.1'); // IP of the network printer (does only matter if it's a network printer)
Setting('TPRINTER_PORT', 9100); // Port of the network printer (does only matter if it's a network printer)
Setting('TPRINTER_CONNECTOR', '/dev/usb/lp0'); // Printer device (does only matter if you use a locally attached printer)
// For USB on Linux this is often '/dev/usb/lp0', for serial printers it could be similar to '/dev/ttyS0'
// Make sure that the user that runs the webserver has permissions to write to the printer - on Linux add your webserver user to the LP group with usermod -a -G lp www-data


// Feature flags
// Here you can disable the parts which you don't need to have a less cluttered UI
// (set the setting to "false" to disable the corresponding part, which should be self explanatory)
Setting('FEATURE_FLAG_STOCK', true);
Setting('FEATURE_FLAG_SHOPPINGLIST', true);
Setting('FEATURE_FLAG_RECIPES', true);
Setting('FEATURE_FLAG_CHORES', true);
Setting('FEATURE_FLAG_TASKS', true);
Setting('FEATURE_FLAG_BATTERIES', true);
Setting('FEATURE_FLAG_EQUIPMENT', true);
Setting('FEATURE_FLAG_CALENDAR', true);
Setting('FEATURE_FLAG_LABEL_PRINTER', false);

// Sub feature flags
Setting('FEATURE_FLAG_STOCK_PRICE_TRACKING', true);
Setting('FEATURE_FLAG_STOCK_LOCATION_TRACKING', true);
Setting('FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_TRACKING', true);
Setting('FEATURE_FLAG_STOCK_PRODUCT_OPENED_TRACKING', true);
Setting('FEATURE_FLAG_STOCK_PRODUCT_FREEZING', true);
Setting('FEATURE_FLAG_STOCK_BEST_BEFORE_DATE_FIELD_NUMBER_PAD', true); // Activate the number pad in due date fields on (supported) mobile browsers
Setting('FEATURE_FLAG_SHOPPINGLIST_MULTIPLE_LISTS', true);
Setting('FEATURE_FLAG_RECIPES_MEALPLAN', true);
Setting('FEATURE_FLAG_CHORES_ASSIGNMENTS', true);
Setting('FEATURE_FLAG_THERMAL_PRINTER', false);

// Feature settings
Setting('FEATURE_FLAG_DISABLE_BROWSER_BARCODE_CAMERA_SCANNING', false); // Set this to true if you want to disable the ability to scan a barcode via the device camera (Browser API)
Setting('FEATURE_FLAG_AUTO_TORCH_ON_WITH_CAMERA', true); // Enables the torch automatically (if the device has one)


// ---------------------------------------------------------------------------
// MQTT state publication (docs/plans/18-mqtt-state-publication.md)
//
// Victual can push a snapshot of the household's ambient state - what is in stock, what
// is on the shopping list, and the next due chore, battery and task - to an MQTT broker
// as retained topics, with Home Assistant discovery payloads alongside them. The point is
// that a consumer never has to ask: retained topics survive the server being asleep or
// gone entirely, so nothing has to poll and the pod can scale to zero.
//
// What is published is deliberately narrow. Only facts go on a topic - dates and counts,
// never "expiring soon" or "overdue", because a derived state is a function of the clock
// and would need something awake to recompute it. And anything with access to the broker
// reads this without authenticating to Victual, so the payload carries no user records,
// no notes, no API keys and no price, cost or value field of any kind.
//
// Two triggers: once at the end of any request that changed data, and explicitly via
// "php bin/victual-publish-state" (run that from a postStart hook or a Job next to the
// bin/victual-migrate initContainer, so a fresh deployment republishes everything).
// "php bin/victual-publish-state --retract" clears every retained topic this version owns.
//
// Disabled by default: this is a household-specific integration, and an unconfigured
// install should not try to reach a broker that is not there.
Setting('MQTT_ENABLED', false);
Setting('MQTT_HOST', ''); // Broker hostname or IP - required when MQTT_ENABLED is true
Setting('MQTT_PORT', 1883); // 8883 is the usual port when MQTT_TLS is true
Setting('MQTT_USERNAME', ''); // Leave empty for an anonymous broker
Setting('MQTT_PASSWORD', ''); // Never exposed via GET /api/system/config - see SystemApiController::EXPOSED_SETTINGS
Setting('MQTT_TLS', false); // Whether to wrap the broker connection in TLS
Setting('MQTT_CLIENT_ID', 'victual'); // Prefix of the MQTT client id; a per-connection suffix is appended so two publishes never collide
Setting('MQTT_TOPIC_PREFIX', 'victual'); // Root of every topic this publishes, e.g. "victual/state/stock"
Setting('MQTT_DISCOVERY_PREFIX', 'homeassistant'); // Home Assistant's MQTT discovery prefix
Setting('MQTT_DISCOVERY_MODE', 'device'); // "device" for one config topic declaring every entity (Home Assistant 2024.11 and newer), "entity" for one config topic per sensor
Setting('MQTT_CONNECT_TIMEOUT_SECONDS', 2); // Also the socket timeout - this bounds how long a write can be delayed when the broker is unreachable
Setting('MQTT_DEVICE_NAME', 'Victual'); // The device name Home Assistant shows for the published entities

// InfluxDB event writing (same plan, question 7)
//
// The other half of the split the plan draws. MQTT carries facts anything on the broker may
// read and therefore carries no prices at all; InfluxDB is queried with its own credentials
// rather than broadcast, so it is where "how has spending shifted" is answered.
//
// Only *events* are written, never sampled state: a point when a purchase commits produces a
// series whose gaps mean "no purchases", which is true, where sampling stock from a pod that
// is mostly asleep would produce holes that mean "nobody was shopping". Two measurements,
// both tagged only with product_id and carrying no user-identifying data:
//
//   price_paid,product_id=<id>  price=<paid>,amount=<booked>   at the booking's own timestamp
//   stock_value,product_id=<id> value=<worth>,amount=<in stock> at the end of the request
//
// Written on the same after-commit seam as the MQTT publish, over InfluxDB's v2
// /api/v2/write line-protocol endpoint. A failure is logged and never reaches the write that
// triggered it.
Setting('INFLUXDB_ENABLED', false);
Setting('INFLUXDB_URL', ''); // Base URL of the InfluxDB v2 server, e.g. "http://influxdb:8086" - required when INFLUXDB_ENABLED is true
Setting('INFLUXDB_TOKEN', ''); // An API token with write access to the bucket below; never exposed via GET /api/system/config
Setting('INFLUXDB_ORG', 'victual'); // The InfluxDB organisation
Setting('INFLUXDB_BUCKET', 'victual'); // The bucket the points are written to
Setting('INFLUXDB_TIMEOUT_SECONDS', 2); // Connect and total timeout - this bounds how long a write can be delayed when InfluxDB is unreachable


// Default user settings
// These settings can be changed per user and via the UI,
// below are the defaults which are used when the user has not changed the setting so far

// Night mode related
DefaultUserSetting('night_mode', 'follow-system'); // "on" = Night mode is always on ; "off" = Night mode is always off / "follow-system" = System preferred color schema is used
DefaultUserSetting('auto_night_mode_enabled', false); // If night mode is enabled automatically when inside a given time range (see the two settings below)
DefaultUserSetting('auto_night_mode_time_range_from', '20:00'); // Format HH:mm
DefaultUserSetting('auto_night_mode_time_range_to', '07:00'); // Format HH:mm
DefaultUserSetting('auto_night_mode_time_range_goes_over_midnight', true); // If the time range above goes over midnight
DefaultUserSetting('night_mode_enabled_internal', false); // Internal setting if night mode is actually enabled (based on the other settings)

// Generic settings
DefaultUserSetting('auto_reload_on_db_change', false); // If the page should be automatically reloaded when there was an external change
DefaultUserSetting('show_clock_in_header', false); // Show a clock in the header next to the logo or not
DefaultUserSetting('keep_screen_on', false); // If the screen should always be kept on
DefaultUserSetting('keep_screen_on_when_fullscreen_card', false); // If the screen should be kept on when a "fullscreen-card" is displayed

// Stock settings
DefaultUserSetting('product_presets_location_id', -1); // Default location id for new products (-1 means no location is preset)
DefaultUserSetting('product_presets_product_group_id', -1); // Default product group id for new products (-1 means no product group is preset)
DefaultUserSetting('product_presets_qu_id', -1); // Default quantity unit id for new products (-1 means no quantity unit is preset)
DefaultUserSetting('product_presets_default_due_days', 0); // Default due days for new products (-1 means that the product will be never overdue)
DefaultUserSetting('product_presets_treat_opened_as_out_of_stock', true); // Default "Treat opened as out of stock" option for new products
DefaultUserSetting('product_presets_default_stock_label_type', 0); // "Default stock entry label" option for new products (0 = No label, 1 = Single Label, 2 = Label per unit)
DefaultUserSetting('stock_decimal_places_amounts', 4); // Default decimal places allowed for amounts
DefaultUserSetting('stock_decimal_places_prices_input', 2); // Default decimal places allowed for prices (input)
DefaultUserSetting('stock_decimal_places_prices_display', 2); // Default decimal places allowed for prices (display)
DefaultUserSetting('stock_auto_decimal_separator_prices', false);  // If the decimal separator should be set automatically for amount inputs
DefaultUserSetting('stock_due_soon_days', 5); // The "expiring soon" days
DefaultUserSetting('stock_default_purchase_amount', 0); // The default amount prefilled on the purchase page
DefaultUserSetting('stock_default_consume_amount', 1); // The default amount prefilled on the consume page
DefaultUserSetting('stock_default_consume_amount_use_quick_consume_amount', false); // If the products quick consume amount should be prefilled on the consume page
DefaultUserSetting('scan_mode_consume_enabled', false); // If scan mode on the consume page is enabled
DefaultUserSetting('scan_mode_purchase_enabled', false); // If scan mode on the purchase page is enabled
DefaultUserSetting('show_icon_on_stock_overview_page_when_product_is_on_shopping_list', true); // When enabled, an icon is shown on the stock overview page (next to the product name) when the prodcut is currently on a shopping list
DefaultUserSetting('stock_overview_show_all_out_of_stock_products', false); // By default the stock overview page lists all products which are currently in stock or below their min. stock amount - when this is enabled, all (active) products are always shown
DefaultUserSetting('show_purchased_date_on_purchase', false); // Whether the purchased date should be editable on purchase (defaults to today otherwise)
DefaultUserSetting('show_warning_on_purchase_when_due_date_is_earlier_than_next', true); // Show a warning on purchase when the due date of the purchased product is earlier than the next due date in stock

// Shopping list settings
DefaultUserSetting('shopping_list_to_stock_workflow_auto_submit_when_prefilled', false); // Automatically do the booking using the last price and the amount of the shopping list item, if the product has "Default due days" set
DefaultUserSetting('shopping_list_show_calendar', false); // When enabled, a small (month view) calendar will be shown on the shopping list page
DefaultUserSetting('shopping_list_round_up', false); // When enabled, all quantity amounts on the shopping list are always displayed rounded up to the nearest whole number
DefaultUserSetting('shopping_list_auto_add_below_min_stock_amount', false); // If products should be automatically added to the shopping list when they are below their min. stock amount
DefaultUserSetting('shopping_list_auto_add_below_min_stock_amount_list_id', 1); // When the above setting is enabled, the id of the shopping list to which the products will be added
DefaultUserSetting('shopping_list_print_show_header', true); // Default for the shopping list print option "Show header"
DefaultUserSetting('shopping_list_print_group_by_product_group', true); // Default for the shopping list print option "Group by product group"
DefaultUserSetting('shopping_list_print_layout_type', 'table'); // Default for the shopping list print option "Layout type" (table or list)

// Recipe settings
DefaultUserSetting('recipe_ingredients_group_by_product_group', false); // Group recipe ingredients by their product group
DefaultUserSetting('recipes_show_list_side_by_side', true); // If the recipe should be displayed next to recipe list on the recipes page
DefaultUserSetting('recipes_show_ingredient_checkbox', false); // When enabled, a little checkbox will be shown next to each ingredient to mark it as done

// Chores settings
DefaultUserSetting('chores_due_soon_days', 5); // The "due soon" days
DefaultUserSetting('chores_overview_swap_tracking_buttons', false); // When enabled, the "Track next chore schedule" and "Track chore execution now" buttons/menu items are swapped

// Batteries settings
DefaultUserSetting('batteries_due_soon_days', 5); // The "due soon" days

// Tasks settings
DefaultUserSetting('tasks_due_soon_days', 5); // The "due soon" days

// Calendar settings
DefaultUserSetting('calendar_color_products', '#007bff'); // The event color (hex code) for due products
DefaultUserSetting('calendar_color_tasks', '#28a745'); // The event color (hex code) for due tasks
DefaultUserSetting('calendar_color_chores', '#ffc107'); // The event color (hex code) for due chores
DefaultUserSetting('calendar_color_batteries', '#17a2b8'); // The event color (hex code) for due battery charge cycles
DefaultUserSetting('calendar_color_meal_plan', '#6c757d'); // The event color (hex code) for meal plan items
