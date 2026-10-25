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
  const ro = (await userToken("openid profile victual:read")).access_token;
  const widenDefault = await exchange(sidecar, ro, { audience: api.clientId, scope: "openid victual:read victual:write" });
  const gotDefault = widenDefault.access_token ? claims(widenDefault.access_token).scope : "";
  record("N11", "neg", "0055 d7: the exchanged token carries no scope the subject token lacked",
    "victual:write refused or dropped (subject token: victual:read only)", `default configuration: ${err(widenDefault)}; scope=${gotDefault}`,
    !String(gotDefault).includes("victual:write"), "G-scope-ceiling");
  {
    // Supported configuration: a client policy with the downscope-assertion-grant-enforcer executor.
    await admin("PUT", "/client-policies/profiles", { profiles: [{ name: "victual-downscope", description: "spike", executors: [{ executor: "downscope-assertion-grant-enforcer", configuration: {} }] }] });
    await admin("PUT", "/client-policies/policies", { policies: [{ name: "victual-downscope-all", description: "spike", enabled: true, conditions: [{ condition: "any-client", configuration: {} }], profiles: ["victual-downscope"] }] });
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
  await registrationGate();
  await loginGate();
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
    return { t, x, sid: claims(t.access_token).sid, client };
  };
  const observe = async (f) => ({
    refresh: errd(await refresh(f.client, f.t.refresh_token)),
    introUser: await introspect(sidecar, f.t.access_token),
    introExchanged: await introspect(sidecar, f.x.access_token),
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

async function registrationGate() {
  const URL_REG = `${ISSUER}/clients-registrations/openid-connect`;
  const reg = async (body, headers = {}) => {
    const r = await fetch(URL_REG, { method: "POST", headers: { "Content-Type": "application/json", ...headers }, body: JSON.stringify(body) });
    const text = await r.text();
    let j; try { j = JSON.parse(text); } catch { j = { raw: text.slice(0, 120) }; }
    return { status: r.status, ...j };
  };
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
  // Client Policies: refuse what the registration policies allow.
  const POLICY_BASE = (await admin("GET", "/client-policies/policies")).policies ?? [];
  const PROFILES = (await admin("GET", "/client-policies/profiles")).profiles ?? [];
  await admin("PUT", "/client-policies/profiles", { profiles: [...PROFILES, { name: "victual-dcr", description: "spike", executors: [
    // https, or http only on a loopback address; no wildcard. (secure-redirect-uris-enforcer gates loopback http and any http with one switch.)
    { executor: "secure-client-uris-pattern", configuration: { "client-uri-fields": ["redirectUris"],
      "allowed-patterns": ["^https://[a-zA-Z0-9.-]+(:[0-9]+)?/[^*]*$", "^http://(127\\.0\\.0\\.1|\\[::1\\])(:[0-9]+)?/[^*]*$"] } },
    { executor: "pkce-enforcer", configuration: { "auto-configure": true } },
    { executor: "secure-client-authenticator", configuration: { "allowed-client-authenticators": ["client-jwt"], "default-client-authenticator": "client-jwt" } },
  ] }] });
  await admin("PUT", "/client-policies/policies", { policies: [...POLICY_BASE, { name: "victual-dcr-anonymous", description: "spike", enabled: true,
    conditions: [{ condition: "client-updater-context", configuration: { "update-client-source": ["ByAnonymous"] } }], profiles: ["victual-dcr"] }] });
  await setTrusted([gw, "localhost", "127.0.0.1", "app.example"], true);
  const e1 = await reg({ ...loop, client_name: "probe-public-2" });
  const e2 = await reg({ ...loop, client_name: "probe-https-2", redirect_uris: ["https://app.example/cb"] });
  const e3 = await reg({ ...loop, client_name: "probe-http-2", redirect_uris: ["http://app.example/cb"] });
  const e4 = await reg({ ...loop, client_name: "probe-wild-2", redirect_uris: ["https://app.example/*"] });
  const e5 = await reg({ client_name: "probe-confidential-2", redirect_uris: [REDIRECT_URI], token_endpoint_auth_method: "client_secret_basic", grant_types: ["authorization_code"], response_types: ["code"] });
  const made = e1.client_id ? await admin("GET", `/clients?clientId=${e1.client_id}`) : [];
  const attrs = made[0] ? `public=${made[0].publicClient} pkce=${made[0].attributes?.["pkce.code.challenge.method"]}` : "";
  const sum = (r) => `${r.status}${r.error_description ? ` ${String(r.error_description).slice(0, 70)}` : ""}`;
  record("D2", "info", "0056 prereq 2: a client policy limits anonymous registration to public clients with loopback or https redirects",
    "loopback and https public clients register; http (non-loopback), wildcard and confidential clients are refused",
    `loopback public -> ${sum(e1)} (${attrs}); https -> ${sum(e2)}; http non-loopback -> ${sum(e3)}; https wildcard -> ${sum(e4)}; confidential (secret auth) -> ${sum(e5)}; policy executors: secure-client-uris-pattern (redirectUris), pkce-enforcer, secure-client-authenticator (client-jwt only)`,
    e1.status === 201 && e2.status === 201 && e3.status >= 400 && e4.status >= 400 && e5.status >= 400, "G-registration: Client Policies (supported)");
  // Clean up: remove the registered probe clients, restore policies, reset trusted hosts.
  for (const name of ["probe-public", "probe-https", "probe-http", "probe-confidential", "probe-scope", "probe-wild", "probe-public-2", "probe-https-2", "probe-http-2", "probe-wild-2", "probe-confidential-2"]) {
    for (const c of (await admin("GET", `/clients?search=true&clientId=`)).filter((x) => x.name === name)) await admin("DELETE", `/clients/${c.id}`);
  }
  await admin("PUT", "/client-policies/policies", { policies: POLICY_BASE });
  await admin("PUT", "/client-policies/profiles", { profiles: PROFILES });
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
