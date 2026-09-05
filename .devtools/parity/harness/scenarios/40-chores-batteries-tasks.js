'use strict';

// Chores, batteries and tasks — the three "something is due" features.
//
// One scenario because they are the same shape three times: a definition row, a tracking
// row, a due-date calculation that reads the tracking rows back, and an undo. What differs
// between them is the scheduling arithmetic, which is done in SQL, which is the part an
// engine change can move.
//
// `chores.start_date` is one of ADR-0005's two accepted differences, so this scenario is
// the one that exercises it — a date-only start renders as "2025-01-01" upstream and
// "2025-01-01 00:00:00" here. The accepted-differences registry expects to fire on exactly
// this and nowhere else.

const START_DATE = '2025-01-01';
const TRACKED = '2026-02-03 08:30:00';

module.exports = {
	name: 'chores-batteries-tasks',
	tags: ['chores', 'batteries', 'tasks'],

	async run(api) {
		// --- chores ---------------------------------------------------------------------
		// Two period types, because the due-date arithmetic differs per type and "daily"
		// alone would not exercise the branch that reads the last execution.
		const daily = await api.post('/objects/chores', {
			name: 'Parity Daily Chore',
			description: 'parity fixture',
			period_type: 'daily',
			period_days: 1,
			// Date-only on purpose: this is the ADR-0005 exception's trigger.
			start_date: START_DATE,
			track_date_only: 1
		}, { label: 'chores: create daily chore' });

		const manually = await api.post('/objects/chores', {
			name: 'Parity Manual Chore',
			period_type: 'manually',
			start_date: START_DATE
		}, { label: 'chores: create manual chore' });

		const dailyId = daily.body && daily.body.created_object_id;
		const manualId = manually.body && manually.body.created_object_id;

		await api.get('/chores', { label: 'chores: list' });
		await api.get('/objects/chores?order=id%3Aasc', { label: 'chores: entity list' });

		if (dailyId) {
			await api.get(`/chores/${dailyId}`, { label: 'chores: details before execution' });

			const executed = await api.post(`/chores/${dailyId}/execute`, {
				tracked_time: TRACKED, done_by: 1
			}, { label: 'chores: execute' });

			await api.get(`/chores/${dailyId}`, { label: 'chores: details after execution' });
			await api.get('/chores', { label: 'chores: list after execution' });
			await api.get('/objects/chores_log?order=id%3Aasc', { label: 'chores: log' });

			const executionId = executed.body && (executed.body.id ||
				(executed.body.chore_execution && executed.body.chore_execution.id));

			if (executionId) {
				await api.post(`/chores/executions/${executionId}/undo`, undefined,
					{ label: 'chores: undo execution' });
				await api.get(`/chores/${dailyId}`, { label: 'chores: details after undo' });
				await api.post(`/chores/executions/${executionId}/undo`, undefined,
					{ label: 'chores: undo the same execution twice' });
			}
		}

		// Assignment calculation walks the users table and the chore's assignment config;
		// it is a no-op on chores with no assignment, and answering identically for a
		// no-op is still the contract.
		await api.post('/chores/executions/calculate-next-assignments', {},
			{ label: 'chores: calculate next assignments' });

		// Merge is destructive and its result shape is what a client sees after it.
		if (dailyId && manualId) {
			await api.post(`/chores/${dailyId}/merge/${manualId}`, undefined,
				{ label: 'chores: merge manual into daily' });
			await api.get('/objects/chores?order=id%3Aasc', { label: 'chores: list after merge' });
		}

		await api.get('/chores/999999', { label: 'chores: details for a missing chore' });

		// A chore whose assignment type needs users and whose assignment_config names none.
		// Upstream answers the execution
		//   500 {"error_message":"array_rand(): Argument #1 ($array) must not be empty"}
		// because the random strategy picks with array_rand() in the branch an empty group
		// falls into; here the strategy has nobody to choose between and stores null, which
		// is what the no-assignment type stores, so the execution succeeds. The
		// empty-assignment-group entry in lib/accepted.js is the record.
		//
		// Last in the chores block deliberately: it is the one step whose two sides end in
		// different states, and everything above it is compared before that is true.
		const unassigned = await api.post('/objects/chores', {
			name: 'Parity Unassigned Random Chore',
			period_type: 'manually',
			assignment_type: 'random'
		}, { label: 'chores: create a random chore with nobody assigned' });

		const unassignedId = unassigned.body && unassigned.body.created_object_id;

		if (unassignedId) {
			await api.post(`/chores/${unassignedId}/execute`, { tracked_time: TRACKED, done_by: 1 },
				{ label: 'chores: execute a chore with an empty assignment group' });

			// The chore row rather than the details view, because the row is the part that
			// still agrees: next_execution_assigned_to_user_id is null on both sides —
			// upstream because the execution never got that far, here because "nobody" is
			// the answer — so this step asserts that the fork stored null and not some user
			// it invented. The details view would differ in last_tracked and tracked_count,
			// which is the accepted status difference seen downstream rather than anything
			// further to decide.
			await api.get(`/objects/chores/${unassignedId}`,
				{ label: 'chores: the chore row after an empty-group execution' });
		}

		// --- batteries -------------------------------------------------------------------
		const battery = await api.post('/objects/batteries', {
			name: 'Parity Battery',
			description: 'parity fixture',
			used_in: 'the parity suite',
			charge_interval_days: 30
		}, { label: 'batteries: create' });

		const batteryId = battery.body && battery.body.created_object_id;

		await api.get('/batteries', { label: 'batteries: list' });

		if (batteryId) {
			await api.get(`/batteries/${batteryId}`, { label: 'batteries: details before charge' });

			const charged = await api.post(`/batteries/${batteryId}/charge`, { tracked_time: TRACKED },
				{ label: 'batteries: charge' });

			await api.get(`/batteries/${batteryId}`, { label: 'batteries: details after charge' });
			await api.get('/batteries', { label: 'batteries: list after charge' });
			await api.get('/objects/battery_charge_cycles?order=id%3Aasc', { label: 'batteries: charge cycles' });

			const cycleId = charged.body && (charged.body.id ||
				(charged.body.battery_charge_cycle && charged.body.battery_charge_cycle.id));

			if (cycleId) {
				await api.post(`/batteries/charge-cycles/${cycleId}/undo`, undefined,
					{ label: 'batteries: undo charge cycle' });
				await api.get(`/batteries/${batteryId}`, { label: 'batteries: details after undo' });
			}
		}

		await api.get('/batteries/999999', { label: 'batteries: details for a missing battery' });

		// --- tasks ------------------------------------------------------------------------
		const category = await api.post('/objects/task_categories', { name: 'Parity Tasks' },
			{ label: 'tasks: create category' });
		const categoryId = category.body && category.body.created_object_id;

		const task = await api.post('/objects/tasks', {
			name: 'Parity Task',
			description: 'parity fixture',
			due_date: '2026-12-31',
			category_id: categoryId
		}, { label: 'tasks: create' });

		const taskId = task.body && task.body.created_object_id;

		await api.get('/tasks', { label: 'tasks: list' });

		if (taskId) {
			await api.post(`/tasks/${taskId}/complete`, { done_time: TRACKED },
				{ label: 'tasks: complete' });
			await api.get('/tasks', { label: 'tasks: list after complete' });
			await api.get(`/objects/tasks/${taskId}`, { label: 'tasks: row after complete' });

			await api.post(`/tasks/${taskId}/undo`, undefined, { label: 'tasks: undo complete' });
			await api.get(`/objects/tasks/${taskId}`, { label: 'tasks: row after undo' });
		}

		await api.post('/tasks/999999/complete', {}, { label: 'tasks: complete a missing task' });
	}
};
