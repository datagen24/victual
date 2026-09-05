'use strict';

// Building a year, as data, before anything is sent anywhere.
//
// The whole plan is materialised first and then replayed against each instance, and that
// ordering is what buys the two properties the year phase rests on. Trace-length equality
// becomes structural — replay.js iterates a fixed array rather than running control flow
// twice, so a scenario *cannot* branch differently on one side. And the year becomes
// reviewable: `parity year --plan-only` writes it out, and what a run is about to do can be
// read before a container is started.
//
// Operations name rows by symbol (`{product:milk}`), never by id, because ids are allocated
// by each server. replay.js substitutes from a per-instance table and writes ids back into
// it; it never compares or tests one, which is what keeps the substitution from becoming a
// branch.

const crypto = require('crypto');

const { worldFor, PROFILES } = require('./world');
const { makeCalendar } = require('./calendar');
const { Ledger } = require('./ledger');
const { streamFor } = require('./rng');
const { call, arrange, auth, mark, CREATED, verifyStock } = require('./ops');
const checkpoints = require('./checkpoints');

const groceries = require('./narrative/groceries');
const cooking = require('./narrative/cooking');
const household = require('./narrative/household');
const shopping = require('./narrative/shopping');
const audit = require('./narrative/audit');
const tare = require('./narrative/tare');

// Bumped when the generator's output changes on purpose. plan.lock.json is keyed by it, so
// a deliberate change to the narrative and an accidental one look different in review.
const GENERATOR_VERSION = 1;

// The canonical anchor: historical, and chosen so the span contains a 29 February. A
// floating anchor loses the leap day in three years out of four, and a year suite that only
// sometimes covers it is a year suite that does not.
const CANONICAL_ANCHOR = '2025-01-01';

// **Every fixture name is prefixed, because a virgin database is not an empty one.** The
// migrations seed rows of their own — `Fridge` among the locations and `Piece` among the
// quantity units on a database that has only just been migrated — and `locations.name` is
// UNIQUE, so an unprefixed fixture collides with them. The first replay stopped on exactly
// that: "Pantry" was created, because nothing seeds it, and "Fridge" was rejected two
// operations later. `10-entities.js` prefixes its names for the same reason.
//
// The prefix lives here rather than in world.js so that file stays a readable description of
// a household rather than of a test fixture.
const FIXTURE_PREFIX = 'Y ';
const named = (name) => `${FIXTURE_PREFIX}${name}`;

// --- The fixture ---------------------------------------------------------------------------

// The household, emitted as `arrange` operations: executed and checked, but not recorded.
// Recording them would duplicate what the `entities` and `stock` scenarios already compare,
// at a couple of hundred steps, and it is exactly the region where a rejected insert would
// drift the two id sequences apart — which is a generator fault rather than a finding about
// the fork, and should be reported as one.
//
// `silently()` is not blind, though: the runner records one synthetic step afterwards
// listing every bound symbol and the id it got, so a create that failed on one side surfaces
// immediately rather than as an unexplained 404 four thousand steps later.
function emitFixture(ctx, ops) {
	const { world } = ctx;

	const simple = (entity, key, kind, body) => ops.push(arrange({
		method: 'POST', path: `/objects/${entity}`, body,
		expect: CREATED,
		bind: { [`${kind}:${key}`]: 'created_object_id' },
		label: `fixture: ${entity} ${key}`
	}));

	for (const l of world.locations) simple('locations', l.key, 'location', { name: named(l.name), is_freezer: l.is_freezer || 0 });
	for (const s of world.shoppingLocations) simple('shopping_locations', s.key, 'shoppingLocation', { name: named(s.name) });
	for (const g of world.productGroups) simple('product_groups', g.key, 'productGroup', { name: named(g.name) });
	for (const q of world.quantityUnits) simple('quantity_units', q.key, 'quantityUnit', { name: named(q.name), name_plural: named(q.name_plural) });
	for (const c of world.taskCategories) simple('task_categories', c.key, 'taskCategory', { name: named(c.name) });
	for (const s of world.mealPlanSections) simple('meal_plan_sections', s.key, 'mealPlanSection', { name: named(s.name), sort_number: s.sort_number });

	for (const conv of world.quantityUnitConversions) {
		ops.push(arrange({
			method: 'POST', path: '/objects/quantity_unit_conversions',
			body: {
				from_qu_id: `{quantityUnit:${conv.from}}`,
				to_qu_id: `{quantityUnit:${conv.to}}`,
				factor: conv.factor
			},
			expect: CREATED,
			bind: { [`conversion:${conv.from}:${conv.to}`]: 'created_object_id' },
			label: `fixture: conversion ${conv.from}->${conv.to}`
		}));
	}

	// **Users are not a generic-CRUD entity**, so this is not `/objects/users` — that answers
	// "Entity does not exist or is not exposed", which is where the second replay stopped.
	// `POST /users` is the route and it returns 204 (UsersApiController:48-51), so the ids
	// have to come from a list afterwards, matched by username rather than by position.
	for (const u of world.users) {
		ops.push(arrange({
			method: 'POST', path: '/users',
			body: { username: u.username, first_name: u.display, last_name: 'Year', password: 'parity-year' },
			expect: { status: 204 },
			label: `fixture: user ${u.username}`
		}));
	}
	ops.push(arrange({
		method: 'GET', path: '/users',
		expect: { status: 200, kind: 'array', minLength: world.users.length },
		bind: Object.fromEntries(world.users.map((u) => [`user:${u.key}`, `find(username=${u.username}).id`])),
		label: 'fixture: the users, by username'
	}));

	// **And they need permissions, or the weeks that run as them do nothing.** A user created
	// through POST /users has none, so the first week that switched session answered 403
	// "Permission missing: SHOPPINGLIST_ITEMS_ADD" — which is correct behaviour and a fixture
	// that had not finished setting itself up. ADMIN is the root of the permission tree, so
	// granting it is granting everything; its id is read rather than assumed to be 1.
	ops.push(arrange({
		method: 'GET', path: '/objects/permission_hierarchy?order=id:asc',
		expect: { status: 200, kind: 'array', minLength: 1 },
		bind: { 'permission:admin': 'find(name=ADMIN).id' },
		label: 'fixture: the ADMIN permission'
	}));
	for (const u of world.users) {
		ops.push(arrange({
			method: 'PUT', path: `/users/{user:${u.key}}/permissions`,
			body: { permissions: ['{permission:admin}'] },
			expect: { status: 204 },
			label: `fixture: ${u.username} may use the household`
		}));
	}

	for (const p of world.products) {
		const body = {
			name: named(p.name),
			product_group_id: `{productGroup:${p.group}}`,
			location_id: `{location:${p.loc}}`,
			qu_id_stock: `{quantityUnit:${p.qu}}`,
			qu_id_purchase: `{quantityUnit:${p.quPurchase || p.qu}}`,
			min_stock_amount: p.min
		};
		if (p.shelfLife !== null) body.default_best_before_days = p.shelfLife;
		if (p.freezeBonus) body.default_best_before_days_after_freezing = p.freezeBonus;
		if (p.consumeAt) body.default_consume_location_id = `{location:${p.consumeAt}}`;
		if (p.tare) { body.enable_tare_weight_handling = 1; body.tare_weight = p.tare; }
		simple('products', p.key, 'product', body);
	}

	// **Product-specific conversions are edited, not created.** When a product's purchase
	// and stock units differ and no global conversion resolves between them, the application
	// creates one itself with a factor of 1 — Y Bread arrived with piece->slice already
	// there. Posting the same triple then collides, which is where the fourth replay stopped.
	// So the row the application made is found by the unit the fixture allocated and given
	// its real factor.
	for (const p of world.products.filter((x) => x.conversion)) {
		ops.push(arrange({
			method: 'GET',
			path: `/objects/quantity_unit_conversions?query%5B%5D=product_id%3D{product:${p.key}}&order=id:asc`,
			expect: { status: 200, kind: 'array', minLength: 1 },
			bind: { [`conversion:${p.key}`]: `find(from_qu_id={quantityUnit:${p.quPurchase}}).id` },
			label: `fixture: find ${p.name}'s ${p.quPurchase}->${p.qu} conversion`
		}));
		ops.push(arrange({
			method: 'PUT', path: `/objects/quantity_unit_conversions/{conversion:${p.key}}`,
			body: { factor: p.conversion },
			expect: { status: 204 },
			label: `fixture: ${p.name} ${p.quPurchase}->${p.qu} = ${p.conversion}`
		}));
	}

	for (const b of world.barcodes) {
		const body = { product_id: `{product:${b.product}}`, barcode: b.barcode };
		if (b.amount) body.amount = b.amount;
		if (b.shoppingLocation) body.shopping_location_id = `{shoppingLocation:${b.shoppingLocation}}`;
		ops.push(arrange({
			method: 'POST', path: '/objects/product_barcodes', body,
			expect: CREATED,
			bind: { [`barcode:${b.barcode}`]: 'created_object_id' },
			label: `fixture: barcode ${b.barcode}`
		}));
	}

	for (const r of world.recipes) {
		ops.push(arrange({
			method: 'POST', path: '/objects/recipes',
			body: { name: named(r.name), base_servings: r.servings, desired_servings: r.servings },
			expect: CREATED,
			bind: { [`recipe:${r.key}`]: 'created_object_id' },
			label: `fixture: recipe ${r.name}`
		}));
	}
	for (const r of world.recipes) {
		for (const pos of r.positions || []) {
			ops.push(arrange({
				method: 'POST', path: '/objects/recipes_pos',
				body: {
					recipe_id: `{recipe:${r.key}}`,
					product_id: `{product:${pos.product}}`,
					amount: pos.amount,
					qu_id: `{quantityUnit:${(ctx.world.products.find((p) => p.key === pos.product) || {}).qu}}`,
					only_check_single_unit_in_stock: pos.only_check_single_unit_in_stock || 0
				},
				expect: CREATED,
				label: `fixture: ${r.name} needs ${pos.product}`
			}));
		}
		for (const nested of r.nests || []) {
			ops.push(arrange({
				method: 'POST', path: '/objects/recipes_nestings',
				body: { recipe_id: `{recipe:${r.key}}`, includes_recipe_id: `{recipe:${nested}}`, servings: 1 },
				expect: CREATED,
				label: `fixture: ${r.name} includes ${nested}`
			}));
		}
	}

	// **Chores that assign work need someone to assign it to.** ChoresService:70-79 guards
	// `count($assignedUsers) == 1` but not 0, so a `random` chore with nobody assigned
	// answers HTTP 500 with a raw `array_rand(): Argument #1 ($array) must not be empty` on
	// the first execution. The year found that on day 0 of its smallest profile. Assigning
	// the household is what a real chore does anyway, and it is what makes the rotation this
	// fixture exists to exercise actually rotate.
	const assignedHousehold = world.users.map((u) => `{user:${u.key}}`).join(',');
	for (const c of world.chores) {
		ops.push(arrange({
			method: 'POST', path: '/objects/chores',
			body: {
				name: named(c.name),
				period_type: c.period_type,
				period_days: c.period_days,
				assignment_type: c.assignment_type,
				assignment_config: c.assignment_type === 'no-assignment' ? null : assignedHousehold,
				track_date_only: 0,
				start_date: ctx.cal.date(0)
			},
			expect: CREATED,
			bind: { [`chore:${c.key}`]: 'created_object_id' },
			label: `fixture: chore ${c.name}`
		}));
	}

	for (const b of world.batteries) {
		ops.push(arrange({
			method: 'POST', path: '/objects/batteries',
			body: { name: named(b.name), charge_interval_days: b.charge_interval_days },
			expect: CREATED,
			bind: { [`battery:${b.key}`]: 'created_object_id' },
			label: `fixture: battery ${b.name}`
		}));
	}

	for (const s of world.settings) {
		// Recorded, not arranged: stock_due_soon_days decides what every /stock/volatile
		// checkpoint means, so a difference here would silently change the question rather
		// than answer it.
		ops.push(call({
			method: 'PUT', path: `/user/settings/${s.key}`,
			body: { value: s.value },
			expect: { status: 204 },
			window: ctx.cal.dayWindow(0),
			label: `fixture: setting ${s.key} = ${s.value}`
		}));
	}

	// Two recorded reads over the tables everything else depends on. Three recorded calls
	// for a couple of hundred arranged ones is the trade: the world is asserted identical
	// once, rather than re-asserted on every use.
	ops.push(call({
		method: 'GET', path: '/objects/products?order=id:asc',
		expect: { status: 200, kind: 'array', minLength: world.products.length },
		window: ctx.cal.dayWindow(0),
		label: 'fixture: the products, as built'
	}));
	ops.push(call({
		method: 'GET', path: '/objects/recipes?order=id:asc',
		expect: { status: 200, kind: 'array', minLength: world.recipes.length },
		window: ctx.cal.dayWindow(0),
		label: 'fixture: the recipes, as built'
	}));
}

// --- The year -------------------------------------------------------------------------------

function buildYearPlan({ profile: profileName = 'year', seed = 20260905, anchor = CANONICAL_ANCHOR } = {}) {
	const world = worldFor(profileName);
	const profile = world.profile;
	const cal = makeCalendar({ anchor, days: profile.days });
	const ledger = new Ledger();

	// Symbols resolve to themselves at generation time: the ledger only needs a stable key
	// per product, and the *ids* are a replay-time concern by construction.
	const sym = {
		product: (key) => `product:${key}`,
		location: (key) => `location:${key}`
	};

	const ctx = {
		world, profile, cal, ledger, sym,
		// Everything the generic emitters may touch. A tare product speaks a different
		// protocol on the wire (narrative/tare.js) and is handled only there.
		plainProducts: world.products.filter((p) => !p.tare),
		users: world.users,
		choreState: household.initChoreSchedule(world),
		batteryState: household.initBatterySchedule(world),
		openTasks: [],
		barcodeOf: (productKey) => (world.barcodes.find((b) => b.product === productKey) || {}).barcode,
		rng: null,
		verifyAfter: () => {}
	};

	// **What the plan intended, checked against what the database holds, per operation.**
	//
	// `rowsSum` already says the booking moved the right amount; this says the resulting
	// state is the right one. It costs a request, so it is emitted after every operation
	// whose effect is not a plain add or subtract — opening splits an entry, a transfer moves
	// between locations, an inventory correction books a delta from an absolute, an undo
	// reverses a booking, an edit writes a pair, a tare product speaks in gross readings —
	// and at a sampled cadence for ordinary purchases and consumes.
	//
	// The cadence is what bounds how far a divergence can travel: without it the first
	// evidence is an aggregate at the next monthly checkpoint, which names a product and not
	// the operation. With it, at most `verifyEvery` ordinary operations on a product can pass
	// before something names the day and the step.
	const ALWAYS_VERIFY = new Set(['open', 'transfer', 'inventory', 'undo', 'edit', 'spoil', 'self-production', 'tare']);
	let verifyCounter = 0;
	ctx.verifyAfter = (ops, product, day, kind) => {
		verifyCounter += 1;
		if (!ALWAYS_VERIFY.has(kind) && verifyCounter % profile.verifyEvery !== 0) return;
		const id = sym.product(product.key);
		const equalsOpened = kind === 'open' ? ledger.openAmountOf(id) : undefined;
		ops.push(verifyStock({
			productKey: product.key,
			amount: ledger.amountOf(id),
			opened: equalsOpened,
			window: cal.dayWindow(day),
			label: `d${day}: after ${kind}, ${product.name || product.key} should hold ${ledger.amountOf(id)}`
		}));
	};

	for (const p of world.products) {
		ledger.defineProduct(sym.product(p.key), {
			defaultConsumeLocationId: p.consumeAt ? sym.location(p.consumeAt) : null
		});
	}

	const ops = [];
	ops.push(mark({ note: `fixture for profile ${profile.label}`, day: 0 }));
	emitFixture(ctx, ops);

	const recentBookings = [];
	const withStream = (name, fn) => { ctx.rng = streamFor(seed, name); fn(); };

	for (let day = 0; day < profile.days; day++) {
		const weekday = cal.weekday(day);
		ops.push(mark({ note: `day ${day} — ${cal.date(day)}`, day }));

		// One week a month runs as another user, so stock_log.user_id is not constant and
		// uihelper_stock_journal_summary has more than one group to group by.
		const userWeek = Math.floor(day / 7) % 4;
		if (weekday === 0) {
			ops.push(auth({
				user: userWeek === 0 ? 'admin' : world.users[(userWeek - 1) % world.users.length].key,
				label: `week ${cal.week(day)} runs as ${userWeek === 0 ? 'admin' : world.users[(userWeek - 1) % world.users.length].username}`
			}));
		}

		if (weekday === 0) withStream('shopping', () => shopping.fillList({ ctx, day, ops }));
		if (weekday === 1) {
			withStream('groceries', () => groceries.shop({ ctx, day, ops }));
			withStream('freezer', () => groceries.freeze({ ctx, day, ops }));
			withStream('shopping', () => shopping.clearList({ ctx, day, ops }));
		}
		if (weekday >= 2) withStream('groceries', () => groceries.eat({ ctx, day, ops }));
		if (weekday === 3) withStream('groceries', () => groceries.openSomething({ ctx, day, ops }));
		if (weekday === 4) withStream('spoilage', () => groceries.spoil({ ctx, day, ops }));
		if (weekday === 5) withStream('cooking', () => cooking.cook({ ctx, day, ops }));
		if (weekday === 6) withStream('cooking', () => cooking.planWeek({ ctx, day, ops }));

		withStream('tare', () => tare.refill({ ctx, day, ops }));
		withStream('tare', () => tare.use({ ctx, day, ops }));
		withStream('household', () => household.doChores({ ctx, day, ops }));
		withStream('batteries', () => household.chargeBatteries({ ctx, day, ops }));
		withStream('tasks', () => household.tasks({ ctx, day, ops }));

		// Remember a purchase we could undo later, **by the symbol that operation actually
		// bound** rather than by rebuilding the name from today's date. The purchase was
		// emitted on the shop day, not on this one, so a reconstructed `booking:pasta:30`
		// named a symbol nothing had ever bound — the URL would have kept the literal
		// braces, 404ed on both instances, and compared equal. validate.js caught it; a
		// differential suite never would have.
		const lastPurchase = [...ops].reverse().find((o) => o.ledger && o.ledger.kind === 'purchase' && o.bind);
		if (lastPurchase && recentBookings.length < 40) {
			const symbol = Object.keys(lastPurchase.bind).find((k) => k.startsWith('booking:'));
			if (symbol && !recentBookings.some((b) => b.symbol === symbol)) {
				recentBookings.push({
					symbol,
					product: lastPurchase.ledger.product,
					amount: lastPurchase.ledger.amount,
					seq: lastPurchase.ledger.seq
				});
			}
		}

		if (cal.isMonthStart(day)) {
			withStream('shopping', () => shopping.sweepDueAndExpired({ ctx, day, ops }));
			withStream('audit', () => audit.selfProduce({ ctx, day, ops }));
			withStream('audit', () => audit.undoSomething({ ctx, day, ops, recentBookings }));
			withStream('audit', () => audit.editEntry({ ctx, day, ops }));
			ops.push(call({
				method: 'POST', path: '/chores/executions/calculate-next-assignments',
				body: {},
				expect: { status: 204 },
				window: cal.dayWindow(day),
				label: `m${cal.month(day)}: recalculate chore assignments`
			}));
			checkpoints.medium({ ctx, day, ops });
		} else if (weekday === 6) {
			checkpoints.light({ ctx, day, ops });
		}

		if (cal.isMonthStart(day) && cal.month(day) % 3 === 0) {
			withStream('audit', () => audit.stocktake({ ctx, day, ops }));
		}
	}

	const lastDay = profile.days - 1;
	ops.push(mark({ note: 'year end', day: lastDay }));
	checkpoints.yearEnd({ ctx, day: lastDay, ops, expectedRows: { 'stock log': ledger.bookings.length } });

	withStream('audit', () => audit.isolatedTail({ ctx, ops }));

	const problems = ledger.selfCheck();
	const plan = {
		meta: {
			generatorVersion: GENERATOR_VERSION,
			profile: profile.label,
			seed,
			anchor,
			days: profile.days,
			startDate: cal.date(0),
			endDate: cal.date(profile.days - 1)
		},
		counts: countOps(ops),
		ledger: {
			problems,
			bookings: ledger.bookings.length,
			expectedAmounts: Object.fromEntries(ledger.expectedAmounts()),
			// Where the model ends up holding stock, so the end state can be checked by
			// position and not only by total. Without it a location error introduced after
			// the last monthly checkpoint survives to the end of the run unnoticed — which
			// an injected move between locations demonstrated.
			expectedLocations: ledger.locationAmounts(),
			// The modelled bookings themselves, for the oracles that are expressed over the
			// year's history rather than over its end state. Not part of the plan hash — the
			// hash is over `ops`, which is what a replay actually performs.
			bookingsDetail: ledger.bookings.map((b, index) => ({
				seq: index,
				type: b.type,
				productKey: String(b.productId).replace(/^product:/, ''),
				amount: b.amount,
				price: b.price === undefined ? null : b.price,
				purchasedDate: b.purchasedDate || null,
				entryKey: b.entryKey === undefined ? null : b.entryKey,
				touched: b.touched ? b.touched.map((t) => ({ key: t.key, amount: t.amount })) : null,
				spoiled: b.spoiled || false,
				undone: b.undone || false
			}))
		},
		days: profile.days,
		ops
	};
	plan.meta.hash = canonicalHash(plan);
	return plan;
}

function countOps(ops) {
	const byOp = {};
	const byLedgerKind = {};
	for (const o of ops) {
		byOp[o.op] = (byOp[o.op] || 0) + 1;
		if (o.ledger) byLedgerKind[o.ledger.kind] = (byLedgerKind[o.ledger.kind] || 0) + 1;
	}
	return { total: ops.length, byOp, byLedgerKind, recorded: byOp.call || 0, arranged: byOp.arrange || 0 };
}

// The plan's fingerprint, over the operations exactly as they will be replayed.
//
// **It is not anchor-invariant, and it cannot be.** An earlier version of this function
// scrubbed dates out before hashing, on the theory that the lock could then pin the
// narrative without pinning the calendar. It does not hold: the narrative keys on real
// weekdays and real month boundaries — which is precisely what buys month-end, month-length
// and leap-day coverage — so moving the anchor moves the shop day, moves every monthly
// checkpoint, and produces a genuinely different year. Scrubbing the dates would have hidden
// that behind an identical hash.
//
// So the anchor is part of the key instead. plan.lock.json records one entry per
// (generatorVersion, profile, seed, anchor), and a run with a combination the lock does not
// carry is allowed but reported as unpinned rather than checked against a hash that cannot
// match.
function canonicalHash(plan) {
	const sortDeep = (value) => {
		if (Array.isArray(value)) return value.map(sortDeep);
		if (value && typeof value === 'object') {
			const out = {};
			for (const k of Object.keys(value).sort()) out[k] = sortDeep(value[k]);
			return out;
		}
		return value;
	};
	return crypto.createHash('sha256')
		.update(JSON.stringify({ v: plan.meta.generatorVersion, ops: sortDeep(plan.ops) }))
		.digest('hex');
}

// The lock key for a plan: everything that changes what the year is.
function lockKey(meta) {
	return `g${meta.generatorVersion}/${meta.profile}/seed-${meta.seed}/anchor-${meta.anchor}`;
}

module.exports = { buildYearPlan, canonicalHash, lockKey, GENERATOR_VERSION, CANONICAL_ANCHOR, PROFILES };
