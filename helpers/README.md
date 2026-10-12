# Helpers

Classes and functions used across layers that are neither middleware, controllers nor
services. Classes are in the `Victual\Helpers` namespace; `extensions.php` defines global
functions without a namespace.

## Contents

| Path | Contents |
|---|---|
| `extensions.php` | Global array, type-conversion and validation helpers, and the `Setting()` and `DefaultUserSetting()` functions that `config-dist.php` and `data/config.php` use to define the `VICTUAL_*` constants. |
| `ConfigurationValidator.php` | Validates the `VICTUAL_*` constants at startup; throws `EInvalidConfig`. |
| `PrerequisiteChecker.php` | Checks PHP extensions, the config file and Composer packages at startup; throws `ERequirementNotMet`. |
| `SlimBladeView.php` | Renders Blade templates from `views/` into a PSR-7 response. `app.php` registers it as the `view` container entry. |
| `UrlManager.php` | Builds absolute application URLs from `VICTUAL_BASE_URL`. |
| `CachePaths.php` | Names files in `VICTUAL_VIEWCACHE_PATH` that several components share, such as the route cache. |
| `StderrLogger.php` | PSR-3 logger that writes one line per record to stderr. `app.php` passes it to `ExceptionController`. |
| `OutboundHostPolicy.php`, `OutboundHostRefusedException.php` | Refuses a URL whose host resolves to a loopback, private, link-local or other internal address. |
| `BaseBarcodeLookupPlugin.php` | Base class for barcode lookup plugins selected by `STOCK_BARCODE_LOOKUP_PLUGIN`. `plugins/DemoBarcodeLookupPlugin.php` is the example. |
| `Gtin.php` | Normalises GTIN, EAN and UPC barcodes by leading zeros and length. It does not validate the check digit. |
| `Grocycode.php` | Parses the `grcy:` label format. See [docs/grocycode.md](../docs/grocycode.md). |
| `CanonicalJson.php`, `ECanonicalizationFailed.php` | RFC 8785 JSON canonicalization for label document digests. Its tests are `.devtools/labels/canonical-json-tests.php`. |

## Constraints

- [AGENTS.md](../AGENTS.md) forbids user-configurable outbound URLs. `OutboundHostPolicy`
  applies a fixed policy to a URL that an external source supplies, so it adds no setting.
  Use it before fetching any URL that did not come from the deployment's own
  configuration.
- [ADR-0011](../docs/adr/0011-label-namespace.md): the application reads Grocycode and
  never emits it. New label payloads use `vctl:` identities from `services/Labels/`.
