'use strict';

// The MCP sidecar, end to end, against the stack's own Victual.
//
//   node run-mcp.js --victual http://127.0.0.1:8080 --mcp http://127.0.0.1:8082/mcp
//
// **Fork-only, so the oracle is the fork's REST API, not upstream.** Every one of the six
// tools (docs/mcp-interface-spec.md §5) is a reshaping of one or two Victual GETs, so each
// is checked against the GET it wraps: the same product ids, the same totals, the same
// sections. That is the question worth asking of a shipped image — the sidecar's own
// node:test suite (mcp/tests/) runs the handlers against canned responses and cannot see a
// Victual that answers differently from the canned ones.
//
// Four groups, in order:
//
//   transport   /healthz, and a request with no credential refused before any JSON-RPC
//   keys        an MCP-type read-only key minted the way a person mints one (the
//               /manageapikeys form), and what Victual says it may do; a regular key and a
//               garbage key refused through the sidecar; the MCP key refused a write
//   tools       the official SDK v2 client: negotiation, tools/list, each tool against its
//               REST oracle, and an out-of-range argument refused
//   filter      a user holding only STOCK_VIEW sees only the four stock tools, and is
//               refused the shopping list when it asks anyway
//
// **Run it after `api`**, which is where `parity all` puts it: against an empty database
// every comparison is two empty lists agreeing, which cannot fail. Each check says how many
// rows it compared, so a vacuous pass is visible as one.
//
// What it leaves behind, on the fork only: two API keys for admin, one user
// (`parity-mcp-limited`) with one key. That is why it runs last in `all`.

const fs = require('fs');
const path = require('path');

const { victual } = require('./lib/instance');

function parseArgs(argv) {
	const args = {
		victual: process.env.PARITY_VICTUAL_URL || 'http://127.0.0.1:8080',
		mcp: process.env.PARITY_MCP_URL || 'http://127.0.0.1:8082/mcp',
		out: path.join(__dirname, '..', 'reports')
	};
	for (let i = 2; i < argv.length; i++) {
		if (argv[i] === '--victual') args.victual = argv[++i];
		else if (argv[i] === '--mcp') args.mcp = argv[++i];
		else if (argv[i] === '--out') args.out = argv[++i];
	}
	return args;
}

const results = [];
function check(group, name, ok, detail) {
	results.push({ group, name, ok: !!ok, detail: detail || null });
	console.log(`  ${ok ? '\x1b[32mPASS\x1b[0m' : '\x1b[31mFAIL\x1b[0m'}  ${name}`);
	if (detail) console.log(`        ${detail}`);
	return !!ok;
}

// --- Keys -----------------------------------------------------------------------------------

// POST /manageapikeys/new is the form a person uses; the plaintext exists only in the page it
// answers with (the table stores a hash), in <code id="new-api-key-value">.
async function mintKey(instance, { type, readOnly, description }) {
	const form = { description, key_type: type };
	if (readOnly) form.read_only = '1';
	const { response, text } = await instance.raw('POST', '/manageapikeys/new', { form, readText: true });
	const match = text.match(/id="new-api-key-value">\s*([^<\s]+)\s*</);
	if (response.status !== 200 || !match) {
		throw new Error(`minting a ${type} key answered HTTP ${response.status}` +
			(match ? '' : ', with no key in the page'));
	}
	return match[1];
}

// Victual called directly with a key, the way the sidecar calls it (src/auth/resolver.ts):
// VICTUAL-API-KEY plus the type header that narrows the lookup to MCP keys.
async function victualWithKey(base, method, urlPath, key, { mcpType = true, body } = {}) {
	const headers = { 'VICTUAL-API-KEY': key };
	if (mcpType) headers['VICTUAL-API-KEY-TYPE'] = 'mcp';
	if (body !== undefined) headers['Content-Type'] = 'application/json';
	const response = await fetch(`${base}/api${urlPath}`, {
		method, headers, body: body === undefined ? undefined : JSON.stringify(body)
	});
	const text = await response.text();
	let parsed = null;
	try { parsed = text ? JSON.parse(text) : null; } catch { parsed = text.slice(0, 300); }
	return { status: response.status, body: parsed };
}

// A bare JSON-RPC tools/list, for the refusals: the SDK client would hide the HTTP status
// behind its own error, and the status is the thing under test.
async function rawToolsList(mcpUrl, key) {
	const headers = {
		'Content-Type': 'application/json',
		Accept: 'application/json, text/event-stream'
	};
	if (key !== null) headers.Authorization = `Bearer ${key}`;
	const response = await fetch(mcpUrl, {
		method: 'POST', headers,
		body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/list', params: {} })
	});
	return { status: response.status, wwwAuthenticate: response.headers.get('www-authenticate'), text: await response.text() };
}

// --- The client -----------------------------------------------------------------------------

async function connect(mcpUrl, key) {
	// ESM-only, and this harness is CommonJS: a dynamic import is the one bridge.
	const { Client, StreamableHTTPClientTransport } = await import('@modelcontextprotocol/client');
	// `auto` is what a current client does: server/discover first, falling back to the 2025
	// handshake. The SDK's own default is `legacy`, which would report 2025 whatever the
	// server supports (mcp/scripts/probe.mjs has the same note).
	const client = new Client({ name: 'victual-parity', version: '1.0.0' }, { versionNegotiation: { mode: 'auto' } });
	const transport = new StreamableHTTPClientTransport(new URL(mcpUrl), {
		requestInit: { headers: { authorization: `Bearer ${key}` } }
	});
	await client.connect(transport);
	return client;
}

async function callTool(client, name, args) {
	try {
		const result = await client.callTool({ name, arguments: args });
		return { result, error: null };
	} catch (error) {
		return { result: null, error };
	}
}

const ids = (rows, field = 'product_id') => [...new Set(rows.map((r) => Number(r[field])))].sort((a, b) => a - b);
const same = (a, b) => a.length === b.length && a.every((v, i) => v === b[i]);
const describeDiff = (got, want) => {
	const missing = want.filter((v) => !got.includes(v));
	const extra = got.filter((v) => !want.includes(v));
	return `got ${got.length}, REST ${want.length}` +
		(missing.length ? `; missing ${missing.slice(0, 8).join(',')}` : '') +
		(extra.length ? `; extra ${extra.slice(0, 8).join(',')}` : '');
};

// A tool result is usable when it is not an error and carries structuredContent — the
// §5 contract. Anything else is reported with the text the tool gave.
function usable(group, name, call) {
	if (call.error) return check(group, name, false, `threw: ${String(call.error.message || call.error).slice(0, 200)}`) && null;
	if (call.result.isError) {
		const text = (call.result.content || []).map((c) => c.text).join(' ');
		return check(group, name, false, `isError: ${text.slice(0, 200)}`) && null;
	}
	if (!call.result.structuredContent) return check(group, name, false, 'no structuredContent') && null;
	return call.result.structuredContent;
}

// --- Phase ----------------------------------------------------------------------------------

async function main() {
	const args = parseArgs(process.argv);
	const mcpBase = args.mcp.replace(/\/mcp\/?$/, '');
	const admin = victual(args.victual);
	await admin.login();
	const rest = async (p) => {
		const record = await admin.silently(() => admin.get(p));
		if (record.status !== 200) throw new Error(`oracle GET ${p} answered HTTP ${record.status}`);
		return record.body;
	};

	console.log('');
	console.log('\x1b[1mtransport\x1b[0m');
	const health = await fetch(`${mcpBase}/healthz`);
	const healthBody = await health.text();
	check('transport', 'GET /healthz answers 200 with an empty body', health.status === 200 && healthBody === '',
		`HTTP ${health.status}, ${healthBody.length} bytes`);
	const anonymous = await rawToolsList(args.mcp, null);
	check('transport', 'no credential is a 401 before any JSON-RPC, with a Bearer challenge',
		anonymous.status === 401 && /^Bearer /.test(anonymous.wwwAuthenticate || ''),
		`HTTP ${anonymous.status}, WWW-Authenticate ${anonymous.wwwAuthenticate}`);

	console.log('');
	console.log('\x1b[1mkeys\x1b[0m');
	const mcpKey = await mintKey(admin, { type: 'mcp', readOnly: true, description: 'parity mcp (read-only)' });
	const regularKey = await mintKey(admin, { type: 'default', readOnly: false, description: 'parity regular key' });
	check('keys', 'an MCP-type read-only key is minted through /manageapikeys/new', true,
		`${mcpKey.length}-character key`);

	const caps = await victualWithKey(args.victual, 'GET', '/user/capabilities', mcpKey);
	const capsBody = caps.body || {};
	check('keys', 'GET /api/user/capabilities describes the key as mcp and read-only',
		caps.status === 200 && capsBody.key_type === 'mcp' && capsBody.read_only === true &&
			Array.isArray(capsBody.permissions),
		`HTTP ${caps.status}, ${JSON.stringify({ key_type: capsBody.key_type, read_only: capsBody.read_only, permissions: (capsBody.permissions || []).length })}`);

	const write = await victualWithKey(args.victual, 'POST', '/objects/locations', mcpKey,
		{ body: { name: 'parity-mcp-must-not-exist' } });
	check('keys', 'the read-only MCP key is refused a write (POST /api/objects/locations)', write.status === 403,
		`HTTP ${write.status}${write.body && write.body.error_message ? `: ${write.body.error_message}` : ''}`);

	const regularDirect = await victualWithKey(args.victual, 'GET', '/stock', regularKey, { mcpType: false });
	const regularViaMcp = await rawToolsList(args.mcp, regularKey);
	check('keys', 'a regular key works on Victual but is refused through the sidecar',
		regularDirect.status === 200 && regularViaMcp.status === 401,
		`direct HTTP ${regularDirect.status}, through the sidecar HTTP ${regularViaMcp.status}`);

	const garbage = await rawToolsList(args.mcp, 'not-a-key-0000000000000000');
	check('keys', 'a garbage key is refused through the sidecar', garbage.status === 401, `HTTP ${garbage.status}`);

	console.log('');
	console.log('\x1b[1mtools\x1b[0m');
	const client = await connect(args.mcp, mcpKey);
	const negotiated = client.getNegotiatedProtocolVersion();
	check('tools', 'the SDK v2 client connects and negotiates a protocol revision', Boolean(negotiated),
		`${negotiated} (${client.getProtocolEra()}), server ${JSON.stringify(client.getServerVersion())}`);

	const listed = (await client.listTools()).tools.map((t) => t.name).sort();
	const ALL = ['expiring_soon', 'find_product', 'missing_products', 'recipes_i_can_cook', 'shopping_list', 'stock_overview'];
	check('tools', 'tools/list offers all six read tools to an administrator\'s key', same(listed, ALL), listed.join(', '));

	// stock_overview ⇔ GET /api/stock
	const stock = await rest('/stock');
	const so = usable('tools', 'stock_overview', await callTool(client, 'stock_overview', { limit: 200 }));
	if (so) {
		const got = ids(so.rows);
		const want = ids(stock);
		const amountsAgree = so.rows.every((row) => {
			const r = stock.find((s) => Number(s.product_id) === row.product_id);
			return r && Math.abs(Number(r.amount) - row.amount) < 1e-9;
		});
		check('tools', 'stock_overview has GET /api/stock\'s products and amounts',
			so.total === stock.length && (stock.length > 200 || same(got, want)) && amountsAgree,
			`${describeDiff(got, want)}, total ${so.total}, amounts ${amountsAgree ? 'agree' : 'differ'}`);
	}

	// expiring_soon ⇔ GET /api/stock/volatile?due_soon_days=5
	const volatile5 = await rest('/stock/volatile?due_soon_days=5');
	const es = usable('tools', 'expiring_soon', await callTool(client, 'expiring_soon', { days: 5, limit: 200 }));
	if (es) {
		const sections = [['due', 'due_products'], ['overdue', 'overdue_products'], ['expired', 'expired_products']];
		const detail = sections.map(([tool, api]) => `${tool}: ${describeDiff(ids(es[tool]), ids(volatile5[api] || []))}`);
		check('tools', 'expiring_soon\'s three sections match GET /api/stock/volatile',
			sections.every(([tool, api]) => same(ids(es[tool]), ids(volatile5[api] || []))), detail.join('; '));
	}

	// missing_products ⇔ volatile.missing_products
	const volatile = await rest('/stock/volatile');
	const mp = usable('tools', 'missing_products', await callTool(client, 'missing_products', { limit: 200 }));
	if (mp) {
		const want = ids(volatile.missing_products || [], 'id');
		check('tools', 'missing_products matches GET /api/stock/volatile\'s missing_products',
			mp.total === want.length && same(ids(mp.rows), want), describeDiff(ids(mp.rows), want));
	}

	// find_product ⇔ GET /api/objects/products, for a product that is in stock
	const products = await rest('/objects/products');
	const stocked = stock.map((s) => products.find((p) => Number(p.id) === Number(s.product_id)))
		.find((p) => p && Number(p.active) === 1 && /^[\p{L}\p{M}0-9 ._-]{3,}$/u.test(p.name));
	if (!stocked) {
		check('tools', 'find_product', false, 'no active product in stock to search for — run the api phase first');
	} else {
		const term = stocked.name.slice(0, Math.min(stocked.name.length, 6));
		const fp = usable('tools', 'find_product', await callTool(client, 'find_product', { query: term, limit: 25 }));
		if (fp) {
			const hit = fp.rows.find((r) => r.product_id === Number(stocked.id));
			const want = stock.filter((s) => Number(s.product_id) === Number(stocked.id))
				.reduce((sum, s) => sum + Number(s.amount), 0);
			const needle = term.toLocaleLowerCase();
			const expected = ids(products.filter((p) => Number(p.active) === 1 &&
				p.name.toLocaleLowerCase().includes(needle)), 'id');
			check('tools', `find_product("${term}") finds ${stocked.name} with its stock amount, and only matches`,
				hit && Math.abs(hit.in_stock_amount - want) < 1e-9 && same(ids(fp.rows), expected),
				`${hit ? `in_stock_amount ${hit.in_stock_amount} (REST ${want})` : 'not found'}; ${describeDiff(ids(fp.rows), expected)}`);
		}
	}

	// shopping_list ⇔ GET /api/objects/shopping_list?query[]=shopping_list_id=1
	const items = await rest('/objects/shopping_list?query%5B%5D=shopping_list_id%3D1');
	const sl = usable('tools', 'shopping_list', await callTool(client, 'shopping_list', { shopping_list_id: 1, limit: 200 }));
	if (sl) {
		const want = ids(items, 'id');
		check('tools', 'shopping_list matches GET /api/objects/shopping_list for list 1',
			sl.total === items.length && same(ids(sl.rows, 'item_id'), want), describeDiff(ids(sl.rows, 'item_id'), want));
	}

	// recipes_i_can_cook ⇔ GET /api/recipes/fulfillment ∩ type=normal
	const fulfillment = await rest('/recipes/fulfillment');
	const recipes = await rest('/objects/recipes');
	const normal = new Set(recipes.filter((r) => r.type === 'normal').map((r) => Number(r.id)));
	const truthy = (v) => v === true || v === 1 || v === '1';
	const wantCook = ids(fulfillment.filter((f) => normal.has(Number(f.recipe_id)) && truthy(f.need_fulfilled)), 'recipe_id');
	const wantLittle = ids(fulfillment.filter((f) => normal.has(Number(f.recipe_id)) && !truthy(f.need_fulfilled) &&
		truthy(f.need_fulfilled_with_shopping_list)), 'recipe_id');
	const rc = usable('tools', 'recipes_i_can_cook', await callTool(client, 'recipes_i_can_cook', { limit: 50 }));
	if (rc) {
		check('tools', 'recipes_i_can_cook matches GET /api/recipes/fulfillment for normal recipes',
			same(ids(rc.can_cook, 'recipe_id'), wantCook) && same(ids(rc.missing_little, 'recipe_id'), wantLittle),
			`can_cook ${describeDiff(ids(rc.can_cook, 'recipe_id'), wantCook)}; ` +
			`missing_little ${describeDiff(ids(rc.missing_little, 'recipe_id'), wantLittle)}`);
	}

	const bad = await callTool(client, 'stock_overview', { limit: 0 });
	check('tools', 'an out-of-range argument is refused (stock_overview limit 0)',
		Boolean(bad.error) || (bad.result && bad.result.isError),
		bad.error ? `threw: ${String(bad.error.message).slice(0, 120)}` : `isError ${bad.result && bad.result.isError}`);
	await client.close();

	console.log('');
	console.log('\x1b[1mfilter\x1b[0m');
	await limitedUserChecks(args, admin);

	const failed = results.filter((r) => !r.ok);
	fs.mkdirSync(args.out, { recursive: true });
	fs.writeFileSync(path.join(args.out, 'mcp.json'),
		`${JSON.stringify({ recordedAt: new Date().toISOString(), negotiated, results }, null, '\t')}\n`);
	console.log('');
	console.log(failed.length === 0
		? `\x1b[32m${results.length} MCP checks passed\x1b[0m`
		: `\x1b[31m${failed.length} of ${results.length} MCP checks failed\x1b[0m`);
	process.exit(failed.length === 0 ? 0 : 1);
}

// A user holding STOCK_VIEW and nothing else. tools/list is filtered by what
// GET /api/user/capabilities reports (§5), and a tool it hides is still refused by Victual
// when asked for by name — the filter is presentation, the permission check is Victual's.
async function limitedUserChecks(args, admin) {
	const username = 'parity-mcp-limited';
	const password = 'parity-mcp-limited-password';
	const hierarchy = await admin.silently(() => admin.get('/objects/permission_hierarchy'));
	const stockView = (hierarchy.body || []).find((p) => p.name === 'STOCK_VIEW');
	if (!stockView) {
		check('filter', 'a STOCK_VIEW-only user sees four tools', false, 'no STOCK_VIEW in permission_hierarchy');
		return;
	}
	await admin.silently(() => admin.post('/users', { username, password }));
	const users = await admin.silently(() => admin.get('/users'));
	const user = (users.body || []).find((u) => u.username === username);
	if (!user) {
		check('filter', 'a STOCK_VIEW-only user sees four tools', false, `could not create ${username}`);
		return;
	}
	const set = await admin.silently(() => admin.put(`/users/${user.id}/permissions`, { permissions: [Number(stockView.id)] }));
	if (set.status !== 204) {
		check('filter', 'a STOCK_VIEW-only user sees four tools', false, `setting permissions answered HTTP ${set.status}`);
		return;
	}

	const limited = victual(args.victual);
	await limited.login(username, password);
	let key;
	try {
		key = await mintKey(limited, { type: 'mcp', readOnly: true, description: 'parity mcp (limited user)' });
	} catch (e) {
		check('filter', 'a STOCK_VIEW-only user can mint an MCP key', false, e.message);
		return;
	}
	const client = await connect(args.mcp, key);
	const listed = (await client.listTools()).tools.map((t) => t.name).sort();
	const want = ['expiring_soon', 'find_product', 'missing_products', 'stock_overview'];
	check('filter', 'a STOCK_VIEW-only user sees only the four stock tools', same(listed, want), listed.join(', '));
	const refused = await callTool(client, 'shopping_list', {});
	const text = refused.result ? (refused.result.content || []).map((c) => c.text).join(' ') : String(refused.error);
	check('filter', 'and asking for shopping_list by name is refused by Victual',
		(refused.result && refused.result.isError && /forbidden/i.test(text)) || Boolean(refused.error),
		text.slice(0, 200));
	const allowed = usable('filter', 'stock_overview for the limited user', await callTool(client, 'stock_overview', {}));
	if (allowed) check('filter', 'while stock_overview answers it', true, `${allowed.total} products`);
	await client.close();
}

main().catch((error) => {
	console.error(`run-mcp: ${error.stack || error}`);
	process.exit(2);
});
