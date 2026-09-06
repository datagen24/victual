'use strict';

// Chores, batteries and tasks — the three areas whose APIs accept an explicit time, and so
// the three that were always simulable even before the clock was faked.
//
// **The chore schedule is computed here, never read back.** Asking /chores what is due and
// acting on the answer would be a branch on a response, which is the one thing an operation
// in this plan may not be: it would let the two instances diverge in *what they were asked*
// and the diff would report a harness bug as a finding. The cost is that a generator whose
// model of a period type disagrees with `chores_current` will faithfully drive a wrong
// schedule against both instances and parity will not notice — which is why deep mode reads
// /chores at every checkpoint and compares it to a baseline.

const { call, bookingRows } = require('../ops');
const { intBetween, pick, chance } = require('../rng');

// The generator's model of each period type, in days. `adaptive` is grocy's average of
// recent executions; a fixed ten days is close enough to keep it firing regularly, and the
// checkpoint reads are what check the application's own arithmetic.
const PERIOD_DAYS = {
	daily: 1,
	weekly: 7,
	monthly: 30,
	yearly: 365,
	adaptive: 10,
	manually: null   // never automatic
};

function initChoreSchedule(world) {
	const state = new Map();
	for (const chore of world.chores) {
		state.set(chore.key, { nextDue: PERIOD_DAYS[chore.period_type] === null ? null : 0 });
	}
	return state;
}

function doChores({ ctx, day, ops }) {
	const { rng, cal, world, choreState, users } = ctx;

	for (const chore of world.chores) {
		const st = choreState.get(chore.key);
		const every = PERIOD_DAYS[chore.period_type];

		// A manually-scheduled chore has no due date; it happens when someone decides to.
		if (every === null) {
			if (!chance(rng, 0.01)) continue;
		} else {
			if (st.nextDue === null || day < st.nextDue) continue;
		}

		// An hour of the day, so the tracked_time is a datetime rather than a bare date and
		// the intra-day ordering has something to be ordered by.
		const hour = intBetween(rng, 7, 20);
		const skipped = chance(rng, 0.1);
		const doneBy = pick(rng, users);

		ops.push(call({
			method: 'POST', path: `/chores/{chore:${chore.key}}/execute`,
			body: {
				tracked_time: cal.at(day, hour),
				done_by: `{user:${doneBy.key}}`,
				skipped: skipped ? 1 : 0
			},
			// 200 with the created chores_log row (ChoresApiController:86-90).
			expect: { status: 200, kind: 'object', shape: ['id', 'chore_id', 'tracked_time'] },
			window: cal.hourWindow(day, hour),
			bind: { [`choreExec:${chore.key}:${day}`]: 'id' },
			label: `d${day} ${String(hour).padStart(2, '0')}h: ${chore.name}${skipped ? ' (skipped)' : ''}`
		}));

		if (every !== null) st.nextDue = day + every;

		// Occasionally someone undoes one. The execution is named by the symbol bound just
		// above, so the undo can never be aimed at a row that does not exist.
		if (chance(rng, 0.04)) {
			ops.push(call({
				method: 'POST', path: `/chores/executions/{choreExec:${chore.key}:${day}}/undo`,
				body: {},
				expect: { status: 204 },
				window: cal.dayWindow(day),
				label: `d${day}: undo ${chore.name}`
			}));
		}
	}
}

function initBatterySchedule(world) {
	const state = new Map();
	for (const b of world.batteries) state.set(b.key, { nextDue: 0 });
	return state;
}

function chargeBatteries({ ctx, day, ops }) {
	const { rng, cal, world, batteryState } = ctx;
	for (const battery of world.batteries) {
		const st = batteryState.get(battery.key);
		if (day < st.nextDue) continue;
		const hour = intBetween(rng, 8, 19);
		ops.push(call({
			method: 'POST', path: `/batteries/{battery:${battery.key}}/charge`,
			// A bare date is rejected here — BatteriesApiController:244-248 validates with
			// IsIsoDateTime only, unlike the chore route which accepts either.
			body: { tracked_time: cal.at(day, hour) },
			expect: { status: 200, kind: 'object', shape: ['id', 'battery_id', 'tracked_time'] },
			window: cal.hourWindow(day, hour),
			bind: { [`batteryCharge:${battery.key}:${day}`]: 'id' },
			label: `d${day}: charge ${battery.name}`
		}));
		st.nextDue = day + battery.charge_interval_days;
	}
}

function tasks({ ctx, day, ops }) {
	const { rng, cal, world, openTasks } = ctx;

	if (chance(rng, 0.35)) {
		const category = pick(rng, world.taskCategories);
		const key = `task:${day}`;
		ops.push(call({
			method: 'POST', path: '/objects/tasks',
			body: {
				name: `Task raised on ${cal.date(day)}`,
				due_date: cal.dateOffset(day, intBetween(rng, 3, 30)),
				category_id: `{taskCategory:${category.key}}`
			},
			expect: { status: 200, shape: ['created_object_id'] },
			window: cal.dayWindow(day),
			bind: { [key]: 'created_object_id' },
			label: `d${day}: raise a ${category.name} task`
		}));
		openTasks.push(key);
	}

	if (openTasks.length > 0 && chance(rng, 0.3)) {
		const key = openTasks.shift();
		const hour = intBetween(rng, 9, 18);
		ops.push(call({
			method: 'POST', path: `/tasks/{${key}}/complete`,
			body: { done_time: cal.at(day, hour) },
			expect: { status: 204 },
			window: cal.hourWindow(day, hour),
			label: `d${day}: complete ${key}`
		}));
	}
}

module.exports = { doChores, chargeBatteries, tasks, initChoreSchedule, initBatterySchedule, PERIOD_DAYS };
