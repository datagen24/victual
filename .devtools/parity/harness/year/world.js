'use strict';

// The household, as data.
//
// Nothing here performs I/O or knows what an HTTP request is. plan.js turns it into
// `arrange` operations that replay.js executes like any other, which is what keeps the
// generator offline and this file reviewable as a description of a home rather than as a
// script.
//
// **Chosen for coverage, not for realism alone.** Shelf lives span 3 to 900 days so that
// some products expire inside the year and others never do; one product carries a tare
// weight because `ConsumeProduct`'s tare branch (services/StockService.php:566-573) is
// arithmetic nothing else exercises — and only one, because a tare product inside a recipe
// would drag its gross-reading protocol into the cooking path (see narrative/tare.js); several buy in a different unit than they stock so the
// quantity-unit conversion path runs on every booking; three sit at a default consume
// location so `stock_next_use`'s first ordering term is not constant for the whole year.

const LOCATIONS = [
	{ key: 'pantry', name: 'Pantry' },
	{ key: 'fridge', name: 'Fridge' },
	{ key: 'freezer', name: 'Freezer', is_freezer: 1 },
	{ key: 'cellar', name: 'Cellar' },
	{ key: 'bathroom', name: 'Bathroom cabinet' },
	{ key: 'garage', name: 'Garage' }
];

const SHOPPING_LOCATIONS = [
	{ key: 'supermarket', name: 'Supermarket' },
	{ key: 'corner', name: 'Corner shop' },
	{ key: 'farm', name: 'Farm box' }
];

const PRODUCT_GROUPS = [
	{ key: 'dairy', name: 'Dairy' },
	{ key: 'bakery', name: 'Bakery' },
	{ key: 'produce', name: 'Produce' },
	{ key: 'pantrygrp', name: 'Pantry staples' },
	{ key: 'frozen', name: 'Frozen' },
	{ key: 'drinks', name: 'Drinks' },
	{ key: 'household', name: 'Household' },
	{ key: 'baby', name: 'Baby' }
];

const QUANTITY_UNITS = [
	{ key: 'piece', name: 'Piece', name_plural: 'Pieces' },
	{ key: 'gram', name: 'Gram', name_plural: 'Grams' },
	{ key: 'kilogram', name: 'Kilogram', name_plural: 'Kilograms' },
	{ key: 'millilitre', name: 'Millilitre', name_plural: 'Millilitres' },
	{ key: 'litre', name: 'Litre', name_plural: 'Litres' },
	{ key: 'pack', name: 'Pack', name_plural: 'Packs' },
	{ key: 'slice', name: 'Slice', name_plural: 'Slices' }
];

// Global conversions, plus the two product-specific ones that make a purchase unit differ
// from a stock unit.
const QUANTITY_UNIT_CONVERSIONS = [
	{ from: 'kilogram', to: 'gram', factor: 1000 },
	{ from: 'litre', to: 'millilitre', factor: 1000 }
];

// `shelfLife` is the generator's, not a column: it computes best_before_date from the day
// of purchase. `default_best_before_days` is sent to the application as well, so the two
// agree about what the product is.
const PRODUCTS = [
	{ key: 'milk',      name: 'Milk',            group: 'dairy',     loc: 'fridge',  shelfLife: 7,   min: 2, qu: 'litre' },
	{ key: 'butter',    name: 'Butter',          group: 'dairy',     loc: 'fridge',  shelfLife: 60,  min: 1, qu: 'piece' },
	{ key: 'cheese',    name: 'Cheese',          group: 'dairy',     loc: 'fridge',  shelfLife: 90,  min: 1, qu: 'gram', quPurchase: 'kilogram' },
	{ key: 'yoghurt',   name: 'Yoghurt',         group: 'dairy',     loc: 'fridge',  shelfLife: 21,  min: 4, qu: 'piece' },
	{ key: 'bread',     name: 'Bread',           group: 'bakery',    loc: 'pantry',  shelfLife: 4,   min: 1, qu: 'slice', quPurchase: 'piece', conversion: 18 },
	{ key: 'rolls',     name: 'Rolls',           group: 'bakery',    loc: 'pantry',  shelfLife: 3,   min: 0, qu: 'piece' },
	{ key: 'spinach',   name: 'Spinach',         group: 'produce',   loc: 'fridge',  shelfLife: 5,   min: 0, qu: 'gram', quPurchase: 'kilogram' },
	{ key: 'apples',    name: 'Apples',          group: 'produce',   loc: 'pantry',  shelfLife: 14,  min: 3, qu: 'piece' },
	{ key: 'carrots',   name: 'Carrots',         group: 'produce',   loc: 'fridge',  shelfLife: 21,  min: 2, qu: 'piece' },
	{ key: 'potatoes',  name: 'Potatoes',        group: 'produce',   loc: 'cellar',  shelfLife: 45,  min: 5, qu: 'gram', quPurchase: 'kilogram' },
	{ key: 'onions',    name: 'Onions',          group: 'produce',   loc: 'cellar',  shelfLife: 60,  min: 3, qu: 'piece' },
	{ key: 'berries',   name: 'Frozen berries',  group: 'frozen',    loc: 'freezer', shelfLife: 270, min: 1, qu: 'gram', quPurchase: 'kilogram', consumeAt: 'freezer' },
	{ key: 'peas',      name: 'Frozen peas',     group: 'frozen',    loc: 'freezer', shelfLife: 300, min: 1, qu: 'gram', quPurchase: 'kilogram', consumeAt: 'freezer' },
	{ key: 'fish',      name: 'Fish fillets',    group: 'frozen',    loc: 'freezer', shelfLife: 180, min: 2, qu: 'piece', consumeAt: 'freezer', freezeBonus: 120 },
	{ key: 'pizza',     name: 'Pizza',           group: 'frozen',    loc: 'freezer', shelfLife: 200, min: 1, qu: 'piece', freezeBonus: 120 },
	{ key: 'coffee',    name: 'Coffee',          group: 'drinks',    loc: 'pantry',  shelfLife: 300, min: 1, qu: 'gram', quPurchase: 'pack', conversion: 500 },
	{ key: 'juice',     name: 'Orange juice',    group: 'drinks',    loc: 'fridge',  shelfLife: 30,  min: 1, qu: 'millilitre', quPurchase: 'litre' },
	{ key: 'flour',     name: 'Flour',           group: 'pantrygrp', loc: 'pantry',  shelfLife: 400, min: 1, qu: 'gram', quPurchase: 'kilogram' },
	{ key: 'oil',       name: 'Olive oil',       group: 'pantrygrp', loc: 'pantry',  shelfLife: 500, min: 1, qu: 'millilitre', quPurchase: 'litre' },
	{ key: 'pasta',     name: 'Pasta',           group: 'pantrygrp', loc: 'pantry',  shelfLife: 700, min: 2, qu: 'gram', quPurchase: 'pack', conversion: 500 },
	{ key: 'rice',      name: 'Rice',            group: 'pantrygrp', loc: 'pantry',  shelfLife: 900, min: 1, qu: 'gram', quPurchase: 'kilogram' },
	{ key: 'tomatoes',  name: 'Tinned tomatoes', group: 'pantrygrp', loc: 'pantry',  shelfLife: 900, min: 4, qu: 'piece' },
	{ key: 'detergent', name: 'Detergent',       group: 'household', loc: 'bathroom', shelfLife: null, min: 1, qu: 'millilitre', quPurchase: 'litre', tare: 250 },
	{ key: 'soap',      name: 'Soap',            group: 'household', loc: 'bathroom', shelfLife: null, min: 2, qu: 'piece' }
];

// A second barcode on a few products carries an amount and a shopping location, so the
// by-barcode routes do something the plain ones do not.
const BARCODES = PRODUCTS.map((p, i) => ({ product: p.key, barcode: `VCTL${String(1000 + i)}` }))
	.concat([
		{ product: 'milk', barcode: 'VCTL2001', amount: 2, shoppingLocation: 'supermarket' },
		{ product: 'pasta', barcode: 'VCTL2002', amount: 2, shoppingLocation: 'corner' },
		{ product: 'coffee', barcode: 'VCTL2003', amount: 1, shoppingLocation: 'farm' },
		{ product: 'tomatoes', barcode: 'VCTL2004', amount: 4, shoppingLocation: 'supermarket' }
	]);

const RECIPES = [
	{ key: 'porridge',  name: 'Porridge',   servings: 2, positions: [
		{ product: 'milk', amount: 300 }, { product: 'apples', amount: 1 } ] },
	{ key: 'pastabake', name: 'Pasta bake', servings: 4, positions: [
		{ product: 'pasta', amount: 250 }, { product: 'tomatoes', amount: 2 },
		{ product: 'cheese', amount: 100 }, { product: 'onions', amount: 1 } ] },
	{ key: 'soup',      name: 'Winter soup', servings: 4, positions: [
		{ product: 'carrots', amount: 3 }, { product: 'onions', amount: 1 },
		{ product: 'potatoes', amount: 400 }, { product: 'oil', amount: 20 } ] },
	{ key: 'sandwich',  name: 'Sandwich',   servings: 1, positions: [
		{ product: 'bread', amount: 2 }, { product: 'cheese', amount: 40 },
		{ product: 'butter', amount: 1, only_check_single_unit_in_stock: 1 } ] },
	{ key: 'roast',     name: 'Sunday roast', servings: 4, positions: [
		{ product: 'potatoes', amount: 800 }, { product: 'carrots', amount: 4 },
		{ product: 'fish', amount: 2 }, { product: 'oil', amount: 30 } ] },
	{ key: 'pancakes',  name: 'Pancakes',   servings: 3, positions: [
		{ product: 'flour', amount: 200 }, { product: 'milk', amount: 250 },
		{ product: 'berries', amount: 100 } ] },
	// The two nesting recipes, which is what puts rows in recipes_nestings and makes
	// recipes_pos_resolved do something.
	{ key: 'sundaylunch', name: 'Sunday lunch', servings: 4, nests: ['roast', 'soup'], positions: [
		{ product: 'juice', amount: 500 } ] },
	{ key: 'leftovers',   name: 'Leftovers',    servings: 2, nests: ['pastabake'], positions: [
		{ product: 'apples', amount: 2 } ] }
];

const MEAL_PLAN_SECTIONS = [
	{ key: 'breakfast', name: 'Breakfast', sort_number: 1 },
	{ key: 'lunch', name: 'Lunch', sort_number: 2 },
	{ key: 'dinner', name: 'Dinner', sort_number: 3 }
];

// One chore per period type. `hourly` is deliberately absent from the parity narrative —
// its next_estimated_execution_time moves *within* a run, which is a flake rather than a
// finding — and lives in the deep-mode intra-day fixture instead.
const CHORES = [
	{ key: 'dishes',   name: 'Wash up',            period_type: 'daily',    period_days: 1,   assignment_type: 'random' },
	{ key: 'bins',     name: 'Put the bins out',   period_type: 'weekly',   period_days: 1,   assignment_type: 'in-alphabetical-order' },
	{ key: 'laundry',  name: 'Laundry',            period_type: 'weekly',   period_days: 1,   assignment_type: 'who-least-did-first' },
	{ key: 'filter',   name: 'Change the filter',  period_type: 'monthly',  period_days: 1,   assignment_type: 'who-least-did-first' },
	{ key: 'deepclean', name: 'Deep clean',        period_type: 'monthly',  period_days: 1,   assignment_type: 'no-assignment' },
	{ key: 'boiler',   name: 'Service the boiler', period_type: 'yearly',   period_days: 1,   assignment_type: 'no-assignment' },
	{ key: 'plants',   name: 'Water the plants',   period_type: 'adaptive', period_days: 1,   assignment_type: 'random' },
	{ key: 'defrost',  name: 'Defrost the freezer', period_type: 'manually', period_days: 1,  assignment_type: 'no-assignment' }
];

const BATTERIES = [
	{ key: 'smoke',    name: 'Smoke alarm',     charge_interval_days: 365 },
	{ key: 'remote',   name: 'TV remote',       charge_interval_days: 180 },
	{ key: 'doorbell', name: 'Doorbell',        charge_interval_days: 90 },
	{ key: 'scale',    name: 'Kitchen scale',   charge_interval_days: 30 }
];

const TASK_CATEGORIES = [
	{ key: 'admin', name: 'Admin' },
	{ key: 'repairs', name: 'Repairs' },
	{ key: 'garden', name: 'Garden' }
];

// Four, so that chore assignment rotates over a year instead of being a no-op, and so that
// stock_log.user_id has more than one value for uihelper_stock_journal_summary to group by.
const USERS = [
	{ key: 'alice', username: 'alice', display: 'Alice' },
	{ key: 'bob', username: 'bob', display: 'Bob' },
	{ key: 'carol', username: 'carol', display: 'Carol' }
];

// **Pinned, not defaulted.** `stock_due_soon_days` decides what every /stock/volatile
// checkpoint *means*, so a default drifting between versions would silently change the
// question. `shopping_list_auto_add_below_min_stock_amount` is pinned off because leaving it
// on makes every product create a hidden side effect the shadow ledger would have to model
// to stay satisfiable.
const SETTINGS = [
	{ key: 'stock_due_soon_days', value: '5' },
	{ key: 'shopping_list_auto_add_below_min_stock_amount', value: '0' },
	{ key: 'stock_default_purchase_amount', value: '0' },
	{ key: 'stock_default_consume_amount', value: '1' }
];

// Profiles scale the world down rather than describing a different one, so a smoke run
// exercises the same shapes as a year.
const PROFILES = {
	smoke: { label: 'smoke', days: 31, products: 8, recipes: 3, chores: 4, batteries: 2, cadence: 1 },
	year: { label: 'year', days: 365, products: 24, recipes: 8, chores: 8, batteries: 4, cadence: 1 },
	dense: { label: 'dense', days: 365, products: 24, recipes: 8, chores: 8, batteries: 4, cadence: 2 }
};

// The world a profile actually gets. Products are taken in declaration order so that a
// smoke world is a prefix of a year world — recipes then reference only products that exist,
// which is checked rather than assumed.
function worldFor(profileName) {
	const profile = PROFILES[profileName];
	if (!profile) throw new Error(`unknown profile "${profileName}" — one of ${Object.keys(PROFILES).join(', ')}`);

	const products = PRODUCTS.slice(0, profile.products);
	const productKeys = new Set(products.map((p) => p.key));
	const recipes = RECIPES
		.slice(0, profile.recipes)
		.filter((r) => r.positions.every((pos) => productKeys.has(pos.product)));

	return {
		profile,
		locations: LOCATIONS,
		shoppingLocations: SHOPPING_LOCATIONS,
		productGroups: PRODUCT_GROUPS,
		quantityUnits: QUANTITY_UNITS,
		quantityUnitConversions: QUANTITY_UNIT_CONVERSIONS,
		products,
		barcodes: BARCODES.filter((b) => productKeys.has(b.product)),
		recipes: recipes.filter((r) => !r.nests || r.nests.every((n) => recipes.some((x) => x.key === n))),
		mealPlanSections: MEAL_PLAN_SECTIONS,
		chores: CHORES.slice(0, profile.chores),
		batteries: BATTERIES.slice(0, profile.batteries),
		taskCategories: TASK_CATEGORIES,
		users: USERS,
		settings: SETTINGS
	};
}

module.exports = { worldFor, PROFILES, PRODUCTS, LOCATIONS, RECIPES, CHORES, BATTERIES };
