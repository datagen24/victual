# Barcodes and scanning

## Scanning hardware

A USB barcode laser scanner works considerably better than a phone camera — faster, in any
lighting, from any angle — and is a keyboard-wedge device: it types the barcode followed by
`Enter`, with no driver needed. It works best when configured to prefix every scan with a
character that is not normally the start of an item name (`$` works well) and to send `Tab`
after the scan, so the browser can tell a scan apart from typing. Test a new scanner's
behavior against **`/barcodescannertesting`** before relying on it elsewhere.

Any field carrying the barcode icon also accepts a scan from the device camera (client-side,
via [ZXing](https://github.com/zxing-js/library)); this needs `https://` due to browser
security restrictions, and can be disabled with
`FEATURE_FLAG_DISABLE_BROWSER_BARCODE_CAMERA_SCANNING` — see
[Configuration](../configuration.md#feature-settings).

## Looking a product up by barcode

The product picker's "External barcode lookup" workflow queries an external barcode
database and offers to create a matching product. `STOCK_BARCODE_LOOKUP_PLUGIN`
([Configuration](../configuration.md#barcode-lookup)) names which plugin does the lookup;
the built-in one queries [Open Food Facts](https://world.openfoodfacts.org/), whose US
product coverage is thin — see
[plan 09](https://github.com/datagen24/victual/blob/master/docs/plans/09-barcode-lookup-sources.md)
for what a better US source would need. `plugins/DemoBarcodeLookupPlugin.php` is a commented
example for writing your own.

## Grocycode

Grocycode is grocy's own barcode format for referring to a specific product, stock entry,
chore, battery or recipe from a printed label — scanning one on the consume or transfer page
selects that exact entry rather than merely that product. `GROCYCODE_TYPE`
([Configuration](../configuration.md#grocycode)) chooses between a 1D (Code128) and 2D
(DataMatrix) symbol. See [grocycode](../../grocycode.md) for the payload format
itself, and its warning about what stays read-only in this fork.

## Resolving a label

`GET /api/labels/resolve/{code}` looks a scanned code up against whichever payload format it
matches — Victual's own opaque label identities, and grocy's `grcy:` Grocycode, which this
fork continues to read but never mints. **`/locationlabels`**
([Stock](../using-victual/stock.md#labels)) is the stateless page built on the same lookup,
for checking what a label resolves to without booking anything against it.
