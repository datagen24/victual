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
		// productId -> Map(ordering signature -> { locations, prices }) — the candidates a tie
		// left open. The candidate sets are recorded at the moment of the draw and kept
		// afterwards, because the lot the *model* drained may be the one the application
		// kept: an allowed-location set built only from what the model still holds would
		// reject the application's equally valid choice.
		this.ambiguous = new Map();
		// entryKey -> the key of the entry carrying its origin booking, mirroring the
		// application's `stock_entry_origins` (migration 0267). Only a partial open creates a
		// link, because only `OpenProduct` records one (StockService.php:1561) — a transferred
		// entry has no origin row in either place, and its edits are invisible to the average
		// in both. Kept apart from `entries` because an entry can be consumed away while the
		// bookings that need its lineage stay in the ledger.
		this.origins = new Map();
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

	// The simulated date every subsequent booking is made on.
	//
	// **Delivered events are timestamped, and until this existed nothing compared that.** A
	// `price_paid` point carries the booking's own `row_created_timestamp`, so a point with
	// the right product, price and amount sitting on the wrong day satisfied a multiset
	// comparison that only knew those three. The plan's day loop stamps the model as it
	// advances, which costs one call a day and makes the expected day part of the evidence.
	stampDay(date) {
		this.today = date;
	}

	book(type, productId, amount, extra = {}) {
		this.bookings.push({ type, productId, amount, day: this.today || null, ...extra });
	}

	purchase({ productId, amount, bbd, purchasedDate, locationId, price, type = 'purchase' }) {
		const entry = {
			key: this.nextEntryKey++,
			productId, amount, bbd, purchasedDate, locationId,
			// The price as given, null included. An earlier version coerced null to 0 after
			// one observed response, which generalised a single case into a rule: an unknown
			// price and an explicit zero can mean different things, and only one endpoint was
			// ever looked at. The equivalence now lives where it is justified — the valuation
			// comparison — and the representation difference is recorded as an observation
			// rather than modelled away. See narrative/prices.js.
			price: price === undefined ? null : price,
			open: 0
		};
		this.entries.push(entry);
		this.origins.set(entry.key, entry.key);
		// purchasedDate travels with the booking because the average-price and
		// price-history oracles are expressed in terms of the day the plan bought on, and
		// that day is client-supplied — it is the evidence that a year happened.
		//
		// `entryKey` travels with it because products_average_price treats an entry that was
		// later *edited* differently from one that was not, and an oracle that cannot say
		// which entry a booking created has to skip edited products rather than model them.
		this.book(type, productId, amount, { price, purchasedDate, entryKey: entry.key });
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
		// Which signatures had more than one lot at the moment of the draw: those are the
		// choices the application made arbitrarily.
		const pool = this.ordered(productId);
		const bySignature = this.tieCounts(pool);

		let left = amount;
		const touched = [];
		for (const entry of pool) {
			if (left <= 0) break;
			if ((bySignature.get(this.orderingSignature(entry)) || 0) > 1) {
				this.noteTie(productId, this.orderingSignature(entry), pool);
			}
			const take = Math.min(entry.amount, left);
			entry.amount -= take;
			left -= take;
			touched.push({ key: entry.key, amount: take, price: entry.price });
		}
		this.entries = this.entries.filter((e) => e.amount > 0);
		// Which entries this drew down, because the view's `edited_origin_amount` is the
		// edited amount plus whatever had already been consumed from that same entry.
		this.book('consume', productId, -amount, { spoiled, touched });
		return touched;
	}

	// Opening moves quantity into the opened state. Total is unchanged; a partial open
	// splits the entry, which is why the entry count can rise while the sum does not.
	open({ productId, amount }) {
		if (!this.canConsume(productId, amount)) {
			throw new Error(`ledger: open ${amount} of product ${productId} with ${this.amountOf(productId)} in stock`);
		}
		let left = amount;
		const pool = this.ordered(productId).filter((e) => !e.open);
		const bySignature = this.tieCounts(pool);
		for (const entry of pool) {
			if (left <= 0) break;
			if ((bySignature.get(this.orderingSignature(entry)) || 0) > 1) {
				this.noteTie(productId, this.orderingSignature(entry), pool);
			}
			if (entry.amount <= left) {
				entry.open = 1;
				left -= entry.amount;
			} else {
				// The remainder of a partial open: a new entry with no booking of its own,
				// which is why its lineage has to be recorded rather than inferred.
				const rest = this.nextEntryKey++;
				this.entries.push({ ...entry, key: rest, amount: entry.amount - left });
				this.origins.set(rest, this.origins.get(entry.key) || entry.key);
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
		const pool = this.ordered(productId).filter((e) => e.locationId === fromLocationId);
		const bySignature = this.tieCounts(pool);
		for (const entry of pool) {
			if (left <= 0) break;
			if ((bySignature.get(this.orderingSignature(entry)) || 0) > 1) {
				this.noteTie(productId, this.orderingSignature(entry), pool);
			}
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
		const pool = this.ordered(productId);
		const bySignature = this.tieCounts(pool);
		for (const entry of pool) {
			if (left <= 0) break;
			if ((bySignature.get(this.orderingSignature(entry)) || 0) > 1) {
				this.noteTie(productId, this.orderingSignature(entry), pool);
			}
			const take = Math.min(entry.amount, left);
			entry.amount -= take;
			left -= take;
		}
		this.entries = this.entries.filter((e) => e.amount > 0);
		this.book('inventory-correction', productId, delta);
		return delta;
	}

	// The ordering key `stock_next_use` sorts on.
	//
	// **A transfer routinely creates a pair sharing it.** It splits one lot in two that share
	// a best-before date, a purchased date and a price, differing only by location — and
	// location only enters the ordering through
	// `CASE WHEN COALESCE(default_consume_location_id, -1) = location_id THEN 0 ELSE 1 END`,
	// which is equal for both unless one of them sits at the product's default consume
	// location. The remaining terms are then equal, `ROW_NUMBER()` picks arbitrarily, and
	// which of the two a consume draws from is genuinely undefined. A year run reaching day
	// 169 is what established this — butter, transferred fridge to freezer, then consumed
	// from the half the model had not picked.
	orderingSignature(entry) {
		const meta = this.products.get(entry.productId) || { defaultConsumeLocationId: null };
		const def = meta.defaultConsumeLocationId === null ? -1 : meta.defaultConsumeLocationId;
		return [entry.locationId === def ? 0 : 1, entry.open ? 1 : 0, entry.bbd, entry.purchasedDate].join('|');
	}

	// How many lots in a draw pool share each ordering key. More than one means the
	// application's choice between them is arbitrary.
	tieCounts(pool) {
		const counts = new Map();
		for (const e of pool) {
			const sig = this.orderingSignature(e);
			counts.set(sig, (counts.get(sig) || 0) + 1);
		}
		return counts;
	}

	// Records that a draw was made from a tied set, together with the locations and prices
	// that set could have left behind — captured now, while every candidate is still live.
	noteTie(productId, sig, pool) {
		if (!this.ambiguous.has(productId)) this.ambiguous.set(productId, new Map());
		const byProduct = this.ambiguous.get(productId);
		if (!byProduct.has(sig)) byProduct.set(sig, { locations: new Set(), prices: new Set() });
		const rec = byProduct.get(sig);
		for (const e of pool) {
			if (this.orderingSignature(e) !== sig) continue;
			rec.locations.add(e.locationId);
			rec.prices.add(e.price === undefined ? null : e.price);
		}
	}

	// Signatures whose per-lot split the model can no longer vouch for, because a draw was
	// made from a set of tied lots and the application's choice between them is arbitrary.
	//
	// **The ambiguity is carried, not resolved.** The model keeps an internal split so it can
	// go on planning satisfiable operations, but it never asserts that split and never reads
	// the application's choice back to adopt it — doing either would quietly invent the
	// deterministic tie-break the application does not have. What stays exactly constrained
	// is everything the tie does not touch: the amount removed, the product total, the group's
	// own total, and the set of locations the group may occupy.
	ambiguousSignatures(productId) {
		const live = new Set();
		for (const e of this.entries) {
			if (e.productId === productId && e.amount > 0) live.add(this.orderingSignature(e));
		}
		return [...(this.ambiguous.get(productId) || new Map())].filter(([sig]) => live.has(sig));
	}

	// What the model is willing to assert about a product's lots, split into the part a tie
	// leaves determined and the part it does not.
	//
	// **The point is to narrow the expectation, not to drop it.** An earlier version emitted
	// no assertion at all once a product held tied lots, which gave up the amount removed,
	// the product total, each group's own total and the set of valid locations — all still
	// exactly determined — in order to accommodate the one thing that is not: which member of
	// the tie shrank. Everything outside the tie stays exact; inside it, the group is
	// compared against the outcomes the application permits.
	//
	// The model's own split within a group is deliberately not published. Reading the
	// application's choice back and adopting it would introduce a deterministic tie-break the
	// application does not define, and quietly make every later assertion agree with whatever
	// the build under test happened to do.
	lotExpectation(productId) {
		const ambiguous = new Map(this.ambiguousSignatures(productId));
		const exact = [];
		const groups = new Map();
		for (const e of this.entries) {
			if (e.productId !== productId || e.amount <= 0) continue;
			const sig = this.orderingSignature(e);
			const row = {
				amount: e.amount,
				best_before_date: e.bbd,
				purchased_date: e.purchasedDate,
				price: e.price === undefined ? null : e.price,
				open: e.open ? 1 : 0,
				location_id: e.locationId
			};
			if (!ambiguous.has(sig)) { exact.push(row); continue; }
			if (!groups.has(sig)) {
				const candidates = ambiguous.get(sig);
				groups.set(sig, {
					where: { best_before_date: row.best_before_date, purchased_date: row.purchased_date, open: row.open },
					total: 0,
					locations: [...candidates.locations],
					prices: [...candidates.prices]
				});
			}
			const g = groups.get(sig);
			g.total += row.amount;
			if (!g.locations.includes(row.location_id)) g.locations.push(row.location_id);
			if (!g.prices.some((p) => p === row.price)) g.prices.push(row.price);
		}
		return { exact, groups: [...groups.values()] };
	}

	entryByKey(key) {
		return this.entries.find((e) => e.key === key);
	}

	// **Undoing a purchase deletes the entry that purchase created — it is not a consume.**
	//
	// The model used to follow an undo with `consume(amount)`, which removes the same total
	// from whichever lots `stock_next_use` puts first. That agrees on every total and
	// disagrees on which lot survives, so 274 days of ledger, log, position and average-price
	// invariants all passed while the model held a lot the application had deleted. The lot
	// assertion is what found it, on the first year run after it was added.
	//
	// `UndoBooking` deletes every `stock` row with the booking's `stock_id`
	// (services/StockService.php:2078) and refuses outright when that entry has later
	// bookings against it (`:2064`), so the model removes the entry whole and only when it is
	// whole.
	undoPurchase(entryKey) {
		const entry = this.entryByKey(entryKey);
		if (!entry) return null;
		this.entries = this.entries.filter((e) => e.key !== entryKey);
		return entry.amount;
	}

	// Every (product, location) the model currently holds stock at.
	//
	// **This is what a transfer changes, and nothing else the suite asserts would notice.**
	// A transfer books a from/to pair summing to zero and leaves the product's total
	// untouched, so `rowsSum: 0` and an unchanged total are equally true of a transfer that
	// moved the right amount and one that moved nothing at all. Only the position says which.
	locationAmounts() {
		const out = new Map();
		for (const e of this.entries) {
			if (e.amount <= 0) continue;
			const key = `${e.productId}@${e.locationId}`;
			out.set(key, (out.get(key) || 0) + e.amount);
		}
		return [...out].map(([key, amount]) => {
			const [productId, locationId] = key.split('@');
			return { productId, locationId, amount };
		});
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
