# 09. Barcode lookup sources for US products

**Goal:** Barcode scanning that actually resolves products bought in the US.
**Depends on:** nothing. Independent of [04](04-seed-datasets.md), though it largely
removes the motivation for shipping barcode data there.
**Status:** deferred, [issue 80](https://github.com/datagen24/victual/issues/80); see
[Open questions](#open-questions).

> **Deferred, not cancelled.** This plan is parked pending Q1's experiment: roughly twenty
> real barcodes off the maintainer's own pantry, run against Open Food Facts and USDA
> FoodData Central (and ideally one commercial API), to tabulate actual hit rates. All five
> open questions below stay unanswered until that data exists. Nothing else in the roadmap
> depends on this plan, so it can sit here indefinitely with no effect on anything else.

## The problem

`STOCK_BARCODE_LOOKUP_PLUGIN` defaults to `OpenFoodFactsBarcodeLookupPlugin`. Open Food
Facts is crowdsourced, and its coverage reflects where its contributors are — strongest in
France, Germany and the UK. US coverage exists and is growing, but it is materially thinner
than in Europe, so a US household scans a lot of barcodes that return nothing.

## What the plugin contract actually requires

Low, which is the good news. `helpers/BaseBarcodeLookupPlugin.php` requires a subclass to
implement `ExecuteLookup($barcode)` returning `null` or an associative array with:

```
name, location_id, qu_id_purchase, qu_id_stock,
__qu_factor_purchase_to_stock, __barcode      (+ optional __image_url)
```

The base class is handed `$Locations`, `$QuantityUnits` and `$UserSettings`, so resolving
defaults is already solved. A new source is one self-contained file in `plugins/`.

## Candidate: USDA FoodData Central

The obvious US counterpart. Free with an API key, and its Branded Foods data comes from
manufacturer label submissions rather than crowdsourcing, so it is authoritative for
US packaged goods in a way OFF is not.

**One significant catch, and it dictates the implementation.** FDC has **no barcode lookup
endpoint**. Looking up a barcode means putting the GTIN into the full text search, and that
search is fuzzy — it will cheerfully return an unrelated product for a barcode it has never
seen. A naive plugin would therefore silently match the wrong product, which is worse than
returning nothing.

So the plugin must:

1. search with the barcode as the query, restricted to `dataType=Branded`
2. read `gtinUpc` off each result
3. **return a match only if `gtinUpc` equals the scanned barcode**, normalised for leading
   zeros and check digit length
4. return `null` otherwise

That verification step is the whole plugin. It is a handful of lines, and skipping it
reintroduces the wrong-product match described above.

Also worth noting FDC is nutrition-first: it does not carry product images, so
`__image_url` would be absent where OFF supplies one.

## Proposed change

### A. A USDA FoodData Central plugin

`plugins/UsdaFoodDataCentralBarcodeLookupPlugin.php`, with the verification above and an
API key from a new config setting. Self-contained, no changes to anything existing.

### B. A chaining plugin

`STOCK_BARCODE_LOOKUP_PLUGIN` takes exactly one plugin name. Rather than changing that
mechanism, add a plugin that *is* a chain:

```php
Setting('STOCK_BARCODE_LOOKUP_PLUGIN', 'ChainedBarcodeLookupPlugin');
Setting('STOCK_BARCODE_LOOKUP_CHAIN', ['UsdaFoodDataCentral', 'OpenFoodFacts']);
```

It calls each in order and returns the first non-null result. No change to the config
mechanism, the base class or the calling code — and it means a US household can put FDC
first and still fall back to OFF for imported goods, which is exactly the mixed reality of
a US pantry.

### C. Cache lookups (optional, but cheap)

Every scan of an unknown barcode is a network round trip, and a failed lookup costs the
same as a successful one. A small table of barcode to result, including negative results
with a TTL, would make repeat scans instant and reduce dependence on either service being
up. Worth doing only if scanning feels slow in practice — see Q4.

### API

None. Plugins are server side and configured, not exposed.

**Client impact: none on the wire, and one thing worth naming.** A client that scans a
barcode gets a *different product back* once a new source is enabled — better data, not a
contract change, but a change in behaviour that no manifest and no snapshot can see. Sweep
**S14** is this plan's inheritance and is the reason the surface is worth caring about at
all. Every source added is another party choosing `__image_url`, so the filename class,
the image extension allow-list and the refusal of loopback and private hosts land before
the first new source, not after.

## Relationship to [04 seed datasets](04-seed-datasets.md)

Plan 04 asks whether to ship barcode data with seed datasets. This plan is the argument
against: barcodes are regional, a wrong barcode is worse than none, and lookup at scan time
is both regionally correct and always current. Fixing lookup is the better investment.

## Open questions

1. **Is USDA FoodData Central the right US source?** It is free and authoritative for
   branded packaged goods, but it is a nutrition database that happens to carry GTINs, not a
   product database. Commercial barcode APIs exist and would resolve more consumer goods
   including non-food, at a cost and with a key. Worth a quick real-world test: take twenty
   barcodes off things actually in your pantry and see what each source resolves. That
   answers this better than any amount of reading.
2. **Chain order.** FDC first then OFF seems right for a US household, but if FDC's fuzzy
   search proves unreliable even with verification, OFF first is safer.
3. **Non-food items.** Neither source covers household goods well — cleaning products,
   batteries, toiletries. Open Products Facts is the OFF sibling for these and could be a
   third link in the chain.
4. **Cache lookups?** Only worth it if scanning is noticeably slow, and nothing here
   prevents adding it after the fact.
5. **What should happen on no match?** Today the product form opens with the barcode
   prefilled and nothing else. That is reasonable, and arguably a US user will hit it often
   enough that it is worth making the manual path pleasant rather than chasing perfect
   coverage.

## Effort

Small. The FDC plugin is a few hours including the verification logic; the chaining plugin
is an hour. The twenty barcode test in Q1 should come first — it is thirty minutes and it
decides whether this plan is worth doing at all or whether something commercial is needed.

## Sources

- [USDA FoodData Central API guide](https://calorieapi.com/blog/usda-fooddata-central-api-guide)
- [GBFPD documentation, USDA](https://fdc.nal.usda.gov/docs/GBFPD_Documentation_and_Download_User_Guide_Jan2024.pdf)
- [Open Food Facts data and API](https://world.openfoodfacts.org/data)
- [Open Products Facts](https://us.openproductsfacts.org/)
