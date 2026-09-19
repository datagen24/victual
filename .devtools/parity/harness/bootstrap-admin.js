'use strict';

// The first administrator's first login, as a deployment that set no password gets it.
//
//   node bootstrap-admin.js --victual http://127.0.0.1:8080 \
//        --migrate-log <file> [--out <dir>]
//
// The password to change to is PARITY_VICTUAL_ADMIN_PASSWORD, from the environment rather
// than an argument, so that it does not appear in `ps` (stack/stack.sh sets it).
//
// **A fresh Victual has no password anybody knows.** With VICTUAL_BOOTSTRAP_ADMIN_PASSWORD
// unset, the first migration generates one, prints it once on stderr and flags the account
// for a forced change; the database holds only the hash. So the operator's first job is to
// read it off the migrate container's log, and the account it opens can do nothing on the
// API but change that password. This walks exactly that, and asserts each step:
//
//   1. the migrate log names a generated password — the only copy there is;
//   2. it logs in;
//   3. the API refuses that session anything but the allowlist (403 on /api/stock);
//   4. the allowlist answers (GET /api/user) and names the account;
//   5. PUT /api/users/{id} with current_password changes it;
//   6. the generated password no longer logs in, the new one does, and the API answers.
//
// stack/stack.sh runs this between the web tier coming up and anything else logging in, so
// every phase after it starts from the state an operator reaches — not from a password set
// through the environment, which is the other supported path and skips all six steps. Set
// PARITY_BOOTSTRAP_ADMIN=env to boot that way instead.
//
// Exit 0 when every step held, 1 when one did not. No dependencies: Node 18's fetch.

const fs = require('fs');
const path = require('path');

// The line services/DatabaseMigrationService.php::ReportGeneratedAdminPassword() writes. The
// nix workflow reads it with the same pattern; if the wording changes both have to follow.
const GENERATED = /created the first administrator "([^"]+)" with the generated password ([0-9a-f]{24})\b/;

function parseArgs(argv) {
	const args = { victual: 'http://127.0.0.1:8080', migrateLog: null, password: process.env.PARITY_VICTUAL_ADMIN_PASSWORD || null, out: null };
	for (let i = 2; i < argv.length; i++) {
		if (argv[i] === '--victual') args.victual = argv[++i].replace(/\/+$/, '');
		else if (argv[i] === '--migrate-log') args.migrateLog = argv[++i];
		else if (argv[i] === '--out') args.out = argv[++i];
	}
	if (!args.migrateLog || !args.password) {
		console.error('usage: PARITY_VICTUAL_ADMIN_PASSWORD=<new> node bootstrap-admin.js --victual <url> --migrate-log <file>');
		process.exit(2);
	}
	return args;
}

// A session of its own per login, so a step cannot pass on a cookie an earlier one left.
function session(base) {
	const cookies = new Map();
	async function request(method, urlPath, { form, json } = {}) {
		const init = { method, redirect: 'manual', headers: {} };
		if (cookies.size) init.headers.Cookie = [...cookies].map(([k, v]) => `${k}=${v}`).join('; ');
		if (form) {
			init.headers['Content-Type'] = 'application/x-www-form-urlencoded';
			init.body = new URLSearchParams(form).toString();
		} else if (json) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(json);
		}
		const response = await fetch(`${base}${urlPath}`, init);
		for (const line of response.headers.getSetCookie()) {
			const [pair] = line.split(';');
			const idx = pair.indexOf('=');
			if (idx > 0) cookies.set(pair.slice(0, idx).trim(), pair.slice(idx + 1).trim());
		}
		const text = await response.text();
		let body = null;
		try { body = text ? JSON.parse(text) : null; } catch { body = text.slice(0, 300); }
		return { status: response.status, body, location: response.headers.get('location') };
	}
	// **Both outcomes of a login are a 302**: to the application on success, to
	// /login?invalid=true on refused credentials. The status alone says nothing.
	async function login(username, password) {
		const r = await request('POST', '/login', { form: { username, password } });
		const accepted = r.status === 302 && !/[?&]invalid=/.test(r.location || '');
		return { ...r, accepted, detail: `HTTP ${r.status} to ${r.location}` };
	}
	return { request, login };
}

async function main() {
	const args = parseArgs(process.argv);
	const steps = [];
	let failed = false;
	const step = (name, ok, detail) => {
		steps.push({ name, ok: !!ok, detail: detail || null });
		console.log(`  ${ok ? '\x1b[32mPASS\x1b[0m' : '\x1b[31mFAIL\x1b[0m'}  ${name}`);
		if (detail) console.log(`        ${detail}`);
		if (!ok) failed = true;
		return ok;
	};
	const finish = () => {
		if (args.out) {
			fs.mkdirSync(args.out, { recursive: true });
			// The generated password is kept out of the report: it has been replaced by the
			// time this is written, but a report is a file people pass around.
			fs.writeFileSync(path.join(args.out, 'bootstrap-admin.json'),
				`${JSON.stringify({ recordedAt: new Date().toISOString(), steps }, null, '\t')}\n`);
		}
		process.exit(failed ? 1 : 0);
	};

	const log = fs.readFileSync(args.migrateLog, 'utf8');
	const match = log.match(GENERATED);
	if (!step('the migrate log reports a generated administrator password', match,
		match ? `for "${match[1]}"` : 'no line matched — was VICTUAL_BOOTSTRAP_ADMIN_PASSWORD set after all?')) {
		return finish();
	}
	const [, username, generated] = match;

	const first = session(args.victual);
	const login = await first.login(username, generated);
	if (!step('the generated password logs in', login.accepted, login.detail)) return finish();

	const refused = await first.request('GET', '/api/stock');
	step('the API refuses the flagged account (GET /api/stock)', refused.status === 403,
		`HTTP ${refused.status}${refused.body && refused.body.error_message ? `: ${refused.body.error_message}` : ''}`);

	const me = await first.request('GET', '/api/user');
	const user = Array.isArray(me.body) ? me.body[0] : me.body;
	if (!step('the allowlist answers GET /api/user', me.status === 200 && user && user.id,
		`HTTP ${me.status}, id ${user && user.id}, username ${user && user.username}`)) {
		return finish();
	}

	const same = await first.request('PUT', `/api/users/${user.id}`,
		{ json: { username, password: generated, current_password: generated } });
	step('re-saving the generated password is refused', same.status === 400, `HTTP ${same.status}`);

	const change = await first.request('PUT', `/api/users/${user.id}`,
		{ json: { username, password: args.password, current_password: generated } });
	if (!step('PUT /api/users/{id} with current_password changes it', change.status === 204,
		`HTTP ${change.status}${change.body ? ` ${JSON.stringify(change.body).slice(0, 200)}` : ''}`)) {
		return finish();
	}

	const stale = await session(args.victual).login(username, generated);
	step('the generated password no longer logs in', stale.status === 302 && !stale.accepted, stale.detail);

	const second = session(args.victual);
	const relogin = await second.login(username, args.password);
	step('the new password logs in', relogin.accepted, relogin.detail);
	const open = await second.request('GET', '/api/stock');
	step('and the API answers it (GET /api/stock)', open.status === 200, `HTTP ${open.status}`);

	finish();
}

main().catch((error) => {
	console.error(`bootstrap-admin: ${error.stack || error}`);
	process.exit(2);
});
