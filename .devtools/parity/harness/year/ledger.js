'use strict';

// A shadow model of stock, kept by the generator as it emits.
//
// It exists for two reasons, and the first is what makes the year possible at all.
//
// **Nothing may be emitted that the application would refuse.** A consume for 3 against 2
// in stock is a 400 on both instances — the same status, so no *difference*, so a parity
// run would report agreement over an operation that silently did nothing. Multiply that by
// a year and the suite is green over a database that stopped changing in March. So every
// consume, open, transfer and inventory is checked against this model before it is written
// into the plan, and the model is advanced as if it had run.
//
// **And then it is an oracle.** Because it tracked the whole year independently of the
// application, "what should the stock be" has an answer that was not read back out of the
// database being tested. Upstream agreement and a captured baseline both detect *change*;
// neither detects *wrongness*, and this is what does.
//
// The ordering below is `stock_next_use`'s, copied deliberately rather than approximated
// (db/pgsql/baseline/03_views_group3.sql):
//
//     ORDER BY CASE WHEN COALESCE(p.default_consume_location_id, -1) = s.location_id
//                   THEN 0 ELSE 1 END ASC,
//              s.open DESC, s.best_before_date ASC, s.purchased_date ASC
//
// **There is no total tie-break in it**, and that is a property of the application, not an
// omission here. Two unopened entries of one product at one location with the same
// best-before *and* the same purchased date are tied, and `ROW_NUMBER()` picks between them
// arbitrarily — the two engines may pick differently, and the price on the resulting
// booking then differs. The generator's rule is therefore never to create such a tie by
// accident (see plan.js), and to create exactly one on purpose, late and in isolation, so
// the hazard is exercised once and legibly instead of two hundred times as noise.

// Transaction types that change stock, and how each contributes. Mirrors the executable
// form of the ledger identity: `stock_log.amount` is signed for consume and transfer_from,
// `product-opened` carries a positive amount but adds nothing, and `stock-edit-old` carries
// the *old* amount positively and has to be subtracted.
const CONTRIBUTION = {
	'purchase': 1,
	'self-production': 1,
	'inventory-correction': 1,   // already signed
	'consume': 1,                // already signed (negative)
	'transfer_from': 1,          // already signed (negative)
	'transfer_to': 1,
	'stock-edit-new': 1,
	'stock-edit-old': -1,
	'product-opened': 0
};

class Ledger {
	constructor() {
		this.entries = [];        // live stock rows
		this.bookings = [];       // every stock_log row this year should produce
		this.nextEntryKey = 1;
		this.products = new Map();  // productId -> { defaultConsumeLocationId }
	}

	defineProduct(productId, { defaultConsumeLocationId = null } = {}) {
		this.products.set(productId, { defaultConsumeLocationId });
	}

	// stock_next_use's ordering, for one product. Returned best-first.
	ordered(productId) {
		const meta = this.products.get(productId) || { defaultConsumeLocationId: null };
		const def = meta.defaultConsumeLocationId === null ? -1 : meta.defaultConsumeLocationId;
		return this.entries
			.filter((e) => e.productId === productId && e.amount > 0)
			.sort((a, b) => {
				const al = a.locationId === def ? 0 : 1;
				const bl = b.locationId === def ? 0 : 1;
				if (al !== bl) return al - bl;
				if (a.open !== b.open) return b.open - a.open;          // open DESC
				if (a.bbd !== b.bbd) return String(a.bbd).localeCompare(String(b.bbd));
				if (a.purchasedDate !== b.purchasedDate) return String(a.purchasedDate).localeCompare(String(b.purchasedDate));
				// Not the application's rule — it has none here. Only so that *this* model is
				// deterministic; where it matters, the generator refuses to build the tie.
				return a.key - b.key;
			});
	}

	amountOf(productId) {
		return this.entries
			.filter((e) => e.productId === productId)
			.reduce((n, e) => n + e.amount, 0);
	}

	openAmountOf(productId) {
		return this.entries
			.filter((e) => e.productId === productId && e.open)
			.reduce((n, e) => n + e.amount, 0);
	}

	amountAtLocation(productId, locationId) {
		return this.entries
			.filter((e) => e.productId === productId && e.locationId === locationId)
			.reduce((n, e) => n + e.amount, 0);
	}

	// Is there an entry of this product on this simulated date with this best-before? The
	// generator asks before adding, because two of those is the untie-breakable case.
	hasEntryWith(productId, bbd, purchasedDate) {
		return this.entries.some((e) =>
			e.productId === productId && e.bbd === bbd && e.purchasedDate === purchasedDate);
	}

	book(type, productId, amount, extra = {}) {
		this.bookings.push({ type, productId, amount, ...extra });
	}

	purchase({ productId, amount, bbd, purchasedDate, locationId, price, type = 'purchase' }) {
		const entry = {
			key: this.nextEntryKey++,
			productId, amount, bbd, purchasedDate, locationId, price,
			open: 0
		};
		this.entries.push(entry);
		// purchasedDate travels with the booking because the average-price and
		// price-history oracles are expressed in terms of the day the plan bought on, and
		// that day is client-supplied — it is the evidence that a year happened.
		this.book(type, productId, amount, { price, purchasedDate });
		return entry;
	}

	canConsume(productId, amount) {
		return amount > 0 && this.amountOf(productId) >= amount;
	}

	// Draws down in stock_next_use order. Returns the entries touched, so an emitter can
	// name what it expects the booking to have hit.
	consume({ productId, amount, spoiled = false }) {
		if (!this.canConsume(productId, amount)) {
			throw new Error(`ledger: consume ${amount} of product ${productId} with ${this.amountOf(productId)} in stock`);
		}
		let left = amount;
		const touched = [];
		for (const entry of this.ordered(productId)) {
			if (left <= 0) break;
			const take = Math.min(entry.amount, left);
			entry.amount -= take;
			left -= take;
			touched.push({ key: entry.key, amount: take, price: entry.price });
		}
		this.entries = this.entries.filter((e) => e.amount > 0);
		this.book('consume', productId, -amount, { spoiled });
		return touched;
	}

	// Opening moves quantity into the opened state. Total is unchanged; a partial open
	// splits the entry, which is why the entry count can rise while the sum does not.
	open({ productId, amount }) {
		if (!this.canConsume(productId, amount)) {
			throw new Error(`ledger: open ${amount} of product ${productId} with ${this.amountOf(productId)} in stock`);
		}
		let left = amount;
		for (const entry of this.ordered(productId).filter((e) => !e.open)) {
			if (left <= 0) break;
			if (entry.amount <= left) {
				entry.open = 1;
				left -= entry.amount;
			} else {
				this.entries.push({ ...entry, key: this.nextEntryKey++, amount: entry.amount - left });
				entry.amount = left;
				entry.open = 1;
				left = 0;
			}
		}
		this.book('product-opened', productId, amount);
		return amount - left;
	}

	transfer({ productId, amount, fromLocationId, toLocationId, bbdAfterFreezing = null }) {
		if (this.amountAtLocation(productId, fromLocationId) < amount) {
			throw new Error(`ledger: transfer ${amount} of product ${productId} from location ${fromLocationId}`);
		}
		let left = amount;
		for (const entry of this.ordered(productId).filter((e) => e.locationId === fromLocationId)) {
			if (left <= 0) break;
			const take = Math.min(entry.amount, left);
			entry.amount -= take;
			left -= take;
			this.entries.push({
				...entry,
				key: this.nextEntryKey++,
				amount: take,
				locationId: toLocationId,
				bbd: bbdAfterFreezing || entry.bbd
			});
		}
		this.entries = this.entries.filter((e) => e.amount > 0);
		this.book('transfer_from', productId, -amount);
		this.book('transfer_to', productId, amount);
	}

	// Sets the absolute amount. Up is an addition, down consumes in stock_next_use order —
	// which is what the application does, and why the model has to and not just track a
	// total.
	inventory({ productId, newAmount, bbd, purchasedDate, locationId, price }) {
		const current = this.amountOf(productId);
		const delta = newAmount - current;
		if (delta === 0) return 0;
		if (delta > 0) {
			this.purchase({ productId, amount: delta, bbd, purchasedDate, locationId, price, type: 'inventory-correction' });
			// purchase() already booked it as inventory-correction
			return delta;
		}
		let left = -delta;
		for (const entry of this.ordered(productId)) {
			if (left <= 0) break;
			const take = Math.min(entry.amount, left);
			entry.amount -= take;
			left -= take;
		}
		this.entries = this.entries.filter((e) => e.amount > 0);
		this.book('inventory-correction', productId, delta);
		return delta;
	}

	// The expected value of the executable ledger identity, per product, from the bookings
	// this model recorded. invariants.js compares this against the live instance.
	expectedAmounts() {
		const out = new Map();
		for (const b of this.bookings) {
			// `WHERE undone = 0`, and it is not a detail: an undone booking keeps its row and
			// its audit trail but stops counting toward stock. Summing them anyway is what
			// the first year-profile build did, and selfCheck() caught it as 11 undone
			// purchases' worth of phantom pasta.
			if (b.undone) continue;
			const c = CONTRIBUTION[b.type];
			if (c === undefined) throw new Error(`ledger: unknown transaction type ${b.type}`);
			out.set(b.productId, (out.get(b.productId) || 0) + c * b.amount);
		}
		return out;
	}

	// Self-check, run at the end of generation. A model that disagrees with itself would
	// produce a plan whose expectations are wrong in the same direction as its operations,
	// which is the one failure a shadow oracle must not have.
	selfCheck() {
		const problems = [];
		for (const e of this.entries) {
			if (e.amount <= 0) problems.push(`entry ${e.key} of product ${e.productId} has amount ${e.amount}`);
		}
		const expected = this.expectedAmounts();
		const productIds = new Set([...expected.keys(), ...this.entries.map((e) => e.productId)]);
		for (const pid of productIds) {
			const live = this.amountOf(pid);
			const want = expected.get(pid) || 0;
			if (Math.abs(live - want) > 1e-9) {
				problems.push(`product ${pid}: entries sum to ${live}, bookings imply ${want}`);
			}
		}
		return problems;
	}
}

module.exports = { Ledger, CONTRIBUTION };
