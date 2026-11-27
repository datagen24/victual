// Victual's registration adapter for Keycloak (ADR-0055 decision 8), as a pinned recipe.
//
// The adapter creates one MCP client per dynamic registration through the admin REST API, and with
// it a per-grant client scope whose hardcoded mapper names the client in exchanged tokens (ADR-0055
// prerequisite 10). Every object it creates carries `victual.owner = victual-registration-adapter`
// and `victual.recipe = <version>` in its attributes, so `reconcile()` can find what it owns, bring it
// to the recipe's current version, and delete its own orphans without touching anything else
// (ADR-0055 question 18). The runtime registrations themselves are not in Git; the recipe is.
//
// This is the reference implementation of the recipe for the realm reconciler and the harness.
// Victual's own adapter, when it is built, implements the same recipe file.
import { readFileSync } from "node:fs";

export const OWNER = "victual-registration-adapter";
export const OWNER_ATTR = "victual.owner";
export const RECIPE_ATTR = "victual.recipe";
export const GRANT_CLIENT_ATTR = "victual.grant-client";

/** Loads the recipe file and checks that the version it builds exists. */
export function loadRecipe(file) {
  const r = JSON.parse(readFileSync(file, "utf8"));
  if (!r.versions?.[String(r.version)]) throw new Error(`${file}: recipe version ${r.version} is not defined`);
  return r;
}

/** True when an object's attributes say the adapter owns it. */
export const isAdapterOwned = (o) => o?.attributes?.[OWNER_ATTR] === OWNER;

/** The ownership stamp for recipe `version`. */
const stamp = (version) => ({ [OWNER_ATTR]: OWNER, [RECIPE_ATTR]: String(version) });

/** Maps client-scope names to ids. */
const scopeIds = async (kc) => Object.fromEntries((await kc.call("GET", "/client-scopes")).map((s) => [s.name, s.id]));

/** The grant scope's hardcoded-claim mapper for `clientId` under recipe `version`. */
export function grantMapper(recipe, clientId, version = recipe.version) {
  return {
    name: "victual-grant-client", protocol: "openid-connect", protocolMapper: "oidc-hardcoded-claim-mapper",
    config: { "claim.name": recipe.grantClaim, "claim.value": clientId, "jsonType.label": "String",
      "access.token.claim": "true", "id.token.claim": "false", "introspection.token.claim": "true",
      ...recipe.versions[version].mapperExtra },
  };
}

/** The grant scope for `clientId`, without its mapper. */
export function grantScopeRep(recipe, clientId, version = recipe.version) {
  return {
    name: `${recipe.grantScopePrefix}${clientId}`, protocol: "openid-connect",
    attributes: { "include.in.token.scope": "true", "display.on.consent.screen": "false", ...stamp(version), [GRANT_CLIENT_ATTR]: clientId },
  };
}

/** The client fields the recipe fixes; redirect URIs are per registration and not included. */
export function clientFields(recipe, version = recipe.version) {
  return {
    enabled: true, protocol: "openid-connect", publicClient: true, description: recipe.versions[version].description,
    standardFlowEnabled: true, directAccessGrantsEnabled: false, implicitFlowEnabled: false, serviceAccountsEnabled: false,
    fullScopeAllowed: false, consentRequired: true,
    attributes: { "pkce.code.challenge.method": "S256", ...stamp(version) },
  };
}

/** The default and optional client-scope names of an MCP client under recipe `version`. */
export function scopeLists(recipe, clientId, version = recipe.version) {
  const v = recipe.versions[version];
  return { defaults: [...v.defaultScopes, `${recipe.grantScopePrefix}${clientId}`], optional: [...v.optionalScopes] };
}

/** Registers one MCP client under recipe `version`; returns { clientId, id, grantScopeId }. */
export async function registerMcpClient(kc, recipe, clientId, { redirect, sidecarId, version = recipe.version }) {
  const g = grantScopeRep(recipe, clientId, version);
  await kc.call("POST", "/client-scopes", { ...g, protocolMappers: [grantMapper(recipe, clientId, version)] });
  const ids = await scopeIds(kc);
  const lists = scopeLists(recipe, clientId, version);
  await kc.call("POST", "/clients", {
    clientId, ...clientFields(recipe, version), redirectUris: [redirect],
    defaultClientScopes: lists.defaults, optionalClientScopes: lists.optional.filter((n) => ids[n]),
  });
  const [c] = await kc.call("GET", `/clients?clientId=${encodeURIComponent(clientId)}`);
  if (sidecarId) await kc.call("PUT", `/clients/${sidecarId}/optional-client-scopes/${ids[g.name]}`);
  return { clientId, id: c.id, grantScopeId: ids[g.name] };
}

/** Deletes a registered client and its grant scope. */
export async function deregisterMcpClient(kc, c) {
  await kc.call("DELETE", `/clients/${c.id}`, undefined, { allow: [404] });
  if (c.grantScopeId) await kc.call("DELETE", `/client-scopes/${c.grantScopeId}`, undefined, { allow: [404] });
}

/**
 * Brings every adapter-owned client and grant scope to the recipe's current version and deletes owned
 * grant scopes whose client is gone. With `check` set it only reports. Returns the list of changes.
 * `forbidden` names client scopes the realm forbids; a recipe that lists one is refused, because the
 * realm reconciler would remove the scope again on every run.
 */
export async function reconcileAdapter(kc, recipe, { sidecarClientId, check = false, forbidden = [] } = {}) {
  const v = recipe.version;
  const clash = recipe.versions[v].defaultScopes.concat(recipe.versions[v].optionalScopes).filter((n) => forbidden.includes(n));
  if (clash.length) throw new Error(`adapter recipe ${v} lists forbidden client scopes: ${clash.join(", ")}`);
  const actions = [];
  /** Records a change and performs it unless in check mode. */
  const act = async (msg, fn) => { actions.push(msg); if (!check) await fn(); };
  const all = await kc.call("GET", "/clients?max=10000");
  const clients = all.filter(isAdapterOwned);
  const scopes = (await kc.call("GET", "/client-scopes")).filter(isAdapterOwned);
  const ids = await scopeIds(kc);
  const sidecar = all.find((c) => c.clientId === sidecarClientId);
  const sidecarOptional = sidecar ? (await kc.call("GET", `/clients/${sidecar.id}/optional-client-scopes`)).map((s) => s.name) : [];
  const owned = new Set(clients.map((c) => c.clientId));
  for (const listed of clients) {
    const c = await kc.call("GET", `/clients/${listed.id}`);
    const want = clientFields(recipe);
    const diffs = Object.keys(want).filter((k) => k !== "attributes" && c[k] !== want[k])
      .concat(Object.keys(want.attributes).filter((k) => c.attributes?.[k] !== want.attributes[k]).map((k) => `attributes.${k}`));
    if (diffs.length) await act(`adapter client ${c.clientId}: set ${diffs.join(",")}`, () => kc.call("PUT", `/clients/${c.id}`, { ...c, ...want, attributes: { ...c.attributes, ...want.attributes } }));
    for (const m of c.protocolMappers ?? []) await act(`adapter client ${c.clientId}: remove client mapper ${m.name}`, () => kc.call("DELETE", `/clients/${c.id}/protocol-mappers/models/${m.id}`));
    let gs = scopes.find((s) => s.attributes?.[GRANT_CLIENT_ATTR] === c.clientId);
    const wantScope = grantScopeRep(recipe, c.clientId);
    const wantMapper = grantMapper(recipe, c.clientId);
    if (!gs) {
      await act(`adapter scope ${wantScope.name}: create (missing)`, async () => {
        await kc.call("POST", "/client-scopes", { ...wantScope, protocolMappers: [wantMapper] });
        ids[wantScope.name] = (await scopeIds(kc))[wantScope.name];
      });
      gs = { id: ids[wantScope.name], name: wantScope.name };
    } else {
      const sdiff = Object.keys(wantScope.attributes).filter((k) => gs.attributes?.[k] !== wantScope.attributes[k]);
      if (gs.name !== wantScope.name || sdiff.length) await act(`adapter scope ${wantScope.name}: set ${[gs.name !== wantScope.name ? "name" : null, ...sdiff].filter(Boolean).join(",")}`,
        () => kc.call("PUT", `/client-scopes/${gs.id}`, { ...gs, name: wantScope.name, attributes: { ...gs.attributes, ...wantScope.attributes } }));
      const mappers = await kc.call("GET", `/client-scopes/${gs.id}/protocol-mappers/models`);
      const keep = mappers.find((m) => m.name === wantMapper.name && m.protocolMapper === wantMapper.protocolMapper);
      for (const m of mappers.filter((x) => x !== keep)) await act(`adapter scope ${wantScope.name}: remove mapper ${m.name}`, () => kc.call("DELETE", `/client-scopes/${gs.id}/protocol-mappers/models/${m.id}`));
      if (!keep) await act(`adapter scope ${wantScope.name}: create mapper`, () => kc.call("POST", `/client-scopes/${gs.id}/protocol-mappers/models`, wantMapper));
      else {
        const changed = Object.keys(wantMapper.config).filter((k) => keep.config[k] !== wantMapper.config[k]);
        if (changed.length) await act(`adapter scope ${wantScope.name}: mapper ${changed.join(",")}`,
          () => kc.call("PUT", `/client-scopes/${gs.id}/protocol-mappers/models/${keep.id}`, { ...keep, config: { ...keep.config, ...wantMapper.config } }));
      }
    }
    const lists = scopeLists(recipe, c.clientId);
    for (const [kind, want2] of [["default", lists.defaults], ["optional", lists.optional]]) {
      const have = (await kc.call("GET", `/clients/${c.id}/${kind}-client-scopes`)).map((s) => s.name);
      for (const n of want2.filter((x) => !have.includes(x) && ids[x])) await act(`adapter client ${c.clientId}: add ${kind} scope ${n}`, () => kc.call("PUT", `/clients/${c.id}/${kind}-client-scopes/${ids[n]}`));
      for (const n of have.filter((x) => !want2.includes(x))) await act(`adapter client ${c.clientId}: remove ${kind} scope ${n}`, () => kc.call("DELETE", `/clients/${c.id}/${kind}-client-scopes/${ids[n]}`));
    }
    if (sidecar && !sidecarOptional.includes(gs.name)) await act(`sidecar: add optional scope ${gs.name}`, () => kc.call("PUT", `/clients/${sidecar.id}/optional-client-scopes/${ids[gs.name]}`));
  }
  for (const s of scopes.filter((x) => x.attributes?.[GRANT_CLIENT_ATTR] !== undefined && !owned.has(x.attributes[GRANT_CLIENT_ATTR]))) {
    await act(`adapter scope ${s.name}: delete (orphan; client ${s.attributes[GRANT_CLIENT_ATTR]} absent)`, () => kc.call("DELETE", `/client-scopes/${s.id}`));
  }
  return actions;
}
