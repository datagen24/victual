'use strict';

// One running instance — this fork, or upstream grocy — behind an interface that hides
// every way the two differ in how you *reach* them, so that a scenario is written once.
//
// The differences it hides are exactly three, and none of them is a wire-contract
// difference: the base URL, the name of the session cookie, and the name of the API key
// header (VICTUAL-API-KEY against GROCY-API-KEY). Everything a scenario sends and
// everything it gets back is compared verbatim.
//
// **Authentication is a session cookie, not an API key**, and that is a deliberate choice
// rather than a shortcut. DefaultAuthMiddleware accepts either on API routes, and both
// projects ship the same admin/admin user from the same migration (0027). A session
// avoids reaching into either database to mint a key — which would mean psql on one side
// and sqlite3 on the other, i.e. a bootstrap that differs between the two things being
// compared. The login POST is itself the first thing the suite checks.

const DEFAULT_TIMEOUT_MS = 30000;

class Instance {
	constructor({ name, baseUrl, apiKeyHeader, timeoutMs = DEFAULT_TIMEOUT_MS, adminPassword = 'admin' }) {
		this.name = name;
		this.adminPassword = adminPassword;
		this.baseUrl = baseUrl.replace(/\/+$/, '');
		this.apiKeyHeader = apiKeyHeader;
		this.timeoutMs = timeoutMs;
		// A cookie jar of the smallest useful kind: name -> value, one host. Node's fetch
		// has no jar and the alternative is a dependency for something that is six lines.
		this.cookies = new Map();
		this.trace = [];
		this.recording = true;
	}

	cookieHeader() {
		if (this.cookies.size === 0) return undefined;
		return [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; ');
	}

	absorbCookies(response) {
		// getSetCookie() is the only correct reader here: multiple Set-Cookie headers
		// collapse into one comma-joined string under .get(), and cookie values may
		// themselves contain commas (Expires=Wed, 01 Jan ...).
		const raw = typeof response.headers.getSetCookie === 'function'
			? response.headers.getSetCookie()
			: [];
		for (const line of raw) {
			const [pair] = line.split(';');
			const idx = pair.indexOf('=');
			if (idx > 0) {
				this.cookies.set(pair.slice(0, idx).trim(), pair.slice(idx + 1).trim());
			}
		}
	}

	async raw(method, path, { body, rawBody, headers = {}, form, redirect = 'manual', readText = false } = {}) {
		const url = `${this.baseUrl}${path}`;
		const init = { method, redirect, headers: { ...headers } };

		const cookie = this.cookieHeader();
		if (cookie) init.headers['Cookie'] = cookie;

		if (form) {
			init.headers['Content-Type'] = 'application/x-www-form-urlencoded';
			init.body = new URLSearchParams(form).toString();
		} else if (rawBody !== undefined) {
			// The file upload routes take the bytes as the request body rather than a JSON
			// envelope, so they need a way past the encoder. Caller sets Content-Type.
			init.body = rawBody;
		} else if (body !== undefined) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(body);
		}

		// **The timeout has to cover reading the body, not just receiving the headers.**
		//
		// `fetch()` resolves as soon as the response headers arrive, so clearing the timer
		// there leaves `response.text()` unbounded: a server that sends headers and then
		// stalls the body hangs the harness for as long as it likes. One did — a fixture-stage
		// `POST /users` sat for 286 seconds against a 180-second timeout — and a suite whose
		// stated timeout does not bound its requests cannot report a bounded failure.
		//
		// So the body is read inside the same abort window, and `readText` exists because the
		// caller that needs the text is the one that has to be covered.
		const controller = new AbortController();
		const timer = setTimeout(() => controller.abort(), this.timeoutMs);
		init.signal = controller.signal;

		let response;
		let text;
		try {
			response = await fetch(url, init);
			if (readText) text = await response.text();
		} finally {
			clearTimeout(timer);
		}
		this.absorbCookies(response);
		return readText ? { response, text } : response;
	}

	// Logs in as admin. Upstream creates that user in migration 0027 as admin/admin; the fork
	// seeds it with VICTUAL_BOOTSTRAP_ADMIN_PASSWORD instead and refuses the API to an account
	// that logs in with "admin", so its password is VICTUAL_ADMIN_PASSWORD below - the value
	// stack/stack.sh hands the fork's migrate container.
	async login(username = 'admin', password = this.adminPassword) {
		const response = await this.raw('POST', '/login', {
			form: { username, password }
		});
		// Both outcomes redirect, on both applications: to the app on success, to
		// /login?invalid=true on refused credentials. So the status alone accepts a wrong
		// password - which is what this said until 2026-09-19, when the bootstrap handover
		// asserted that a replaced password no longer logs in and found it "still" did.
		const location = response.headers.get('location') || '';
		const ok = response.status >= 300 && response.status < 400 && !/[?&]invalid=/.test(location);
		if (!ok) {
			throw new Error(
				`${this.name}: login failed (HTTP ${response.status}${location ? ` to ${location}` : ''}). ` +
				'A failure here usually means the instance did not migrate; on the fork side it can ' +
				'also mean there is no stack password to read (reports/.victual-admin-password) or ' +
				'PARITY_VICTUAL_ADMIN_PASSWORD names a different one.'
			);
		}
		return true;
	}

	// The scenario-facing call. Returns a plain record rather than a Response so that a
	// scenario cannot accidentally depend on header order or on the stream being read
	// twice, and appends it to the trace that gets diffed.
	async api(method, path, body, options = {}) {
		const { response, text } = await this.raw(method, `/api${path}`, { body, ...options, readText: true });

		let parsed;
		let parseError = null;
		if (text.length === 0) {
			parsed = null;
		} else {
			try {
				parsed = JSON.parse(text);
			} catch (e) {
				parseError = e.message;
				// Kept whole rather than summarised: when an endpoint answers HTML the
				// first line of it is usually the reason.
				parsed = { __unparsed: text.slice(0, 2000) };
			}
		}

		const record = {
			label: options.label || `${method} ${path}`,
			method,
			path,
			request: body === undefined ? null : body,
			status: response.status,
			contentType: response.headers.get('content-type'),
			body: parsed,
			parseError
		};

		if (this.recording) this.trace.push(record);
		return record;
	}

	get(path, options) { return this.api('GET', path, undefined, options); }
	post(path, body, options) { return this.api('POST', path, body, options); }
	put(path, body, options) { return this.api('PUT', path, body, options); }
	delete(path, options) { return this.api('DELETE', path, undefined, options); }

	// Runs a block without recording it. For arranging state a scenario needs but is not
	// asserting on — the trace stays a statement of what the scenario checks.
	async silently(fn) {
		const was = this.recording;
		this.recording = false;
		try {
			return await fn();
		} finally {
			this.recording = was;
		}
	}

	resetTrace() {
		this.trace = [];
	}
}

// The fork's administrator password: PARITY_VICTUAL_ADMIN_PASSWORD when bin/parity (or you)
// set it, otherwise the per-stack random one stack/stack.sh wrote for a fresh database. There
// is no fixed fallback any more, so a harness started with no stack says so at login.
function readAdminPassword() {
	if (process.env.PARITY_VICTUAL_ADMIN_PASSWORD) return process.env.PARITY_VICTUAL_ADMIN_PASSWORD;
	const path = require('path');
	const dir = process.env.PARITY_STATE_DIR || process.env.PARITY_REPORTS || path.join(__dirname, '..', '..', 'reports');
	try {
		return require('fs').readFileSync(path.join(dir, '.victual-admin-password'), 'utf8').trim();
	} catch {
		return '';
	}
}
const VICTUAL_ADMIN_PASSWORD = readAdminPassword();

function victual(baseUrl) {
	return new Instance({ name: 'victual', baseUrl, apiKeyHeader: 'VICTUAL-API-KEY', adminPassword: VICTUAL_ADMIN_PASSWORD });
}

function upstream(baseUrl) {
	return new Instance({ name: 'upstream', baseUrl, apiKeyHeader: 'GROCY-API-KEY' });
}

module.exports = { Instance, victual, upstream, VICTUAL_ADMIN_PASSWORD };
