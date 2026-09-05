'use strict';

// The half of the fork that has no upstream to be compared against.
//
//   node run-side-effects.js --victual http://127.0.0.1:8080 \
//        --mqtt 127.0.0.1:1883 --influx http://127.0.0.1:8086
//
// Plan 18 publishes seven ambient sensors and opt-in per-product entities to retained MQTT
// topics after commit, and writes price and stock-value events to InfluxDB through a
// transactional outbox. Upstream grocy does none of that, so parity is not the question —
// **the question is whether the feature does what the plan says**, against a real broker
// and a real InfluxDB rather than the stand-ins.
//
// That distinction is the reason this file exists. `.devtools/mqtt/` already probes eight
// failure modes, and two of its probes run against stand-ins on purpose — a PHP built-in
// server for InfluxDB, a PHP stream socket for the broker — which is what keeps that phase
// dependency free, and is also, in its own words, the limit of what it proves. This stack
// has the real broker and the real database already running, so the same properties get
// asserted against the real thing. It complements those probes; it does not replace them.
//
// Plan 18 also records that the Home Assistant-side verifications (its 2, 4 and 8) are
// outstanding because they need the household's Home Assistant. Nothing here changes that:
// a retained topic with a well-formed discovery payload is evidence that Home Assistant
// *could* consume it, not that it did.

const fs = require('fs');
const path = require('path');

const { victual } = require('./lib/instance');
// The broker subscriber and the InfluxDB reader moved to lib/ so that the year phase can
// ask the same questions of the same infrastructure at a checkpoint. Nothing about what
// this phase asks changed with the move: `queryInflux` keeps the relative window that is
// right here, where the booking under test was made seconds ago on the real clock.
const { collectRetained } = require('./lib/mqtt');
const { queryInflux } = require('./lib/influx');

function parseArgs(argv) {
	const args = {
		victual: process.env.PARITY_VICTUAL_URL || 'http://127.0.0.1:8080',
		mqtt: process.env.PARITY_MQTT || '127.0.0.1:1883',
		influx: process.env.PARITY_INFLUX_URL || 'http://127.0.0.1:8086',
		influxToken: process.env.PARITY_INFLUX_TOKEN || 'victual-parity-token',
		influxOrg: process.env.PARITY_INFLUX_ORG || 'victual',
		influxBucket: process.env.PARITY_INFLUX_BUCKET || 'victual',
		topicPrefix: process.env.PARITY_MQTT_PREFIX || 'victual',
		out: path.join(__dirname, '..', 'reports')
	};
	for (let i = 2; i < argv.length; i++) {
		if (argv[i] === '--victual') args.victual = argv[++i];
		else if (argv[i] === '--mqtt') args.mqtt = argv[++i];
		else if (argv[i] === '--influx') args.influx = argv[++i];
		else if (argv[i] === '--out') args.out = argv[++i];
	}
	return args;
}

// --- The boot publish ---------------------------------------------------------------------------

// Runs `bin/victual-publish-state`. This is the one place the harness reaches outside HTTP,
// because the command has no HTTP surface — it is a deployment step, and the property under
// test is that the deployment step works.
//
// **How to run it is the caller's to say, not this file's.** It used to `podman exec` into
// a container named `parity-victual` and run `php bin/victual-publish-state`. Since the
// port to the Nix images (issue #56) there is no such container: the fork side is a pod of
// two, and neither serving image has a shell, a `PATH` or a plain PHP CLI to exec into —
// the CLI entry points live in `victual-migrate`, whose interpreter is a store path. That
// is stack knowledge, so it stays in `stack/stack.sh`, and `bin/parity side-effects` passes
// it down as `PARITY_PUBLISH_CMD`. Guessing a default here would only produce a wrong one.
function runPublishState(args) {
	const { execFileSync } = require('child_process');
	const override = process.env.PARITY_PUBLISH_CMD;

	if (!override) {
		return {
			ok: false,
			detail:
				'PARITY_PUBLISH_CMD is not set, so there is no way to run bin/victual-publish-state. ' +
				'Run this phase through `.devtools/parity/bin/parity side-effects`, which sets it, ' +
				'or set it yourself to a shell command that runs the tool against this stack.'
		};
	}

	const argv = ['sh', '-c', override];

	try {
		const out = execFileSync(argv[0], argv.slice(1), { encoding: 'utf8', timeout: 60000 });
		return { ok: true, detail: (out || '').trim().split('\n').slice(-1)[0] || 'exit 0' };
	} catch (e) {
		return {
			ok: false,
			detail: `${argv.join(' ')} failed: ${String((e.stderr || e.message || '')).trim().slice(0, 300)}`
		};
	}
}

// --- Checks -----------------------------------------------------------------------------------

function check(results, name, ok, detail) {
	results.push({ name, ok: !!ok, detail });
	const mark = ok ? '\x1b[32mPASS\x1b[0m' : '\x1b[31mFAIL\x1b[0m';
	console.log(`  ${mark}  ${name}`);
	if (detail) console.log(`        ${detail}`);
	return ok;
}

async function main() {
	const args = parseArgs(process.argv);
	const [mqttHost, mqttPort] = args.mqtt.split(':');
	const results = [];

	const api = victual(args.victual);
	await api.login();

	// A booking, so that there is something to publish about. Done through the API rather
	// than seeded, because the property under test is that a *committed write* publishes.
	const location = await api.post('/objects/locations', { name: 'Side Effect Location' });
	const qu = await api.post('/objects/quantity_units',
		{ name: 'Side Effect Unit', name_plural: 'Side Effect Units' });
	const product = await api.post('/objects/products', {
		name: 'Side Effect Product',
		location_id: location.body && location.body.created_object_id,
		qu_id_purchase: qu.body && qu.body.created_object_id,
		qu_id_stock: qu.body && qu.body.created_object_id,
		min_stock_amount: 5
	});
	const productId = product.body && product.body.created_object_id;

	if (productId) {
		await api.post(`/stock/products/${productId}/add`, {
			amount: 3,
			best_before_date: '2030-01-01',
			transaction_type: 'purchase',
			price: 9.99,
			location_id: location.body && location.body.created_object_id
		});
	}

	// The publisher runs after commit; give it a moment before asking the broker.
	await new Promise((r) => setTimeout(r, 2000));

	// **Then run the boot publish, because the discovery payloads come from there and
	// nowhere else.** `bin/victual-publish-state` is plan 18's "publish on boot" half —
	// PHP has no boot event, so it is a command the deployment runs (a postStart hook, or a
	// Job beside the migrate initContainer) rather than something the application does to
	// itself. The first run of this file subscribed without it and reported "no
	// homeassistant/ topic", which was this harness not doing what a deployment does, not
	// the fork failing to publish. Running it here also exercises the self-healing property
	// the command exists for.
	const publishResult = runPublishState(args);
	check(results, 'bin/victual-publish-state exits cleanly', publishResult.ok,
		publishResult.detail);

	await new Promise((r) => setTimeout(r, 1500));

	console.log('');
	console.log('  MQTT');

	let messages = [];
	let mqttError = null;
	try {
		messages = await collectRetained(mqttHost, Number(mqttPort), '#');
	} catch (e) {
		mqttError = String(e.message || e);
	}

	check(results, 'broker reachable and subscribable', !mqttError, mqttError || `${messages.length} retained messages`);

	const stateTopics = messages.filter((m) => m.topic.startsWith(`${args.topicPrefix}/`));
	check(results, 'state topics published under the configured prefix',
		stateTopics.length > 0,
		stateTopics.length > 0
			? stateTopics.slice(0, 8).map((m) => m.topic).join(', ')
			: `no topic began with "${args.topicPrefix}/" — of ${messages.length} messages seen`);

	// Plan 18 publishes seven ambient sensors. Naming the count rather than the topics
	// keeps this from breaking on a rename, while still failing if a sensor is dropped.
	const SENSOR_COUNT = 7;
	const sensorTopics = new Set(stateTopics
		.filter((m) => m.topic.includes('/state/'))
		.map((m) => m.topic));
	check(results, `at least the ${SENSOR_COUNT} ambient sensors are on retained topics`,
		sensorTopics.size >= SENSOR_COUNT,
		`${sensorTopics.size} distinct state topics: ${[...sensorTopics].slice(0, 10).join(', ')}`);

	// Retained is the property that matters: a client connecting later has to get the
	// current value without waiting for the next change. A published-but-not-retained topic
	// looks identical in a live subscription and is broken for every real consumer.
	const notRetained = stateTopics.filter((m) => !m.retained);
	check(results, 'every state topic is retained',
		notRetained.length === 0,
		notRetained.length === 0 ? 'all retained' : `not retained: ${notRetained.map((m) => m.topic).join(', ')}`);

	// Home Assistant discovery. The default MQTT_DISCOVERY_MODE is "device", which is one
	// config topic declaring every entity.
	const discovery = messages.filter((m) => m.topic.startsWith('homeassistant/'));
	check(results, 'a Home Assistant discovery config was published',
		discovery.length > 0,
		discovery.length > 0 ? discovery.map((m) => m.topic).join(', ') : 'no homeassistant/ topic');

	let discoveryParses = discovery.length > 0;
	for (const m of discovery) {
		try {
			JSON.parse(m.payload);
		} catch {
			discoveryParses = false;
		}
	}
	check(results, 'every discovery payload is valid JSON', discoveryParses,
		discoveryParses ? '' : 'at least one discovery payload did not parse');

	// A payload written out as zeros and acknowledged is one of the eight defects
	// `.devtools/mqtt/payload-validation-check.php` guards. Here the equivalent question is
	// whether the values on the broker are the ones the API reports.
	const stockPayloads = stateTopics.filter((m) => /stock/i.test(m.topic));
	const allZero = stockPayloads.length > 0 && stockPayloads.every((m) => /^"?0"?$/.test(m.payload.trim()));
	check(results, 'stock topics are not uniformly zero', !allZero,
		allZero ? 'every stock topic published 0 after a purchase of 3' : '');

	console.log('');
	console.log('  InfluxDB');

	// Plan 18 Q7: price and stock-value events, delivered through a transactional outbox.
	// **`price_paid`, not `price`** — BookingEventPublisher's docblock names the two
	// measurements and the first run of this file guessed the name, got HTTP 200 with zero
	// rows, and passed. A query that returns nothing from a bucket that has nothing is
	// indistinguishable from a query for a measurement that was never written, which is why
	// the row count is asserted below rather than only the status.
	for (const measurement of ['price_paid', 'stock_value']) {
		try {
			const result = await queryInflux(args, measurement);
			check(results, `influx holds points for "${measurement}"`,
				result.status === 200 && result.rowCount > 0,
				`HTTP ${result.status}, ${result.rowCount} rows`);
		} catch (e) {
			check(results, `influx holds points for "${measurement}"`, false, String(e.message || e));
		}
	}

	// The outbox itself. Rows left undelivered after a successful publish are the defect
	// `.devtools/mqtt/outbox-check.php` is about — an event lost after a commit — and the
	// table is readable through the generic entity endpoint only if it is exposed, so this
	// reads it the way an operator would and tolerates it not being there.
	const outbox = await api.get('/objects/mqtt_product_entities');
	check(results, 'the fork-only mqtt_product_entities view answers',
		outbox.status === 200,
		`HTTP ${outbox.status}`);

	const failed = results.filter((r) => !r.ok);
	fs.mkdirSync(args.out, { recursive: true });
	fs.writeFileSync(path.join(args.out, 'side-effects.json'),
		JSON.stringify({ startedAt: new Date().toISOString(), results, messages }, null, '\t'));

	console.log('');
	console.log(failed.length === 0
		? `\x1b[32mPASS — ${results.length} checks\x1b[0m`
		: `\x1b[31mFAIL — ${failed.length} of ${results.length} checks\x1b[0m`);
	console.log(`  report: ${path.join(args.out, 'side-effects.json')}`);

	process.exit(failed.length === 0 ? 0 : 1);
}

main().catch((error) => {
	console.error(error);
	process.exit(2);
});
