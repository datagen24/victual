#!/usr/bin/env node
// Applies deploy/keycloak/realm.json to a running Keycloak through its admin REST API (plan 43).
//
//   node reconcile.mjs [--config FILE] [--check] [--adopt] [--allow-version-mismatch]
//
// Without --check it brings the realm to the configuration, then reads everything back and checks
// again; anything still different is reported as not applied. With --check it writes nothing and
// reports what an apply would change. Output is one JSON document on stdout; progress goes to stderr.
//
// What it manages: the objects the configuration declares, stamped `victual.owner =
// victual-realm-config` (attributes on clients, client scopes, roles; config on identity providers;
// an annotation on user-profile attributes; a description marker on flows). It prunes owned objects
// the configuration no longer declares. It never creates, changes or deletes users, credentials,
// sessions, groups or group membership, and never touches an object it does not own except where the
// configuration states a realm-wide rule (forbiddenClientScopes, defaultRoles.absent,
// realmDefaultClientScopes.absent, flowBindings, clientRegistrationPolicies). Adapter-owned objects are
// left to the registration adapter's own reconcile step (adapter.mjs).
//
// Exit codes: 0 in sync; 1 configuration refused or Keycloak refused a write; 2 --check found drift;
// 3 applied, but the read-back still differs; 4 refused: an unowned object holds a declared name, the
// Keycloak version differs from the pinned one, or the adapter recipe conflicts with the realm.
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";
import { createHash } from "node:crypto";
import { adminClient, AdminError } from "./lib/admin.mjs";
import { ConfigError, loadConfig, redact } from "./lib/config.mjs";
import { buildFlow, deleteFlow, normalizeDeclared, readFlow, rebuildFlow, NEXT } from "./lib/flows.mjs";
import { isAdapterOwned, loadRecipe, reconcileAdapter } from "./adapter.mjs";

export const OWNER = "victual-realm-config";
export const OWNER_ATTR = "victual.owner";
export const VERSION_ATTR = "victual.config-version";
export const FLOW_MARK = ` [managed: ${OWNER}]`;
const HERE = dirname(fileURLToPath(import.meta.url));

/** Raised when the reconciler refuses to proceed (exit 4). */
export class Refusal extends Error {}

/** True for a plain object (not an array, not null). */
const isObj = (x) => x !== null && typeof x === "object" && !Array.isArray(x);

/** Sorted JSON of an array's elements, so lists compare as sets. */
const setKey = (a) => JSON.stringify([...(a ?? [])].map((x) => JSON.stringify(x)).sort());

/**
 * Lists the paths at which `have` differs from `want`, comparing only what `want` declares. Objects
 * recurse; arrays compare as sets; a declared empty string matches an absent value, because Keycloak
 * drops empty attributes; scalars compare by string form, because attributes are strings.
 */
export function diffKeys(want, have, path = "") {
  const out = [];
  for (const [k, w] of Object.entries(want)) {
    const h = have?.[k];
    const p = path ? `${path}.${k}` : k;
    if (isObj(w)) out.push(...diffKeys(w, isObj(h) ? h : {}, p));
    else if (Array.isArray(w)) { if (setKey(w) !== setKey(Array.isArray(h) ? h : [])) out.push(p); }
    else if (w === "" && (h === undefined || h === null || h === "")) continue;
    else if (h === undefined || String(w) !== String(h)) out.push(p);
  }
  return out;
}

/** The SHA-256 of a secret, stored beside a secret Keycloak masks on read so drift can be seen. */
const secretDigest = (s) => createHash("sha256").update(s).digest("hex");

/** Builds the reconcile context: admin client, mode flags and the change and conflict logs. */
function context(kc, loaded, opts) {
  const ctx = {
    kc, cfg: loaded.config, loaded, check: !!opts.check, adopt: !!opts.adopt, actions: [], conflicts: [], exemptions: [],
    /** Records a change, and performs it unless in check mode. */
    async act(msg, fn) { ctx.actions.push(redact(msg, loaded.secrets)); process.stderr.write(`${ctx.check ? "drift" : "apply"}: ${redact(msg, loaded.secrets)}\n`); if (!ctx.check) await fn(); },
    /** Records an unowned object that holds a declared name; returns true when --adopt takes it over. */
    conflict(kind, name) { if (ctx.adopt) { ctx.actions.push(`${kind} ${name}: adopt`); return true; } ctx.conflicts.push(`${kind} ${name}: exists and is not owned by ${OWNER}`); return false; },
  };
  return ctx;
}

/** The ownership attributes for the current configuration version. */
const stamp = (cfg) => ({ [OWNER_ATTR]: OWNER, [VERSION_ATTR]: String(cfg.configVersion) });
/** True when attributes (or an identity provider's config) say the realm configuration owns it. */
const owned = (attrs) => attrs?.[OWNER_ATTR] === OWNER;

/** Step: the realm exists and its declared settings match. Returns false when check mode stops early. */
async function stepRealm(ctx) {
  const { kc, cfg } = ctx;
  const r = await kc.call("GET", "", undefined, { allow: [404] });
  if (r?.missing) {
    await ctx.act(`realm ${cfg.realm.realm}: create`, () => kc.root("POST", "", { realm: cfg.realm.realm, enabled: cfg.realm.enabled ?? true }));
    if (ctx.check) return false;
  }
  const realm = await kc.call("GET", "");
  const d = diffKeys(cfg.realm, realm);
  if (d.length) await ctx.act(`realm: set ${d.join(",")}`, () => kc.call("PUT", "", cfg.realm));
  return true;
}

/** Step: realm roles the configuration declares; owned roles it no longer declares are pruned later. */
async function stepRoles(ctx) {
  const { kc, cfg } = ctx;
  const roles = await kc.call("GET", "/roles?briefRepresentation=false&max=10000");
  for (const want of cfg.realmRoles ?? []) {
    const have = roles.find((r) => r.name === want.name);
    const rep = { name: want.name, description: want.description ?? "", attributes: { [OWNER_ATTR]: [OWNER] } };
    if (!have) { await ctx.act(`role ${want.name}: create`, () => kc.call("POST", "/roles", rep)); continue; }
    if (!owned(Object.fromEntries(Object.entries(have.attributes ?? {}).map(([k, v]) => [k, v[0]]))) && !ctx.conflict("role", want.name)) continue;
    const d = diffKeys({ description: rep.description, attributes: { [OWNER_ATTR]: [OWNER] } }, have);
    if (d.length) await ctx.act(`role ${want.name}: set ${d.join(",")}`, () => kc.call("PUT", `/roles-by-id/${have.id}`, { ...have, ...rep }));
  }
  const absent = cfg.defaultRoles?.absent ?? [];
  if (absent.length) {
    const realm = await kc.call("GET", "");
    const comps = await kc.call("GET", `/roles-by-id/${realm.defaultRole.id}/composites`);
    for (const role of comps.filter((c) => !c.clientRole && absent.includes(c.name))) {
      await ctx.act(`default roles: remove ${role.name}`, () => kc.call("DELETE", `/roles-by-id/${realm.defaultRole.id}/composites`, [role]));
    }
  }
}

/** Diffs and repairs the protocol mappers of a client or client scope at `base` against `want`. */
async function syncMappers(ctx, label, base, want = [], have = []) {
  const { kc } = ctx;
  for (const m of want) {
    const rep = { protocol: "openid-connect", ...m };
    const cur = have.find((x) => x.name === m.name);
    if (!cur) { await ctx.act(`${label}: create mapper ${m.name}`, () => kc.call("POST", `${base}/protocol-mappers/models`, rep)); continue; }
    const d = cur.protocolMapper !== m.protocolMapper ? ["protocolMapper"] : diffKeys({ config: m.config ?? {} }, cur);
    if (d.includes("protocolMapper")) {
      await ctx.act(`${label}: replace mapper ${m.name}`, async () => { await kc.call("DELETE", `${base}/protocol-mappers/models/${cur.id}`); await kc.call("POST", `${base}/protocol-mappers/models`, rep); });
    } else if (d.length) {
      await ctx.act(`${label}: mapper ${m.name} set ${d.join(",")}`, () => kc.call("PUT", `${base}/protocol-mappers/models/${cur.id}`, { ...cur, config: { ...cur.config, ...m.config } }));
    }
  }
  for (const m of have.filter((x) => !want.some((w) => w.name === x.name))) {
    await ctx.act(`${label}: remove undeclared mapper ${m.name}`, () => kc.call("DELETE", `${base}/protocol-mappers/models/${m.id}`));
  }
}

/** Step: client scopes and their mappers, and the realm's default client-scope lists. */
async function stepClientScopes(ctx) {
  const { kc, cfg } = ctx;
  const scopes = await kc.call("GET", "/client-scopes");
  for (const want of cfg.clientScopes ?? []) {
    const rep = { name: want.name, protocol: "openid-connect", description: want.description ?? "",
      attributes: { "include.in.token.scope": String(want.includeInTokenScope ?? true), "display.on.consent.screen": "false", ...stamp(cfg) } };
    const have = scopes.find((s) => s.name === want.name);
    if (!have) { await ctx.act(`client scope ${want.name}: create`, () => kc.call("POST", "/client-scopes", { ...rep, protocolMappers: (want.protocolMappers ?? []).map((m) => ({ protocol: "openid-connect", ...m })) })); continue; }
    if (!owned(have.attributes) && !ctx.conflict("client scope", want.name)) continue;
    const d = diffKeys({ description: rep.description, attributes: rep.attributes }, have);
    if (d.length) await ctx.act(`client scope ${want.name}: set ${d.join(",")}`, () => kc.call("PUT", `/client-scopes/${have.id}`, { ...have, ...rep, attributes: { ...have.attributes, ...rep.attributes } }));
    await syncMappers(ctx, `client scope ${want.name}`, `/client-scopes/${have.id}`, want.protocolMappers, have.protocolMappers);
  }
  for (const kind of ["default", "optional"]) {
    const absent = cfg.realmDefaultClientScopes?.absent ?? [];
    for (const s of (await kc.call("GET", `/default-${kind}-client-scopes`)).filter((x) => absent.includes(x.name))) {
      await ctx.act(`realm ${kind} client scopes: remove ${s.name}`, () => kc.call("DELETE", `/default-${kind}-client-scopes/${s.id}`));
    }
  }
}

/** Step: owned authentication flows match their declared trees, and the realm binds the declared flows. */
async function stepFlows(ctx) {
  const { kc, cfg } = ctx;
  for (const want of cfg.authenticationFlows ?? []) {
    const decl = { ...want, description: (want.description ?? "") + FLOW_MARK };
    const flows = await kc.call("GET", "/authentication/flows");
    const have = flows.find((f) => f.alias === want.alias);
    if (!have) { await ctx.act(`flow ${want.alias}: create`, () => buildFlow(kc, want.alias, decl)); continue; }
    if (!have.description?.endsWith(FLOW_MARK) && !ctx.conflict("flow", want.alias)) continue;
    const same = have.description === decl.description && JSON.stringify(await readFlow(kc, want.alias)) === JSON.stringify(normalizeDeclared(want.executions));
    if (!same) await ctx.act(`flow ${want.alias}: rebuild (tree or description differs)`, () => rebuildFlow(kc, want.alias, decl));
  }
  const realm = await kc.call("GET", "");
  const d = diffKeys(cfg.flowBindings ?? {}, realm);
  if (d.length) await ctx.act(`realm flow bindings: set ${d.join(",")}`, () => kc.call("PUT", "", cfg.flowBindings));
}

/** Step: identity providers. Secrets Keycloak masks on read are compared through a stored digest. */
async function stepIdentityProviders(ctx) {
  const { kc, cfg } = ctx;
  const idps = await kc.call("GET", "/identity-provider/instances");
  for (const want of cfg.identityProviders ?? []) {
    const { clientSecret, ...config } = want.config ?? {};
    const rep = { ...want, config: { ...config, [OWNER_ATTR]: OWNER, ...(clientSecret ? { "victual.client-secret-sha256": secretDigest(clientSecret) } : {}) } };
    const full = { ...rep, config: { ...rep.config, ...(clientSecret ? { clientSecret } : {}) } };
    const have = idps.find((i) => i.alias === want.alias);
    if (!have) { await ctx.act(`identity provider ${want.alias}: create`, () => kc.call("POST", "/identity-provider/instances", full)); continue; }
    if (!owned(have.config) && !ctx.conflict("identity provider", want.alias)) continue;
    const d = diffKeys(rep, have);
    if (d.length) await ctx.act(`identity provider ${want.alias}: set ${d.join(",")}`, () => kc.call("PUT", `/identity-provider/instances/${encodeURIComponent(want.alias)}`, { ...have, ...full, config: { ...have.config, ...full.config } }));
  }
}

/** The client fields compared and written directly; lists, mappers, roles and the secret are separate. */
function clientRep(cfg, want) {
  const { defaultClientScopes, optionalClientScopes, protocolMappers, serviceAccountClientRoles, secret, ...rest } = want;
  return { enabled: true, protocol: "openid-connect", ...rest, attributes: { ...(rest.attributes ?? {}), ...stamp(cfg) } };
}

/** Diffs and repairs a service account's direct client-role mappings against `want` (container -> roles). */
async function syncServiceAccountRoles(ctx, c, want) {
  const { kc } = ctx;
  const sa = await kc.call("GET", `/clients/${c.id}/service-account-user`);
  const maps = (await kc.call("GET", `/users/${sa.id}/role-mappings`)).clientMappings ?? {};
  const clients = await kc.call("GET", "/clients?max=10000");
  for (const container of new Set([...Object.keys(want), ...Object.keys(maps)])) {
    const target = clients.find((x) => x.clientId === container);
    const have = (maps[container]?.mappings ?? []).map((r) => r.name);
    const desired = want[container] ?? [];
    const all = target ? await kc.call("GET", `/clients/${target.id}/roles`) : [];
    const add = all.filter((r) => desired.includes(r.name) && !have.includes(r.name));
    const del = (maps[container]?.mappings ?? []).filter((r) => !desired.includes(r.name));
    const unknown = desired.filter((n) => !all.some((r) => r.name === n));
    if (unknown.length) throw new Refusal(`client ${c.clientId}: roles ${unknown.join(", ")} do not exist on ${container}`);
    if (add.length) await ctx.act(`client ${c.clientId}: grant ${container} ${add.map((r) => r.name).join(",")}`, () => kc.call("POST", `/users/${sa.id}/role-mappings/clients/${target.id}`, add));
    if (del.length) await ctx.act(`client ${c.clientId}: revoke ${container} ${del.map((r) => r.name).join(",")}`, () => kc.call("DELETE", `/users/${sa.id}/role-mappings/clients/${maps[container].id}`, del));
    // fullScopeAllowed is off, so a role reaches the token only through the client's scope mappings.
    // They are kept equal to the service account's roles.
    if (!target) continue;
    const scoped = await kc.call("GET", `/clients/${c.id}/scope-mappings/clients/${target.id}`);
    const sAdd = all.filter((r) => desired.includes(r.name) && !scoped.some((x) => x.name === r.name));
    const sDel = scoped.filter((r) => !desired.includes(r.name));
    if (sAdd.length) await ctx.act(`client ${c.clientId}: scope-map ${container} ${sAdd.map((r) => r.name).join(",")}`, () => kc.call("POST", `/clients/${c.id}/scope-mappings/clients/${target.id}`, sAdd));
    if (sDel.length) await ctx.act(`client ${c.clientId}: unscope-map ${container} ${sDel.map((r) => r.name).join(",")}`, () => kc.call("DELETE", `/clients/${c.id}/scope-mappings/clients/${target.id}`, sDel));
  }
}

/** Step: clients, their mappers, scope lists, secrets and service-account roles. */
async function stepClients(ctx) {
  const { kc, cfg } = ctx;
  const scopeIds = Object.fromEntries((await kc.call("GET", "/client-scopes")).map((s) => [s.name, s.id]));
  const adapterScopes = new Set((await kc.call("GET", "/client-scopes")).filter(isAdapterOwned).map((s) => s.name));
  for (const want of cfg.clients ?? []) {
    const rep = clientRep(cfg, want);
    let [have] = await kc.call("GET", `/clients?clientId=${encodeURIComponent(want.clientId)}`);
    if (!have) {
      await ctx.act(`client ${want.clientId}: create`, () => kc.call("POST", "/clients", {
        ...rep, ...(want.secret ? { secret: want.secret } : {}),
        protocolMappers: (want.protocolMappers ?? []).map((m) => ({ protocol: "openid-connect", ...m })),
        defaultClientScopes: want.defaultClientScopes ?? [], optionalClientScopes: want.optionalClientScopes ?? [],
      }));
      if (ctx.check) continue;
      [have] = await kc.call("GET", `/clients?clientId=${encodeURIComponent(want.clientId)}`);
    } else if (!owned(have.attributes) && !ctx.conflict("client", want.clientId)) continue;
    const d = diffKeys(rep, have);
    if (d.length) await ctx.act(`client ${want.clientId}: set ${d.join(",")}`, () => kc.call("PUT", `/clients/${have.id}`, { ...have, ...rep, attributes: { ...have.attributes, ...rep.attributes } }));
    if (want.secret && have.clientAuthenticatorType !== "federated-jwt") {
      const cur = await kc.call("GET", `/clients/${have.id}/client-secret`);
      if (cur?.value !== want.secret) await ctx.act(`client ${want.clientId}: set secret`, async () => kc.call("PUT", `/clients/${have.id}`, { ...(await kc.call("GET", `/clients/${have.id}`)), secret: want.secret }));
    }
    await syncMappers(ctx, `client ${want.clientId}`, `/clients/${have.id}`, want.protocolMappers ?? [], have.protocolMappers ?? []);
    for (const [kind, list] of [["default", want.defaultClientScopes], ["optional", want.optionalClientScopes]]) {
      if (!list) continue;
      const cur = (await kc.call("GET", `/clients/${have.id}/${kind}-client-scopes`)).map((s) => s.name).filter((n) => !adapterScopes.has(n));
      for (const n of list.filter((x) => !cur.includes(x))) {
        if (!scopeIds[n]) throw new Refusal(`client ${want.clientId}: ${kind} scope ${n} does not exist`);
        await ctx.act(`client ${want.clientId}: add ${kind} scope ${n}`, () => kc.call("PUT", `/clients/${have.id}/${kind}-client-scopes/${scopeIds[n]}`));
      }
      for (const n of cur.filter((x) => !list.includes(x))) await ctx.act(`client ${want.clientId}: remove ${kind} scope ${n}`, () => kc.call("DELETE", `/clients/${have.id}/${kind}-client-scopes/${scopeIds[n]}`));
    }
    if (want.serviceAccountClientRoles && (have.serviceAccountsEnabled || rep.serviceAccountsEnabled)) {
      if (ctx.check && !have.serviceAccountsEnabled) continue;
      await syncServiceAccountRoles(ctx, have, want.serviceAccountClientRoles);
    }
  }
}

/** Step: no client in the realm, whoever owns it, holds a forbidden client scope. */
async function stepForbiddenScopes(ctx) {
  const { kc, cfg } = ctx;
  const forbidden = cfg.forbiddenClientScopes ?? [];
  if (!forbidden.length) return;
  const exempt = cfg.forbiddenClientScopesExempt ?? {};
  for (const c of await kc.call("GET", "/clients?max=10000")) {
    if (exempt[c.clientId]) { ctx.exemptions.push(`${c.clientId}: ${exempt[c.clientId]}`); continue; }
    for (const kind of ["default", "optional"]) {
      for (const s of (await kc.call("GET", `/clients/${c.id}/${kind}-client-scopes`)).filter((x) => forbidden.includes(x.name))) {
        await ctx.act(`client ${c.clientId}: remove forbidden ${kind} scope ${s.name}`, () => kc.call("DELETE", `/clients/${c.id}/${kind}-client-scopes/${s.id}`));
      }
    }
  }
}

/** Step: client profiles and policies named `victual-*` match the configuration; others are kept. */
async function stepClientPolicies(ctx) {
  const { kc, cfg } = ctx;
  const want = cfg.clientPolicies ?? { profiles: [], policies: [] };
  for (const [kind, path] of [["profiles", "/client-policies/profiles"], ["policies", "/client-policies/policies"]]) {
    const cur = (await kc.call("GET", path))[kind] ?? [];
    const keep = cur.filter((p) => !p.name.startsWith("victual-"));
    const mine = cur.filter((p) => p.name.startsWith("victual-"));
    const drifted = want[kind].map((p) => p.name).filter((n) => { const h = mine.find((x) => x.name === n); return !h || diffKeys(want[kind].find((x) => x.name === n), h).length; });
    const extra = mine.filter((p) => !want[kind].some((w) => w.name === p.name)).map((p) => p.name);
    if (drifted.length || extra.length) {
      await ctx.act(`client ${kind}: set ${[...drifted, ...extra.map((n) => `-${n}`)].join(",")}`, () => kc.call("PUT", path, { [kind]: [...keep, ...want[kind]] }));
    }
  }
}

/** Step: the anonymous client-registration policies Keycloak creates have the declared configuration. */
async function stepRegistrationPolicies(ctx) {
  const { kc, cfg } = ctx;
  const type = "org.keycloak.services.clientregistration.policy.ClientRegistrationPolicy";
  const comps = await kc.call("GET", `/components?type=${type}`);
  const realm = await kc.call("GET", "");
  for (const want of cfg.clientRegistrationPolicies ?? []) {
    const have = comps.find((c) => c.name === want.name && c.subType === want.subType);
    if (!have) { await ctx.act(`registration policy ${want.subType}/${want.name}: create`, () => kc.call("POST", "/components", { ...want, providerType: type, parentId: realm.id })); continue; }
    const d = Object.keys(want.config).filter((k) => setKey(want.config[k]) !== setKey(have.config?.[k]));
    if (have.providerId !== want.providerId) d.push("providerId");
    if (d.length) await ctx.act(`registration policy ${want.subType}/${want.name}: set ${d.join(",")}`, () => kc.call("PUT", `/components/${have.id}`, { ...have, providerId: want.providerId, config: { ...have.config, ...want.config } }));
  }
}

/** Step: owned user-profile attributes; other attributes, and every user's values, are left alone. */
async function stepUserProfile(ctx) {
  const { kc, cfg } = ctx;
  const up = await kc.call("GET", "/users/profile");
  const want = (cfg.userProfile?.attributes ?? []).map((a) => ({ ...a, annotations: { ...(a.annotations ?? {}), [OWNER_ATTR]: OWNER } }));
  const changes = [];
  let attrs = [...up.attributes];
  for (const w of want) {
    const i = attrs.findIndex((a) => a.name === w.name);
    if (i < 0) { attrs.push(w); changes.push(`+${w.name}`); continue; }
    if (attrs[i].annotations?.[OWNER_ATTR] !== OWNER && !ctx.conflict("user-profile attribute", w.name)) continue;
    if (diffKeys(w, attrs[i]).length) { attrs[i] = { ...attrs[i], ...w }; changes.push(w.name); }
  }
  const stale = attrs.filter((a) => a.annotations?.[OWNER_ATTR] === OWNER && !want.some((w) => w.name === a.name)).map((a) => a.name);
  attrs = attrs.filter((a) => !stale.includes(a.name));
  changes.push(...stale.map((n) => `-${n}`));
  if (changes.length) await ctx.act(`user profile: ${changes.join(",")}`, () => kc.call("PUT", "/users/profile", { ...up, attributes: attrs }));
}

/** Step: objects the registration adapter generated are brought to its pinned recipe. */
async function stepAdapter(ctx, configFile) {
  const ra = ctx.cfg.registrationAdapter;
  if (!ra) return;
  const recipe = loadRecipe(resolve(dirname(configFile), ra.recipe));
  try {
    const acts = await reconcileAdapter(ctx.kc, recipe, { sidecarClientId: ra.sidecarClientId, check: ctx.check, forbidden: ctx.cfg.forbiddenClientScopes ?? [] });
    ctx.actions.push(...acts);
    for (const a of acts) process.stderr.write(`${ctx.check ? "drift" : "apply"}: ${a}\n`);
  } catch (e) { if (/forbidden client scopes/.test(e.message)) throw new Refusal(e.message); throw e; }
}

/** Step: owned objects the configuration no longer declares are deleted. */
async function stepPrune(ctx) {
  const { kc, cfg } = ctx;
  /** The set of values of key `k` across list `l`. */
  const names = (l, k) => new Set((l ?? []).map((x) => x[k]));
  const clients = names(cfg.clients, "clientId"), scopes = names(cfg.clientScopes, "name"), roles = names(cfg.realmRoles, "name");
  const idps = names(cfg.identityProviders, "alias"), flows = names(cfg.authenticationFlows, "alias");
  for (const c of (await kc.call("GET", "/clients?max=10000")).filter((x) => owned(x.attributes) && !clients.has(x.clientId))) {
    await ctx.act(`client ${c.clientId}: delete (no longer declared)`, () => kc.call("DELETE", `/clients/${c.id}`));
  }
  for (const s of (await kc.call("GET", "/client-scopes")).filter((x) => owned(x.attributes) && !scopes.has(x.name))) {
    await ctx.act(`client scope ${s.name}: delete (no longer declared)`, () => kc.call("DELETE", `/client-scopes/${s.id}`));
  }
  for (const r of (await kc.call("GET", "/roles?briefRepresentation=false&max=10000")).filter((x) => x.attributes?.[OWNER_ATTR]?.[0] === OWNER && !roles.has(x.name))) {
    await ctx.act(`role ${r.name}: delete (no longer declared)`, () => kc.call("DELETE", `/roles-by-id/${r.id}`));
  }
  for (const i of (await kc.call("GET", "/identity-provider/instances")).filter((x) => owned(x.config) && !idps.has(x.alias))) {
    await ctx.act(`identity provider ${i.alias}: delete (no longer declared)`, () => kc.call("DELETE", `/identity-provider/instances/${encodeURIComponent(i.alias)}`));
  }
  for (const f of (await kc.call("GET", "/authentication/flows")).filter((x) => x.description?.endsWith(FLOW_MARK) && !flows.has(x.alias))) {
    await ctx.act(`flow ${f.alias}: delete (no longer declared${f.alias.endsWith(NEXT) ? "; left by an interrupted rebuild" : ""})`, () => deleteFlow(kc, f.alias));
  }
}

/** Step: the realm records which configuration version and digest it was last brought to. */
async function stepStamp(ctx) {
  const { kc, cfg, loaded } = ctx;
  const realm = await kc.call("GET", "");
  const want = { [VERSION_ATTR]: String(cfg.configVersion), "victual.config-digest": loaded.digest };
  const d = diffKeys(want, realm.attributes ?? {});
  if (d.length) await ctx.act(`realm: record ${d.join(",")}`, () => kc.call("PUT", "", { attributes: { ...(realm.attributes ?? {}), ...want } }));
}

/** Runs every step once, in dependency order. */
async function pass(ctx, configFile) {
  if (!(await stepRealm(ctx))) return;
  await stepRoles(ctx);
  await stepClientScopes(ctx);
  await stepFlows(ctx);
  await stepIdentityProviders(ctx);
  await stepClients(ctx);
  await stepAdapter(ctx, configFile);
  await stepForbiddenScopes(ctx);
  await stepClientPolicies(ctx);
  await stepRegistrationPolicies(ctx);
  await stepUserProfile(ctx);
  await stepPrune(ctx);
  await stepStamp(ctx);
}

/**
 * Reconciles the realm. `opts`: { config, check, adopt, allowVersionMismatch, env }. Returns
 * { code, report }. Never throws for an expected refusal; the code carries it.
 */
export async function reconcile(opts = {}) {
  const env = opts.env ?? process.env;
  const configFile = resolve(opts.config ?? resolve(HERE, "realm.json"));
  const report = { mode: opts.check ? "check" : "apply", config: configFile, actions: [], conflicts: [], notApplied: [] };
  let loaded;
  try { loaded = loadConfig(configFile, env); } catch (e) {
    if (e instanceof ConfigError) return { code: 1, report: { ...report, error: `configuration refused: ${e.message}` } };
    throw e;
  }
  Object.assign(report, { realm: loaded.config.realm.realm, configVersion: loaded.config.configVersion, digest: loaded.digest, included: loaded.included });
  const kc = adminClient(loaded.config.realm.realm, env);
  try {
    report.keycloak = await kc.serverVersion();
    if (report.keycloak !== loaded.config.keycloakVersion && !opts.allowVersionMismatch) {
      return { code: 4, report: { ...report, error: `Keycloak is ${report.keycloak}; the configuration is pinned to ${loaded.config.keycloakVersion}` } };
    }
    const ctx = context(kc, loaded, opts);
    // A conflict or check stops before any write: run a check pass first, then apply.
    const pre = context(kc, loaded, { ...opts, check: true });
    await pass(pre, configFile);
    if (pre.conflicts.length) return { code: 4, report: { ...report, actions: pre.actions, conflicts: pre.conflicts, error: "refused: unowned objects hold declared names (use --adopt to take them over)" } };
    if (opts.check) return { code: pre.actions.length ? 2 : 0, report: { ...report, actions: pre.actions } };
    await pass(ctx, configFile);
    report.actions = ctx.actions;
    const post = context(kc, loaded, { ...opts, check: true });
    await pass(post, configFile);
    report.notApplied = post.actions;
    report.exemptions = post.exemptions;
    return { code: post.actions.length ? 3 : 0, report };
  } catch (e) {
    const msg = redact(e.message, loaded.secrets);
    if (e instanceof Refusal) return { code: 4, report: { ...report, error: `refused: ${msg}` } };
    if (e instanceof AdminError) return { code: 1, report: { ...report, error: `Keycloak refused a write: ${msg}` } };
    throw e;
  }
}

/** Parses the command line and runs reconcile(). */
async function main(argv) {
  const opts = { check: argv.includes("--check"), adopt: argv.includes("--adopt"), allowVersionMismatch: argv.includes("--allow-version-mismatch") };
  const i = argv.indexOf("--config");
  if (i >= 0) opts.config = argv[i + 1];
  const { code, report } = await reconcile(opts);
  process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
  process.exitCode = code;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) await main(process.argv.slice(2));
