// Authentik compatibility probe for ADR-0055 / ADR-0056. Needs `./run.sh up && node setup.mjs`.
// Each case names the record clause it checks, what that clause requires, and what Authentik
// did. "pass" means Authentik (or the resource-server check below) behaved as the record
// requires; "FAIL" means it did not. Writes results.json and prints a Markdown table.
//
// The resource-server check is the one ADR-0055 decision 3 describes, configured with an
// issuer, a discovery URL and one audience only: RS256 against the JWKS, iss, exp/nbf with 60 s
// skew, aud must contain the configured audience, empty aud refused. No Authentik-specific code.
//
// User tokens come from authorization code + PKCE through a headless scripted login (case G4,
// codeFlow below) for the providers that have the code grant; the app-password grant remains for
// the short-lived provider and the Actor.
import { createHash, createPublicKey, randomBytes, verify } from "node:crypto";
import { execFileSync } from "node:child_process";
import { readFileSync, writeFileSync } from "node:fs";

const S = JSON.parse(readFileSync(new URL("./state.json", import.meta.url)));
const AK = S.ak, T = `${AK}/application/o/token/`;
const ADMIN = { Authorization: "Bearer spike-only-bootstrap-token-0123456789", "Content-Type": "application/json" };
const TX = "urn:ietf:params:oauth:grant-type:token-exchange";
const AT = "urn:ietf:params:oauth:token-type:access_token";
const { mcp, mcpB, mcpScalar, sidecar, sidecarB, api, other, rogue, noGrant, short } = S.providers;
const { alice, bob } = S.users;
const results = [];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const claims = (t) => JSON.parse(Buffer.from(t.split(".")[1], "base64url"));

async function token(body) {
  const r = await fetch(T, { method: "POST", body: new URLSearchParams(body) });
  return { status: r.status, ...(await r.json()) };
}
const appPasswordToken = (p, u, scope = "openid profile victual:read") =>
  token({ grant_type: "client_credentials", client_id: p.client_id, client_secret: p.client_secret, username: u.username, password: u.app_password, scope });
const REDIRECT = "http://localhost:8765/callback";
// Authorization code + PKCE (S256) with a scripted login through Authentik's flow executor API:
// no browser. Keeps a cookie jar by hand; a POST to the executor answers 302 to the executor.
async function codeFlow(p, u, scope, { password = u.password, pkce = "S256" } = {}) {
  const jar = new Map();
  const cookie = () => [...jar].map(([k, v]) => `${k}=${v}`).join("; ");
  async function req(method, url, body) {
    const headers = { Cookie: cookie() };
    if (body) { headers["Content-Type"] = "application/json"; headers["X-authentik-CSRF"] = jar.get("authentik_csrf") ?? ""; }
    const r = await fetch(url.startsWith("http") ? url : AK + url, { method, headers, body: body && JSON.stringify(body), redirect: "manual" });
    for (const c of r.headers.getSetCookie()) { const [kv] = c.split(";"); const i = kv.indexOf("="); jar.set(kv.slice(0, i), kv.slice(i + 1)); }
    return r;
  }
  async function challenge(method, url, body) {
    let r = await req(method, url, body);
    for (let i = 0; i < 6 && r.status === 302 && r.headers.get("location").startsWith("/api/v3/flows/executor/"); i++) r = await req("GET", r.headers.get("location"));
    if (r.status === 302) return { component: "xak-flow-redirect", to: r.headers.get("location") };
    return r.json();
  }
  async function runFlow(slug, query) {
    const ex = `/api/v3/flows/executor/${slug}/?query=${encodeURIComponent(query)}`;
    let c = await challenge("GET", ex);
    for (let i = 0; i < 8; i++) {
      if (c.component === "xak-flow-redirect") return c.to;
      const body = c.component === "ak-stage-identification" ? { uid_field: u.username }
        : c.component === "ak-stage-password" ? { password }
        : c.component === "ak-stage-consent" ? { token: c.token } : null;
      if (!body) throw new Error(`flow ${slug}: unhandled stage ${c.component}`);
      c = await challenge("POST", ex, body);
      if (c.response_errors) throw new Error(`flow ${slug}: login refused ${JSON.stringify(c.response_errors).slice(0, 120)}`);
    }
    throw new Error(`flow ${slug}: too many stages`);
  }
  const verifier = randomBytes(32).toString("base64url");
  const state = randomBytes(8).toString("hex");
  const q = new URLSearchParams({ client_id: p.client_id, response_type: "code", redirect_uri: REDIRECT, scope, state });
  if (pkce === "S256") { q.set("code_challenge", createHash("sha256").update(verifier).digest("base64url")); q.set("code_challenge_method", "S256"); }
  if (pkce === "plain") { q.set("code_challenge", verifier); q.set("code_challenge_method", "plain"); }
  let next = `/application/o/authorize/?${q}`;
  for (let i = 0; i < 12; i++) {
    const r = await req("GET", next);
    const loc = r.headers.get("location");
    if (!loc) throw new Error(`authorize: ${r.status} without a redirect`);
    const url = new URL(loc, AK);
    if (url.origin + url.pathname === REDIRECT) {
      if (url.searchParams.get("error")) throw new Error(`authorize error ${url.searchParams.get("error")}`);
      if (url.searchParams.get("state") !== state) throw new Error("state mismatch");
      return { code: url.searchParams.get("code"), verifier };
    }
    const m = url.pathname.match(/^\/if\/flow\/([^/]+)\//);
    next = m ? await runFlow(m[1], url.search.slice(1)) : url.pathname + url.search;
  }
  throw new Error("authorize: too many redirects");
}
const codeGrant = (p, a, over = {}) => token({ grant_type: "authorization_code", client_id: p.client_id, client_secret: p.client_secret, code: a.code, redirect_uri: REDIRECT, code_verifier: a.verifier, ...over });
const codeToken = async (p, u, scope) => {
  try { return await codeGrant(p, await codeFlow(p, u, scope)); } catch (e) { return { status: 0, error: e.message }; }
};
const CODE_CLIENTS = new Set([mcp, mcpB, other].map((p) => p.client_id));
const userToken = (p, u, scope = "openid profile victual:read") =>
  CODE_CLIENTS.has(p.client_id) && u.password ? codeToken(p, u, scope) : appPasswordToken(p, u, scope);
const exchange = (by, subject, extra = {}) =>
  token({ grant_type: TX, client_id: by.client_id, client_secret: by.client_secret, subject_token: subject, subject_token_type: AT, ...extra });
async function admin(method, path, body) {
  const r = await fetch(`${AK}/api/v3${path}`, { method, headers: ADMIN, body: body && JSON.stringify(body) });
  if (!r.ok) throw new Error(`${method} ${path} -> ${r.status} ${(await r.text()).slice(0, 300)}`);
  return r.status === 204 ? null : r.json();
}

// --- resource-server check (ADR-0055 decision 3)
const jwksCache = new Map();
async function rsCheck(tok, { issuer, discovery, audience }) {
  try {
    const meta = await (await fetch(discovery)).json();
    if (meta.issuer !== issuer) return `discovery issuer ${meta.issuer} != pinned ${issuer}`;
    if (!jwksCache.has(meta.jwks_uri)) jwksCache.set(meta.jwks_uri, (await (await fetch(meta.jwks_uri)).json()).keys);
    const [h, p, sig] = tok.split(".");
    const head = JSON.parse(Buffer.from(h, "base64url"));
    const jwk = jwksCache.get(meta.jwks_uri).find((k) => k.kid === head.kid);
    if (!jwk || head.alg !== "RS256") return `no RS256 key for kid ${head.kid}`;
    if (!verify("RSA-SHA256", Buffer.from(`${h}.${p}`), createPublicKey({ key: jwk, format: "jwk" }), Buffer.from(sig, "base64url"))) return "bad signature";
    const c = claims(tok), now = Date.now() / 1000;
    if (c.iss !== issuer) return `iss ${c.iss}`;
    if (!(c.exp + 60 > now)) return "expired";
    if (c.nbf && c.nbf - 60 > now) return "not yet valid";
    const aud = [].concat(c.aud ?? []);
    if (!aud.length) return "empty aud";
    if (!aud.includes(audience)) return `aud ${aud.join(",")} lacks ${audience}`;
    return "accepted";
  } catch (e) { return `error ${e.message}`; }
}
const ISSUER = `${AK}/`;
const disc = (p) => `${AK}/application/o/${p.slug}/.well-known/openid-configuration`;
const RS_SIDECAR = { issuer: ISSUER, discovery: disc(mcp), audience: S.mcpResource }; // one fixed MCP resource audience (G2)
const RS_API = { issuer: ISSUER, discovery: disc(api), audience: api.client_id };

function record(id, kind, clause, expected, observed, ok) {
  results.push({ id, kind, clause, expected, observed, verdict: ok ? "pass" : "FAIL" });
}
const err = (r) => r.error ? `${r.status} ${r.error}` : `${r.status} token`;

// ============ positive cases
{
  const r = await fetch(`${ISSUER}.well-known/openid-configuration`);
  const m = await (await fetch(disc(api))).json();
  record("P1", "pos", "0055 d3: resource servers fetch discovery from the issuer",
    "discovery reachable from the issuer URL; its issuer equals the token iss",
    `issuer ${m.issuer}; ${ISSUER}.well-known/openid-configuration -> ${r.status}; per-provider discovery -> 200`,
    r.ok);
}
const at = await userToken(mcp, alice);
const ac = at.access_token ? claims(at.access_token) : {};
record("P2", "pos", "0055 d4 / 0056 d3: a user token names a stable subject, not email",
  "200; sub opaque and stable; no email in sub",
  `${err(at)}; sub=${String(ac.sub).slice(0, 12)}… aud=${ac.aud} iss=${ac.iss} ttl=${ac.exp - ac.iat}s`,
  at.status === 200 && ac.sub && !String(ac.sub).includes("@"));
{
  const again = await userToken(mcp, alice);
  const same = again.access_token && claims(again.access_token).sub === ac.sub;
  const viaOther = await userToken(other, alice);
  const sameAcross = viaOther.access_token && claims(viaOther.access_token).sub === ac.sub;
  record("P3", "pos", "0055 d4: one (issuer, subject) per user across clients",
    "same sub on a second token and from a different provider",
    `same on reissue: ${same}; same across providers: ${sameAcross}`, same && sameAcross);
}
record("P4", "pos", "0055 d3: the sidecar accepts a token for its audience",
  "accepted", await rsCheck(at.access_token, RS_SIDECAR), (await rsCheck(at.access_token, RS_SIDECAR)) === "accepted");
const ex = await exchange(sidecar, at.access_token, { audience: api.client_id, scope: "openid victual:read" });
const xc = ex.access_token ? claims(ex.access_token) : {};
record("P5", "pos", "0055 d7: the sidecar exchanges the user's token for an /api token",
  "200; sub unchanged; aud = /api; iss unchanged",
  `${err(ex)}; same sub: ${xc.sub === ac.sub}; aud=${xc.aud}; iss=${xc.iss}`,
  ex.status === 200 && xc.sub === ac.sub && xc.aud === api.client_id && xc.iss === ac.iss);
{
  const v = ex.access_token ? await rsCheck(ex.access_token, RS_API) : "no token";
  record("P6", "pos", "0055 d3: /api accepts the exchanged token", "accepted", v, v === "accepted");
}
record("P7", "pos", "0055 d7: the issued token records the sidecar in act",
  "act.sub names the sidecar (impersonation mode, no actor_token)",
  `act=${JSON.stringify(xc.act ?? null)} azp=${xc.azp} (the G1 claim victual_exchanger=${xc.victual_exchanger} is separate)`, !!xc.act);
{
  // An ownerless Actor has no API in the open-source edition (enterprise "Agents" create one).
  // Created here through `ak shell` to exercise the mechanism; this is NOT a supported setup path.
  let observed, ok = false;
  try {
    const py = [
      "from authentik.core.models import Actor, ActorPolicyInheritance, Token, TokenIntents",
      "a = Actor.for_user(None, ActorPolicyInheritance.NONE)",
      "t = Token.objects.create(identifier='spike-actor-' + a.username, intent=TokenIntents.INTENT_APP_PASSWORD, user=a, expiring=False)",
      "print('ACTOR', a.username, t.key)",
    ].join("\n");
    const out = execFileSync("podman", ["exec", "victual-spike-authentik-server", "ak", "shell", "-c", py], { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] });
    const [, uname, key] = out.match(/ACTOR (\S+) (\S+)/);
    const actorJwt = (await appPasswordToken(mcp, { username: uname, app_password: key }, "openid")).access_token;
    const obo = await exchange(sidecar, at.access_token, { audience: api.client_id, scope: "openid victual:read", actor_token: actorJwt, actor_token_type: "urn:ietf:params:oauth:token-type:jwt" });
    const oc = obo.access_token ? claims(obo.access_token) : {};
    observed = `${err(obo)}; sub same: ${oc.sub === ac.sub}; act=${JSON.stringify(oc.act ?? null)}`;
    ok = obo.status === 200 && oc.sub === ac.sub && !!oc.act?.sub;
  } catch (e) { observed = `could not run: ${e.message.slice(0, 120)}`; }
  record("P8", "pos", "0055 d7: delegation (OBO) records the sidecar in act",
    "200; act.sub = the sidecar's Actor (Actor created via ak shell, unsupported path)", observed, ok);
}
{
  // ADR-0056 prerequisite 4: group removal shows at the next token.
  const before = ac.groups ?? [];
  await admin("POST", `/core/groups/${S.group.pk}/remove_user/`, { pk: alice.pk });
  const after = claims((await userToken(mcp, alice)).access_token).groups ?? [];
  await admin("POST", `/core/groups/${S.group.pk}/add_user/`, { pk: alice.pk });
  const back = claims((await userToken(mcp, alice)).access_token).groups ?? [];
  record("P9", "pos", "0056 d4 / prerequisite 4: group changes reach the next token",
    "group present, absent after removal, present after re-adding",
    `before=${JSON.stringify(before)} after-remove=${JSON.stringify(after)} after-add=${JSON.stringify(back)}`,
    before.includes(S.group.name) && !after.includes(S.group.name) && back.includes(S.group.name));
}

// ============ negative cases: resource-server checks
for (const [id, tok, rs, what] of [
  ["N1", at.access_token, RS_API, "an MCP-audience token at /api"],
  ["N2", ex.access_token, RS_SIDECAR, "an /api-audience token at the sidecar"],
  ["N3", (await userToken(other, alice)).access_token, RS_API, "a token from an unrelated provider of the same issuer at /api"],
]) {
  const v = await rsCheck(tok, rs);
  record(id, "neg", "0055 d3: a token for another audience is refused", "refused (aud)", v, v.startsWith("aud"));
}
{
  const [h, p, s] = at.access_token.split(".");
  const forged = `${h}.${Buffer.from(JSON.stringify({ ...ac, aud: api.client_id })).toString("base64url")}.${s}`;
  const v = await rsCheck(forged, RS_API);
  record("N4", "neg", "0055 d3: a tampered token is refused", "refused (signature)", v, v === "bad signature");
}

// ============ negative cases: token exchange
const neg = async (id, clause, expectedErr, r, note = "") =>
  record(id, "neg", clause, `refused ${expectedErr}`, `${err(r)}${r.error_description ? ` (${r.error_description.slice(0, 80)})` : ""}${note}`, r.status >= 400 && (!expectedErr || r.error === expectedErr));
await neg("N5", "0055 d3: resource indicators are not silently ignored", "invalid_target",
  await exchange(sidecar, at.access_token, { resource: "http://localhost:8080/api", scope: "openid victual:read" }));
await neg("N6", "0055 d7: only the trusted sidecar may mint /api tokens", "invalid_target",
  await exchange(rogue, at.access_token, { audience: api.client_id, scope: "openid victual:read" }));
await neg("N7", "0055 d7: a client without the exchange grant cannot exchange", "",
  await exchange(noGrant, at.access_token, { audience: api.client_id, scope: "openid victual:read" }));
await neg("N8", "0055 d7: a subject token from an untrusted provider is refused", "invalid_grant",
  await exchange(sidecar, (await userToken(other, alice)).access_token, { audience: api.client_id, scope: "openid victual:read" }));
{
  const [h, p, s] = at.access_token.split(".");
  const forged = `${h}.${Buffer.from(JSON.stringify({ ...ac, sub: "someone-else" })).toString("base64url")}.${s}`;
  await neg("N9", "0055 d7: a tampered subject token is refused", "invalid_grant",
    await exchange(sidecar, forged, { audience: api.client_id, scope: "openid victual:read" }));
}
{
  const r = await fetch(T, { method: "POST", body: new URLSearchParams([["grant_type", TX], ["client_id", sidecar.client_id],
    ["client_secret", sidecar.client_secret], ["subject_token", at.access_token], ["subject_token_type", AT],
    ["audience", api.client_id], ["audience", mcp.client_id], ["scope", "openid"]]) });
  await neg("N10", "0055 d3: one audience per token", "invalid_target", { status: r.status, ...(await r.json()) });
}
// The scope-ceiling policy (G3) is bound to the /api application by setup.mjs. `ceiling(policyPk, fn)`
// swaps what is bound for the duration of fn (null: nothing) and always restores the default.
const bindCeiling = async (policy) => (await admin("POST", "/policies/bindings/", { policy, target: S.policies.apiApp, order: 0 })).pk;
async function ceiling(policy, fn) {
  await admin("DELETE", `/policies/bindings/${S.policies.binding}/`);
  let tmp = null;
  try {
    if (policy) tmp = await bindCeiling(policy);
    return await fn();
  } finally {
    if (tmp) await admin("DELETE", `/policies/bindings/${tmp}/`);
    S.policies.binding = await bindCeiling(S.policies.ceiling);
  }
}
{
  // Authentik's native behaviour, evidence for why the policy is required: unbind it for this case.
  const ro = await userToken(mcp, alice, "openid profile victual:read");
  const w = await ceiling(null, () => exchange(sidecar, ro.access_token, { audience: api.client_id, scope: "openid victual:read victual:write" }));
  const got = w.access_token ? claims(w.access_token).scope : "";
  record("N11", "neg", "0055 d7: the exchanged token carries no scope the subject token lacked (scope-ceiling policy unbound)",
    "victual:write refused or dropped (subject token: victual:read only)", `${err(w)}; scope=${got}`, !String(got).includes("victual:write"));
}
{
  // The default deployment configuration: the policy bound by setup.mjs (G3).
  const ro = (await userToken(mcp, alice, "openid profile victual:read")).access_token;
  const widen = await exchange(sidecar, ro, { audience: api.client_id, scope: "openid victual:read victual:write" });
  const same = await exchange(sidecar, ro, { audience: api.client_id, scope: "openid victual:read" });
  const rw = (await userToken(mcp, alice, "openid profile victual:read victual:write")).access_token;
  const full = await exchange(sidecar, rw, { audience: api.client_id, scope: "openid victual:read victual:write" });
  record("N11b", "neg", "0055 d7 with the scope-ceiling policy that setup.mjs binds to the /api application",
    "widening refused; equal or narrower scope still exchanged",
    `widen -> ${err(widen)}; same scope -> ${err(same)}; write subject asking write -> ${err(full)}`,
    widen.status >= 400 && same.status === 200 && full.status === 200);
}
{
  const bt = (await userToken(mcp, bob)).access_token;
  await admin("PATCH", `/core/users/${bob.pk}/`, { is_active: false });
  const r = await exchange(sidecar, bt, { audience: api.client_id, scope: "openid victual:read" });
  await admin("PATCH", `/core/users/${bob.pk}/`, { is_active: true });
  await neg("N12", "0055 d4: a disabled user's live token cannot be exchanged", "", r);
}
{
  const st = (await userToken(short, alice)).access_token;
  await sleep(4000);
  await neg("N13", "0055 d7: an expired subject token is refused", "invalid_grant",
    await exchange(sidecar, st, { audience: api.client_id, scope: "openid victual:read" }));
  await sleep(62000);
  const v = await rsCheck(st, { issuer: ISSUER, discovery: disc(short), audience: short.client_id });
  record("N14", "neg", "0055 d3: an expired token is refused (60 s skew)", "refused (expired)", v, v === "expired");
}
{
  // ADR-0056 prerequisite 2: how an MCP client registers. Authentik's DCR needs a bearer token.
  let observed, ok;
  try {
    await admin("POST", "/providers/oauth2-dcr/", { provider: mcp.pk });
  } catch (e) { /* already configured */ }
  const url = `${AK}/application/o/${mcp.slug}/register/`;
  const body = JSON.stringify({ client_name: "probe", redirect_uris: ["http://localhost:8765/callback"], token_endpoint_auth_method: "none", grant_types: ["authorization_code", "refresh_token"] });
  const anon = await fetch(url, { method: "POST", headers: { "Content-Type": "application/json" }, body });
  const noScope = await fetch(url, { method: "POST", headers: { "Content-Type": "application/json", Authorization: `Bearer ${at.access_token}` }, body });
  observed = `no token -> ${anon.status}; user token without the DCR scope -> ${noScope.status}`;
  ok = anon.status >= 400 && noScope.status >= 400;
  record("N15", "neg", "0056 prereq 2: registration is not open to anonymous callers",
    "anonymous and unscoped registration refused (so MCP clients cannot self-register without a token)", observed, ok);
}

// ============ G1: exchanger identity (ADR-0055 decision 7, open question 8)
// setup.mjs puts a scope mapping on every /api scope. While the token endpoint builds an
// exchanged token, it reads the client that authenticated (extract_client_auth, the function
// TokenView.post used to find the provider whose secret was verified) and signs it as
// victual_exchanger, with the subject token's azp/sid/jti as victual_grant.
const XC = "victual_exchanger";
const basic = (p) => ({ Authorization: "Basic " + Buffer.from(`${encodeURIComponent(p.client_id)}:${encodeURIComponent(p.client_secret)}`).toString("base64") });
const rawToken = async (headers, pairs) => {
  const r = await fetch(T, { method: "POST", headers, body: new URLSearchParams(pairs) });
  return { status: r.status, ...(await r.json()) };
};
const txBody = (subject, extra = []) => [["grant_type", TX], ["subject_token", subject], ["subject_token_type", AT], ["audience", api.client_id], ["scope", "openid victual:read"], ...extra];
const sub1 = (await userToken(mcp, alice, "openid profile victual:read")).access_token;
const sub1c = claims(sub1);
const xcOf = (r) => (r.access_token ? claims(r.access_token) : {});
{
  const a = await exchange(sidecar, sub1, { audience: api.client_id, scope: "openid victual:read" });
  const b = await exchange(sidecarB, sub1, { audience: api.client_id, scope: "openid victual:read" });
  const ca = xcOf(a), cb = xcOf(b);
  const v = await rsCheck(a.access_token, RS_API);
  record("G1a", "pos", "0055 d7 / open q 8: a signed claim names the authenticated exchanger",
    `${XC} = the exchanging client's client_id; a second exchanger gives its own value; /api still accepts the token`,
    `sidecar -> ${ca[XC]}; sidecar-b -> ${cb[XC]}; azp=${ca.azp} (target, as before); /api: ${v}`,
    ca[XC] === sidecar.client_id && cb[XC] === sidecarB.client_id && v === "accepted");
}
{
  // Callers cannot set or override it. The injection that matters: a different client_id in the body
  // while the Basic header authenticates the sidecar, plus claim-shaped form fields.
  const fields = [["victual_exchanger", "evil"], ["victual_grant", '{"azp":"evil"}'], ["act", '{"sub":"evil"}'], ["azp", "evil"], ["aud", "evil"], ["sub", "evil"]];
  const r1 = await rawToken(basic(sidecar), txBody(sub1, [["client_id", sidecarB.client_id], ["client_secret", sidecarB.client_secret], ...fields]));
  const r2 = await rawToken(basic(sidecarB), txBody(sub1, [["client_id", sidecar.client_id], ["client_secret", sidecar.client_secret]]));
  // Duplicate client_id in the body, no Basic header: both Authentik and the mapping take the last value.
  const r3 = await rawToken({}, txBody(sub1, [["client_id", sidecar.client_id], ["client_id", sidecarB.client_id], ["client_secret", sidecarB.client_secret]]));
  const c1 = xcOf(r1), c2 = xcOf(r2), c3 = xcOf(r3);
  record("G1b", "neg", "0055 d7 / open q 8: callers cannot inject or override the exchanger claim",
    "Basic header wins over body client_id and claim-shaped form fields; with duplicate body client_id the claim equals the client whose secret matched",
    `Basic sidecar + body sidecar-b + fields -> ${err(r1)} ${XC}=${c1[XC]} act=${JSON.stringify(c1.act ?? null)} aud=${c1.aud} sub same=${c1.sub === sub1c.sub}; Basic sidecar-b + body sidecar -> ${XC}=${c2[XC]}; duplicate client_id -> ${XC}=${c3[XC]}`,
    c1[XC] === sidecar.client_id && !c1.act && c1.aud === api.client_id && c1.sub === sub1c.sub && c1.victual_grant?.azp === mcp.client_id
      && c2[XC] === sidecarB.client_id && c3[XC] === sidecarB.client_id);
}
{
  const wrong = [
    ["right id, wrong secret (form)", {}, [["client_id", sidecarB.client_id], ["client_secret", "nope"]]],
    ["sidecar-b id with the sidecar's secret (form)", {}, [["client_id", sidecarB.client_id], ["client_secret", sidecar.client_secret]]],
    ["client_id without a secret", {}, [["client_id", sidecar.client_id]]],
    ["Basic sidecar-b id with the sidecar's secret", basic({ client_id: sidecarB.client_id, client_secret: sidecar.client_secret }), []],
  ];
  const out = [];
  for (const [label, h, extra] of wrong) {
    const r = await rawToken(h, txBody(sub1, extra));
    out.push(`${label}: ${r.status} ${r.error ?? "TOKEN"}`);
  }
  record("G1c", "neg", "0055 d7 / open q 8: a client_id without a valid secret yields no token and no claim",
    "every variant refused (invalid_client), no access_token", out.join("; "), out.every((o) => /: 4\d\d invalid_client$/.test(o)));
}
{
  // Claim-name collision. An admin-written mapping that spreads user attributes into the token is
  // the realistic way a user-controlled value reaches claims. Authentik merges mappings in
  // scope_name order (get_claims) and does not reserve claim names, so the exchanger mapping sits
  // on the victual:* scopes, which sort after profile.
  const before = (await admin("GET", `/providers/oauth2/${api.pk}/`)).property_mappings;
  const attrMap = await admin("POST", "/propertymappings/provider/scope/", { name: "victual-spike attrs-collision", scope_name: "profile", expression: "return dict(user.attributes)" });
  let r, c;
  try {
    await admin("PATCH", `/providers/oauth2/${api.pk}/`, { property_mappings: [...before, attrMap.pk] });
    await admin("PATCH", `/core/users/${alice.pk}/`, { attributes: { [XC]: "forged", victual_grant: { azp: "forged", sid: "forged" }, act: { sub: "forged" }, forged_extra: "kept" } });
    r = await exchange(sidecar, sub1, { audience: api.client_id, scope: "openid profile victual:read" });
    c = xcOf(r);
  } finally {
    await admin("PATCH", `/core/users/${alice.pk}/`, { attributes: {} });
    await admin("PATCH", `/providers/oauth2/${api.pk}/`, { property_mappings: before });
    await admin("DELETE", `/propertymappings/provider/scope/${attrMap.pk}/`);
  }
  record("G1d", "neg", "0055 d7 / open q 8: a user attribute with the claim's name cannot override it",
    `${XC} and victual_grant.azp keep the authenticated values although a mapping on scope profile spreads user.attributes`,
    `${err(r)}; ${XC}=${c[XC]}; victual_grant=${JSON.stringify(c.victual_grant)}; act=${JSON.stringify(c.act ?? null)} (not reserved: an attribute named act got through); forged_extra=${c.forged_extra}`,
    r.status === 200 && c[XC] === sidecar.client_id && c.victual_grant?.azp === mcp.client_id && c.victual_grant?.sid !== "forged");
}
{
  // Present on every exchanged token, absent from every other token.
  const rw = (await userToken(mcp, alice, "openid profile victual:read victual:write")).access_token;
  const cases = [
    ["openid victual:read", sub1], ["victual:read", sub1], ["openid victual:read victual:write", rw], ["victual:write", rw],
  ];
  const present = [];
  for (const [sc, st] of cases) present.push(`${sc}: ${xcOf(await exchange(sidecar, st, { audience: api.client_id, scope: sc }))[XC] === sidecar.client_id}`);
  const refused = [];
  for (const [label, extra] of [["scope openid only", { scope: "openid" }], ["no scope", { scope: undefined }], ["unknown scope only", { scope: "nonsense" }]]) {
    const body = { audience: api.client_id, ...extra };
    if (body.scope === undefined) delete body.scope;
    const r = await exchange(sidecar, sub1, body);
    refused.push(`${label}: ${r.status} ${r.error ?? "TOKEN"}`);
  }
  const apiDirect = await appPasswordToken(api, alice, "openid victual:read");
  const noAud = await exchange(sidecar, sub1, { scope: "openid victual:read" });
  const absent = [
    `api's own client-credentials token: ${XC} in token=${XC in xcOf(apiDirect)}`,
    `MCP user token (code flow): ${XC in sub1c}`,
    `exchange with no audience (issued on the sidecar's provider, aud=${xcOf(noAud).aud}): ${XC in xcOf(noAud)}`,
  ];
  record("G1e", "pos", "0055 d7: the claim is on every exchanged /api token and on no other",
    "claim present for every accepted exchange scope; exchanges that carry no victual:* scope refused (the ceiling policy requires one); absent from non-exchanged tokens",
    `present: ${present.join(", ")}; refused: ${refused.join(", ")}; absent: ${absent.join("; ")}`,
    present.every((x) => x.endsWith("true")) && refused.every((x) => /: 4\d\d /.test(x)) && absent.slice(0, 2).every((x) => x.endsWith("false")));
}
{
  // The same derivation can populate `act`, if the maintainer prefers that claim name (ADR-0055 decision 7).
  // Not the default: Authentik's own `act` is the delegation Actor (P8) and a mapping's `act` replaces it
  // (id_token.py to_dict: id_dict.update(self.claims) runs after act is set).
  const before = (await admin("GET", `/providers/oauth2/${api.pk}/`)).property_mappings;
  const expr = [
    "from authentik.providers.oauth2.utils import extract_client_auth",
    `if http_request is None or http_request.POST.get("grant_type") != ${JSON.stringify(TX)}:`,
    "    return {}",
    "client_id, _secret = extract_client_auth(http_request)",
    'return {"act": {"sub": client_id}}',
  ].join("\n");
  const m = await admin("POST", "/propertymappings/provider/scope/", { name: "victual-spike act-variant", scope_name: "victual:read", expression: expr });
  let r, r2, c, c2;
  try {
    await admin("PATCH", `/providers/oauth2/${api.pk}/`, { property_mappings: [...before, m.pk] });
    r = await exchange(sidecar, sub1, { audience: api.client_id, scope: "openid victual:read" });
    r2 = await rawToken(basic(sidecar), txBody(sub1, [["client_id", sidecarB.client_id], ["client_secret", sidecarB.client_secret], ["act", '{"sub":"evil"}']]));
    c = xcOf(r); c2 = xcOf(r2);
  } finally {
    await admin("PATCH", `/providers/oauth2/${api.pk}/`, { property_mappings: before });
    await admin("DELETE", `/propertymappings/provider/scope/${m.pk}/`);
  }
  record("G1f", "pos", "0055 d7: `act` populated from client authentication by a scope mapping (alternative to the default claim)",
    "act.sub = the authenticated exchanger, also when the body names another client and sets act",
    `${err(r)}; act=${JSON.stringify(c.act ?? null)}; Basic sidecar + body act/client_id -> act=${JSON.stringify(c2.act ?? null)}`,
    c.act?.sub === sidecar.client_id && c2.act?.sub === sidecar.client_id);
}
{
  // Originating grant (decision 6, ADR-0056 grant_id). Code-flow tokens carry a session id (sid).
  const sc = "openid profile victual:read offline_access";
  const t1 = await codeToken(mcp, alice, sc);
  const x1 = xcOf(await exchange(sidecar, t1.access_token, { audience: api.client_id, scope: "openid victual:read" }));
  const rt = await token({ grant_type: "refresh_token", client_id: mcp.client_id, client_secret: mcp.client_secret, refresh_token: t1.refresh_token, scope: sc });
  const x2 = xcOf(await exchange(sidecar, rt.access_token, { audience: api.client_id, scope: "openid victual:read" }));
  const t3 = await codeToken(mcp, alice, sc);
  const ex3 = await exchange(sidecar, t3.access_token, { audience: api.client_id, scope: "openid victual:read" });
  const x3 = xcOf(ex3);
  const g1 = x1.victual_grant ?? {}, g2 = x2.victual_grant ?? {}, g3 = x3.victual_grant ?? {};
  record("G1g", "pos", "0055 d7 / 0056 d11: the exchanged token identifies the originating grant (client and session)",
    "victual_grant.azp = the MCP client; sid stable across a refresh, different for a second login; jti differs per token",
    `first login: ${JSON.stringify({ ...g1, sid: String(g1.sid).slice(0, 8), jti: String(g1.jti).slice(0, 6) })}; after refresh: same sid=${g2.sid === g1.sid}, new jti=${g2.jti !== g1.jti}; second login: new sid=${g3.sid !== g1.sid}`,
    g1.azp === mcp.client_id && !!g1.sid && g2.sid === g1.sid && g2.jti !== g1.jti && g3.sid && g3.sid !== g1.sid);
  // Revocation at the authorization server reaches the exchange: delete the subject access token.
  const jtiOf = (j) => claims(j).jti;
  const rows = (await admin("GET", `/oauth2/access_tokens/?user=${alice.pk}&provider=${mcp.pk}&page_size=100`)).results;
  const row = rows.find((x) => JSON.parse(x.id_token).jti === jtiOf(t3.access_token));
  await admin("DELETE", `/oauth2/access_tokens/${row.pk}/`);
  const afterDelete = await exchange(sidecar, t3.access_token, { audience: api.client_id, scope: "openid victual:read" });
  const issued = await rsCheck(ex3.access_token, RS_API);
  record("G1h", "neg", "0055 d6: revoking the grant at the authorization server stops further exchanges",
    "exchange of a deleted subject access token refused; an exchanged token already issued stays valid until exp (stateless)",
    `exchange after deleting the subject token -> ${err(afterDelete)}; an earlier exchanged token at /api -> ${issued}`,
    afterDelete.status >= 400 && issued === "accepted");
}

// ============ G2: dedicated MCP resource audience (ADR-0055 decision 3, open question 9)
// setup.mjs binds a scope mapping that returns aud = [client_id, <fixed MCP audience>] to each MCP
// client's provider. id_token.py to_dict applies mapping output after the dataclass fields, so it
// replaces aud. The sidecar's resource-server check is configured with the fixed audience only.
{
  const ta = (await userToken(mcp, alice, "openid profile victual:read")).access_token;
  const tb = (await userToken(mcpB, alice, "openid profile victual:read")).access_token;
  const va = await rsCheck(ta, RS_SIDECAR), vb = await rsCheck(tb, RS_SIDECAR);
  const old = await rsCheck(tb, { ...RS_SIDECAR, audience: mcp.client_id });
  record("G2a", "pos", "0055 d3 / open q 9: one fixed MCP audience accepts tokens from two registered clients",
    "both accepted by a sidecar configured with the single audience only",
    `mcp-client aud=${claims(ta).aud} -> ${va}; mcp-client-b aud=${claims(tb).aud} -> ${vb}; (a sidecar pinned to mcp-client's client_id refuses mcp-client-b: ${old})`,
    va === "accepted" && vb === "accepted" && old.startsWith("aud"));
  const only = await userToken(mcp, alice, "profile");
  const vOnly = only.access_token ? await rsCheck(only.access_token, RS_SIDECAR) : err(only);
  const vOther = await rsCheck((await userToken(other, alice, "openid victual:read")).access_token, RS_SIDECAR);
  const vApi = await rsCheck((await appPasswordToken(api, alice, "openid victual:read")).access_token, RS_SIDECAR);
  const vEx = await rsCheck((await exchange(sidecar, ta, { audience: api.client_id, scope: "openid victual:read" })).access_token, RS_SIDECAR);
  const vNone = await rsCheck(ta.replace(/\.[^.]*$/, "."), RS_SIDECAR);
  record("G2b", "neg", "0055 d3 / open q 9: the sidecar refuses tokens without the MCP audience",
    "refused: unbound client, /api client, exchanged token, bound client's token with no bound scope, unsigned token",
    `unbound client (other): ${vOther}; api client: ${vApi}; exchanged /api token: ${vEx}; mcp-client token with scope profile only: ${vOnly}; stripped signature: ${vNone}`,
    [vOther, vApi, vEx, vOnly].every((x) => x.startsWith("aud")) && vNone !== "accepted");
  // A client not bound to the mapping cannot ask its way in: scope mappings are per provider, and
  // resource / audience request parameters do not change aud (N5: ignored at authorize, refused on exchange).
  const sneaky = await token({ grant_type: "client_credentials", client_id: other.client_id, client_secret: other.client_secret,
    username: alice.username, password: alice.app_password, scope: "openid victual:read", resource: S.mcpResource, audience: S.mcpResource });
  const withParam = await token({ grant_type: "client_credentials", client_id: mcp.client_id, client_secret: mcp.client_secret,
    username: alice.username, password: alice.app_password, scope: "openid", resource: "http://localhost:8080/api", audience: api.client_id });
  record("G2c", "neg", "0055 d3: a client not bound to the MCP resource cannot obtain its audience, and a bound client cannot drop or change it",
    "unbound client asking resource/audience: aud has no MCP audience; bound client sending other resource/audience: aud unchanged",
    `unbound: ${err(sneaky)} aud=${sneaky.access_token ? claims(sneaky.access_token).aud : "-"}; bound: ${err(withParam)} aud=${withParam.access_token ? claims(withParam.access_token).aud : "-"}`,
    sneaky.access_token && ![].concat(claims(sneaky.access_token).aud).includes(S.mcpResource)
      && withParam.access_token && JSON.stringify(claims(withParam.access_token).aud) === JSON.stringify([mcp.client_id, S.mcpResource]));
  // List versus scalar aud: the mapping output also lands in the id_token.
  const sc_ = await appPasswordToken(mcpScalar, alice, "openid");
  const ls = await appPasswordToken(mcp, alice, "openid");
  const sAud = sc_.access_token ? claims(sc_.access_token).aud : null, sId = sc_.id_token ? claims(sc_.id_token).aud : null;
  const lId = ls.id_token ? claims(ls.id_token).aud : null;
  record("G2d", "pos", "0055 d3: choice of aud shape (list keeps the client_id in the id_token)",
    "list form: access and id_token aud contain both client_id and MCP audience; scalar form: id_token aud no longer contains the client's client_id",
    `list: id_token aud=${JSON.stringify(lId)}; scalar: access aud=${JSON.stringify(sAud)} id_token aud=${JSON.stringify(sId)}; scalar accepted by the sidecar: ${await rsCheck(sc_.access_token, RS_SIDECAR)}`,
    [].concat(lId).includes(mcp.client_id) && [].concat(lId).includes(S.mcpResource) && sAud === S.mcpResource && sId === S.mcpResource);
  const xb = await exchange(sidecar, tb, { audience: api.client_id, scope: "openid victual:read" });
  const vxb = xb.access_token ? await rsCheck(xb.access_token, RS_API) : err(xb);
  record("G2e", "pos", "0055 d7: the aud override does not break the exchange",
    "the sidecar exchanges a token of the second client; /api accepts it",
    `${err(xb)}; /api: ${vxb}; ${XC}=${xcOf(xb)[XC]}`, xb.status === 200 && vxb === "accepted");
}

// ============ G3: scope ceiling as required configuration, with bundle scopes
{
  const bundleTok = (await userToken(mcp, alice, `openid profile ${S.bundle}`)).access_token;
  const ask = (scope) => exchange(sidecar, bundleTok, { audience: api.client_id, scope });
  const inRead = await ask("openid victual:read"), inWrite = await ask("openid victual:write"), outside = await ask("openid victual:admin");
  const gotScope = (r) => (r.access_token ? claims(r.access_token).scope : "");
  record("G3a", "pos", "0055 d7 / open q 7: bundle-aware ceiling, table in the policy (the default policy)",
    "subject holds the bundle: leaves inside it accepted, a leaf outside refused",
    `inside read -> ${err(inRead)} scope=${gotScope(inRead)}; inside write -> ${err(inWrite)}; outside admin -> ${err(outside)}`,
    inRead.status === 200 && inWrite.status === 200 && outside.status >= 400);
  const plain = await ceiling(S.policies.ceilingPlain, async () => ({ inRead: await ask("openid victual:read"), outside: await ask("openid victual:admin") }));
  record("G3b", "neg", "0055 d7: what a plain set comparison cannot do with bundles",
    "without a table the policy refuses a leaf the bundle covers (false refusal) and still refuses the outside leaf",
    `inside read -> ${err(plain.inRead)}; outside admin -> ${err(plain.outside)}`,
    plain.inRead.status >= 400 && plain.outside.status >= 400);
}

// ============ G4: authorization code + PKCE with a scripted login (no app password)
{
  const t = await codeToken(mcp, alice, "openid profile victual:read");
  const c = t.access_token ? claims(t.access_token) : {};
  record("G4a", "pos", "0055 d6: authorization code with PKCE S256, user logged in through the flow executor API",
    "200; access token with sid (a real session), amr pwd, the user's sub, state echoed",
    `${err(t)}; sid=${c.sid ? "present" : "absent"} amr=${JSON.stringify(c.amr)} sub same as app-password grant: ${c.sub === claims((await appPasswordToken(mcp, alice)).access_token).sub}`,
    t.status === 200 && !!c.sid && JSON.stringify(c.amr) === '["pwd"]');
  const a = await codeFlow(mcp, alice, "openid victual:read");
  const bad = await codeGrant(mcp, a, { code_verifier: randomBytes(32).toString("base64url") });
  const a2 = await codeFlow(mcp, alice, "openid victual:read");
  const first = await codeGrant(mcp, a2);
  const replay = await codeGrant(mcp, a2);
  const badLogin = await codeFlow(mcp, alice, "openid", { password: "wrong-password" }).then(() => "code issued", (e) => `refused (${e.message.slice(0, 40)})`);
  record("G4b", "neg", "0055 d6: PKCE verifier, code replay and password are enforced",
    "wrong code_verifier, a replayed code and a wrong password are all refused",
    `wrong verifier -> ${err(bad)}; first use -> ${first.status}, replay -> ${err(replay)}; wrong password -> ${badLogin}`,
    bad.status >= 400 && first.status === 200 && replay.status >= 400 && badLogin.startsWith("refused"));
  const none = await codeFlow(mcp, alice, "openid victual:read", { pkce: "none" }).then((x) => token({ grant_type: "authorization_code", client_id: mcp.client_id, client_secret: mcp.client_secret, code: x.code, redirect_uri: REDIRECT }), (e) => ({ status: 0, error: e.message.slice(0, 60) }));
  const plain = await codeFlow(mcp, alice, "openid victual:read", { pkce: "plain" }).then((x) => codeGrant(mcp, x), (e) => ({ status: 0, error: e.message.slice(0, 60) }));
  const tryPkce = async (mode) => codeFlow(mcp, alice, "openid victual:read", { pkce: mode }).then((x) => mode === "none"
    ? token({ grant_type: "authorization_code", client_id: mcp.client_id, client_secret: mcp.client_secret, code: x.code, redirect_uri: REDIRECT })
    : codeGrant(mcp, x), (e) => ({ status: 0, error: e.message.slice(0, 50) }));
  const cfg = { none: await tryPkce("none"), plain: await tryPkce("plain"), s256: await tryPkce("S256") };
  record("G4c", "neg", "0055 d6: PKCE S256 only, by the policy that setup.mjs binds to the MCP client applications",
    "authorization without a challenge and with method plain refused at /authorize; S256 still works",
    `no challenge -> ${err(cfg.none)}; plain -> ${err(cfg.plain)}; S256 -> ${err(cfg.s256)}`,
    cfg.none.status !== 200 && cfg.plain.status !== 200 && cfg.s256.status === 200);
  // Native behaviour with the policy unbound, evidence for why it is required.
  await admin("DELETE", `/policies/bindings/${S.policies.pkceBindings.mcp}/`);
  let nat;
  try { nat = { none: await tryPkce("none"), plain: await tryPkce("plain") }; } finally {
    const app = await admin("GET", `/core/applications/${mcp.slug}/`);
    S.policies.pkceBindings.mcp = (await admin("POST", "/policies/bindings/", { policy: S.policies.pkce, target: app.pk, order: 0 })).pk;
  }
  record("G4c0", "neg", "0055 d6: PKCE S256 only, Authentik's native behaviour (PKCE policy unbound)",
    "authorization without a challenge and with method plain refused",
    `no challenge -> ${err(nat.none)}; plain -> ${err(nat.plain)}`, nat.none.status !== 200 && nat.plain.status !== 200);
  const sc = "openid profile victual:read offline_access";
  const t1 = await codeToken(mcp, alice, sc);
  const rf = (rt) => token({ grant_type: "refresh_token", client_id: mcp.client_id, client_secret: mcp.client_secret, refresh_token: rt, scope: sc });
  const r1 = await rf(t1.refresh_token);
  const reuse = await rf(t1.refresh_token);
  const newest = await rf(r1.refresh_token);
  record("G4d", "neg", "0055 d6: refresh token rotation with reuse detection revoking the family",
    "rotated: a new refresh token; reuse of the old one refused; the newest refresh token then also refused (family revoked)",
    `rotation -> ${err(r1)}, new refresh token ${r1.refresh_token && r1.refresh_token !== t1.refresh_token}; reuse of old -> ${err(reuse)}; newest after the reuse -> ${err(newest)}`,
    r1.status === 200 && reuse.status >= 400 && newest.status >= 400);
}

writeFileSync(new URL("./results.json", import.meta.url), JSON.stringify({ at: new Date().toISOString(), authentik: (await admin("GET", "/admin/version/")).version_current, results }, null, 2));
console.log("| ID | Clause | Expected | Observed | Verdict |\n|---|---|---|---|---|");
for (const r of results) console.log(`| ${r.id} | ${r.clause} | ${r.expected} | ${r.observed.replaceAll("|", "\\|")} | ${r.verdict} |`);
