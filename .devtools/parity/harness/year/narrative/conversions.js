'use strict';

// **The one place a quantity-unit conversion factor moves stock.**
//
// The coverage table used to say conversions could be exercised by "purchasing in the
// purchase unit". They cannot: `AddProduct` (services/StockService.php:211) applies no
// purchase-to-stock conversion at any point, so the `amount` on `/stock/products/{id}/add` is
// in the *stock* unit and always was. Sending 1 for a 500 g pack books one gram.
//
// The factor is read in three places and only one of them changes what a booking removes:
// sub-product substitution (`:620-623`, `:658`, `:1504`). Consuming a *parent* product with
// `allow_subproduct_substitution` draws on its children's stock, converting the requested
// amount from the parent's stock unit into each child's:
//
//     conversion = quantity_unit_conversions_resolved
//                    WHERE product_id = <the child>
//                      AND from_qu_id = <the parent's stock unit>
//                      AND to_qu_id   = <the child's stock unit>
//     amount = amount * conversion.factor
//
// So the assertion is `rowsSum` on the resulting booking: ask for 1 pack, expect 500 grams
// removed. **A wrong factor changes that number and nothing else in the suite would notice** —
// the totals, the positions and the prices are all consistent with whatever the factor says.
//
// This is a fixture rather than a thread through the year on purpose. It needs a parent/child
// pair the household does not otherwise have, and the substitution consume must draw on a
// single known lot: `GetProductStockEntries` selects from `stock_next_use`, which has no
// ORDER BY of its own, so with two candidate lots the row order — and therefore which lot a
// factor is applied to — is not determined by anything the application states.

const { call, arrange, bookingRows, verifyStock, CREATED } = require('../ops');

const PARENT = { key: 'coffeeany', name: 'Y Coffee (any)', qu: 'pack' };
const CHILD = { key: 'coffeebeans', name: 'Y Coffee beans', qu: 'gram' };
const FACTOR = 500;          // 1 pack = 500 g
const STOCKED = 1000;        // two packs' worth, in the child's own unit

function conversionProbe({ ctx, ops }) {
	const { cal, ledger, sym, world } = ctx;
	const day = cal.days - 1;
	const purchasedDate = cal.date(day);
	const bbd = cal.dateOffset(day, 400);
	const group = world.productGroups[0].key;
	const loc = 'pantry';

	ops.push({ op: 'mark', note: 'isolated: quantity-unit conversion via sub-product substitution', day });

	// The parent holds no stock of its own. Purchase unit equals stock unit on both, so the
	// application does not create a conversion row of its own that this one would collide
	// with — the pack->gram row below is the only override in play.
	ops.push(arrange({
		method: 'POST', path: '/objects/products',
		body: {
			name: PARENT.name,
			product_group_id: `{productGroup:${group}}`,
			location_id: `{location:${loc}}`,
			qu_id_stock: `{quantityUnit:${PARENT.qu}}`,
			qu_id_purchase: `{quantityUnit:${PARENT.qu}}`,
			min_stock_amount: 0
		},
		expect: CREATED,
		bind: { [`product:${PARENT.key}`]: 'created_object_id' },
		label: `conversion probe: ${PARENT.name}, the parent`
	}));

	ops.push(arrange({
		method: 'POST', path: '/objects/products',
		body: {
			name: CHILD.name,
			parent_product_id: `{product:${PARENT.key}}`,
			product_group_id: `{productGroup:${group}}`,
			location_id: `{location:${loc}}`,
			qu_id_stock: `{quantityUnit:${CHILD.qu}}`,
			qu_id_purchase: `{quantityUnit:${CHILD.qu}}`,
			min_stock_amount: 0
		},
		expect: CREATED,
		bind: { [`product:${CHILD.key}`]: 'created_object_id' },
		label: `conversion probe: ${CHILD.name}, the child`
	}));

	// The factor the substitution will apply, keyed exactly as the lookup expects: the row
	// belongs to the *child*, and converts from the parent's stock unit to the child's.
	ops.push(arrange({
		method: 'POST', path: '/objects/quantity_unit_conversions',
		body: {
			product_id: `{product:${CHILD.key}}`,
			from_qu_id: `{quantityUnit:${PARENT.qu}}`,
			to_qu_id: `{quantityUnit:${CHILD.qu}}`,
			factor: FACTOR
		},
		expect: CREATED,
		bind: { 'conversion:coffee': 'created_object_id' },
		label: `conversion probe: 1 ${PARENT.qu} = ${FACTOR} ${CHILD.qu} for ${CHILD.name}`
	}));

	// **The factor, read back before anything depends on it.** A conversion row that was
	// written wrong would otherwise surface only as a booking of the wrong size, which is a
	// worse diagnosis than "the row says the wrong thing".
	ops.push(call({
		method: 'GET',
		path: `/objects/quantity_unit_conversions?query%5B%5D=product_id%3D{product:${CHILD.key}}&order=id:asc`,
		expect: {
			status: 200, kind: 'array', length: 1,
			rowShape: ['id', 'product_id', 'from_qu_id', 'to_qu_id', 'factor'],
			rowEquals: { factor: FACTOR }
		},
		window: cal.dayWindow(day),
		label: `conversion probe: the stored factor is ${FACTOR}`
	}));

	ledger.defineProduct(sym.product(CHILD.key), { defaultConsumeLocationId: null });

	ops.push(call({
		method: 'POST', path: `/stock/products/{product:${CHILD.key}}/add`,
		body: {
			amount: STOCKED, price: 12.5,
			best_before_date: bbd, purchased_date: purchasedDate,
			transaction_type: 'purchase',
			location_id: `{location:${loc}}`
		},
		expect: bookingRows({ transactionType: 'purchase', extra: { amount: STOCKED } }),
		window: cal.dayWindow(day),
		label: `conversion probe: buy ${STOCKED} ${CHILD.qu} of ${CHILD.name}`
	}));
	ledger.purchase({
		productId: sym.product(CHILD.key), amount: STOCKED, bbd, purchasedDate,
		locationId: sym.location(loc), price: 12.5
	});

	// **The parent reports its children's stock, unconverted.** `stock_current`'s
	// `amount_aggregated` is a plain `SUM(amount)` over the sub-products (migration 0081), so
	// the parent shows 1000 — grams — even though its own stock unit is packs. That is worth
	// pinning: it is surprising, it is what the amount check inside ConsumeProduct compares
	// against, and a change to it would alter which consumes the application accepts.
	ops.push(call({
		method: 'GET', path: `/stock/products/{product:${PARENT.key}}`,
		expect: {
			status: 200, kind: 'object',
			equals: { stock_amount_aggregated: STOCKED }
		},
		window: cal.dayWindow(day),
		// `stock_amount` for a parent holding nothing of its own is deliberately not asserted:
		// migration 0081 wraps it in IFNULL(..., 0) but nothing establishes what the API layer
		// makes of that, and the raw value stays in the trace where it can be read.
		label: `conversion probe: ${PARENT.name} aggregates ${STOCKED} from its child`
	}));

	// The operation the fixture exists for. One pack asked of the parent, `FACTOR` grams
	// removed from the child — and `rowsSum` is what says so.
	const packs = 1;
	ops.push(call({
		method: 'POST', path: `/stock/products/{product:${PARENT.key}}/consume`,
		body: { amount: packs, allow_subproduct_substitution: true },
		expect: bookingRows({
			transactionType: 'consume',
			rowsSum: -(packs * FACTOR),
			extra: { product_id: `{product:${CHILD.key}}` }
		}),
		window: cal.dayWindow(day),
		label: `conversion probe: consume ${packs} ${PARENT.qu} of the parent ` +
			`=> ${packs * FACTOR} ${CHILD.qu} of the child`
	}));
	ledger.consume({ productId: sym.product(CHILD.key), amount: packs * FACTOR });

	ops.push(verifyStock({
		productKey: CHILD.key,
		amount: ledger.amountOf(sym.product(CHILD.key)),
		window: cal.dayWindow(day),
		label: `conversion probe: ${CHILD.name} should hold ${ledger.amountOf(sym.product(CHILD.key))}`
	}));

	// A fractional request, because a factor that were being rounded or integer-divided would
	// survive the whole-pack case and fail here.
	const half = 0.5;
	ops.push(call({
		method: 'POST', path: `/stock/products/{product:${PARENT.key}}/consume`,
		body: { amount: half, allow_subproduct_substitution: true },
		expect: bookingRows({
			transactionType: 'consume',
			rowsSum: -(half * FACTOR),
			extra: { product_id: `{product:${CHILD.key}}` }
		}),
		window: cal.dayWindow(day),
		label: `conversion probe: consume ${half} ${PARENT.qu} => ${half * FACTOR} ${CHILD.qu}`
	}));
	ledger.consume({ productId: sym.product(CHILD.key), amount: half * FACTOR });

	ops.push(verifyStock({
		productKey: CHILD.key,
		amount: ledger.amountOf(sym.product(CHILD.key)),
		window: cal.dayWindow(day),
		label: `conversion probe: ${CHILD.name} should hold ${ledger.amountOf(sym.product(CHILD.key))}`
	}));
}

module.exports = { conversionProbe, FACTOR, PARENT, CHILD };
