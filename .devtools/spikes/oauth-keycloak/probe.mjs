// Keycloak compatibility probe for ADR-0055 / ADR-0056. Needs `./run.sh up && node setup.mjs`.
// Each case names the record clause it checks, what that clause requires, and what Keycloak
// did. "pass" means Keycloak (or the resource-server check below) behaved as the record
// requires; "FAIL" means it did not; "n/a" means the case needs another run mode. Writes
// results.json and prints a Markdown table. Case IDs P1 to P10 and N1 to N19 follow the Authentik report where the case is the same; the others are new to this spike.
//
//   node probe.mjs              core run: no Preview or experimental feature enabled
//   node probe.mjs --preview    needs `./run.sh up preview`: delegation (act) cases only
//
// User tokens come from authorization code + PKCE (S256) driven headlessly through the realm's
// login form (lib.mjs). No direct (password) grant is used for tokens; case N19 checks the
// password grant is refused.
import { execFileSync } from "node:child_process";
import { deregisterMcpClient, GRANT_CLAIM, registerMcpClient } from "./adapter.mjs";
import { randomBytes } from "node:crypto";
import { readFileSync, writeFileSync } from "node:fs";
import {
  AT, admin, authorize, claims, err, errd, exchange, finishCode, ISSUER, KC, login, OIDC, REALM, rsCheck, sleep, submitLogin, token, TOKEN, TX,
} from "./lib.mjs";

const REDIRECT_URI = "http://127.0.0.1:8765/callback"; // the loopback redirect every scripted client uses
const MODE = process.argv.includes("--preview") ? "preview" : "core";
const S = JSON.parse(readFileSync(new URL("./state.json", import.meta.url)));
const { mcp, sidecar, uriSidecar, api, other, rogue, noExchange, short, plain } = S.clients;
const { alice, bob } = S.users;
const cred = (u) => ({ username: u.username, password: u.password });
const DISCOVERY = `${ISSUER}/.well-known/openid-configuration`;
const RS_SIDECAR = { issuer: ISSUER, discovery: DISCOVERY, audience: S.mcpAud };
const RS_API = { issuer: ISSUER, discovery: DISCOVERY, audience: S.apiAud };
const READ = "openid profile victual:read";
const T0 = Date.now();
const results = [];
const facts = {};
function record(id, kind, clause, expected, observed, ok, gate = "") {
  results.push({ id, kind, gate, clause, expected, observed: String(observed), verdict: ok === "n/a" ? "n/a" : ok ? "pass" : "FAIL" });
}
const userToken = (scope = READ, user = alice, client = mcp, opts = {}) => login(client, cred(user), { scope, ...opts });
const introspect = async (by, tok) => {
  const r = await token({ token: tok, client_id: by.clientId, client_secret: by.secret }, {}, `${OIDC}/token/introspect`);
  return r.active;
};
const refresh = (client, rt) => token({ grant_type: "refresh_token", client_id: client.clientId, refresh_token: rt, ...(client.secret ? { client_secret: client.secret } : {}) });
const forge = (tok, patch) => {
  const [h, , s] = tok.split(".");
  return `${h}.${Buffer.from(JSON.stringify({ ...claims(tok), ...patch })).toString("base64url")}.${s}`;
};
// The client policies come from setup.mjs. setPolicies(names) leaves only those enabled; null restores all.
const ALL_POLICIES = (await admin("GET", "/client-policies/policies")).policies ?? [];
const setPolicies = (names) => admin("PUT", "/client-policies/policies", { policies: names === null ? ALL_POLICIES : ALL_POLICIES.filter((p) => names.includes(p.name)) });
const serverinfo = await admin("GET", "/admin/serverinfo");
const profile = serverinfo.profileInfo;
const enabledNonSupported = [...profile.previewFeatures, ...profile.experimentalFeatures].filter((f) => !profile.disabledFeatures.includes(f));
facts.keycloak = serverinfo.systemInfo.version;
facts.enabledPreviewOrExperimental = enabledNonSupported;
facts.mode = MODE;

// A token that expires in 3 s is issued now and checked at the end (cases N13, N14).
const shortTok = MODE === "core" ? (await userToken("openid victual:read", alice, short)).access_token : null;

if (MODE === "core") await core();
else await preview();

await finish();

// ============================================================================ core run
async function core() {
  // ---- G-features: nothing Preview or experimental is on in this run
  record("G1", "gate", "0055 d2: nothing decision 7 requires may rely on a Preview feature",
    "this run has no Preview or experimental feature enabled",
    `Keycloak ${facts.keycloak}; enabled Preview/experimental features: ${enabledNonSupported.length ? enabledNonSupported.join(",") : "none"}; ` +
    `disabled Preview: ${profile.previewFeatures.filter((f) => profile.disabledFeatures.includes(f)).join(",")}`,
    enabledNonSupported.length === 0, "G-features");

  // ---- positive cases
  {
    const r = await fetch(DISCOVERY), m = await r.json();
    const r2 = await fetch(`${KC}/.well-known/oauth-authorization-server/realms/${REALM}`), m2 = await r2.json();
    record("P1", "pos", "0055 d3: resource servers fetch discovery from the issuer",
      "discovery reachable from the issuer URL; its issuer equals the token iss",
      `iss=${ISSUER}; OIDC ${DISCOVERY.replace(KC, "")} -> ${r.status} issuer=${m.issuer}; RFC 8414 /.well-known/oauth-authorization-server/realms/${REALM} -> ${r2.status} issuer=${m2.issuer}; ` +
      `resource_indicators_supported=${m.resource_indicators_supported ?? "absent"}`,
      r.ok && m.issuer === ISSUER && r2.ok && m2.issuer === ISSUER, "G-discovery");
  }
  const at = await userToken();
  const ac = at.access_token ? claims(at.access_token) : {};
  record("P2", "pos", "0055 d4 / 0056 d3: a user token names a stable subject, not email; MCP token has the dedicated MCP audience",
    "200; sub opaque, no email in sub; aud contains the sidecar's canonical URI; ttl 300 s",
    `${err(at)}; sub=${String(ac.sub).slice(0, 12)}... aud=${JSON.stringify(ac.aud)} azp=${ac.azp} ttl=${ac.exp - ac.iat}s; code flow iss param=${at._iss}`,
    at.status === 200 && ac.sub && !String(ac.sub).includes("@") && [].concat(ac.aud).includes(S.mcpAud) && ac.exp - ac.iat === 300, "G-audience");
  {
    const again = await userToken();
    const same = again.access_token && claims(again.access_token).sub === ac.sub;
    const viaOther = await userToken("openid", alice, other);
    const sameAcross = viaOther.access_token && claims(viaOther.access_token).sub === ac.sub;
    record("P3", "pos", "0055 d4: one (issuer, subject) per user across clients",
      "same sub on a second token and from a different client", `same on reissue: ${same}; same across clients: ${sameAcross}`, same && sameAcross);
  }
  {
    const v = await rsCheck(at.access_token, RS_SIDECAR);
    record("P4", "pos", "0055 d3: the sidecar accepts a token for its audience", "accepted", v, v === "accepted");
  }
  const ex = await exchange(sidecar, at.access_token, { audience: api.clientId, scope: "openid victual:read" });
  const xc = ex.access_token ? claims(ex.access_token) : {};
  record("P5", "pos", "0055 d7: the sidecar exchanges the user's token for an /api token",
    "200; sub unchanged; aud = /api; iss unchanged",
    `${err(ex)}; same sub: ${xc.sub === ac.sub}; aud=${JSON.stringify(xc.aud)}; iss=${xc.iss}; scope=${xc.scope}; no refresh token: ${!ex.refresh_token}`,
    ex.status === 200 && xc.sub === ac.sub && xc.aud === S.apiAud && xc.iss === ac.iss, "standard token exchange v2");
  {
    // A sidecar whose client id is its resource URI needs no separate sidecar audience.
    const only = forge(at.access_token, {}); // unchanged token, used only to show aud below
    const e2 = await exchange(uriSidecar, at.access_token, { audience: api.clientId });
    const c2 = e2.access_token ? claims(e2.access_token) : {};
    record("P5b", "pos", "0055 d3/d7: the MCP audience can be the sidecar's own client id",
      "a sidecar registered with its resource URI as client id exchanges a token whose aud holds that URI",
      `${err(e2)}; subject aud=${JSON.stringify(claims(only).aud)}; exchanged azp=${c2.azp} aud=${c2.aud}`,
      e2.status === 200 && c2.azp === S.mcpAud && c2.aud === S.apiAud, "G-audience");
  }
  {
    const v = ex.access_token ? await rsCheck(ex.access_token, RS_API) : "no token";
    record("P6", "pos", "0055 d3: /api accepts the exchanged token", "accepted", v, v === "accepted");
  }
  record("P7", "pos", "0055 d7: the issued token records the sidecar in act",
    "act.sub names the sidecar (no actor_token)", `act=${JSON.stringify(xc.act ?? null)}`, !!xc.act, "G-exchanger");
  record("P7b", "pos", "0055 d7 / open question 8: another claim the server derives from client authentication names the sidecar",
    "a signed claim equals the authenticating client and differs from the subject token's azp",
    `exchanged azp=${xc.azp} (subject token azp=${ac.azp}); documented: token-exchange doc shows azp = requester-client`,
    xc.azp === sidecar.clientId && xc.azp !== ac.azp, "G-exchanger");
  record("P8", "pos", "0055 d7: delegation (OBO) records the sidecar in act", "200; act set by delegation",
    "not run in the core run: delegation is Preview (token-exchange-delegation). See `node probe.mjs --preview`", "n/a", "G-features");
  {
    const gname = S.group.name;
    const before = ac.groups ?? [];
    await admin("DELETE", `/users/${alice.id}/groups/${S.group.id}`);
    const after = claims((await userToken()).access_token).groups ?? [];
    await admin("PUT", `/users/${alice.id}/groups/${S.group.id}`);
    const back = claims((await userToken()).access_token).groups ?? [];
    record("P9", "pos", "0056 d4 / prerequisite 4: group changes reach the next token",
      "group present, absent after removal, present after re-adding",
      `before=${JSON.stringify(before)} after-remove=${JSON.stringify(after)} after-add=${JSON.stringify(back)}`,
      before.includes(gname) && !after.includes(gname) && back.includes(gname));
  }
  {
    const first = await userToken();
    const r2 = await refresh(mcp, first.refresh_token);
    const ok2 = r2.status === 200 && r2.refresh_token && r2.refresh_token !== first.refresh_token && claims(r2.access_token).sub === ac.sub;
    record("P10", "pos", "0055 d6: refresh tokens rotate", "200; a new refresh token differs from the old one; same sub",
      `${err(r2)}; rotated: ${r2.refresh_token !== first.refresh_token}; refresh ttl claim: ${r2.refresh_expires_in}s`, ok2);
    const reuse = await refresh(mcp, first.refresh_token);
    const family = await refresh(mcp, r2.refresh_token);
    record("N16", "neg", "0055 d6: reuse of an old refresh token is refused and revokes the family",
      "old token refused; the newer token of the same family also refused",
      `old token -> ${errd(reuse)}; newer token after reuse -> ${errd(family)}`, reuse.status >= 400 && family.status >= 400);
  }

  // ---- negative cases: resource-server checks
  for (const [id, tok, rs] of [
    ["N1", at.access_token, RS_API],
    ["N2", ex.access_token, RS_SIDECAR],
    ["N3", (await userToken("openid", alice, other)).access_token, RS_API],
  ]) {
    const v = await rsCheck(tok, rs);
    record(id, "neg", "0055 d3: a token for another audience is refused", "refused (aud)", v, v.startsWith("aud"));
  }
  {
    const v = await rsCheck(forge(at.access_token, { aud: S.apiAud }), RS_API);
    record("N4", "neg", "0055 d3: a tampered token is refused", "refused (signature)", v, v === "bad signature");
  }

  // ---- negative cases: token exchange
  const neg = (id, clause, wantErr, r, gate = "", extra = "") =>
    record(id, "neg", clause, `refused${wantErr ? ` ${wantErr}` : ""}`, `${errd(r)}${extra}`, r.status >= 400 && (!wantErr || r.error === wantErr), gate);
  {
    const r = await exchange(sidecar, at.access_token, { resource: "http://evil.example/unknown", audience: api.clientId, scope: "openid victual:read" });
    record("N5", "neg", "0055 d3: resource indicators are not silently ignored (exchange)", "an unknown `resource` is refused",
      `${errd(r)}; aud=${r.access_token ? JSON.stringify(claims(r.access_token).aud) : "-"}; docs: \"does not yet have support for the resource parameter\"`,
      r.status >= 400, "G-audience");
  }
  {
    // The rogue client IS in the subject token's audience here, so only its own configuration decides.
    const withRogue = await userToken("openid victual:read victual-rogue-audience");
    const r = await exchange(rogue, withRogue.access_token, { audience: api.clientId, scope: "openid victual:read" });
    const r2 = await exchange(rogue, at.access_token, { audience: api.clientId, scope: "openid victual:read" });
    neg("N6", "0055 d7: only the trusted sidecar mints /api tokens", "", r, "G-audience",
      `; same client but not in the subject token aud -> ${errd(r2)}`);
    neg("N6b", "0055 d7: the sidecar cannot mint a token for an audience outside its own scopes", "",
      await exchange(sidecar, at.access_token, { audience: other.clientId, scope: "openid victual:read" }), "G-audience");
  }
  {
    const own = await userToken("openid", alice, noExchange);
    const viaSecret = await exchange(noExchange, own.access_token, { audience: noExchange.clientId });
    neg("N7", "0055 d7: a client without the exchange grant cannot exchange", "", viaSecret, "standard token exchange v2",
      `; its own token (aud requirement waived), switch off`);
  }
  neg("N8", "0055 d7: a subject token not audienced to the exchanger is refused", "",
    await exchange(sidecar, (await userToken("openid", alice, other)).access_token, { audience: api.clientId }), "standard token exchange v2",
    "; subject token came from another client of the same realm");
  neg("N9", "0055 d7: a tampered subject token is refused", "",
    await exchange(sidecar, forge(at.access_token, { sub: "someone-else" }), { audience: api.clientId, scope: "openid victual:read" }));
  neg("N10", "0055 d3: one audience per token", "",
    await exchange(sidecar, at.access_token, { audience: [api.clientId, S.mcpAud], scope: "openid victual:read" }), "",
    "; Keycloak allows several audiences when the requester's scopes produce them, so this refusal comes from availability, not from a one-audience rule");
  // N11: scope ceiling, default configuration
  // setup.mjs installs the client policies; N11 shows Keycloak's default by switching the downscope policy off.
  await setPolicies(["victual-dcr-anonymous"]);
  const ro = (await userToken("openid profile victual:read")).access_token;
  const widenDefault = await exchange(sidecar, ro, { audience: api.clientId, scope: "openid victual:read victual:write" });
  const gotDefault = widenDefault.access_token ? claims(widenDefault.access_token).scope : "";
  record("N11", "neg", "0055 d7: the exchanged token carries no scope the subject token lacked",
    "victual:write refused or dropped (subject token: victual:read only)", `default configuration: ${err(widenDefault)}; scope=${gotDefault}`,
    !String(gotDefault).includes("victual:write"), "G-scope-ceiling");
  {
    // Supported configuration: the victual-downscope-all client policy that setup.mjs installs
    // (downscope-assertion-grant-enforcer executor, any client), switched back on.
    await setPolicies(null);
    const widen = await exchange(sidecar, ro, { audience: api.clientId, scope: "openid victual:read victual:write" });
    const same = await exchange(sidecar, ro, { audience: api.clientId, scope: "openid victual:read" });
    const narrower = await exchange(sidecar, ro, { audience: api.clientId, scope: "openid" });
    const rw = (await userToken("openid profile victual:read victual:write")).access_token;
    const full = await exchange(sidecar, rw, { audience: api.clientId, scope: "openid victual:read victual:write" });
    record("N11b", "neg", "0055 d7 with the downscope-assertion-grant-enforcer client policy",
      "widening refused; equal and narrower scope still exchanged; write-to-write works",
      `widen -> ${errd(widen)}; same -> ${err(same)}; narrower -> ${err(narrower)}; write subject asking write -> ${err(full)}`,
      widen.status >= 400 && same.status === 200 && narrower.status === 200 && full.status === 200, "G-scope-ceiling: client policies (supported)");
    // Names, not expansions: Keycloak compares scope strings.
    const stockBundle = (await userToken("openid profile victual:stock")).access_token;
    const leafOfBundle = await exchange(sidecar, stockBundle, { audience: api.clientId, scope: "openid victual:stock:book" });
    const sameBundle = await exchange(sidecar, stockBundle, { audience: api.clientId, scope: "openid victual:stock" });
    const leafToken = (await userToken("openid profile victual:stock:book")).access_token;
    const bundleFromLeaf = await exchange(sidecar, leafToken, { audience: api.clientId, scope: "openid victual:stock" });
    record("N11c", "neg", "0055 d7: both comparisons expand bundle scopes into leaves first",
      "a leaf scope inside the subject token's bundle is allowed (expanded comparison); a bundle from a leaf is refused",
      `subject bundle victual:stock, ask leaf victual:stock:book -> ${errd(leafOfBundle)}; same bundle -> ${err(sameBundle)}; subject leaf, ask bundle -> ${errd(bundleFromLeaf)}. Keycloak compares scope names; it holds no leaf expansion`,
      leafOfBundle.status === 200 && sameBundle.status === 200 && bundleFromLeaf.status >= 400, "G-scope-ceiling");
  }
  {
    const bt = (await userToken(READ, bob)).access_token;
    await admin("PUT", `/users/${bob.id}`, { enabled: false });
    const stateless = await rsCheck(bt, RS_SIDECAR);
    const r = await exchange(sidecar, bt, { audience: api.clientId, scope: "openid victual:read" });
    await admin("PUT", `/users/${bob.id}`, { enabled: true });
    const fresh = await userToken(READ, bob); // a second session, disabled below, to test its refresh token
    await admin("PUT", `/users/${bob.id}`, { enabled: false });
    const refreshed = await refresh(mcp, fresh.refresh_token);
    await admin("PUT", `/users/${bob.id}`, { enabled: true });
    neg("N12", "0055 d4: a disabled user's live token cannot be exchanged", "", r, "",
      `; stateless RS check of the same token: ${stateless}; refresh token of a disabled user -> ${errd(refreshed)}`);
  }
  {
    // N13 and N14 finish at the end; this waits out the rest of the 66 s since issue.
    const rest = 66000 - (Date.now() - T0);
    if (rest > 0) await sleep(rest);
    neg("N13", "0055 d7: an expired subject token is refused", "",
      await exchange(sidecar, shortTok, { audience: api.clientId, scope: "openid victual:read" }));
    const v = await rsCheck(shortTok, { issuer: ISSUER, discovery: DISCOVERY, audience: S.mcpAud });
    record("N14", "neg", "0055 d3: an expired token is refused (60 s skew)", "refused (expired)", v, v === "expired");
  }
  // N17 to N19: grants and PKCE
  {
    const noPkce = await login(mcp, cred(alice), { scope: "openid", params: { code_challenge: null, code_challenge_method: null } });
    const plainPkce = await login(mcp, cred(alice), { scope: "openid", params: { code_challenge_method: "plain" } });
    record("N17", "neg", "0055 d6: PKCE S256 only; a public client without a challenge is refused",
      "authorization request without code_challenge refused; method plain refused",
      `no challenge -> ${errd(noPkce)}; plain -> ${errd(plainPkce)}`, noPkce.status >= 400 && plainPkce.status >= 400);
  }
  {
    // The MCP client has direct access grants off; the password grant must not be available.
    const r = await token({ grant_type: "password", client_id: mcp.clientId, username: alice.username, password: alice.password, scope: "openid" });
    const r2 = await token({ grant_type: "implicit", client_id: mcp.clientId });
    const disc = await (await fetch(DISCOVERY)).json();
    record("N19", "neg", "0055 d6: no password grant, no implicit grant", "password grant refused for the MCP client; client flows configured per client",
      `password grant -> ${errd(r)}; realm discovery still lists grant types: ${disc.grant_types_supported.filter((g) => /password|implicit/.test(g)).join(",")} (per-client switches decide)`,
      r.status >= 400);
  }
  await gatesAfterCore({ at, ex, xc, ac });
}

// ============================================================================ gates
async function gatesAfterCore({ at, ex, xc, ac }) {
  await exchangerGate({ at });
  await audienceGate();
  await revocationGate({ at, ex, xc, ac });
  await k1Gate();
  await k2Gate();
  await registrationGate();
  await loginGate();
  await inventoryGate();
  await imageFacts();
}

// ---- G-exchanger: the exchanger identity is set by the server from client authentication
async function exchangerGate({ at }) {
  const form = (pairs) => new URLSearchParams(pairs);
  const base = [["grant_type", TX], ["subject_token", at.access_token], ["subject_token_type", AT], ["audience", api.clientId], ["scope", "openid victual:read"]];
  const basic = (c, secret = c.secret) => ({ Authorization: `Basic ${Buffer.from(`${c.clientId}:${secret}`).toString("base64")}` });
  const rogueWide = (await userToken("openid victual:read victual-rogue-audience")).access_token; // rogue is in this token's aud
  const asRogueBase = [["grant_type", TX], ["subject_token", rogueWide], ["subject_token_type", AT], ["audience", api.clientId], ["scope", "openid victual:read"]];
  const c1 = await token(form([...base, ["client_id", sidecar.clientId], ["client_secret", rogue.secret]]));
  record("X1", "neg", "0055 d7 / q8: the caller cannot claim to be the sidecar with another client's secret",
    "refused", errd(c1), c1.status >= 400, "G-exchanger");
  const c2 = await token(form([...base, ["client_id", sidecar.clientId]]));
  record("X2", "neg", "0055 d7 / q8: naming the sidecar's client_id without its secret",
    "refused", errd(c2), c2.status >= 400, "G-exchanger");
  const c3 = await token(form([...base, ["client_id", sidecar.clientId]]), basic(rogue));
  record("X3", "neg", "0055 d7 / q8: Basic authentication as another client plus client_id=sidecar in the body",
    "refused, or issued to the authenticated client only",
    `${errd(c3)}${c3.access_token ? `; azp=${claims(c3.access_token).azp}` : ""}`,
    c3.status >= 400 || claims(c3.access_token).azp === rogue.clientId, "G-exchanger");
  const c4 = await token(form([...asRogueBase, ["client_id", rogue.clientId], ["client_secret", rogue.secret], ["azp", sidecar.clientId], ["act", JSON.stringify({ sub: sidecar.clientId })],
    ["actor_token", at.access_token], ["actor_token_type", AT], ["client_id", sidecar.clientId]]));
  // Rogue holds no /api audience, so the exchange is refused outright; the same extras against a granted exchange follow.
  const c5 = await token(form([...base, ["client_id", sidecar.clientId], ["client_secret", sidecar.secret], ["azp", rogue.clientId], ["authorized_party", rogue.clientId],
    ["act", JSON.stringify({ sub: "forged" })], ["client_id", rogue.clientId]]));
  const k5 = c5.access_token ? claims(c5.access_token) : {};
  record("X4", "neg", "0055 d7 / q8: extra parameters (azp, act, a second client_id) do not change the exchanger claim",
    "azp stays the authenticated client; no caller-supplied act",
    `rogue with extras and a second client_id -> ${errd(c4)}; sidecar with azp=rogue, act={forged}, client_id twice -> ${errd(c5)}; azp=${k5.azp}; act=${JSON.stringify(k5.act ?? null)}`,
    c4.status >= 400 && (c5.status >= 400 || (k5.azp === sidecar.clientId && !k5.act)), "G-exchanger");
  const c5b = await token(form([...base, ["client_id", sidecar.clientId], ["client_secret", sidecar.secret], ["azp", rogue.clientId], ["authorized_party", rogue.clientId],
    ["act", JSON.stringify({ sub: "forged", client_id: "forged" })], ["may_act", JSON.stringify({ sub: "forged" })]]));
  const k5b = c5b.access_token ? claims(c5b.access_token) : {};
  record("X4b", "neg", "0055 d7 / q8: a valid sidecar request with azp, act and may_act form fields does not change the claims",
    "azp stays the sidecar; no act", `${errd(c5b)}; azp=${k5b.azp}; act=${JSON.stringify(k5b.act ?? null)}; may_act=${JSON.stringify(k5b.may_act ?? null)}`,
    c5b.status >= 400 || (k5b.azp === sidecar.clientId && !k5b.act && !k5b.may_act), "G-exchanger");
  const c6 = await token(form([...base, ["actor_token", at.access_token], ["actor_token_type", AT], ["client_id", sidecar.clientId], ["client_secret", sidecar.secret]]));
  const k6 = c6.access_token ? claims(c6.access_token) : {};
  record("X5", "neg", "0055 d7 / q8: an actor_token sent without the Preview feature does not become act",
    "refused, or ignored with no act in the token",
    `${errd(c6)}; azp=${k6.azp}; act=${JSON.stringify(k6.act ?? null)}`, c6.status >= 400 || !k6.act, "G-exchanger");
  const pub = await token(form([...base, ["client_id", mcp.clientId]]));
  record("X6", "neg", "0055 d7: a public client cannot exchange", "refused", errd(pub), pub.status >= 400, "G-exchanger");
}

// ---- G-audience
async function audienceGate() {
  const unassigned = await userToken("openid victual-api-audience");
  record("A1", "neg", "0055 d3 / q9: the MCP client cannot obtain a token for a resource it is not permitted",
    "requesting the /api audience scope at the MCP client is refused", errd(unassigned), unassigned.status >= 400, "G-audience");
  const bogus = await userToken("openid victual:read", alice, mcp, { params: { resource: "http://evil.example/unknown" } });
  const known = await userToken("openid victual:read", alice, mcp, { params: { resource: S.apiAud } });
  record("A2", "neg", "0055 d3 / RFC 8707: `resource` at /authorize with an unknown value is refused, not ignored",
    "unknown resource refused; a resource the client may not use is refused",
    `unknown -> ${errd(bogus)}${bogus.access_token ? ` aud=${JSON.stringify(claims(bogus.access_token).aud)}` : ""}; /api resource -> ${errd(known)}${known.access_token ? ` aud=${JSON.stringify(claims(known.access_token).aud)}` : ""}`,
    bogus.status >= 400 && known.status >= 400, "G-audience: resource indicators (experimental, off)");
  {
    // A client created with Keycloak's own defaults (full scope allowed, realm default scopes).
    const cid = "victual-spike-probe-keycloak-defaults";
    await admin("POST", "/clients", { clientId: cid, publicClient: true, standardFlowEnabled: true, redirectUris: [REDIRECT_URI], attributes: { "pkce.code.challenge.method": "S256" } });
    const [c] = await admin("GET", `/clients?clientId=${cid}`);
    const t = await login({ clientId: cid }, cred(alice), { scope: "openid" });
    await admin("DELETE", `/clients/${c.id}`);
    const k = t.access_token ? claims(t.access_token) : {};
    const plainTok = await userToken("openid", alice, plain);
    const v = await rsCheck(plainTok.access_token, RS_SIDECAR);
    record("A3", "info", "0055 d3: the default aud of a token is not the receiver's URI",
      "a client with Keycloak defaults gets no MCP audience unless configured; a token without aud is refused",
      `default client aud=${JSON.stringify(k.aud ?? null)} (not a client id, not an MCP URI); realm-default scopes with full scope off: aud=${JSON.stringify(claims(plainTok.access_token).aud ?? null)}, RS check -> ${v}`,
      !k.aud?.includes?.(S.mcpAud) && v === "empty aud", "G-audience");
  }
}

// ---- G-revocation
async function revocationGate({ ex, xc, ac, at }) {
  record("R1", "info", "0055 d6 / q8: a grant/session identifier survives the exchange",
    "sid present in the user token and equal in the exchanged token",
    `user token sid=${ac.sid}; exchanged sid=${xc.sid}; session ${ac.sid === xc.sid ? "unchanged" : "changed"}; docs: \"Token exchange never creates a new user session\"`,
    !!ac.sid && ac.sid === xc.sid, "G-revocation");
  const names = Object.keys(xc).filter((k) => !(k in ac));
  const mentionsMcp = JSON.stringify(xc).includes(mcp.clientId);
  record("R2", "info", "0055 d6 / q8: the exchanged token identifies the originating MCP client for per-client grant revocation",
    "a claim in the exchanged token names the MCP client whose grant was used",
    `exchanged claims absent from the user token: ${names.join(",") || "none"}; MCP client id appears anywhere in the exchanged token: ${mentionsMcp}; only azp (sidecar) and sid`,
    mentionsMcp, "G-revocation");
  const fixture = async (client) => {
    const t = await userToken(READ, alice, client);
    const x = await exchange(sidecar, t.access_token, { audience: api.clientId, scope: "openid victual:read" });
    if (!(await introspect(api, x.access_token))) throw new Error("exchanged token not active at /api introspection before revocation");
    return { t, x, sid: claims(t.access_token).sid, client };
  };
  const observe = async (f) => ({
    refresh: errd(await refresh(f.client, f.t.refresh_token)),
    introUser: await introspect(sidecar, f.t.access_token),
    introExchanged: await introspect(api, f.x.access_token), // by the audience client: the exchanger cannot introspect its own exchanged token (K1i)
    rsExchanged: await rsCheck(f.x.access_token, RS_API),
    reexchange: err(await exchange(sidecar, f.t.access_token, { audience: api.clientId, scope: "openid victual:read" })),
  });
  const show = (o) => `refresh -> ${o.refresh}; introspect user token active=${o.introUser}; introspect exchanged active=${o.introExchanged}; stateless RS check of exchanged: ${o.rsExchanged}; exchange again -> ${o.reexchange}`;
  {
    const f = await fixture(mcp);
    const r = await fetch(`${OIDC}/revoke`, { method: "POST", body: new URLSearchParams({ token: f.t.refresh_token, token_type_hint: "refresh_token", client_id: mcp.clientId }) });
    const o = await observe(f);
    record("R3", "info", "0055 d6: revoking the client's refresh token (RFC 7009)", "refresh refused; stateless check keeps accepting until exp",
      `revoke -> ${r.status}; ${show(o)}`, o.refresh.startsWith("400") && o.rsExchanged === "accepted", "G-revocation");
  }
  {
    const f = await fixture(mcp);
    const r = await admin("DELETE", `/sessions/${f.sid}`, undefined, { raw: true });
    const o = await observe(f);
    record("R4", "info", "0055 d6: deleting the user session (admin API) by sid", "refresh refused; exchanged token active=false at introspection; stateless check accepts until exp",
      `DELETE session -> ${r.status}; ${show(o)}`, o.refresh.startsWith("400") && o.introExchanged === false && o.rsExchanged === "accepted", "G-revocation");
  }
  {
    const f = await fixture(S.clients.consent);
    const r = await admin("DELETE", `/users/${alice.id}/consents/${S.clients.consent.clientId}`, undefined, { raw: true });
    const o = await observe(f);
    record("R5", "info", "0055 d6: per-client grant revocation = deleting the user's consent for one client (admin API)",
      "the client's refresh token and the tokens exchanged from it stop working",
      `DELETE consent -> ${r.status}; ${show(o)}`, o.refresh.startsWith("400"), "G-revocation");
  }
  {
    // A second client in the same user session keeps working when only one client's grant is revoked.
    const f = await fixture(mcp);
    const g = await fixture(S.clients.consent);
    const r = await admin("DELETE", `/users/${alice.id}/consents/${S.clients.consent.clientId}`, undefined, { raw: true });
    const other = await refresh(mcp, f.t.refresh_token);
    record("R6", "info", "0055 d6: revoking one client's consent leaves another client's grant of the same user intact",
      "the other client's refresh token still works", `DELETE consent (consent client) -> ${r.status}; MCP client refresh -> ${errd(other)}`, other.status === 200, "G-revocation");
  }
}

// ---- K1: the exchanged token identifies the originating grant (ADR-0055 prerequisite 10, question 13)
// The MCP clients k1a and k1b come from adapter.mjs: each has a per-grant client scope whose Hardcoded
// claim mapper writes `victual_client`, a default scope of that client and an optional scope of the sidecar.
async function k1Gate() {
  const { k1a, k1b } = S.clients;
  const SC = "openid victual:read";
  const grantEx = (subject, by) => exchange(sidecar, subject, { audience: api.clientId, scope: `${SC} ${by.grantScope}` });
  const pickNotes = (tok) => Object.fromEntries(Object.entries(claims(tok)).filter(([k]) => k.startsWith("note_")).map(([k, v]) => [k, String(v).slice(0, 24)]));
  const delScope = async (name) => { for (const x of (await admin("GET", "/client-scopes")).filter((y) => y.name === name)) await admin("DELETE", `/client-scopes/${x.id}`, undefined, { raw: true }); };

  // K1a: candidate 1, User Session Note mapper. Which notes does a browser login store, and does one name the client?
  {
    const names = ["clientId", "client_id", "client", "AUTH_METHOD", "AUTH_TIME", "KC_DEVICE_NOTE", "identity_provider", "iss", "scope", "clientHost", "clientAddress"];
    await delScope("victual-k1-notes");
    await admin("POST", "/client-scopes", { name: "victual-k1-notes", protocol: "openid-connect", attributes: { "include.in.token.scope": "true", "display.on.consent.screen": "false" },
      protocolMappers: names.map((n) => ({ name: `note-${n}`, protocol: "openid-connect", protocolMapper: "oidc-usersessionmodel-note-mapper",
        config: { "user.session.note": n, "claim.name": `note_${n}`, "jsonType.label": "String", "access.token.claim": "true", "id.token.claim": "false", "introspection.token.claim": "true" } })) });
    const id = (await admin("GET", "/client-scopes")).find((x) => x.name === "victual-k1-notes").id;
    await admin("PUT", `/clients/${k1a.id}/default-client-scopes/${id}`);
    await admin("PUT", `/clients/${sidecar.id}/optional-client-scopes/${id}`);
    const t = await userToken(SC, alice, k1a);
    const x = await exchange(sidecar, t.access_token, { audience: api.clientId, scope: `${SC} victual-k1-notes` });
    const onUser = pickNotes(t.access_token), onX = x.access_token ? pickNotes(x.access_token) : {};
    await delScope("victual-k1-notes");
    record("K1a", "info", "0055 d6, q13, prerequisite 10: a User Session Note mapper carries the originating MCP client into the exchanged token",
      "a session note claim in the exchanged token names the MCP client",
      `notes tried: ${names.join(", ")}. Notes that exist on a browser login: ${Object.keys(onUser).map((k) => k.slice(5)).join(", ")} (values: ${JSON.stringify(onUser)}); same claims on the exchanged token: ${Object.keys(onX).length === Object.keys(onUser).length}; the documented notes are identity_provider, identity_provider_identity (brokered logins), IMPERSONATOR_* and the service-account details; none holds the requesting client`,
      JSON.stringify(onX).includes(k1a.clientId), "G-grant-identity");
  }

  // K1b: candidate 2, a mapper on the MCP client itself (dedicated scope), and one on the sidecar
  {
    const add = (c, name, claim, value) => admin("POST", `/clients/${c.id}/protocol-mappers/models`, { name, protocol: "openid-connect", protocolMapper: "oidc-hardcoded-claim-mapper",
      config: { "claim.name": claim, "claim.value": value, "jsonType.label": "String", "access.token.claim": "true", "introspection.token.claim": "true" } });
    await add(k1a, "k1-client-mapper", "k1_on_mcp_client", "mcp"); await add(sidecar, "k1-client-mapper", "k1_on_sidecar", "sidecar");
    const t = await userToken(SC, alice, k1a);
    const x = await exchange(sidecar, t.access_token, { audience: api.clientId, scope: SC });
    const u = claims(t.access_token), c = claims(x.access_token);
    for (const cl of [k1a, sidecar]) for (const m of await admin("GET", `/clients/${cl.id}/protocol-mappers/models`)) if (m.name === "k1-client-mapper") await admin("DELETE", `/clients/${cl.id}/protocol-mappers/models/${m.id}`);
    record("K1b", "info", "0055 d6, q13: a mapper on the MCP client (or on the sidecar) reaches the exchanged token",
      "the exchanged token carries the claim of the MCP client's mapper",
      `user token claims ${JSON.stringify({ mcpClientMapper: u.k1_on_mcp_client ?? null })}; exchanged token claims ${JSON.stringify({ mcpClientMapper: c.k1_on_mcp_client ?? null, sidecarMapper: c.k1_on_sidecar ?? null })}. Mappers in a client's own (dedicated) scope are not applied at the exchange; only client scopes requested at the exchange are (as in L3)`,
      c.k1_on_mcp_client === "mcp", "G-grant-identity");
  }

  // K1c: candidate 3, a per-grant client scope with a Hardcoded claim mapper, requested by the sidecar at the exchange
  const ta = await userToken(SC, alice, k1a), tb = await userToken(SC, alice, k1b);
  const xa = await grantEx(ta.access_token, k1a), xb = await grantEx(tb.access_token, k1b);
  const ca = claims(xa.access_token), cb = claims(xb.access_token);
  const bare = await exchange(sidecar, ta.access_token, { audience: api.clientId, scope: SC });
  record("K1c", "pos", "0055 d6, q13, prerequisite 10: the exchanged token carries a server-signed claim naming the originating MCP client",
    "for each of two clients: victual_client equals the client the user authorized; the per-grant scope is in `scope`; sid and sub carried over; azp is still the sidecar",
    `A: ${err(xa)} ${GRANT_CLAIM}=${ca.victual_client} sid=${ca.sid === claims(ta.access_token).sid} azp=${ca.azp} scope has ${k1a.grantScope}=${String(ca.scope).includes(k1a.grantScope)}; B: ${GRANT_CLAIM}=${cb.victual_client} azp=${cb.azp}; sidecar asking without the grant scope: ${GRANT_CLAIM}=${bare.access_token ? claims(bare.access_token).victual_client : err(bare)} (Victual's rule: no claim, no access)`,
    ca.victual_client === k1a.clientId && cb.victual_client === k1b.clientId && ca.azp === sidecar.clientId && ca.sid === claims(ta.access_token).sid && ca.sub === claims(ta.access_token).sub
    && String(ca.scope).includes(k1a.grantScope) && !String(ca.scope).includes(k1b.grantScope) && bare.access_token && !claims(bare.access_token).victual_client, "G-grant-identity: client scope + Hardcoded claim (supported)");
  record("K1d", "neg", "0055 d6, prerequisite 10: the claim is server-signed; editing it breaks the signature",
    "a token with victual_client changed to the other client is refused", await rsCheck(forge(xa.access_token, { victual_client: k1b.clientId }), RS_API),
    (await rsCheck(forge(xa.access_token, { victual_client: k1b.clientId }), RS_API)) === "bad signature", "G-grant-identity");
  {
    // K1e: a caller cannot choose the identity
    const other = await exchange(sidecar, ta.access_token, { audience: api.clientId, scope: `${SC} ${k1b.grantScope}` });
    const both = await exchange(sidecar, ta.access_token, { audience: api.clientId, scope: `${SC} ${k1a.grantScope}`, [GRANT_CLAIM]: k1b.clientId, azp: k1b.clientId, client_id_hint: k1b.clientId });
    const viaUser = await login(k1a, cred(alice), { scope: `${SC} ${k1b.grantScope}` });
    const viaParam = await login(k1a, cred(alice), { scope: SC, params: { [GRANT_CLAIM]: k1b.clientId, claims: JSON.stringify({ access_token: { [GRANT_CLAIM]: { value: k1b.clientId } } }) } });
    const vk = viaParam.access_token ? claims(viaParam.access_token) : {};
    record("K1e", "neg", "0055 d6, q13, prerequisite 10: a caller-supplied identity is never authoritative",
      "A's subject token with B's grant scope is refused; extra form fields change nothing; A cannot ask for B's scope at /authorize; a `claims` request parameter does not set the claim",
      `B's scope with A's token -> ${err(other)}; extra ${GRANT_CLAIM} and azp fields -> ${err(both)}, ${GRANT_CLAIM}=${both.access_token ? claims(both.access_token).victual_client : "-"}; A asks B's scope at authorize -> ${err(viaUser)}; ${GRANT_CLAIM} and claims parameters at authorize -> ${err(viaParam)}, ${GRANT_CLAIM}=${vk.victual_client}`,
      other.status >= 400 && both.access_token && claims(both.access_token).victual_client === k1a.clientId && viaUser.status >= 400 && vk.victual_client === k1a.clientId, "G-grant-identity");
  }
  {
    // K1f: the same request without the downscope client policy
    await setPolicies(["victual-dcr-anonymous"]);
    const forged = await exchange(sidecar, ta.access_token, { audience: api.clientId, scope: `${SC} ${k1b.grantScope}` });
    await setPolicies(null);
    record("K1f", "neg", "0055 d6, q13, prerequisite 10: without the victual-downscope-all client policy the sidecar cannot relabel a grant",
      "A's subject token with B's grant scope refused",
      `policy switched off: ${err(forged)} ${GRANT_CLAIM}=${forged.access_token ? claims(forged.access_token).victual_client : "-"} (azp=${forged.access_token ? claims(forged.access_token).azp : "-"}); the policy is therefore required deployment configuration for this claim`,
      forged.status >= 400, "G-grant-identity: needs the client policy");
  }

  // K1g: candidate 4, sid plus a server-side mapping (admin API: the clients of a session)
  {
    const sessionClients = async (sid) => Object.values((await admin("GET", `/users/${alice.id}/sessions`)).find((s) => s.id === sid)?.clients ?? {});
    const sep = [claims(ta.access_token).sid, claims(tb.access_token).sid];
    const sepClients = [await sessionClients(sep[0]), await sessionClients(sep[1])];
    const sa = await userToken(SC, alice, k1a);
    const sb = await userToken(SC, alice, k1b, { f: sa._f });
    const shared = claims(sa.access_token).sid, sharedB = claims(sb.access_token).sid;
    const sharedClients = await sessionClients(shared);
    await grantEx(sa.access_token, k1a);
    const afterEx = await sessionClients(shared);
    record("K1g1", "pos", "0055 d6, q13: sid plus the admin API's session client list identifies the originating client (separate sessions)",
      "one MCP client in each session; the exchange adds none (the exchange creates no session)",
      `session A clients=${JSON.stringify(sepClients[0])}; session B clients=${JSON.stringify(sepClients[1])}; sids differ=${sep[0] !== sep[1]}; the sidecar is not added to the session by an exchange`,
      sep[0] !== sep[1] && sepClients[0].length === 1 && sepClients[0][0] === k1a.clientId && sepClients[1].length === 1 && sepClients[1][0] === k1b.clientId, "G-grant-identity: sid + admin API (supported)");
    record("K1g2", "neg", "0055 d6, q13: sid identifies the originating client when two clients share one SSO session",
      "the session names one MCP client",
      `same browser, two clients: sid A == sid B: ${shared === sharedB}; session clients=${JSON.stringify(sharedClients)}; after an exchange=${JSON.stringify(afterEx)}. sid alone cannot say which grant an exchanged token came from`,
      shared !== sharedB || sharedClients.length === 1, "G-grant-identity");
  }

  // K1h: lightweight access tokens (use-lightweight-access-token executor)
  {
    const audScope = (await admin("GET", "/client-scopes")).find((x) => x.name === "victual-api-audience");
    const m = (await admin("GET", `/client-scopes/${audScope.id}/protocol-mappers/models`))[0];
    const profs = (await admin("GET", "/client-policies/profiles")).profiles;
    await admin("PUT", "/client-policies/profiles", { profiles: [...profs, { name: "victual-k1-lightweight", description: "probe only", executors: [{ executor: "use-lightweight-access-token", configuration: { "auto-configure": true } }] }] });
    await admin("PUT", "/client-policies/policies", { policies: [...ALL_POLICIES, { name: "victual-k1-lightweight", description: "probe only", enabled: true, conditions: [{ condition: "client-access-type", configuration: { type: ["confidential"] } }], profiles: ["victual-k1-lightweight"] }] });
    let off, on, introLight;
    try {
      off = await grantEx(ta.access_token, k1a);
      await admin("PUT", `/client-scopes/${audScope.id}/protocol-mappers/models/${m.id}`, { ...m, config: { ...m.config, "lightweight.claim": "true" } });
      on = await grantEx(ta.access_token, k1a);
      introLight = on.access_token ? await token({ token: on.access_token, client_id: api.clientId, client_secret: api.secret }, {}, `${OIDC}/token/introspect`) : {};
    } finally {
      await admin("PUT", `/client-scopes/${audScope.id}/protocol-mappers/models/${m.id}`, m);
      await admin("PUT", "/client-policies/policies", { policies: ALL_POLICIES });
      await admin("PUT", "/client-policies/profiles", { profiles: profs });
    }
    const cl = on?.access_token ? claims(on.access_token) : {};
    record("K1h", "pos", "0055 d6, q13: the grant claim survives a lightweight access token",
      "with lightweight tokens on, the exchanged token still names the client (claim flagged lightweight, and the per-grant scope in `scope`)",
      `audience mapper not flagged lightweight: ${errd(off)}; flagged: ${err(on)}, claims=${Object.keys(cl).join(",")}, ${GRANT_CLAIM}=${cl.victual_client}, grant scope in scope=${String(cl.scope).includes(k1a.grantScope)}; /api check=${on?.access_token ? await rsCheck(on.access_token, RS_API) : "-"}; introspection returns the full claims: ${introLight?.email !== undefined}`,
      on?.status === 200 && cl.victual_client === k1a.clientId && String(cl.scope).includes(k1a.grantScope), "G-grant-identity: client policy (supported)");
  }

  // K1i: where the exchanged token can be introspected
  {
    const bySidecar = await introspect(sidecar, xa.access_token), byApi = await introspect(api, xa.access_token);
    record("K1i", "info", "0055 d6: introspection of an exchanged token",
      "active at the resource server (the /api client), the point Victual would check",
      `introspected by the sidecar (the exchanger, azp): active=${bySidecar}; by the /api client (the audience): active=${byApi}. The sidecar's own introspection is inactive even before any revocation, so a revocation check must be made by the audience client`,
      byApi === true, "G-revocation");
  }

  // K1j: consent screen for each client
  {
    await admin("DELETE", `/users/${alice.id}/consents/${k1b.clientId}`, undefined, { raw: true });
    const a = await authorize(k1b, { scope: SC });
    const s = await submitLogin(a, cred(alice));
    const fin = await finishCode(a, s.response); // sets a.consentScreen when the consent page was shown and accepted
    const a2 = await authorize(k1b, { scope: SC });
    const s2 = await submitLogin(a2, cred(alice));
    const fin2 = await finishCode(a2, s2.response);
    record("K1j", "pos", "0055 d6, q13: an adapter-created client has Consent required, so the user's grant is a stored consent per client",
      "a consent screen is shown on first use; the consent is stored; the next login skips the screen",
      `screen shown after the stored consent was deleted=${!!a.consentScreen}; code after accepting=${!!fin.code}; stored consents=${(await admin("GET", `/users/${alice.id}/consents`)).map((c) => c.clientId).join(",")}; second login: screen shown=${!!a2.consentScreen}, code=${!!fin2.code}`,
      !!a.consentScreen && !!fin.code && !a2.consentScreen && !!fin2.code, "G-revocation");
  }

  // ---- K1r: revoke one grant by each mechanism; where does the exchanged token stop?
  const jar = (shared) => (shared ? "same browser session" : "separate sessions");
  const fixture = async (shared, offline = false) => {
    let a = await userToken(SC + (offline ? " offline_access" : ""), alice, k1a);
    if (offline) { const r = await refresh(k1a, a.refresh_token); a = { ...a, access_token: r.access_token, refresh_token: r.refresh_token }; }
    const b = await userToken(SC, alice, k1b, { f: shared ? a._f : undefined });
    return { a, b, xa: await grantEx(a.access_token, k1a), xb: await grantEx(b.access_token, k1b), sidA: claims(a.access_token).sid };
  };
  const sessions = async () => ({ online: await admin("GET", `/users/${alice.id}/sessions`), consents: (await admin("GET", `/users/${alice.id}/consents`)).map((c) => c.clientId) });
  // What Victual would check for an exchanged token: introspection by the /api client, and a lookup keyed on
  // victual_client, sid and sub (session still holds the client, consent stored, client not-before earlier than iat).
  const verdictOf = async (x, snapshot) => {
    const c = claims(x.access_token);
    const [cl] = await admin("GET", `/clients?clientId=${encodeURIComponent(c.victual_client)}`);
    const offline = await admin("GET", `/users/${alice.id}/offline-sessions/${cl.id}`);
    const inSession = snapshot.online.some((s) => s.id === c.sid && Object.values(s.clients).includes(c.victual_client)) || offline.some((s) => s.id === c.sid);
    const v = { introspect: await introspect(api, x.access_token), sessionHoldsClient: inSession, consent: snapshot.consents.includes(c.victual_client), notBeforeStale: c.iat < (cl.notBefore ?? 0) };
    return { ...v, blocked: !v.introspect || !v.sessionHoldsClient || !v.consent || v.notBeforeStale };
  };
  const fmt = (v) => `introspect=${v.introspect} session+client=${v.sessionHoldsClient} consent=${v.consent} notBeforeStale=${v.notBeforeStale} => ${v.blocked ? "BLOCKED" : "valid"}`;
  const resetNotBefore = async () => { const [c] = await admin("GET", `/clients?clientId=${k1a.clientId}`); if (c.notBefore) await admin("PUT", `/clients/${c.id}`, { ...c, notBefore: 0 }); };
  const mechanisms = [
    ["1", "deleting the user's consent for client A (admin API)", (f) => admin("DELETE", `/users/${alice.id}/consents/${k1a.clientId}`, undefined, { raw: true }), "per-grant"],
    ["2", "revoking client A's refresh token (RFC 7009)", (f) => fetch(`${OIDC}/revoke`, { method: "POST", body: new URLSearchParams({ token: f.a.refresh_token, token_type_hint: "refresh_token", client_id: k1a.clientId }) }), "per-grant"],
    ["3", "deleting the user session of grant A (admin API, by sid)", (f) => admin("DELETE", `/sessions/${f.sidA}`, undefined, { raw: true }), "per-grant"],
    ["4", "client A's not-before (admin API PUT notBefore=now)", async (f) => { await sleep(1100); const [c] = await admin("GET", `/clients?clientId=${k1a.clientId}`); return admin("PUT", `/clients/${c.id}`, { ...c, notBefore: Math.floor(Date.now() / 1000) }, { raw: true }); }, "per-grant"],
    ["5", "logging the user out of every session (admin API)", (f) => admin("POST", `/users/${alice.id}/logout`, undefined, { raw: true }), "user-wide"],
  ];
  for (const shared of [false, true]) {
    for (const [n, label, run, scopeOf] of mechanisms) {
      const f = await fixture(shared);
      const before = await sessions();
      const [pa, pb] = [await verdictOf(f.xa, before), await verdictOf(f.xb, before)];
      const resp = await run(f);
      const snap = await sessions();
      const [va, vb] = [await verdictOf(f.xa, snap), await verdictOf(f.xb, snap)];
      const ra = await refresh(k1a, f.a.refresh_token), rb = await refresh(k1b, f.b.refresh_token);
      const stateless = [await rsCheck(f.xa.access_token, RS_API), await rsCheck(f.xb.access_token, RS_API)];
      await resetNotBefore();
      const want = scopeOf === "user-wide" ? va.blocked && vb.blocked : va.blocked && !vb.blocked;
      record(`K1r${n}${shared ? "h" : "s"}`, "info", `0055 d6, q13, prerequisite 10: ${label}, ${jar(shared)}`,
        scopeOf === "user-wide" ? "both grants' exchanged tokens blocked (a user-wide mechanism)" : "A's exchanged token blocked at introspection or the grant lookup; B's still valid",
        `before: A ${pa.blocked ? "BLOCKED" : "valid"}, B ${pb.blocked ? "BLOCKED" : "valid"}; call -> ${resp.status}; after: A ${fmt(va)}; B ${fmt(vb)}; refresh A -> ${errd(ra)}; refresh B -> ${errd(rb)}; stateless check A/B: ${stateless.join("/")}`,
        want && !pa.blocked && !pb.blocked, "G-revocation");
    }
  }
  {
    // K1r6: an offline grant
    const f = await fixture(false, true);
    const before = await sessions();
    const [pa, pb] = [await verdictOf(f.xa, before), await verdictOf(f.xb, before)];
    const resp = await admin("DELETE", `/sessions/${f.sidA}?isOffline=true`, undefined, { raw: true });
    const snap = await sessions();
    const [va, vb] = [await verdictOf(f.xa, snap), await verdictOf(f.xb, snap)];
    const ra = await refresh(k1a, f.a.refresh_token), rb = await refresh(k1b, f.b.refresh_token);
    record("K1r6s", "info", "0055 d6, q13, prerequisite 10: deleting the offline session of grant A (admin API, isOffline=true), separate sessions",
      "A's exchanged token blocked; B's still valid",
      `exchanged token's sid is the offline session (${claims(f.xa.access_token).sid === f.sidA}); call -> ${resp.status}; A ${fmt(va)}; B ${fmt(vb)}; refresh A -> ${errd(ra)}; refresh B -> ${errd(rb)}`,
      va.blocked && !vb.blocked && !pa.blocked && !pb.blocked, "G-revocation");
  }
  {
    // K1r7: revoking the access token (RFC 7009) is not a grant revocation
    const f = await fixture(false);
    const resp = await fetch(`${OIDC}/revoke`, { method: "POST", body: new URLSearchParams({ token: f.a.access_token, token_type_hint: "access_token", client_id: k1a.clientId }) });
    const snap = await sessions();
    const va = await verdictOf(f.xa, snap);
    record("K1r7s", "info", "0055 d6: revoking client A's access token (RFC 7009) does not revoke the grant or its exchanged tokens",
      "the exchanged token stays valid (the guide: revoking the subject access token does not revoke the exchanged one)",
      `revoke -> ${resp.status}; A ${fmt(va)}; refresh A -> ${errd(await refresh(k1a, f.a.refresh_token))}`, !va.blocked, "G-revocation");
  }
  {
    // K1s: a stateless check keeps accepting until exp
    const f = await fixture(false);
    await admin("DELETE", `/users/${alice.id}/consents/${k1a.clientId}`, undefined, { raw: true });
    const v = await rsCheck(f.xa.access_token, RS_API);
    record("K1s", "info", "0055 d6: a stateless check of an exchanged token after its grant was revoked",
      "accepted until exp (five minutes at most)", `consent deleted; stateless check -> ${v}; exp in ${claims(f.xa.access_token).exp - Math.floor(Date.now() / 1000)} s`, v === "accepted", "G-revocation");
  }
}

// ---- K2: the registration adapter binds each MCP client to the MCP audience (ADR-0055 prerequisite 11, question 14)
async function k2Gate() {
  const SC = "openid victual:read";
  const created = [];
  const mk = async (name, opts = {}) => { const c = await registerMcpClient(name, { sidecarId: sidecar.id, ...opts }); created.push(c); return c; };
  const audOf = (r) => (r.access_token ? [].concat(claims(r.access_token).aud ?? []) : null);
  try {
    const c1 = await mk("victual-spike-mcp-k2-1"), c2 = await mk("victual-spike-mcp-k2-2");
    const cNo = await mk("victual-spike-mcp-k2-noaud", { bindAudience: false });
    const [rep1] = await admin("GET", `/clients?clientId=${c1.clientId}`);
    const t1 = await userToken(SC, alice, c1), t2 = await userToken(SC, alice, c2), tNo = await userToken(SC, alice, cNo);
    // K2a: adapter-created clients verify at the sidecar
    const v1 = await rsCheck(t1.access_token, RS_SIDECAR), v2 = await rsCheck(t2.access_token, RS_SIDECAR);
    record("K2a", "pos", "0055 d3, d8, prerequisite 11: tokens of two clients the adapter created are accepted at the sidecar",
      "both accepted; aud holds the MCP audience; the client's default scopes include victual-mcp-audience and not victual-api-audience",
      `client 1: ${v1} aud=${JSON.stringify(audOf(t1))}; client 2: ${v2} aud=${JSON.stringify(audOf(t2))}; client 1 default scopes=${rep1.defaultClientScopes.join(",")}; optional=${rep1.optionalClientScopes.join(",")}`,
      v1 === "accepted" && v2 === "accepted" && rep1.defaultClientScopes.includes("victual-mcp-audience") && !rep1.defaultClientScopes.concat(rep1.optionalClientScopes).includes("victual-api-audience"), "G-audience: adapter binds the client scope");
    // K2b: a client cannot select an audience it is not bound to
    const tries = {
      "audience=/api parameter": await userToken(SC, alice, c1, { params: { audience: api.clientId } }),
      "resource=/api": await userToken(SC, alice, c1, { params: { resource: S.apiAud } }),
      "resource=unknown": await userToken(SC, alice, c1, { params: { resource: "http://evil.example/x" } }),
      "scope victual-api-audience": await userToken(`${SC} victual-api-audience`, alice, c1),
      "scope victual-rogue-audience": await userToken(`${SC} victual-rogue-audience`, alice, c1),
      "scope victual-other-audience": await userToken(`${SC} victual-other-audience`, alice, c1),
    };
    const allowedAud = new Set([S.mcpAud, sidecar.clientId]);
    const ok = Object.values(tries).every((r) => r.status >= 400 || audOf(r).every((a) => allowedAud.has(a)));
    record("K2b", "neg", "0055 d3, d8, prerequisite 11: a client cannot select an audience it is not bound to",
      "each attempt is refused, or the token's aud stays within the MCP audience and the sidecar's client id",
      Object.entries(tries).map(([k, r]) => `${k} -> ${r.access_token ? `200 aud=${JSON.stringify(audOf(r))}` : errd(r)}`).join("; "), ok, "G-audience");
    // K2c: a client without the scope gets no MCP audience, and the sidecar refuses it
    const vNo = await rsCheck(tNo.access_token, RS_SIDECAR);
    const selfBind = await userToken(`${SC} victual-mcp-audience`, alice, cNo);
    const exNo = await exchange(sidecar, tNo.access_token, { audience: api.clientId, scope: SC });
    record("K2c", "neg", "0055 d3, d8, prerequisite 11: a client without the audience scope has no MCP audience, is refused at the sidecar and cannot bind itself",
      "sidecar refuses; asking for the scope at authorize is refused; the sidecar cannot exchange its token",
      `aud=${JSON.stringify(audOf(tNo))}; sidecar check -> ${vNo}; asking for victual-mcp-audience -> ${errd(selfBind)}; exchange -> ${errd(exNo)}`,
      vNo !== "accepted" && selfBind.status >= 400 && exNo.status >= 400, "G-audience");
    // K2d: exchanged tokens target only /api
    const x1 = await exchange(sidecar, t1.access_token, { audience: api.clientId, scope: SC });
    const x2 = await exchange(sidecar, t2.access_token, { audience: api.clientId, scope: SC });
    const a1 = claims(x1.access_token).aud, a2 = claims(x2.access_token).aud;
    const rs = [await rsCheck(x1.access_token, RS_API), await rsCheck(x2.access_token, RS_API), await rsCheck(x1.access_token, RS_SIDECAR)];
    record("K2d", "pos", "0055 d7, d8, prerequisite 11: exchanged tokens target only /api",
      "aud is exactly the /api URI; accepted at /api; refused at the sidecar",
      `client 1 exchanged aud=${JSON.stringify(a1)}; client 2 exchanged aud=${JSON.stringify(a2)}; /api check: ${rs[0]}, ${rs[1]}; sidecar check of an exchanged token: ${rs[2]}`,
      a1 === S.apiAud && a2 === S.apiAud && rs[0] === "accepted" && rs[1] === "accepted" && rs[2].startsWith("aud"), "G-audience");
    // K2e: an exchanged token cannot be exchanged back into the MCP audience
    const back = {
      "sidecar: x1, audience=MCP URI": await exchange(sidecar, x1.access_token, { audience: S.mcpAud, scope: SC }),
      "sidecar: x1, audience=sidecar": await exchange(sidecar, x1.access_token, { audience: sidecar.clientId, scope: SC }),
      "sidecar: x1, scope victual-mcp-audience": await exchange(sidecar, x1.access_token, { audience: api.clientId, scope: `${SC} victual-mcp-audience` }),
      "sidecar: user token, audience=MCP URI": await exchange(sidecar, t1.access_token, { audience: S.mcpAud, scope: SC }),
      "sidecar: user token, scope victual-mcp-audience": await exchange(sidecar, t1.access_token, { audience: api.clientId, scope: `${SC} victual-mcp-audience` }),
      "client named by the MCP URI: x1": await exchange(uriSidecar, x1.access_token, { audience: api.clientId, scope: SC }),
    };
    record("K2e", "neg", "0055 d7, d8, prerequisite 11: an exchanged token cannot be exchanged back into the MCP audience",
      "every attempt is refused, or issues no MCP audience",
      Object.entries(back).map(([k, r]) => `${k} -> ${r.access_token ? `200 aud=${JSON.stringify(audOf(r))}` : err(r)}`).join("; "),
      Object.values(back).every((r) => r.status >= 400 || !audOf(r).includes(S.mcpAud)), "G-audience");
  } finally {
    for (const c of created) await deregisterMcpClient(c).catch(() => {});
  }
}

// ---- Configuration inventory (prerequisite 8): read the realm back and compare it with what setup.mjs promises
async function inventoryGate() {
  const miss = [];
  const need = (ok, what) => { if (!ok) miss.push(what); };
  const realm = await admin("GET", "");
  for (const [k, v] of Object.entries({ accessTokenLifespan: 300, ssoSessionIdleTimeout: 1800, ssoSessionMaxLifespan: 36000, revokeRefreshToken: true, refreshTokenMaxReuse: 0, registrationAllowed: false, verifyEmail: false, loginWithEmailAllowed: true })) need(realm[k] === v, `realm ${k}=${realm[k]}`);
  const scopes = await admin("GET", "/client-scopes");
  const mappersOf = async (name) => { const sc = scopes.find((x) => x.name === name); return sc ? (await admin("GET", `/client-scopes/${sc.id}/protocol-mappers/models`)).map((m) => `${m.name}:${m.protocolMapper}`) : null; };
  const wantScopes = { "victual:read": [], "victual:write": [], "victual:stock": [], "victual:stock:book": [],
    "victual-mcp-audience": ["mcp-resource-audience:oidc-audience-mapper", "sidecar-client-audience:oidc-audience-mapper"],
    "victual-rogue-audience": ["rogue-client-audience:oidc-audience-mapper"], "victual-api-audience": ["api-resource-audience:oidc-audience-mapper"],
    "victual-other-audience": ["other-resource-audience:oidc-audience-mapper"], "victual-groups": ["groups:oidc-group-membership-mapper"],
    [S.clients.k1a.grantScope]: ["victual-grant-client:oidc-hardcoded-claim-mapper"] };
  for (const [name, want] of Object.entries(wantScopes)) { const have = await mappersOf(name); need(have && want.length === have.length && want.every((w) => have.includes(w)), `scope ${name} mappers ${JSON.stringify(have)}`); }
  const pols = (await admin("GET", "/client-policies/policies")).policies, profs = (await admin("GET", "/client-policies/profiles")).profiles;
  const exec = (n) => profs.find((p) => p.name === n)?.executors.map((e) => e.executor).sort().join("+");
  need(exec("victual-downscope") === "downscope-assertion-grant-enforcer", "profile victual-downscope");
  need(exec("victual-dcr") === ["consent-required", "pkce-enforcer", "secure-client-authenticator", "secure-client-uris-pattern"].join("+"), `profile victual-dcr ${exec("victual-dcr")}`);
  for (const n of ["victual-downscope-all", "victual-dcr-anonymous"]) need(pols.find((p) => p.name === n)?.enabled === true, `policy ${n} enabled`);
  const cl = async (c) => (await admin("GET", `/clients/${c.id}`));
  const m = await cl(mcp), sc = await cl(sidecar), ap = await cl(api), k = await cl(S.clients.k1a);
  need(m.attributes["pkce.code.challenge.method"] === "S256" && m.publicClient && !m.directAccessGrantsEnabled && !m.implicitFlowEnabled && !m.fullScopeAllowed, "mcp client flags");
  need(sc.attributes["standard.token.exchange.enabled"] === "true" && !sc.publicClient, "sidecar flags");
  need(!ap.standardFlowEnabled && !ap.directAccessGrantsEnabled && !ap.serviceAccountsEnabled && !ap.implicitFlowEnabled, "api client flows");
  need(k.consentRequired && k.defaultClientScopes.includes("victual-mcp-audience") && k.defaultClientScopes.includes(S.clients.k1a.grantScope), "adapter client flags");
  // K3b: the /api client is a resource: no flow may issue it a token
  const apiCc = await token({ grant_type: "client_credentials", client_id: api.clientId, client_secret: api.secret });
  const apiPw = await token({ grant_type: "password", client_id: api.clientId, client_secret: api.secret, username: alice.username, password: alice.password, scope: "openid" });
  const apiCode = await login(api, cred(alice), { scope: "openid" });
  record("K3b", "neg", "0055 d3, prerequisite 8: the /api resource client cannot obtain tokens through any flow",
    "client credentials, password and authorization code requests as the /api client are refused",
    `client_credentials -> ${errd(apiCc)}; password -> ${errd(apiPw)}; authorization code -> ${errd(apiCode)}`,
    apiCc.status >= 400 && apiPw.status >= 400 && apiCode.status >= 400, "G-config");
  record("K3", "info", "0055 prerequisite 8: the installed configuration equals what setup.mjs and adapter.mjs promise (README inventory)",
    "every realm setting, client scope with its mappers, client policy, profile and client flag listed in the inventory is present with the stated value",
    miss.length ? `differences: ${miss.join("; ")}` : `realm settings 8, client scopes ${Object.keys(wantScopes).length} with mappers, profiles 2 (${profs.map((p) => p.name).join(", ")}), policies 2, client flags (mcp, sidecar, api, adapter client) all as stated`,
    miss.length === 0, "G-config");
}

async function registrationGate() {
  const URL_REG = `${ISSUER}/clients-registrations/openid-connect`;
  const reg = async (body, headers = {}) => {
    const r = await fetch(URL_REG, { method: "POST", headers: { "Content-Type": "application/json", ...headers }, body: JSON.stringify(body) });
    const text = await r.text();
    let j; try { j = JSON.parse(text); } catch { j = { raw: text.slice(0, 120) }; }
    return { status: r.status, ...j };
  };
  const r2s = (r) => `${r.status}${r.error_description ? ` ${String(r.error_description).slice(0, 70)}` : ""}`;
  const loop = { client_name: "probe-public", redirect_uris: [REDIRECT_URI], token_endpoint_auth_method: "none", grant_types: ["authorization_code", "refresh_token"], response_types: ["code"] };
  const d1 = await reg(loop);
  record("N15", "neg", "0056 prereq 2: registration is not open to anonymous callers",
    "anonymous registration refused by default", `${d1.status} ${d1.error ?? d1.client_id ?? ""} ${d1.error_description ?? ""}`, d1.status >= 400, "G-registration");
  // Allow the registering host (the container network's gateway sees the host as its peer).
  // The address Keycloak sees for this request is logged with the rejected registration (N15 above); it is
  // not the network gateway when podman forwards the published port.
  const gw = execFileSync("sh", ["-c", "podman logs victual-spike-keycloak-server 2>&1"], { encoding: "utf8" })
    .split("\n").filter((l) => l.includes("CLIENT_REGISTER_ERROR")).map((l) => l.match(/ipAddress="([^"]+)"/)?.[1]).filter(Boolean).pop()
    ?? JSON.parse(execFileSync("podman", ["network", "inspect", "victual-spike-keycloak"], { encoding: "utf8" }))[0].subnets[0].gateway;
  const comps = await admin("GET", "/components?type=org.keycloak.services.clientregistration.policy.ClientRegistrationPolicy");
  const trusted = comps.find((c) => c.providerId === "trusted-hosts" && c.subType === "anonymous");
  const setTrusted = (hosts, uriMatch) => admin("PUT", `/components/${trusted.id}`, { ...trusted, config: { ...trusted.config, "trusted-hosts": hosts, "host-sending-registration-request-must-match": ["true"], "client-uris-must-match": [String(uriMatch)] } });
  await setPolicies(["victual-downscope-all"]); // D1: Trusted Hosts alone, without victual-dcr-anonymous
  await setTrusted([gw, "localhost", "127.0.0.1"], true);
  const d2 = await reg(loop);
  const d2b = await reg({ ...loop, client_name: "probe-https", redirect_uris: ["https://app.example/cb"] });
  const d2c = await reg({ ...loop, client_name: "probe-http", redirect_uris: ["http://evil.example/cb"] });
  const d2d = await reg({ client_name: "probe-confidential", redirect_uris: [REDIRECT_URI], token_endpoint_auth_method: "client_secret_basic", grant_types: ["authorization_code"], response_types: ["code"] });
  const d2e = await reg({ ...loop, client_name: "probe-scope", scope: "openid victual:read victual:write" });
  const d2f = await reg({ ...loop, client_name: "probe-wild", redirect_uris: ["http://127.0.0.1:8765/*"] });
  const sc = d2e.scope ?? "";
  record("D1", "info", "0056 prereq 2: anonymous registration with only the Trusted Hosts policy configured",
    "trusted host registers a loopback public client; untrusted redirect hosts refused; confidential clients and wildcards are what the policies allow",
    `peer ${gw}; loopback public -> ${d2.status} ${d2.error_description ?? ""}; https://app.example -> ${d2b.status} ${d2b.error_description ?? ""}; http://evil.example -> ${d2c.status} ${d2c.error_description ?? ""}; confidential -> ${d2d.status} ${d2d.error_description ?? ""}; scope request -> ${d2e.status} scope="${sc}"; wildcard redirect -> ${d2f.status} ${d2f.error_description ?? ""}`,
    d2.status === 201 && d2c.status >= 400, "G-registration: Client Registration Policies (supported)");
  // Client Policies: victual-dcr-anonymous (installed by setup.mjs) refuses what the registration policies allow.
  await setPolicies(null);
  await setTrusted([gw, "localhost", "127.0.0.1", "app.example"], true);
  const e1 = await reg({ ...loop, client_name: "probe-public-2" });
  const e2 = await reg({ ...loop, client_name: "probe-https-2", redirect_uris: ["https://app.example/cb"] });
  const e3 = await reg({ ...loop, client_name: "probe-http-2", redirect_uris: ["http://app.example/cb"] });
  const e4 = await reg({ ...loop, client_name: "probe-wild-2", redirect_uris: ["https://app.example/*"] });
  const e5 = await reg({ client_name: "probe-confidential-2", redirect_uris: [REDIRECT_URI], token_endpoint_auth_method: "client_secret_basic", grant_types: ["authorization_code"], response_types: ["code"] });
  const made = e1.client_id ? await admin("GET", `/clients?clientId=${e1.client_id}`) : [];
  const attrs = made[0] ? `public=${made[0].publicClient} pkce=${made[0].attributes?.["pkce.code.challenge.method"]} consentRequired=${made[0].consentRequired}` : "";
  const sum = (r) => `${r.status}${r.error_description ? ` ${String(r.error_description).slice(0, 70)}` : ""}`;
  record("D2", "info", "0056 prereq 2: a client policy limits anonymous registration to public clients with loopback or https redirects",
    "loopback and https public clients register with PKCE S256 and Consent required; http (non-loopback), wildcard and confidential clients are refused",
    `loopback public -> ${sum(e1)} (${attrs}); https -> ${sum(e2)}; http non-loopback -> ${sum(e3)}; https wildcard -> ${sum(e4)}; confidential (secret auth) -> ${sum(e5)}; policy executors: secure-client-uris-pattern (redirectUris), pkce-enforcer, secure-client-authenticator (client-jwt only), consent-required`,
    e1.status === 201 && e2.status === 201 && e3.status >= 400 && e4.status >= 400 && e5.status >= 400 && made[0]?.consentRequired === true && made[0]?.attributes?.["pkce.code.challenge.method"] === "S256", "G-registration: Client Policies (supported)");

  // K2: what a dynamic registrant can get of the MCP audience (prerequisite 11). The audience scope is bound by the adapter
  // through the admin API; anonymous registration must not be able to bind it itself.
  {
    const allowed = comps.find((c) => c.providerId === "allowed-client-templates" && c.subType === "anonymous");
    const MCP_SCOPE = "victual-mcp-audience";
    const tryRegister = async (name, scope) => {
      const r = await reg({ ...loop, client_name: name, ...(scope ? { scope } : {}) });
      const [c] = r.client_id ? await admin("GET", `/clients?clientId=${r.client_id}`) : [];
      const wanted = r.client_id ? await login({ clientId: r.client_id, redirect: REDIRECT_URI }, cred(alice), { scope: `openid ${MCP_SCOPE}` }) : { status: 0, error: "no client" };
      const plainTok = r.client_id ? await login({ clientId: r.client_id, redirect: REDIRECT_URI }, cred(alice), { scope: "openid" }) : {};
      return { r, c, wanted, plainTok,
        has: c ? [...(c.defaultClientScopes ?? []), ...(c.optionalClientScopes ?? [])].includes(MCP_SCOPE) : false,
        audWanted: wanted.access_token ? [].concat(claims(wanted.access_token).aud ?? []) : null,
        audPlain: plainTok.access_token ? [].concat(claims(plainTok.access_token).aud ?? []) : null };
    };
    const k = await tryRegister("probe-k2-dcr", `${MCP_SCOPE} victual-api-audience`);
    const k0 = await tryRegister("probe-k2-dcr-plain", undefined);
    record("K2g", "neg", "0055 d8, prerequisite 11: an anonymous registrant cannot bind itself to the MCP audience (Allowed Client Scopes list empty)",
      "a registration asking for the scope is refused or the client does not hold it; a client registered without it is refused the scope at authorize and its tokens lack the MCP audience",
      `register with scope -> ${r2s(k.r)}; client holds the scope: ${k.has}; register without scope -> ${r2s(k0.r)}, client holds the scope: ${k0.has}; authorize with the scope -> ${errd(k0.wanted)}; token aud=${JSON.stringify(k0.audPlain)}`,
      (k.r.status >= 400 || !k.has) && !k0.has && k0.wanted.status >= 400 && !(k0.audPlain ?? []).includes(S.mcpAud), "G-audience: client registration policy (supported)");
    await admin("PUT", `/components/${allowed.id}`, { ...allowed, config: { ...allowed.config, "allowed-client-scopes": [MCP_SCOPE] } });
    const k2 = await tryRegister("probe-k2-dcr-open", MCP_SCOPE);
    await admin("PUT", `/components/${allowed.id}`, allowed);
    record("K2h", "info", "0055 d8, prerequisite 11: demonstration of what the policy must forbid: the audience scope on the Allowed Client Scopes list of anonymous registration",
      "with the scope on the list, a dynamic registrant binds itself to the MCP audience (so the list must not contain it)",
      `list = [${MCP_SCOPE}]: register -> ${r2s(k2.r)} granted scope="${k2.r.scope ?? ""}"; client holds the scope: ${k2.has}; authorize with it -> ${errd(k2.wanted)}; token aud=${JSON.stringify(k2.audWanted)}; the list was restored afterwards`,
      k2.has && (k2.audWanted ?? []).includes(S.mcpAud), "G-audience: client registration policy (supported)");
  }
  // Clean up: remove the registered probe clients, restore policies, reset trusted hosts.
  for (const name of ["probe-public", "probe-https", "probe-http", "probe-confidential", "probe-scope", "probe-wild", "probe-public-2", "probe-https-2", "probe-http-2", "probe-wild-2", "probe-confidential-2", "probe-k2-dcr", "probe-k2-dcr-plain", "probe-k2-dcr-open"]) {
    for (const c of (await admin("GET", `/clients?search=true&clientId=`)).filter((x) => x.name === name)) await admin("DELETE", `/clients/${c.id}`);
  }
  await setPolicies(null);
  await setTrusted([], true);
}

// ---- G-login: users authenticated without Keycloak holding Victual's passwords (identity brokering)
async function loginGate() {
  const { startStub } = await import("./idp-stub.mjs");
  const stub = await startStub();
  const ALIAS = "victual-stub", FLOW = "victual-first-broker", BROWSER = "victual-idp-only";
  const del = (path) => admin("DELETE", path, undefined, { raw: true });
  const cleanup = async () => {
    await admin("PUT", "", { browserFlow: "browser" }, { realm: REALM });
    await del(`/identity-provider/instances/${ALIAS}`);
    for (const f of await admin("GET", "/authentication/flows")) if ([FLOW, BROWSER].includes(f.alias)) await del(`/authentication/flows/${f.id}`);
    for (const sc of await admin("GET", "/client-scopes")) if (sc.name === "victual-subject-scope") await del(`/client-scopes/${sc.id}`);
    for (const n of ["stub-new", "carol-spike", "stub-third"]) for (const u of await admin("GET", `/users?username=${n}&exact=true`)) await del(`/users/${u.id}`);
  };
  try {
    await cleanup();
    // Victual-owned subject: copied from the upstream `sub` into a user attribute, then into a claim.
    const prof = await admin("GET", "/users/profile");
    await admin("PUT", "/users/profile", { ...prof, unmanagedAttributePolicy: "ENABLED" });
    // First-broker-login flow without prompts: create the user, or link the one that exists.
    await admin("POST", "/authentication/flows", { alias: FLOW, description: "spike", providerId: "basic-flow", topLevel: true, builtIn: false });
    for (const provider of ["idp-create-user-if-unique", "idp-auto-link"]) await admin("POST", `/authentication/flows/${FLOW}/executions/execution`, { provider });
    for (const e of await admin("GET", `/authentication/flows/${FLOW}/executions`)) await admin("PUT", `/authentication/flows/${FLOW}/executions`, { ...e, requirement: "ALTERNATIVE" });
    const host = `host.containers.internal:${(await import("./idp-stub.mjs")).PORT}`;
    await admin("POST", "/identity-provider/instances", {
      alias: ALIAS, displayName: "Victual (stub)", providerId: "oidc", enabled: true, trustEmail: false, storeToken: false,
      firstBrokerLoginFlowAlias: FLOW,
      config: {
        issuer: stub.issuer, authorizationUrl: `${stub.issuer}/authorize`, tokenUrl: `http://${host}/token`, jwksUrl: `http://${host}/jwks`,
        useJwksUrl: "true", validateSignature: "true", clientId: stub.clientId, clientSecret: stub.clientSecret, clientAuthMethod: "client_secret_post",
        pkceEnabled: "true", pkceMethod: "S256", defaultScope: "openid email profile", syncMode: "FORCE",
      },
    });
    await admin("POST", `/identity-provider/instances/${ALIAS}/mappers`, {
      name: "victual-subject", identityProviderAlias: ALIAS, identityProviderMapper: "oidc-user-attribute-idp-mapper",
      config: { claim: "sub", "user.attribute": "victual_subject", syncMode: "FORCE" },
    });
    for (const c of [mcp, sidecar, api]) await admin("POST", `/clients/${c.id}/protocol-mappers/models`, {
      name: "victual_sub", protocol: "openid-connect", protocolMapper: "oidc-usermodel-attribute-mapper",
      config: { "user.attribute": "victual_subject", "claim.name": "victual_sub", "jsonType.label": "String", "access.token.claim": "true", "id.token.claim": "false", "introspection.token.claim": "true" },
    });
    await admin("POST", "/client-scopes", { name: "victual-subject-scope", protocol: "openid-connect", attributes: { "include.in.token.scope": "true", "display.on.consent.screen": "false" }, protocolMappers: [{
      name: "victual_sub", protocol: "openid-connect", protocolMapper: "oidc-usermodel-attribute-mapper",
      config: { "user.attribute": "victual_subject", "claim.name": "victual_sub", "jsonType.label": "String", "access.token.claim": "true", "id.token.claim": "false", "introspection.token.claim": "true" } }] });
    const subjScope = (await admin("GET", "/client-scopes")).find((x) => x.name === "victual-subject-scope");
    await admin("PUT", `/clients/${sidecar.id}/optional-client-scopes/${subjScope.id}`);
    await admin("PUT", `/clients/${mcp.id}/default-client-scopes/${subjScope.id}`);
    const viaStub = async (identity) => {
      stub.identity = identity;
      return login(mcp, {}, { scope: "openid victual:read", hint: ALIAS });
    };
    const userOf = async (name) => {
      const [u] = await admin("GET", `/users?username=${name}&exact=true`);
      return u && { ...u, credentials: await admin("GET", `/users/${u.id}/credentials`), federated: await admin("GET", `/users/${u.id}/federated-identity`) };
    };
    const noPassword = (u) => u && u.credentials.filter((c) => c.type === "password").length === 0;

    // L1: a user that does not exist is created by the first brokered login
    const t1 = await viaStub({ sub: "victual-subject-0001", preferred_username: "stub-new", email: "stub-new@example.invalid", given_name: "Stub", family_name: "New" });
    const u1 = await userOf("stub-new");
    const c1 = t1.access_token ? claims(t1.access_token) : {};
    record("L1", "info", "0055 d2 gate 2: Victual's users and passwords stay in Victual (identity brokering, first login creates the user)",
      "a brokered login yields a Keycloak token for a user with no password credential",
      `${errd(t1)}${t1._iss ? "" : ""}; Keycloak user stored: ${u1 ? JSON.stringify({ id: u1.id, username: u1.username, email: u1.email, firstName: u1.firstName, lastName: u1.lastName, attributes: u1.attributes, credentials: u1.credentials.length, federated: u1.federated.map((f) => `${f.identityProvider}:${f.userId}`) }) : "none"}; token sub=${c1.sub} (upstream sub victual-subject-0001)`,
      t1.status === 200 && noPassword(u1) && c1.sub === u1?.id, "G-login");
    // L2: a user that already exists (no password) is linked by the auto-link step
    const pre = await admin("POST", "/users", { username: "carol-spike", email: "carol-spike@example.invalid", emailVerified: true, firstName: "Carol", lastName: "Spike", enabled: true }, { raw: true });
    const before = await userOf("carol-spike");
    const t2 = await viaStub({ sub: "victual-subject-0002", preferred_username: "carol-spike", email: "carol-spike@example.invalid", given_name: "Carol", family_name: "Spike" });
    const after = await userOf("carol-spike");
    record("L2", "info", "0055 d2 gate 2: a pre-created Keycloak user without a password is linked on first brokered login (auto-link)",
      "same Keycloak user id before and after; federated identity added; still no password",
      `${errd(t2)}; create user -> ${pre.status}; id before=${before?.id} after=${after?.id}; federated=${after?.federated.map((f) => `${f.identityProvider}:${f.userId}`)}; password credentials=${after ? after.credentials.filter((c) => c.type === "password").length : "?"}; token sub=${t2.access_token ? claims(t2.access_token).sub : "-"}`,
      t2.status === 200 && before?.id === after?.id && after.federated.length === 1 && noPassword(after), "G-login");
    // L3: the subject Victual would map: Keycloak sub differs from the upstream sub; a claim can carry it
    const t3 = await viaStub({ sub: "victual-subject-0003", preferred_username: "stub-third", email: "stub-third@example.invalid", given_name: "Stub", family_name: "Third" });
    const k3 = t3.access_token ? claims(t3.access_token) : {};
    const x3 = t3.access_token ? await exchange(sidecar, t3.access_token, { audience: api.clientId, scope: "openid victual:read" }) : {};
    const kx3 = x3.access_token ? claims(x3.access_token) : {};
    const x3b = t3.access_token ? await exchange(sidecar, t3.access_token, { audience: api.clientId, scope: "openid victual:read victual-subject-scope" }) : {};
    const kx3b = x3b.access_token ? claims(x3b.access_token) : {};
    record("L3", "info", "0055 d4 / 0056 d3: which subject Victual maps for a brokered user",
      "the token carries Victual's own subject, or the mapping uses Keycloak's sub",
      `Keycloak sub=${k3.sub} (not the upstream sub); claim victual_sub=${k3.victual_sub} via IdP attribute mapper + user attribute mapper; exchange -> ${errd(x3)}; mapper on the mcp client, the sidecar (requester) and the api (audience) client; exchanged token victual_sub=${kx3.victual_sub}; with the claim in a client scope requested at the exchange (${errd(x3b)}): ${kx3b.victual_sub}`,
      k3.victual_sub === "victual-subject-0003" && kx3.victual_sub === "victual-subject-0003", "G-login");
    // L4: no local password path
    const direct = "victual-spike-probe-direct";
    await admin("POST", "/clients", { clientId: direct, publicClient: true, directAccessGrantsEnabled: true, standardFlowEnabled: false });
    const [dc] = await admin("GET", `/clients?clientId=${direct}`);
    const guesses = [];
    for (const p of [randomBytes(12).toString("hex"), "stub-new", S.users.alice.password]) guesses.push((await token({ grant_type: "password", client_id: direct, username: "stub-new", password: p })).error);
    await del(`/clients/${dc.id}`);
    const flow = await admin("POST", "/authentication/flows", { alias: BROWSER, description: "spike", providerId: "basic-flow", topLevel: true, builtIn: false }, { raw: true });
    await admin("POST", `/authentication/flows/${BROWSER}/executions/execution`, { provider: "identity-provider-redirector" });
    const [ex0] = await admin("GET", `/authentication/flows/${BROWSER}/executions`);
    await admin("PUT", `/authentication/flows/${BROWSER}/executions`, { ...ex0, requirement: "REQUIRED" });
    await admin("POST", `/authentication/executions/${ex0.id}/config`, { alias: "victual-default-idp", config: { defaultProvider: ALIAS } });
    await admin("PUT", "", { browserFlow: BROWSER, resetPasswordAllowed: false }, { realm: REALM });
    const a = await authorize(mcp, { scope: "openid" });
    const loc = a.response.headers.get("location") ?? "";
    const formShown = a.response.status === 200 && /name="password"/.test(await a.response.text());
    stub.identity = { sub: "victual-subject-0001", preferred_username: "stub-new", email: "stub-new@example.invalid", given_name: "Stub", family_name: "New" };
    const noHint = await login(mcp, {}, { scope: "openid" }); // no kc_idp_hint: the flow itself must send the browser upstream
    record("L4", "info", "0055 d2 gate 2: local password login can be disabled",
      "no password form on the authorization endpoint; password grant fails for the brokered user",
      `password grant for stub-new with 3 guesses -> ${guesses.join(",")}; after the realm browser flow is replaced by identity-provider-redirector only (flow ${flow.status}): /auth -> ${a.response.status} Location ${loc.slice(0, 60)}; password form shown: ${formShown}; login without kc_idp_hint ends as the upstream identity: ${noHint.access_token ? `sub matches stub-new: ${claims(noHint.access_token).sub === u1?.id}` : errd(noHint)}`,
      guesses.every((g) => g) && loc.includes(`/broker/${ALIAS}/login`) && !formShown, "G-login");
  } catch (e) {
    record("L0", "info", "G-login setup", "completes", `error: ${e.message.slice(0, 300)}`, false, "G-login");
  } finally {
    await cleanup().catch(() => {});
    stub.server.close();
  }
}

async function imageFacts() {
  const server = "victual-spike-keycloak-server", pg = "victual-spike-keycloak-pg";
  const sh = (args) => execFileSync("podman", args, { encoding: "utf8" }).trim();
  facts.memory = sh(["stats", "--no-stream", "--format", "{{.Name}} {{.MemUsage}} {{.CPUPerc}}", server, pg]).split("\n");
  facts.imageSizeBytes = Number(sh(["image", "inspect", "--format", "{{.Size}}", `quay.io/keycloak/keycloak:${facts.keycloak}`]));
  const uid = sh(["exec", server, "id", "-u"]);
  const caps = sh(["inspect", "--format", "{{.HostConfig.Memory}} {{.Config.User}}", server]);
  record("I1", "info", "0055 prereq 3: runs non-root", "uid other than 0", `uid ${uid}; image User=${caps.split(" ")[1]}; memory limit ${Number(caps.split(" ")[0]) / 1048576} MiB; image ${(facts.imageSizeBytes / 1e6).toFixed(0)} MB`, uid !== "0", "G-image");
}
// ---- Preview run: delegation (act) via token-exchange-delegation + parameterized-scopes + admin-fine-grained-authz:v2
async function preview() {
  const consent = S.clients.consent;
  const DELEG = `delegation:client:${sidecar.clientId}`;
  const enabled = new Set(facts.enabledPreviewOrExperimental);
  record("G1p", "info", "0055 d2: which Preview features this run enabled", "token-exchange-delegation and parameterized-scopes (FGAP v2 is on by default in 26.8)",
    `Preview enabled: ${[...enabled].join(",")}`, enabled.has("TOKEN_EXCHANGE_DELEGATION") && enabled.has("PARAMETERIZED_SCOPES"), "G-features");
  // The delegation client scope is created in every realm as an optional scope; the harness creates clients with explicit scope lists, so assign it.
  const delegScope = (await admin("GET", "/client-scopes")).find((x) => x.name === "delegation:client");
  record("DP0", "info", "docs: the feature creates a delegation:client client scope in the realm", "present", delegScope ? `present (id ${delegScope.id}, type ${delegScope.type ?? "optional"})` : "absent", !!delegScope, "G-exchanger");
  for (const c of [consent]) await admin("PUT", `/clients/${c.id}/optional-client-scopes/${delegScope.id}`);
  const actorSa = async (c) => token({ grant_type: "client_credentials", client_id: c.clientId, client_secret: c.secret });
  const delegated = async (subject, actor, extra = []) => token(new URLSearchParams([["grant_type", TX], ["client_id", sidecar.clientId], ["client_secret", sidecar.secret],
    ["subject_token", subject], ["subject_token_type", AT], ["actor_token", actor], ["actor_token_type", AT], ["audience", api.clientId], ["scope", "openid victual:read"]].filter(([k]) => k !== "scope" || !extra.some(([e]) => e === "scope")).concat(extra)));

  // DP1: the delegation scope without a delegate permission is dropped silently
  const before = await login(consent, cred(alice), { scope: `openid victual:read ${DELEG}` });
  const kb = before.access_token ? claims(before.access_token) : {};
  record("DP1", "neg", "0055 d7: a delegation scope is granted only through an FGAP v2 delegate permission", "no may_act without a permission (dropped or refused)",
    `${errd(before)}; scope=${kb.scope}; may_act=${JSON.stringify(kb.may_act ?? null)}`, before.status >= 400 || !kb.may_act, "G-exchanger");

  // Grant the sidecar's client the delegate permission on all users (FGAP v2).
  await admin("PUT", "", { adminPermissionsEnabled: true }, { realm: REALM });
  const [perm] = await admin("GET", "/clients?clientId=admin-permissions");
  const base = `/clients/${perm.id}/authz/resource-server`;
  const delegateScope = (await admin("GET", `${base}/scope?max=100`)).find((x) => x.name === "delegate");
  const pol = await admin("POST", `${base}/policy/client`, { name: "sidecar-may-delegate", type: "client", logic: "POSITIVE", decisionStrategy: "UNANIMOUS", clients: [sidecar.id] });
  await admin("POST", `${base}/permission/scope`, { name: "delegate-all-users", type: "scope", logic: "POSITIVE", decisionStrategy: "UNANIMOUS",
    resourceType: "Users", scopes: [delegateScope.name], policies: [pol.id] });
  const [rogueRep] = await admin("GET", `/clients?clientId=${rogue.clientId}`);
  await admin("PUT", `/clients/${rogueRep.id}`, { ...rogueRep, serviceAccountsEnabled: true });

  // P8: delegated exchange sets act
  const sub = await login(consent, cred(alice), { scope: `openid victual:read ${DELEG}` });
  const ks = sub.access_token ? claims(sub.access_token) : {};
  const saTok = await actorSa(sidecar);
  const saId = saTok.access_token ? claims(saTok.access_token).sub : "?";
  const ex = sub.access_token && saTok.access_token ? await delegated(sub.access_token, saTok.access_token) : {};
  const kx = ex.access_token ? claims(ex.access_token) : {};
  record("P8", "info", "0055 d7: delegation (OBO) records the sidecar in act", "200; act names the sidecar; sub unchanged; aud = /api",
    `subject token: ${errd(sub)} may_act=${JSON.stringify(ks.may_act ?? null)} aud=${JSON.stringify(ks.aud)}; exchange: ${errd(ex)}; act=${JSON.stringify(kx.act ?? null)}; sub same=${kx.sub === ks.sub}; aud=${JSON.stringify(kx.aud)}; azp=${kx.azp}; refresh_token=${ex.refresh_token ? "yes" : "no"}; sidecar service account id=${saId}`,
    ex.status === 200 && kx.act?.client_id === sidecar.clientId && kx.sub === ks.sub, "G-exchanger: needs Preview");
  const rsx = ex.access_token ? await rsCheck(ex.access_token, RS_API) : "none";
  record("P8c", "info", "0055 d3/d7: /api accepts the delegated token", "accepted", rsx, rsx === "accepted", "G-exchanger: needs Preview");

  // DP2: ceiling and session behaviour of the delegated token
  const wide = sub.access_token && saTok.access_token ? await delegated(sub.access_token, saTok.access_token, [["scope", "victual:write"]]) : {};
  record("DP2", "neg", "0055 d7: the delegated exchange keeps the scope ceiling", "victual:write refused or dropped (subject token: victual:read only)",
    `${errd(wide)}; scope=${wide.scope}`, wide.status >= 400 || !String(wide.scope).includes("victual:write"), "G-scope-ceiling");

  // DP3: forged act / azp parameters do not change act
  const forged = sub.access_token && saTok.access_token ? await delegated(sub.access_token, saTok.access_token, [["act", JSON.stringify({ sub: "forged", client_id: "forged" })], ["azp", rogue.clientId]]) : {};
  const kf = forged.access_token ? claims(forged.access_token) : {};
  record("DP3", "neg", "0055 d7 / q8: a caller cannot override act", "act stays the sidecar, or the request is refused",
    `${errd(forged)}; act=${JSON.stringify(kf.act ?? null)}; azp=${kf.azp}`, forged.status >= 400 || (kf.act?.client_id === sidecar.clientId && kf.azp === sidecar.clientId), "G-exchanger");

  // DP4: the actor token must belong to the actor named in may_act
  const rogueSa = await actorSa(rogue);
  const wrongActor = sub.access_token && rogueSa.access_token ? await delegated(sub.access_token, rogueSa.access_token) : { status: 0, error: "no rogue token", error_description: errd(rogueSa) };
  record("DP4", "neg", "0055 d7: an actor token of a client other than the may_act actor is refused", "refused",
    errd(wrongActor), wrongActor.status >= 400, "G-exchanger");

  // DP5: a subject token without may_act cannot be delegated
  const plainSub = await login(consent, cred(alice), { scope: "openid victual:read" });
  const noMayAct = plainSub.access_token && saTok.access_token ? await delegated(plainSub.access_token, saTok.access_token) : {};
  const knm = noMayAct.access_token ? claims(noMayAct.access_token) : {};
  record("DP5", "neg", "0055 d7: an actor_token without a user-granted may_act does not produce act", "refused, or issued with no act",
    `${errd(noMayAct)}; act=${JSON.stringify(knm.act ?? null)}`, noMayAct.status >= 400 || !knm.act, "G-exchanger");

  // DP6: delegation to a client that holds no permission is dropped
  const other2 = await login(consent, cred(alice), { scope: `openid victual:read delegation:client:${rogue.clientId}` });
  const ko = other2.access_token ? claims(other2.access_token) : {};
  record("DP6", "neg", "0055 d7: delegation for an actor without the delegate permission is dropped", "no may_act for the rogue client",
    `${errd(other2)}; may_act=${JSON.stringify(ko.may_act ?? null)}`, other2.status >= 400 || !ko.may_act, "G-exchanger");

  // DP7: consent is asked at each session and is not stored
  const stored = await admin("GET", `/users/${alice.id}/consents`);
  const grantedHere = stored.find((c) => c.clientId === consent.clientId);
  record("DP7", "info", "docs: the delegation consent is never stored permanently", "the stored consent for the client lists no delegation scope",
    `stored consent scopes: ${JSON.stringify(grantedHere?.grantedClientScopes ?? null)}`, !JSON.stringify(grantedHere?.grantedClientScopes ?? []).includes("delegation"), "G-exchanger");

  // DP8: the plain (non-delegated) exchange in the same preview realm is unchanged: no act
  const plainEx = plainSub.access_token ? await exchange(sidecar, plainSub.access_token, { audience: api.clientId, scope: "openid victual:read" }) : {};
  const kp = plainEx.access_token ? claims(plainEx.access_token) : {};
  record("DP8", "info", "0055 d7: a plain exchange in a Preview-enabled realm still sets azp to the sidecar and no act", "azp=sidecar; act absent",
    `${errd(plainEx)}; azp=${kp.azp}; act=${JSON.stringify(kp.act ?? null)}`, plainEx.status === 200 && kp.azp === sidecar.clientId && !kp.act, "G-exchanger");
}

async function finish() {
  facts.seconds = Math.round((Date.now() - T0) / 1000);
  const file = MODE === "core" ? "./results.json" : `./results-${MODE}.json`;
  writeFileSync(new URL(file, import.meta.url), JSON.stringify({ at: new Date().toISOString(), mode: MODE, facts, results }, null, 2));
  const pass = results.filter((r) => r.verdict === "pass").length, fail = results.filter((r) => r.verdict === "FAIL").length;
  console.log("| ID | Gate | Clause | Expected | Observed | Verdict |\n|---|---|---|---|---|---|");
  for (const r of results) console.log(`| ${r.id} | ${r.gate} | ${r.clause} | ${r.expected} | ${r.observed.replaceAll("|", "\\|")} | ${r.verdict} |`);
  console.log(`\n${MODE} run: ${pass} pass, ${fail} FAIL, ${results.length - pass - fail} n/a of ${results.length} cases (${facts.seconds} s)`);
}
