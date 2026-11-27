#!/usr/bin/env node
// Realm-configuration harness (plan 43): tests deploy/keycloak/reconcile.mjs and the realm it
// applies against a live Keycloak 26.8.1 (./run.sh up). Deletes and rebuilds the realms `victual`
// and `upstream` on that instance, so never point it at a Keycloak holding real data.
//
//   node test.mjs            all phases; prints the table and writes results/results.json
//   node test.mjs --no-import   skips phase I (realm import comparison), which restarts Keycloak
//
// Phases: U upgrade from configuration version 1 to 2, M idempotency, D drift repair, P
// preservation (checked inside U, D and F), N the policies' positive and negative cases, F
// refusals, I Keycloak's own realm import for comparison. Every case records the expectation and
// what happened; a FAIL is a result, and no expectation was changed to make a case pass.
import { execFileSync } from "node:child_process";
import { mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";
import * as A from "../../deploy/keycloak/adapter.mjs";
import { readFlow } from "../../deploy/keycloak/lib/flows.mjs";
import * as L from "./lib.mjs";

const { kc } = L;
const results = [];
const ADAPTER_V2 = A.loadRecipe(resolve(L.HERE, "fixtures/adapter-recipe-v2.json"));
const V1 = resolve(L.HERE, "fixtures/realm-v1.json");
const V2 = resolve(L.ROOT, "deploy/keycloak/realm.json");
const WORK = resolve(L.HERE, ".work");
const PW = { breakglass: "harness-only-breakglass-pw", olive: "harness-only-olive-pw", carol: "harness-only-carol-pw", erin: "harness-only-erin-pw", carolUp: "harness-only-carol-up-pw" };
const VICTUAL_SECRET = L.ENV.VICTUAL_CLIENT_SECRET;
/** The Kubernetes subject of service account `name` in the harness namespace. */
const SA = (name) => `system:serviceaccount:victual:${name}`;

/** Records one case: id, clause, expectation, observation and verdict ("pass", "FAIL" or "obs"). */
function rec(id, clause, expected, observed, ok, verdict) {
  const v = verdict ?? (ok ? "pass" : "FAIL");
  results.push({ id, clause, expected, observed: String(observed), verdict: v });
  process.stderr.write(`${v.padEnd(4)} ${id} ${observed}\n`);
}

/** Runs `fn` as case `id`; an exception is recorded as a FAIL with its message. */
async function kase(id, clause, expected, fn) {
  try { const [ok, observed, verdict] = await fn(); rec(id, clause, expected, observed, ok, verdict); }
  catch (e) { rec(id, clause, expected, `threw: ${e.message}`, false); }
}

/** The id of the client with `clientId`, or undefined. */
const clientId = async (cid) => (await kc.call("GET", `/clients?clientId=${encodeURIComponent(cid)}`))[0]?.id;
/** The user with `username` in realm victual. */
const user = async (username) => (await kc.call("GET", `/users?username=${encodeURIComponent(username)}&exact=true`))[0];

/** Creates a local Keycloak user with a password; `local` gives it the victual-local-login role. */
async function makeUser(username, password, { email, local = false } = {}) {
  await kc.call("POST", "/users", { username, enabled: true, email: email ?? `${username}@example.invalid`, emailVerified: true, firstName: username, lastName: "Harness", credentials: [{ type: "password", value: password, temporary: false }] });
  const u = await user(username);
  if (local) await kc.call("POST", `/users/${u.id}/role-mappings/realm`, [await kc.call("GET", "/roles/victual-local-login")]);
  return u;
}

/** Builds the fixtures that preservation is measured against. Returns the handles the cases use. */
async function fixtures(sa) {
  // The upstream identity source: a second realm on the same Keycloak, brokered by OIDC. Unowned by
  // the realm configuration, so it also shows the reconciler leaving an unowned provider alone.
  const up = L.kcRealm("upstream");
  await up.root("POST", "", { realm: "upstream", enabled: true });
  await up.call("POST", "/clients", { clientId: "victual-broker", publicClient: false, secret: "harness-only-upstream", standardFlowEnabled: true, directAccessGrantsEnabled: false, redirectUris: [`${L.ISSUER}/broker/upstream/endpoint`] });
  for (const [username, email, password] of [["carol", "carol@example.invalid", PW.carolUp], ["erin", "erin-up@example.invalid", PW.erin]]) {
    await up.call("POST", "/users", { username, enabled: true, email, emailVerified: true, firstName: username, lastName: "Upstream", credentials: [{ type: "password", value: password, temporary: false }] });
  }
  const U = `${L.KC}/realms/upstream/protocol/openid-connect`;
  await kc.call("POST", "/identity-provider/instances", { alias: "upstream", providerId: "oidc", enabled: true, trustEmail: false, storeToken: false, firstBrokerLoginFlowAlias: "victual-first-broker-login",
    config: { issuer: `${L.KC}/realms/upstream`, authorizationUrl: `${U}/auth`, tokenUrl: `${U}/token`, jwksUrl: `${U}/certs`, useJwksUrl: "true", validateSignature: "true", clientId: "victual-broker", clientSecret: "harness-only-upstream", clientAuthMethod: "client_secret_post", pkceEnabled: "true", pkceMethod: "S256", defaultScope: "openid profile email", syncMode: "IMPORT" } });
  const breakglass = await makeUser("breakglass", PW.breakglass, { local: true });
  await makeUser("olive", PW.olive);
  await makeUser("carol", PW.carol, { email: "carol@example.invalid" });
  await kc.call("POST", "/groups", { name: "household" });
  const household = (await kc.call("GET", "/groups?search=household&exact=true"))[0];
  await kc.call("PUT", `/users/${breakglass.id}/groups/${household.id}`);
  // A SCIM-provisioned user with an upstream id and a group, sent the way an upstream sender would.
  const scimTok = (await L.tokenCall({ grant_type: "client_credentials", client_id: "victual-scim", client_secret: L.ENV.SCIM_CLIENT_SECRET })).body.access_token;
  const g = await L.scim("POST", "/Groups", { schemas: ["urn:ietf:params:scim:schemas:core:2.0:Group"], displayName: "scim-household" }, scimTok);
  const u = await L.scim("POST", "/Users", { schemas: ["urn:ietf:params:scim:schemas:core:2.0:User"], userName: "sam", active: true, externalId: "entra-oid-0001", name: { givenName: "Sam", familyName: "Scim" }, emails: [{ value: "sam@example.invalid", primary: true }] }, scimTok);
  if (g.status !== 201 || u.status !== 201) throw new Error(`SCIM fixture refused: group ${g.status}, user ${u.status}`);
  const pm = await L.scim("PATCH", `/Groups/${g.body.id}`, { schemas: ["urn:ietf:params:scim:api:messages:2.0:PatchOp"], Operations: [{ op: "add", path: "members", value: [{ value: u.body.id }] }] }, scimTok);
  if (pm.status !== 200) throw new Error(`SCIM membership refused: ${pm.status}`);
  // An MCP client registered by the adapter under recipe 2, before the upgrade.
  const mcp = await A.registerMcpClient(kc, ADAPTER_V2, "harness-mcp-1", { redirect: L.REDIRECT, sidecarId: await clientId("victual-mcp"), version: 2 });
  // A brokered sign-in (creates erin and her federated identity) and a live break-glass session.
  const erin = await L.browserLogin({ clientId: "victual", params: { kc_idp_hint: "upstream" }, steps: [{ username: "erin", password: PW.erin }] });
  const erinTok = erin.code ? await L.redeem(erin, "victual", VICTUAL_SECRET) : { status: 0, body: erin };
  const bg = await L.browserLogin({ clientId: "victual", steps: [{ username: "breakglass", password: PW.breakglass }] });
  const session = await L.redeem(bg, "victual", VICTUAL_SECRET);
  return { scim: { group: g, user: u }, mcp, erinTok, session: { refresh: session.body.refresh_token }, household };
}

/**
 * Everything preservation compares: every user's id, credential ids, federated identities, groups,
 * the upstream id attribute, and session count; and the adapter's registered client. Sessions are
 * counted, not listed, because their last-access time changes on every refresh.
 */
async function snapshot() {
  const out = {};
  for (const u of await kc.call("GET", "/users?max=1000&briefRepresentation=false")) {
    out[u.username] = {
      id: u.id, enabled: u.enabled, upstreamId: u.attributes?.upstreamId ?? null,
      credentials: (await kc.call("GET", `/users/${u.id}/credentials`)).map((c) => `${c.type}:${c.id}`).sort(),
      federated: (await kc.call("GET", `/users/${u.id}/federated-identity`)).map((f) => `${f.identityProvider}:${f.userId}`).sort(),
      groups: (await kc.call("GET", `/users/${u.id}/groups`)).map((g) => g.name).sort(),
      sessions: u.username.startsWith("service-account-") ? "n/a" : (await kc.call("GET", `/users/${u.id}/sessions`)).length,
    };
  }
  out["client:harness-mcp-1"] = await clientId("harness-mcp-1") ?? null;
  return out;
}

/** Lists what differs between two snapshots; empty when equal. */
function snapDiff(a, b) {
  const keys = new Set([...Object.keys(a), ...Object.keys(b)].filter((k) => !k.startsWith("service-account-")));
  return [...keys].filter((k) => JSON.stringify(a[k]) !== JSON.stringify(b[k])).map((k) => `${k}: ${JSON.stringify(a[k])} -> ${JSON.stringify(b[k])}`);
}

/** Refreshes the break-glass session's refresh token at the victual client; keeps the rotated token. */
async function refresh(state) {
  const r = await L.tokenCall({ grant_type: "refresh_token", client_id: "victual", client_secret: VICTUAL_SECRET, refresh_token: state.session.refresh });
  if (r.body.refresh_token) state.session.refresh = r.body.refresh_token;
  return r;
}

/** Writes a temporary configuration derived from `base` by `mutate`, with includes made absolute. */
function tempConfig(name, base, mutate) {
  const c = JSON.parse(readFileSync(base, "utf8"));
  const dir = resolve(base, "..");
  c.identityProviders = c.identityProviders.map((i) => (i.$include ? { ...i, $include: resolve(dir, i.$include) } : i));
  c.registrationAdapter.recipe = resolve(dir, c.registrationAdapter.recipe);
  mutate(c);
  const file = resolve(WORK, `${name}.json`);
  writeFileSync(file, JSON.stringify(c, null, 2));
  return file;
}

/** Phase U: configuration version 1 applied, fixtures built, version 2 applied over them. */
async function phaseUpgrade(sa) {
  const v1 = await L.reconcileCli(["--config", V1]);
  rec("U0", "plan 43: apply to an empty instance", "exit 0, nothing left unapplied", `exit ${v1.code}; ${v1.report.actions?.length} changes; unapplied ${JSON.stringify(v1.report.notApplied)}${v1.report.error ? `; ${v1.report.error}` : ""}`, v1.code === 0 && !v1.report.notApplied?.length);
  const state = await fixtures(sa);
  rec("B1", "ADR-0058 d3: a unique upstream identity creates and links one Keycloak user", "token issued; erin has one federated identity at upstream", `token ${state.erinTok.status}; federated ${JSON.stringify((await kc.call("GET", `/users/${(await user("erin"))?.id}/federated-identity`)).map((f) => f.identityProvider))}`, state.erinTok.status === 200);
  const before = await snapshot();
  const t0 = Date.now();
  const v2 = await L.reconcileCli([]);
  const ms = Date.now() - t0;
  rec("U1", "ADR-0055 q18: upgrade version 1 to version 2", "exit 0, nothing left unapplied", `exit ${v2.code}; ${v2.report.actions?.length} changes in ${ms} ms, including the read-back pass; unapplied ${JSON.stringify(v2.report.notApplied)}${v2.report.error ? `; ${v2.report.error}` : ""}`, v2.code === 0 && !v2.report.notApplied?.length);
  writeFileSync(resolve(L.HERE, "results/upgrade-actions.json"), `${JSON.stringify(v2.report.actions, null, 2)}\n`);
  const chk = await L.reconcileCli(["--check"]);
  rec("U2", "plan 43: version 2 holds after the upgrade", "--check exit 0, no changes", `exit ${chk.code}; ${chk.report.actions?.length} changes`, chk.code === 0 && chk.report.actions?.length === 0);
  const realm = await kc.call("GET", "");
  const scopes = (await kc.call("GET", "/client-scopes")).map((s) => s.name);
  const tree = JSON.stringify(await readFlow(kc, "victual-browser"));
  const u3 = { legacyClient: !!(await clientId("victual-legacy-cli")), writeScope: scopes.includes("victual:write"), ssoMax: realm.ssoSessionMaxLifespan, directGrant: realm.directGrantFlow, gate: tree.includes("deny-access-authenticator"), version: realm.attributes["victual.config-version"] };
  rec("U3", "ADR-0055 q18: an upgrade removes what version 2 dropped and changes what it changed", "legacy client and victual:write gone; SSO max 28800; direct grant bound to victual-direct-grant; browser flow has the local-only gate; realm records version 2", JSON.stringify(u3),
    !u3.legacyClient && !u3.writeScope && u3.ssoMax === 28800 && u3.directGrant === "victual-direct-grant" && u3.gate && u3.version === "2");
  const mc = await kc.call("GET", `/clients/${state.mcp.id}`);
  const opt = (await kc.call("GET", `/clients/${state.mcp.id}/optional-client-scopes`)).map((s) => s.name);
  const gs = (await kc.call("GET", "/client-scopes")).find((s) => s.name === "victual-grant:harness-mcp-1");
  rec("U4", "ADR-0055 q18, ADR-0058 d6: the adapter's objects are upgraded to recipe 3", "client and grant scope stamped recipe 3; offline_access no longer optional", `client recipe ${mc.attributes["victual.recipe"]}; grant scope recipe ${gs?.attributes["victual.recipe"]}; optional ${opt.join(",")}`,
    mc.attributes["victual.recipe"] === "3" && gs?.attributes["victual.recipe"] === "3" && !opt.includes("offline_access"));
  const after = await snapshot();
  const d = snapDiff(before, after);
  rec("U5", "issue 1139: reapplying preserves users, credentials, sessions, SCIM users, links", "no user, credential, federated identity, group membership, upstream id or session count changes", d.length ? d.join("; ") : `${Object.keys(after).length - 1} users identical`, d.length === 0);
  const r = await refresh(state);
  const bg = await L.browserLogin({ clientId: "victual", steps: [{ username: "breakglass", password: PW.breakglass }] });
  rec("U6", "issue 1139: a session from before the upgrade keeps working", "its refresh token refreshes; the break-glass password still logs in", `refresh ${r.status}; password login ${bg.code ? "code issued" : bg.error}`, r.status === 200 && !!bg.code);
  // U6's login adds a session, so later phases compare against a snapshot taken after it.
  return { state, base: await snapshot() };
}

/** Phase M: a second apply changes nothing. */
async function phaseIdempotent() {
  const d1 = await L.configDigest();
  const again = await L.reconcileCli([]);
  const d2 = await L.configDigest();
  rec("M1", "plan 43: a second apply changes nothing", "exit 0, zero changes, Keycloak's partial export unchanged", `exit ${again.code}; ${again.report.actions?.length} changes; export ${d1} -> ${d2}`, again.code === 0 && again.report.actions?.length === 0 && d1 === d2);
  const third = await L.reconcileCli([]);
  rec("M2", "plan 43: a third apply changes nothing", "exit 0, zero changes", `exit ${third.code}; ${third.report.actions?.length} changes`, third.code === 0 && third.report.actions?.length === 0);
}

/** Phase D: sixteen kinds of drift, made through the admin API, are reported and repaired. */
async function phaseDrift(ctx) {
  const v = await clientId("victual");
  const vc = await kc.call("GET", `/clients/${v}`);
  const drifts = [];
  /** Makes one drift and records the reconciler output it must produce. */
  const drift = async (name, expect, fn) => { await fn(); drifts.push({ name, expect }); };
  await drift("access-token lifespan 3600", "realm: set accessTokenLifespan", () => kc.call("PUT", "", { accessTokenLifespan: 3600 }));
  const offline = (await kc.call("GET", "/client-scopes")).find((s) => s.name === "offline_access");
  await drift("offline_access optional on victual", "client victual: remove optional scope offline_access", () => kc.call("PUT", `/clients/${v}/optional-client-scopes/${offline.id}`));
  await drift("PKCE off and a wildcard redirect on victual", "client victual: set redirectUris,attributes.pkce.code.challenge.method", () => kc.call("PUT", `/clients/${v}`, { ...vc, redirectUris: [...vc.redirectUris, "*"], attributes: { ...vc.attributes, "pkce.code.challenge.method": "" } }));
  const groupsScope = (await kc.call("GET", "/client-scopes")).find((s) => s.name === "victual-groups");
  await drift("groups mapper deleted", "client scope victual-groups: create mapper groups", () => kc.call("DELETE", `/client-scopes/${groupsScope.id}/protocol-mappers/models/${groupsScope.protocolMappers[0].id}`));
  const deny = (await kc.call("GET", "/authentication/flows/victual-browser/executions")).find((e) => e.providerId === "deny-access-authenticator");
  await drift("deny step disabled in the browser flow", "flow victual-browser: rebuild", () => kc.call("PUT", "/authentication/flows/victual-browser/executions", { ...deny, requirement: "DISABLED" }));
  const pol = await kc.call("GET", "/client-policies/policies");
  await drift("downscope policy deleted", "client policies: set victual-downscope-all", () => kc.call("PUT", "/client-policies/policies", { policies: pol.policies.filter((p) => p.name !== "victual-downscope-all") }));
  const scimSa = await kc.call("GET", `/clients/${await clientId("victual-scim")}/service-account-user`);
  const rm = await clientId("realm-management");
  const mu = (await kc.call("GET", `/clients/${rm}/roles`)).filter((r) => r.name === "manage-users");
  await drift("manage-users revoked from the SCIM sender", "client victual-scim: grant realm-management manage-users", () => kc.call("DELETE", `/users/${scimSa.id}/role-mappings/clients/${rm}`, mu));
  const scimId = await clientId("victual-scim");
  await drift("manage-users removed from the SCIM sender's scope mappings", "client victual-scim: scope-map realm-management manage-users", () => kc.call("DELETE", `/clients/${scimId}/scope-mappings/clients/${rm}`, mu));
  const up = await kc.call("GET", "/users/profile");
  await drift("externalId annotation removed", "user profile: upstreamId", () => kc.call("PUT", "/users/profile", { ...up, attributes: up.attributes.map((a) => (a.name === "upstreamId" ? { ...a, annotations: { "victual.owner": "victual-realm-config" } } : a)) }));
  const realm = await kc.call("GET", "");
  const offlineRole = await kc.call("GET", "/roles/offline_access");
  await drift("offline_access back in the default roles", "default roles: remove offline_access", () => kc.call("POST", `/roles-by-id/${realm.defaultRole.id}/composites`, [offlineRole]));
  await drift("browser flow bound to Keycloak's built-in flow", "realm flow bindings: set browserFlow", () => kc.call("PUT", "", { browserFlow: "browser" }));
  await drift("victual client secret changed", "client victual: set secret", async () => kc.call("PUT", `/clients/${v}`, { ...(await kc.call("GET", `/clients/${v}`)), secret: "drifted-secret" }));
  const comps = await kc.call("GET", "/components?type=org.keycloak.services.clientregistration.policy.ClientRegistrationPolicy");
  const acs = comps.find((c) => c.name === "Allowed Client Scopes" && c.subType === "anonymous");
  await drift("MCP audience scope allowed to anonymous registrants (ADR-0055 K2h)", "registration policy anonymous/Allowed Client Scopes: set allowed-client-scopes", () => kc.call("PUT", `/components/${acs.id}`, { ...acs, config: { ...acs.config, "allowed-client-scopes": ["victual-mcp-audience"] } }));
  await drift("extra mapper on victual", "client victual: remove undeclared mapper harness-extra", () => kc.call("POST", `/clients/${v}/protocol-mappers/models`, { name: "harness-extra", protocol: "openid-connect", protocolMapper: "oidc-hardcoded-claim-mapper", config: { "claim.name": "x", "claim.value": "y", "access.token.claim": "true" } }));
  await drift("session poller client deleted", "client victual-session-poller: create", async () => kc.call("DELETE", `/clients/${await clientId("victual-session-poller")}`));
  await drift("adapter client's PKCE removed", "adapter client harness-mcp-1: set attributes.pkce.code.challenge.method", async () => { const m = await kc.call("GET", `/clients/${ctx.state.mcp.id}`); await kc.call("PUT", `/clients/${m.id}`, { ...m, attributes: { ...m.attributes, "pkce.code.challenge.method": "" } }); });

  const chk = await L.reconcileCli(["--check"]);
  const missing = drifts.filter((d) => !chk.report.actions?.some((a) => a.startsWith(d.expect.split(",")[0])));
  rec("D1", "plan 43: --check reports every drift without writing", `exit 2; names all ${drifts.length} drifts`, `exit ${chk.code}; ${chk.report.actions?.length} changes; not named: ${missing.map((d) => d.name).join(", ") || "none"}`, chk.code === 2 && missing.length === 0);
  const fix = await L.reconcileCli([]);
  rec("D2", "plan 43: apply repairs the drift", "exit 0, nothing left unapplied", `exit ${fix.code}; ${fix.report.actions?.length} changes; unapplied ${JSON.stringify(fix.report.notApplied)}${fix.report.error ? `; ${fix.report.error}` : ""}`, fix.code === 0 && !fix.report.notApplied?.length);
  writeFileSync(resolve(L.HERE, "results/drift-actions.json"), `${JSON.stringify({ drifts, check: chk.report.actions, apply: fix.report.actions }, null, 2)}\n`);
  const after = await L.reconcileCli(["--check"]);
  rec("D3", "plan 43: the repaired realm is in sync", "--check exit 0", `exit ${after.code}; ${after.report.actions?.length} changes`, after.code === 0);
  const snap = await snapshot();
  const d = snapDiff(ctx.base, snap);
  rec("D4", "issue 1139: drift repair preserves users, credentials, sessions, SCIM users, links", "no difference from the snapshot after U6", d.length ? d.join("; ") : "identical", d.length === 0);
  const r = await refresh(ctx.state);
  rec("D5", "issue 1139: the session survives the repair (including the secret being reset)", "refresh 200", `refresh ${r.status} ${r.body.error ?? ""}`, r.status === 200);
}

/** Phase N: what the configured policies allow and refuse. */
async function phasePolicies(ctx, sa) {
  const scimTok = (await L.tokenCall({ grant_type: "client_credentials", client_id: "victual-scim", client_secret: L.ENV.SCIM_CLIENT_SECRET })).body.access_token;
  await kase("N1a", "ADR-0058 d5: the SCIM sender reaches the SCIM API", "200", async () => { const r = await L.scim("GET", "/Users?count=5", undefined, scimTok); return [r.status === 200, `${r.status}; ${r.body.totalResults} users`]; });
  await kase("N1b", "ADR-0058 d5: a manage-users token without the SCIM audience is refused", "401", async () => {
    await kc.call("POST", "/clients", { clientId: "harness-scim-noaud", publicClient: false, secret: "harness-only-noaud", serviceAccountsEnabled: true, standardFlowEnabled: false });
    const id = await clientId("harness-scim-noaud");
    const saU = await kc.call("GET", `/clients/${id}/service-account-user`);
    const rm = await clientId("realm-management");
    await kc.call("POST", `/users/${saU.id}/role-mappings/clients/${rm}`, (await kc.call("GET", `/clients/${rm}/roles`)).filter((r) => ["manage-users", "view-users"].includes(r.name)));
    const t = (await L.tokenCall({ grant_type: "client_credentials", client_id: "harness-scim-noaud", client_secret: "harness-only-noaud" })).body.access_token;
    const r = await L.scim("GET", "/Users", undefined, t);
    await kc.call("DELETE", `/clients/${id}`);
    return [r.status === 401, `${r.status} ${JSON.stringify(r.body).slice(0, 80)}`];
  });
  await kase("N1c", "ADR-0058 d5: an end user's token is refused at SCIM", "401 or 403", async () => {
    const bg = await L.browserLogin({ clientId: "victual", steps: [{ username: "breakglass", password: PW.breakglass }] });
    const t = (await L.redeem(bg, "victual", VICTUAL_SECRET)).body.access_token;
    const r = await L.scim("GET", "/Users", undefined, t);
    return [r.status === 401 || r.status === 403, `${r.status}`];
  });
  await kase("N1d", "ADR-0058 d5: externalId is stored and searchable", "one user for the filter", async () => { const r = await L.scim("GET", `/Users?filter=${encodeURIComponent('externalId eq "entra-oid-0001"')}`, undefined, scimTok); return [r.body.totalResults === 1, `${r.status}; ${r.body.totalResults} result(s)`]; });

  /** Logs breakglass in at `client` asking for offline_access and reports the refresh token's type. */
  const offlineAt = async (client, secret, extra = {}) => {
    const lg = await L.browserLogin({ clientId: client, params: { scope: "openid offline_access" }, steps: [{ username: "breakglass", password: PW.breakglass }], ...extra });
    if (!lg.code) return { error: `${lg.error} ${lg.text.slice(0, 80)}` };
    const t = await L.redeem(lg, client, secret, L.REALM, extra.redirectUri);
    if (!t.body.refresh_token) return { error: `${t.status} ${t.body.error} ${t.body.error_description ?? ""}` };
    return { typ: L.claims(t.body.refresh_token).typ, scope: t.body.scope, exp: L.claims(t.body.refresh_token).exp - L.claims(t.body.refresh_token).iat };
  };
  await kase("N2a", "ADR-0058 d6: no offline token for Victual's client", "no Offline refresh token", async () => { const o = await offlineAt("victual", VICTUAL_SECRET); return [o.error || o.typ !== "Offline", JSON.stringify(o)]; });
  await kase("N2b", "ADR-0058 d6: no offline token for an adapter-registered MCP client", "no Offline refresh token", async () => { const o = await offlineAt("harness-mcp-1"); return [o.error || o.typ !== "Offline", JSON.stringify(o)]; });
  await kase("N2c", "ADR-0058 d6: no offline token at the exempt admin console client", "refused or not Offline", async () => {
    const o = await offlineAt("security-admin-console", undefined, { redirectUri: `${L.KC}/admin/victual/console/` });
    return [o.error || o.typ !== "Offline", JSON.stringify(o)];
  });
  await kase("N2d", "ADR-0058 d6: no offline token through admin-cli's password grant", "no Offline refresh token", async () => {
    const t = await L.tokenCall({ grant_type: "password", client_id: "admin-cli", username: "breakglass", password: PW.breakglass, scope: "openid offline_access" });
    return [t.status !== 200 || L.claims(t.body.refresh_token).typ !== "Offline", `${t.status} ${t.body.refresh_token ? L.claims(t.body.refresh_token).typ : t.body.error}`];
  });

  await kase("N3a", "ADR-0058 d7: an ordinary account's password login is refused at the form", "no code; the page names provider sign-in", async () => { const lg = await L.browserLogin({ clientId: "victual", steps: [{ username: "olive", password: PW.olive }] }); return [!lg.code && /identity provider/.test(lg.text), lg.code ? "code issued" : `${lg.error}: "${(/[^.>]*identity provider[^.<]*\./.exec(lg.text) ?? [""])[0].trim()}"`]; });
  await kase("N3b", "ADR-0058 d7: a break-glass account's password login works", "code issued", async () => { const lg = await L.browserLogin({ clientId: "victual", steps: [{ username: "breakglass", password: PW.breakglass }] }); return [!!lg.code, lg.code ? "code issued" : `${lg.error} ${lg.text.slice(0, 80)}`]; });
  await kase("N3c", "ADR-0058 d7: an ordinary account's password grant (admin-cli) is refused", "not 200", async () => { const t = await L.tokenCall({ grant_type: "password", client_id: "admin-cli", username: "olive", password: PW.olive }); return [t.status !== 200, `${t.status} ${JSON.stringify(t.body)}`]; });
  await kase("N3d", "ADR-0058 d7: a break-glass account's password grant (admin-cli) works", "200", async () => { const t = await L.tokenCall({ grant_type: "password", client_id: "admin-cli", username: "breakglass", password: PW.breakglass }); return [t.status === 200, `${t.status}`]; });
  await kase("N3e", "ADR-0055 d6: no password grant at Victual's client", "400 unauthorized_client", async () => { const t = await L.tokenCall({ grant_type: "password", client_id: "victual", client_secret: VICTUAL_SECRET, username: "breakglass", password: PW.breakglass }); return [t.status === 400 && t.body.error === "unauthorized_client", `${t.status} ${t.body.error}`]; });
  await kase("N3f", "ADR-0058 d7: an ordinary account's wrong password is still counted by brute-force protection", "observation", async () => {
    for (let i = 0; i < 3; i++) await L.tokenCall({ grant_type: "password", client_id: "admin-cli", username: "olive", password: "wrong" });
    const b = await kc.call("GET", `/attack-detection/brute-force/users/${(await user("olive")).id}`);
    return [true, `numFailures ${b.numFailures}, disabled ${b.disabled}`, "obs"];
  });

  await kase("N4a", "ADR-0058 d3: an email collision at first broker login is refused and links nothing", "no code; 'already exists'; carol keeps zero federated identities; no user created", async () => {
    const n0 = (await kc.call("GET", "/users/count"));
    const lg = await L.browserLogin({ clientId: "victual", params: { kc_idp_hint: "upstream" }, steps: [{ username: "carol", password: PW.carolUp }] });
    const fed = await kc.call("GET", `/users/${(await user("carol")).id}/federated-identity`);
    const n1 = (await kc.call("GET", "/users/count"));
    return [!lg.code && /already exists/.test(lg.text) && fed.length === 0 && n0 === n1, `${lg.code ? "code issued" : lg.text.slice(0, 120)}; federated ${fed.length}; users ${n0} -> ${n1}`];
  });

  await kase("N5a", "ADR-0055 d6: an authorization request without PKCE is refused", "error", async () => { const lg = await L.browserLogin({ clientId: "victual", pkce: "none", steps: [{ username: "breakglass", password: PW.breakglass }] }); return [!lg.code, lg.code ? "code issued" : `${lg.error} ${lg.text.slice(0, 80)}`]; });
  await kase("N5b", "ADR-0055 d6: plain PKCE is refused", "error", async () => { const lg = await L.browserLogin({ clientId: "victual", pkce: "plain", steps: [{ username: "breakglass", password: PW.breakglass }] }); return [!lg.code, lg.code ? "code issued" : `${lg.error} ${lg.text.slice(0, 80)}`]; });

  const mcpLogin = await L.browserLogin({ clientId: "harness-mcp-1", params: { scope: "openid victual:read" }, steps: [{ username: "breakglass", password: PW.breakglass }] });
  const mcpTok = mcpLogin.code ? (await L.redeem(mcpLogin, "harness-mcp-1")).body.access_token : null;
  const sidecar = (sub = SA("victual-mcp")) => L.assertion(sa.mint(sub));
  /** Exchanges the MCP token at the sidecar with `form` extras. */
  const exchange = (extra) => L.tokenCall({ grant_type: L.TX, client_id: "victual-mcp", subject_token: mcpTok, subject_token_type: L.AT, requested_token_type: L.AT, audience: L.ENV.VICTUAL_API_AUDIENCE, ...extra });
  await kase("N6a", "ADR-0055 d7, ADR-0057 q6: the sidecar exchanges with its service-account token", "200; aud is /api; same sub", async () => {
    const t = await exchange({ ...sidecar(), scope: "victual:read" });
    const a = mcpTok && L.claims(mcpTok), b = t.body.access_token && L.claims(t.body.access_token);
    return [t.status === 200 && b?.aud === L.ENV.VICTUAL_API_AUDIENCE && a.sub === b.sub, `${t.status}; subject aud ${JSON.stringify(a?.aud)}; exchanged aud ${JSON.stringify(b?.aud)}; azp ${b?.azp}; same sub ${a?.sub === b?.sub}`];
  });
  await kase("N6b", "ADR-0055 d7: an exchange that widens scope is refused", "400 invalid_scope", async () => { const t = await exchange({ ...sidecar(), scope: "victual:stock" }); return [t.status === 400 && t.body.error === "invalid_scope", `${t.status} ${t.body.error}`]; });
  await kase("N6c", "ADR-0057 q6: another namespace's service-account token is refused", "401", async () => { const t = await exchange({ ...sidecar("system:serviceaccount:other:victual-mcp"), scope: "victual:read" }); return [t.status === 401, `${t.status} ${t.body.error}`]; });
  await kase("N6d", "ADR-0057 q6: a client secret is refused for the federated-jwt sidecar", "401", async () => { const t = await exchange({ client_secret: "anything", scope: "victual:read" }); return [t.status === 401, `${t.status} ${t.body.error}`]; });
  await kase("N6e", "ADR-0055 d3: the MCP client's token carries the MCP audience", "aud includes the MCP resource URI", async () => { const a = mcpTok && L.claims(mcpTok); return [[].concat(a?.aud).includes(L.ENV.VICTUAL_MCP_AUDIENCE), JSON.stringify(a?.aud)]; });

  await kase("N7", "ADR-0055 d8: anonymous dynamic registration is refused", "403", async () => {
    const r = await fetch(`${L.ISSUER}/clients-registrations/openid-connect`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ redirect_uris: ["https://example.invalid/cb"], client_name: "anon" }) });
    return [r.status === 403, `${r.status} ${(await r.text()).slice(0, 100)}`];
  });
  await kase("N8", "ADR-0055 d6, ADR-0058 d6: five-minute access tokens, 30-minute idle, 8-hour SSO maximum", "access ttl 300; refresh ttl <= 1800; realm SSO max 28800", async () => {
    const lg = await L.browserLogin({ clientId: "victual", steps: [{ username: "breakglass", password: PW.breakglass }] });
    const t = (await L.redeem(lg, "victual", VICTUAL_SECRET)).body;
    const a = L.claims(t.access_token);
    const realm = await kc.call("GET", "");
    return [a.exp - a.iat === 300 && t.refresh_expires_in <= 1800 && realm.ssoSessionMaxLifespan === 28800, `access ${a.exp - a.iat} s; refresh ${t.refresh_expires_in} s; SSO max ${realm.ssoSessionMaxLifespan} s (read back; not waited out)`];
  });
  await kase("N9", "ADR-0058 d4: Victual's tokens carry Keycloak group membership", "groups contains household in access and ID token", async () => {
    const lg = await L.browserLogin({ clientId: "victual", steps: [{ username: "breakglass", password: PW.breakglass }] });
    const t = (await L.redeem(lg, "victual", VICTUAL_SECRET)).body;
    const g1 = L.claims(t.access_token).groups ?? [], g2 = L.claims(t.id_token).groups ?? [];
    return [g1.includes("household") && g2.includes("household"), `access ${JSON.stringify(g1)}; id ${JSON.stringify(g2)}`];
  });
  await kase("N10a", "ADR-0057 q6: the label worker gets a token with its service-account token", "200; aud is /api", async () => { const t = await L.tokenCall({ grant_type: "client_credentials", client_id: "victual-label-worker", ...L.assertion(sa.mint(SA("victual-label-worker"))) }); return [t.status === 200 && L.claims(t.body.access_token).aud === L.ENV.VICTUAL_API_AUDIENCE, `${t.status} aud ${t.body.access_token ? L.claims(t.body.access_token).aud : t.body.error}`]; });
  await kase("N10b", "ADR-0057 q6: the renderer's token cannot act as the worker", "refused", async () => { const t = await L.tokenCall({ grant_type: "client_credentials", client_id: "victual-label-worker", ...L.assertion(sa.mint(SA("victual-label-renderer"))) }); return [t.status >= 400, `${t.status} ${t.body.error}`]; });
}

/** Phase F: what the reconciler refuses, and that a refusal writes nothing. */
async function phaseRefusals(ctx) {
  // Phase N logged in many times; F's preservation check compares against a snapshot taken now.
  ctx.base = await snapshot();
  /** Runs a refusal case: the digest must not move unless `writes` is set. */
  const refuse = async (id, clause, expected, args, env, want, { writes = false } = {}) => {
    const e0 = await L.configExport();
    const r = await L.reconcileCli(args, env);
    const e1 = await L.configExport();
    const d0 = e0.digest, d1 = e1.digest;
    const moved = d0 === d1 ? "" : ` (${L.exportDiff(e0.doc, e1.doc).join("; ")})`;
    const leaked = [L.ENV.VICTUAL_CLIENT_SECRET, L.ENV.SCIM_CLIENT_SECRET].some((s) => JSON.stringify(r.report).includes(s) || r.stderr.includes(s));
    rec(id, clause, expected, `exit ${r.code}; ${r.report.error ?? ""}${r.report.conflicts?.length ? `; ${r.report.conflicts.join("; ")}` : ""}; export ${d0 === d1 ? "unchanged" : `CHANGED${moved}`}; secrets in output ${leaked}`,
      r.code === want && (writes || d0 === d1) && !leaked);
    return r;
  };
  await refuse("F1", "ADR-0058 d11: an unset secret refuses the whole configuration", "exit 1 naming SCIM_CLIENT_SECRET; nothing written", [], { SCIM_CLIENT_SECRET: "" }, 1);
  await refuse("F2", "plan 43: an unknown key is refused, so a typo is not ignored", "exit 1; nothing written", ["--config", tempConfig("f2", V2, (c) => { c.realmSettings = { accessTokenLifespan: 60 }; })], {}, 1);
  await refuse("F3", "plan 43: a CHANGE-ME placeholder is refused", "exit 1; nothing written", [], { VICTUAL_CLIENT_SECRET: "CHANGE-ME" }, 1);
  await refuse("F4", "plan 43: a pinned Keycloak version that differs is refused", "exit 4; nothing written", ["--config", tempConfig("f4", V2, (c) => { c.keycloakVersion = "26.8.0"; })], {}, 4);
  await kc.call("POST", "/clients", { clientId: "victual-extra", publicClient: true, standardFlowEnabled: false });
  const extra = tempConfig("f5", V2, (c) => { c.clients.push({ clientId: "victual-extra", publicClient: true, standardFlowEnabled: false, directAccessGrantsEnabled: false, attributes: {}, defaultClientScopes: ["basic"], optionalClientScopes: [] }); });
  await refuse("F5", "plan 43: an unowned object holding a declared name is refused", "exit 4 naming victual-extra; nothing written", ["--config", extra], {}, 4);
  const adopt = await L.reconcileCli(["--config", extra, "--adopt"]);
  const stampAfter = (await kc.call("GET", `/clients/${await clientId("victual-extra")}`)).attributes?.["victual.owner"];
  rec("F6", "plan 43: --adopt takes an unowned object over explicitly", "exit 0; client stamped", `exit ${adopt.code}; owner ${stampAfter}`, adopt.code === 0 && stampAfter === "victual-realm-config");
  const back = await L.reconcileCli([]);
  rec("F7", "plan 43: an owned object the configuration drops is deleted", "exit 0; victual-extra deleted", `exit ${back.code}; victual-extra ${(await clientId("victual-extra")) ? "present" : "deleted"}`, back.code === 0 && !(await clientId("victual-extra")));
  const bad = tempConfig("f8", V2, (c) => { c.clientPolicies.profiles[0].executors.push({ executor: "no-such-executor", configuration: {} }); });
  await refuse("F8", "plan 43: a write Keycloak refuses stops the run with the refusal", "exit 1 naming the client-policies call", ["--config", bad], {}, 1, { writes: true });
  const heal = await L.reconcileCli([]);
  const healCheck = await L.reconcileCli(["--check"]);
  rec("F9", "plan 43: after a refused run, the next apply converges", "exit 0; --check exit 0", `apply exit ${heal.code} (${heal.report.actions?.length} changes); check exit ${healCheck.code}`, heal.code === 0 && healCheck.code === 0);
  await refuse("F10", "ADR-0058 d6: an adapter recipe that lists a forbidden scope is refused", "exit 4; nothing written", ["--config", tempConfig("f10", V2, (c) => { c.registrationAdapter.recipe = resolve(L.HERE, "fixtures/adapter-recipe-v2.json"); })], {}, 4);
  const entraEnv = { ENTRA_TENANT_ID: "00000000-0000-0000-0000-000000000000", ENTRA_CLIENT_ID: "harness-entra-client", ENTRA_CLIENT_SECRET: "harness-only-entra" };
  const withEntra = await L.reconcileCli([], entraEnv);
  const idp = await kc.call("GET", "/identity-provider/instances/entra-id", undefined, { allow: [404] });
  const withEntraAgain = await L.reconcileCli([], entraEnv);
  const without = await L.reconcileCli([]);
  const gone = await kc.call("GET", "/identity-provider/instances/entra-id", undefined, { allow: [404] });
  rec("F11", "ADR-0058 q1, q2: the Entra ID template applies only with its variables, idempotently, and is pruned without them", "exit 0 three times; created; second run zero changes; deleted when ENTRA_TENANT_ID is unset",
    `exits ${withEntra.code}/${withEntraAgain.code}/${without.code}; created ${!idp?.missing} (first broker flow ${idp?.firstBrokerLoginFlowAlias}); second run ${withEntraAgain.report.actions?.length} changes; after unset ${gone?.missing ? "deleted" : "present"}`,
    withEntra.code === 0 && withEntraAgain.code === 0 && withEntraAgain.report.actions?.length === 0 && without.code === 0 && !idp?.missing && !!gone?.missing);
  const snap = await snapshot();
  const d = snapDiff(ctx.base, snap);
  rec("P1", "issue 1139: after every refusal, adoption and template run, users are untouched", "no difference from the snapshot taken before F1", d.length ? d.join("; ") : "identical", d.length === 0);
}

/** Phase I: Keycloak's own realm import against the same realm, for the comparison in plan 43. */
async function phaseImport(ctx) {
  const usersBefore = await kc.call("GET", "/users/count");
  // I1: partial import, OVERWRITE, of Victual's client through the admin API.
  const exp = await kc.call("POST", "/partial-export?exportClients=true&exportGroupsAndRoles=false");
  const vRep = exp.clients.find((c) => c.clientId === "victual");
  const idBefore = await clientId("victual");
  const pi = await kc.call("POST", "/partialImport", { ifResourceExists: "OVERWRITE", clients: [vRep] });
  const idAfter = await clientId("victual");
  const r = await refresh(ctx.state);
  const secretNow = (await kc.call("GET", `/clients/${idAfter}/client-secret`)).value;
  rec("I1", "plan 43 comparison: partial import OVERWRITE of one client", "observation", `overwritten ${pi.overwritten}; client id ${idBefore === idAfter ? "kept" : "replaced"}; session refresh ${r.status} ${r.body.error ?? ""}; secret ${secretNow === VICTUAL_SECRET ? "kept" : "replaced by the export's mask or a new value"}`, true, "obs");
  await L.reconcileCli([]); // put the client back before the next case
  // I4: which object kinds partial import accepts. Three kinds the realm configuration needs.
  const probe = await kc.call("POST", "/partialImport", { ifResourceExists: "SKIP",
    authenticationFlows: [{ alias: "i4-probe-flow", providerId: "basic-flow", topLevel: true, builtIn: false, authenticationExecutions: [] }],
    clientScopes: [{ name: "i4-probe-scope", protocol: "openid-connect" }],
    clientPolicies: { policies: [{ name: "i4-probe-policy", enabled: true, conditions: [], profiles: [] }] } }, { allow: [400] });
  const made = { flow: (await kc.call("GET", "/authentication/flows")).some((f) => f.alias === "i4-probe-flow"), scope: (await kc.call("GET", "/client-scopes")).some((x) => x.name === "i4-probe-scope"), policy: (await kc.call("GET", "/client-policies/policies")).policies.some((x) => x.name === "i4-probe-policy") };
  rec("I4", "plan 43 comparison: partial import of a flow, a client scope and a client policy", "observation", `response added ${probe.added}, skipped ${probe.skipped}; created ${JSON.stringify(made)}`, true, "obs");
  // I2 and I3: `kc.sh import` with Keycloak stopped, from the realm as exported now plus a change.
  const full = await kc.call("POST", "/partial-export?exportClients=true&exportGroupsAndRoles=true");
  full.accessTokenLifespan = 1234;
  const file = resolve(WORK, "realm-export.json");
  writeFileSync(file, JSON.stringify(full));
  /** Runs run.sh with arguments, synchronously. */
  const run = (...a) => execFileSync(resolve(L.HERE, "run.sh"), a, { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] });
  run("import", file, "false"); run("start");
  const log2 = readFileSync(resolve(WORK, "import.log"), "utf8");
  const realm2 = await kc.call("GET", "");
  rec("I2", "plan 43 comparison: kc.sh import --override false on an existing realm", "observation", `import log: ${/already exists|skip/i.exec(log2)?.[0] ?? "no skip message"}; accessTokenLifespan ${realm2.accessTokenLifespan} (file said 1234); users ${await kc.call("GET", "/users/count")}`, true, "obs");
  run("import", file, "true"); run("start");
  const realm3 = await kc.call("GET", "");
  const usersAfter = await kc.call("GET", "/users/count");
  const r3 = await refresh(ctx.state);
  rec("I3", "plan 43 comparison: kc.sh import --override true on an existing realm", "observation", `accessTokenLifespan ${realm3.accessTokenLifespan}; users ${usersBefore} -> ${usersAfter}; break-glass session refresh ${r3.status} ${r3.body.error ?? ""}`, true, "obs");
}

/** Runs every phase in order and writes the results. */
async function main() {
  mkdirSync(resolve(L.HERE, "results"), { recursive: true });
  const sa = L.startSaIssuer();
  const started = new Date().toISOString();
  try {
    for (const realm of ["victual", "upstream"]) await L.kcRealm(realm).root("DELETE", `/${realm}`, undefined, { allow: [404] });
    const ctx = await phaseUpgrade(sa);
    await phaseIdempotent();
    await phaseDrift(ctx);
    await phasePolicies(ctx, sa);
    await phaseRefusals(ctx);
    if (!process.argv.includes("--no-import")) await phaseImport(ctx);
  } finally { sa.close(); }
  const commit = execFileSync("git", ["-C", L.ROOT, "rev-parse", "--short", "HEAD"], { encoding: "utf8" }).trim();
  const dirty = execFileSync("git", ["-C", L.ROOT, "status", "--porcelain", "--", "deploy/keycloak", ".devtools/keycloak-config", ":(exclude).devtools/keycloak-config/results"], { encoding: "utf8" }).trim() ? " (with uncommitted changes)" : "";
  const meta = { started, finished: new Date().toISOString(), keycloak: await kc.serverVersion(), node: process.version, base: `${commit}${dirty}` };
  writeFileSync(resolve(L.HERE, "results/results.json"), `${JSON.stringify({ meta, results }, null, 2)}\n`);
  console.log(`| Case | Clause | Expected | Observed | Verdict |\n|---|---|---|---|---|`);
  for (const r of results) console.log(`| ${r.id} | ${r.clause} | ${r.expected} | ${r.observed.replace(/\|/g, "\\|")} | ${r.verdict} |`);
  const fails = results.filter((r) => r.verdict === "FAIL").length;
  console.log(`\n${results.length} cases, ${fails} FAIL; ${JSON.stringify(meta)}`);
  process.exitCode = fails ? 1 : 0;
}

await main();
