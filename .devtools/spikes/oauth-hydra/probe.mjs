// Drives Hydra through the flows issue #770 needs and prints what actually happens.
// Usage: node probe.mjs   (needs run.sh up and idp-stub.mjs running)
import { createHash, randomBytes } from "node:crypto";

const PUB = "http://localhost:4444", ADMIN = "http://localhost:4445";
const REDIRECT = "http://localhost:8976/callback";
const b64u = (b) => Buffer.from(b).toString("base64url");
const decode = (jwt) => JSON.parse(Buffer.from(jwt.split(".")[1], "base64url").toString());
const j = async (url, init) => { const r = await fetch(url, init); const t = await r.text(); let b; try { b = JSON.parse(t); } catch { b = t; } return { status: r.status, body: b, headers: r.headers }; };
const form = (o) => ({ method: "POST", headers: { "content-type": "application/x-www-form-urlencoded" }, body: new URLSearchParams(o) });
const show = (name, v) => console.log(`\n## ${name}\n${typeof v === "string" ? v : JSON.stringify(v, null, 1)}`);

async function createClient(extra = {}) {
  const r = await j(`${ADMIN}/admin/clients`, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({
    client_name: "probe", redirect_uris: [REDIRECT], grant_types: ["authorization_code", "refresh_token"], response_types: ["code"],
    token_endpoint_auth_method: "none", scope: "openid offline_access victual.read victual.write", ...extra }) });
  if (r.status >= 300) throw new Error(JSON.stringify(r.body));
  return r.body;
}

// Follow Hydra -> login stub -> Hydra -> consent stub -> Hydra -> redirect_uri, with a cookie jar.
async function authorize(params, { verifier } = {}) {
  const jar = new Map();
  const cookie = () => [...jar].map(([k, v]) => `${k}=${v}`).join("; ");
  let url = `${PUB}/oauth2/auth?` + new URLSearchParams(params);
  for (let i = 0; i < 12; i++) {
    const res = await fetch(url, { redirect: "manual", headers: { cookie: cookie() } });
    for (const c of res.headers.getSetCookie()) { const [kv] = c.split(";"); const [k, ...v] = kv.split("="); jar.set(k, v.join("=")); }
    const loc = res.headers.get("location");
    if (!loc) return { final: url, status: res.status, body: await res.text() };
    url = new URL(loc, url).toString();
    if (url.startsWith(REDIRECT)) return { redirect: new URL(url) };
  }
  throw new Error("redirect loop");
}

function pkce() { const verifier = b64u(randomBytes(32)); return { verifier, challenge: b64u(createHash("sha256").update(verifier).digest()) }; }

const client = await createClient({ audience: ["http://localhost:8080/mcp"] });
const base = (p) => ({ client_id: client.client_id, response_type: "code", redirect_uri: REDIRECT, scope: "openid offline_access victual.read", state: b64u(randomBytes(8)), code_challenge: p.challenge, code_challenge_method: "S256" });

// T1: code + PKCE, no resource/audience
{ const p = pkce(); const a = await authorize(base(p));
  const code = a.redirect.searchParams.get("code");
  const t = await j(`${PUB}/oauth2/token`, form({ grant_type: "authorization_code", code, code_verifier: p.verifier, client_id: client.client_id, redirect_uri: REDIRECT }));
  show("T1 code+PKCE token response keys", Object.keys(t.body));
  show("T1 access token claims", decode(t.body.access_token));
  globalThis.T1 = t.body; }

// T2: public client without PKCE
{ const noPkce = base({}); delete noPkce.code_challenge; delete noPkce.code_challenge_method; const a = await authorize(noPkce);
  show("T2 no PKCE (public client)", a.redirect ? Object.fromEntries(a.redirect.searchParams) : { status: a.status, body: a.body }); }

// T3: RFC 8707 `resource` on /auth and /token vs Hydra's `audience`
for (const [label, extraAuth, extraTok] of [
  ["resource= (RFC 8707), allowed by consent shim", { resource: "http://localhost:8080/mcp" }, { resource: "http://localhost:8080/mcp" }],
  ["audience= (Hydra native)", { audience: "http://localhost:8080/mcp" }, {}],
  ["resource= naming a resource the consent shim refuses", { resource: "http://evil.example/mcp" }, {}],
  ["audience= naming an unregistered audience", { audience: "http://evil.example/mcp" }, {}],
]) {
  const p = pkce(); const a = await authorize({ ...base(p), ...extraAuth });
  if (!a.redirect) { show(`T3 ${label}`, { status: a.status, body: String(a.body).slice(0, 200) }); continue; }
  const err = a.redirect.searchParams.get("error");
  if (err) { show(`T3 ${label}`, { authorize_error: err, description: a.redirect.searchParams.get("error_description") }); continue; }
  const t = await j(`${PUB}/oauth2/token`, form({ grant_type: "authorization_code", code: a.redirect.searchParams.get("code"), code_verifier: p.verifier, client_id: client.client_id, redirect_uri: REDIRECT, ...extraTok }));
  show(`T3 ${label}`, t.body.access_token ? { aud: decode(t.body.access_token).aud, scp: decode(t.body.access_token).scp } : t.body);
}

// T5: refresh rotation and reuse detection
{ const rt1 = T1.refresh_token;
  const r1 = await j(`${PUB}/oauth2/token`, form({ grant_type: "refresh_token", refresh_token: rt1, client_id: client.client_id }));
  const r2 = await j(`${PUB}/oauth2/token`, form({ grant_type: "refresh_token", refresh_token: rt1, client_id: client.client_id }));
  const r3 = await j(`${PUB}/oauth2/token`, form({ grant_type: "refresh_token", refresh_token: r1.body.refresh_token, client_id: client.client_id }));
  show("T5 refresh: first use / reuse of old / use of its successor after reuse", [r1.status, r2.status, r3.status]); }

// T6: client_credentials
{ const svc = await createClient({ grant_types: ["client_credentials"], response_types: [], token_endpoint_auth_method: "client_secret_basic", scope: "victual.read", audience: ["http://localhost:8080/mcp"] });
  const t = await j(`${PUB}/oauth2/token`, { ...form({ grant_type: "client_credentials", scope: "victual.read", audience: "http://localhost:8080/mcp" }), headers: { "content-type": "application/x-www-form-urlencoded", authorization: "Basic " + Buffer.from(`${svc.client_id}:${svc.client_secret}`).toString("base64") } });
  show("T6 client_credentials", t.body.access_token ? decode(t.body.access_token) : t.body); }

// T8: revocation vs stateless validation, and introspection
{ const p = pkce(); const a = await authorize(base(p));
  const t = await j(`${PUB}/oauth2/token`, form({ grant_type: "authorization_code", code: a.redirect.searchParams.get("code"), code_verifier: p.verifier, client_id: client.client_id, redirect_uri: REDIRECT }));
  const at = t.body.access_token;
  const before = await j(`${ADMIN}/admin/oauth2/introspect`, form({ token: at }));
  const rev = await j(`${PUB}/oauth2/revoke`, form({ token: at, client_id: client.client_id }));
  const after = await j(`${ADMIN}/admin/oauth2/introspect`, form({ token: at }));
  show("T8 introspect before / revoke status / introspect after", [before.body.active, rev.status, after.body.active]); }

// T9: JWKS
{ const k = await j(`${PUB}/.well-known/jwks.json`); show("T9 JWKS", k.body.keys?.map((x) => ({ kid: x.kid, kty: x.kty, alg: x.alg, use: x.use }))); }

// T7: device flow (CLI / headless clients)
{ const dev = await createClient({ grant_types: ["urn:ietf:params:oauth:grant-type:device_code", "refresh_token"], response_types: [], redirect_uris: [], audience: ["http://localhost:8080/mcp"] });
  const d = await j(`${PUB}/oauth2/device/auth`, form({ client_id: dev.client_id, scope: "victual.read offline_access", audience: "http://localhost:8080/mcp" }));
  show("T7 device/auth response keys", d.status === 200 ? Object.keys(d.body) : d);
  if (d.status === 200) {
    const pending = await j(`${PUB}/oauth2/token`, form({ grant_type: "urn:ietf:params:oauth:grant-type:device_code", device_code: d.body.device_code, client_id: dev.client_id }));
    show("T7 poll before approval", pending.body.error);
    // The "user" enters the code in a browser: verify -> /device -> login -> consent -> done.
    const jar = new Map(); const cookie = () => [...jar].map(([k, v]) => `${k}=${v}`).join("; ");
    let url = `${PUB}/oauth2/device/verify?user_code=${d.body.user_code}`;
    for (let i = 0; i < 12; i++) {
      const r = await fetch(url, { redirect: "manual", headers: { cookie: cookie() } });
      for (const c of r.headers.getSetCookie()) { const [kv] = c.split(";"); const [k, ...v] = kv.split("="); jar.set(k, v.join("=")); }
      const loc = r.headers.get("location"); if (!loc) { show("T7 browser end", { status: r.status, url }); break; } url = new URL(loc, url).toString();
    }
    const tok = await j(`${PUB}/oauth2/token`, form({ grant_type: "urn:ietf:params:oauth:grant-type:device_code", device_code: d.body.device_code, client_id: dev.client_id }));
    show("T7 poll after approval", tok.body.access_token ? { claims: decode(tok.body.access_token), has_refresh: !!tok.body.refresh_token } : tok.body);
  } }

// T10: token -> resource server (needs rs.mjs running on :8080)
{ const mcp = (token) => fetch("http://localhost:8080/mcp", { method: "POST", headers: { "content-type": "application/json", accept: "application/json, text/event-stream", authorization: `Bearer ${token}` },
    body: JSON.stringify({ jsonrpc: "2.0", id: 1, method: "initialize", params: { protocolVersion: "2025-11-25", capabilities: {}, clientInfo: { name: "probe", version: "0" } } }) });
  const get = async (extra) => { const p = pkce(); const a = await authorize({ ...base(p), ...extra }); const t = await j(`${PUB}/oauth2/token`, form({ grant_type: "authorization_code", code: a.redirect.searchParams.get("code"), code_verifier: p.verifier, client_id: client.client_id, redirect_uri: REDIRECT })); return t.body.access_token; };
  const good = await get({ resource: "http://localhost:8080/mcp" });
  const noAud = await get({});
  const out = {};
  out["token with aud=resource"] = (await mcp(good)).status;
  out["token with empty aud"] = (await mcp(noAud)).status;
  const tampered = good.slice(0, -4) + (good.endsWith("AAAA") ? "BBBB" : "AAAA");
  out["tampered signature"] = (await mcp(tampered)).status;
  // revoke, then use: stateless validation keeps accepting until exp
  await j(`${PUB}/oauth2/revoke`, form({ token: good, client_id: client.client_id }));
  out["revoked token (stateless validation)"] = (await mcp(good)).status;
  show("T10 resource-server status by token", out); }
