// Authentik compatibility probe for ADR-0055 / ADR-0056. Needs `./run.sh up && node setup.mjs`.
// Each case names the record clause it checks, what that clause requires, and what Authentik
// did. "pass" means Authentik (or the resource-server check below) behaved as the record
// requires; "FAIL" means it did not. Writes results.json and prints a Markdown table.
//
// The resource-server check is the one ADR-0055 decision 3 describes, configured with an
// issuer, a discovery URL and one audience only: RS256 against the JWKS, iss, exp/nbf with 60 s
// skew, aud must contain the configured audience, empty aud refused. No Authentik-specific code.
import { createPublicKey, verify } from "node:crypto";
import { execFileSync } from "node:child_process";
import { readFileSync, writeFileSync } from "node:fs";

const S = JSON.parse(readFileSync(new URL("./state.json", import.meta.url)));
const AK = S.ak, T = `${AK}/application/o/token/`;
const ADMIN = { Authorization: "Bearer spike-only-bootstrap-token-0123456789", "Content-Type": "application/json" };
const TX = "urn:ietf:params:oauth:grant-type:token-exchange";
const AT = "urn:ietf:params:oauth:token-type:access_token";
const { mcp, sidecar, api, other, rogue, noGrant, short } = S.providers;
const { alice, bob } = S.users;
const results = [];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const claims = (t) => JSON.parse(Buffer.from(t.split(".")[1], "base64url"));

async function token(body) {
  const r = await fetch(T, { method: "POST", body: new URLSearchParams(body) });
  return { status: r.status, ...(await r.json()) };
}
const userToken = (p, u, scope = "openid profile victual:read") =>
  token({ grant_type: "client_credentials", client_id: p.client_id, client_secret: p.client_secret, username: u.username, password: u.app_password, scope });
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
const RS_SIDECAR = { issuer: ISSUER, discovery: disc(mcp), audience: mcp.client_id };
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
  `act=${JSON.stringify(xc.act ?? null)} azp=${xc.azp}`, !!xc.act);
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
    const actorJwt = (await userToken(mcp, { username: uname, app_password: key }, "openid")).access_token;
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
{
  const ro = await userToken(mcp, alice, "openid profile victual:read");
  const w = await exchange(sidecar, ro.access_token, { audience: api.client_id, scope: "openid victual:read victual:write" });
  const got = w.access_token ? claims(w.access_token).scope : "";
  record("N11", "neg", "0055 d7: the exchanged token carries no scope the subject token lacked",
    "victual:write refused or dropped (subject token: victual:read only)", `${err(w)}; scope=${got}`, !String(got).includes("victual:write"));
}
{
  // Mitigation for N11 by configuration only: an expression policy bound to the /api
  // application refuses an exchange that asks for a scope the subject token does not carry.
  // Authentik evaluates the target application's bindings with the subject token as oauth_jwt.
  const expression = [
    'jwt = request.context.get("oauth_jwt") or {}',
    "if not jwt or request.http_request is None:",
    "    return True",
    'have = set(str(jwt.get("scope", "")).split())',
    'want = set(request.http_request.POST.get("scope", "").split())',
    "return want <= have",
  ].join("\n");
  for (const p of (await admin("GET", "/policies/expression/?search=victual-spike")).results) await admin("DELETE", `/policies/expression/${p.pk}/`);
  const pol = await admin("POST", "/policies/expression/", { name: "victual-spike-scope-ceiling", expression });
  const app = await admin("GET", `/core/applications/${api.slug}/`);
  const bind = await admin("POST", "/policies/bindings/", { policy: pol.pk, target: app.pk, order: 0 });
  const ro = (await userToken(mcp, alice, "openid profile victual:read")).access_token;
  const widen = await exchange(sidecar, ro, { audience: api.client_id, scope: "openid victual:read victual:write" });
  const same = await exchange(sidecar, ro, { audience: api.client_id, scope: "openid victual:read" });
  const rw = (await userToken(mcp, alice, "openid profile victual:read victual:write")).access_token;
  const full = await exchange(sidecar, rw, { audience: api.client_id, scope: "openid victual:read victual:write" });
  await admin("DELETE", `/policies/bindings/${bind.pk}/`);
  record("N11b", "neg", "0055 d7 with a scope-ceiling policy on the /api application",
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

writeFileSync(new URL("./results.json", import.meta.url), JSON.stringify({ at: new Date().toISOString(), authentik: (await admin("GET", "/admin/version/")).version_current, results }, null, 2));
console.log("| ID | Clause | Expected | Observed | Verdict |\n|---|---|---|---|---|");
for (const r of results) console.log(`| ${r.id} | ${r.clause} | ${r.expected} | ${r.observed.replaceAll("|", "\\|")} | ${r.verdict} |`);
