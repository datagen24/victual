'use strict';

// Tests of the harness itself, for the properties the suite's verdicts depend on and that no
// scenario exercises. Run with `node harness/selftest.js`; exits 0 or 1.
//
// These are here because a suite that reports on someone else's software still has to be
// right about its own. Each case below exists because the behaviour it checks was once wrong.

const http = require('http');
const assert = require('assert');
const { Instance } = require('./lib/instance');

const cases = [];
const test = (name, fn) => cases.push({ name, fn });

// **A stalled response body must abort within the configured timeout.**
//
// `fetch()` resolves as soon as the response headers arrive. An earlier version cleared the
// abort timer at that point, which left `response.text()` unbounded: a server that sent
// headers and then stalled hung the harness indefinitely. One did — a fixture-stage
// `POST /users` sat for 286 seconds against a 180-second timeout — and a suite whose stated
// timeout does not bound its requests cannot report a bounded failure.
//
// The server here is the minimal reproduction: headers, a first chunk, then silence.
test('a response whose body stalls aborts within the timeout', async () => {
	const server = http.createServer((req, res) => {
		res.writeHead(200, { 'Content-Type': 'application/json', 'Transfer-Encoding': 'chunked' });
		res.write('{"partial":');
		// and never ends, and never writes again
	});
	await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
	const { port } = server.address();

	const timeoutMs = 1500;
	const api = new Instance({
		name: 'stall', baseUrl: `http://127.0.0.1:${port}`, apiKeyHeader: 'X-API-KEY', timeoutMs
	});

	// **Guarded, because the fault being tested is an unbounded wait.** Without this the test
	// reproduces the bug by hanging, which reports nothing and blocks whatever runs it. The
	// guard turns "never returned" into a failure with a number in it.
	const GUARD_MS = timeoutMs * 4;
	const startedAt = Date.now();
	let threw = null;
	let timedOut = false;
	try {
		await Promise.race([
			api.get('/anything').catch((e) => { threw = e; }),
			new Promise((resolve) => setTimeout(() => { timedOut = true; resolve(); }, GUARD_MS))
		]);
	} finally {
		// `close()` alone waits for the open connection, and on the unfixed code that
		// connection is exactly the one that never ends — so the test would hang here
		// instead of at the request, which is no better.
		server.closeAllConnections();
		await new Promise((resolve) => server.close(resolve));
	}
	const elapsed = Date.now() - startedAt;

	assert.ok(!timedOut,
		`the request was still waiting after ${GUARD_MS}ms with a ${timeoutMs}ms timeout — ` +
		'the timeout does not cover reading the response body');
	assert.ok(threw, 'the request should have failed rather than resolving on a truncated body');
	// Generous upper bound: what is being tested is that a bound exists at all, not its
	// precision.
	assert.ok(elapsed < GUARD_MS,
		`aborted after ${elapsed}ms, which is not within a timeout of ${timeoutMs}ms`);
	assert.ok(elapsed >= timeoutMs * 0.5,
		`gave up after ${elapsed}ms, well before the ${timeoutMs}ms timeout — a different fault`);
});

// A response that *does* end is still read whole, so the fix above did not simply break the
// ordinary path.
test('a complete response is still read in full', async () => {
	const payload = { hello: 'world', n: 42 };
	const server = http.createServer((req, res) => {
		res.writeHead(200, { 'Content-Type': 'application/json' });
		res.end(JSON.stringify(payload));
	});
	await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
	const { port } = server.address();

	const api = new Instance({
		name: 'ok', baseUrl: `http://127.0.0.1:${port}`, apiKeyHeader: 'X-API-KEY', timeoutMs: 5000
	});
	try {
		const record = await api.get('/anything');
		assert.strictEqual(record.status, 200);
		assert.deepStrictEqual(record.body, payload);
		assert.strictEqual(record.parseError, null);
	} finally {
		await new Promise((resolve) => server.close(resolve));
	}
});

(async () => {
	let failed = 0;
	for (const c of cases) {
		try {
			await c.fn();
			console.log(`  \x1b[32mPASS\x1b[0m  ${c.name}`);
		} catch (e) {
			failed++;
			console.log(`  \x1b[31mFAIL\x1b[0m  ${c.name}`);
			console.log(`        ${e.message}`);
		}
	}
	console.log('');
	console.log(failed === 0
		? `\x1b[32m${cases.length} harness self-tests passed\x1b[0m`
		: `\x1b[31m${failed} of ${cases.length} harness self-tests failed\x1b[0m`);
	process.exit(failed === 0 ? 0 : 1);
})();
