// Simulation of Victual's registration adapter (ADR-0055 decision 8) for Keycloak. The adapter
// creates one MCP client through the admin REST API and, in the same step:
//   1. binds the client to the dedicated MCP resource audience: the client scope
//      `victual-mcp-audience` is a DEFAULT scope of the client (prerequisite 11);
//   2. creates a per-grant client scope `victual-grant:<client id>` whose Hardcoded claim mapper
//      writes `victual_client` = the client id, makes it a default scope of the client, and an
//      optional scope of the sidecar, so the sidecar can ask for it at the exchange (K1);
//   3. turns Consent required on, so the user's grant is a stored consent that can be revoked
//      for this client alone;
//   4. stamps the client and the grant scope with ownership and recipe-version attributes
//      (question 18, prerequisite 8), so `reconcile()` can find what it owns, diff it against the
//      recipe, repair drift, upgrade it to a newer recipe, and remove its own orphans.
// The recipe is pinned here: RECIPES holds every version the adapter can build, RECIPE_VERSION is
// the one it builds and reconciles to. Used by setup.mjs (the two fixed K1 clients) and probe.mjs
// (the K2 and K4 clients). Not Victual code.
import { admin, REDIRECT } from "./lib.mjs";

export const GRANT_SCOPE_PREFIX = "victual-grant:";
export const GRANT_CLAIM = "victual_client";
// Ownership and recipe attributes, written to the `attributes` map of clients and client scopes.
export const OWNER = "victual-registration-adapter";
export const OWNER_ATTR = "victual.owner";
export const RECIPE_ATTR = "victual.recipe";
export const GRANT_CLIENT_ATTR = "victual.grant-client"; // on a grant scope: the client it belongs to
export const RECIPE_VERSION = 2;
const BASE_DEFAULT = ["basic", "acr", "profile", "email"];
const BUNDLES = ["victual:read", "victual:write", "victual:stock", "victual:stock:book"];

// Recipe versions. Version 2 (2026-10-28) is what setup.mjs builds and K1 and K2 test: the grant
// mapper flagged `lightweight.claim` (K1h) and a client description. Version 1 is the same recipe
// without the lightweight flag and the description. It is synthetic: it exists so the K4 upgrade case
// has a mapper setting and a client field to change. (A first draft of version 2 changed
// `userinfo.token.claim`; Keycloak already stores `false` there by default, so it changed nothing.)
export const RECIPES = {
  1: { mapperExtra: { "lightweight.claim": "false" }, description: undefined },
  2: { mapperExtra: { "lightweight.claim": "true" }, description: "MCP client registered by Victual" },
};

/** Maps client-scope names to ids for the realm. */
const scopeIds = async () => Object.fromEntries((await admin("GET", "/client-scopes")).map((s) => [s.name, s.id]));

/** The ownership attributes for an object built from recipe `version`. */
const stamp = (version) => ({ [OWNER_ATTR]: OWNER, [RECIPE_ATTR]: String(version) });

/** True when an object's `attributes` say this adapter owns it. */
export const isOwned = (o) => o?.attributes?.[OWNER_ATTR] === OWNER;

/** The Hardcoded claim mapper of a grant scope, as recipe `version` builds it. */
export function grantMapper(clientId, version = RECIPE_VERSION) {
  return {
    name: "victual-grant-client", protocol: "openid-connect", protocolMapper: "oidc-hardcoded-claim-mapper",
    config: { "claim.name": GRANT_CLAIM, "claim.value": clientId, "jsonType.label": "String",
      "access.token.claim": "true", "id.token.claim": "false", "introspection.token.claim": "true",
      ...RECIPES[version].mapperExtra },
  };
}

/** The grant client scope for `clientId`, without its mapper, as recipe `version` builds it. */
export function grantScopeRep(clientId, version = RECIPE_VERSION) {
  return {
    name: `${GRANT_SCOPE_PREFIX}${clientId}`, protocol: "openid-connect",
    attributes: { "include.in.token.scope": "true", "display.on.consent.screen": "false", ...stamp(version), [GRANT_CLIENT_ATTR]: clientId },
  };
}

/** The fields of an MCP client that the recipe fixes (everything except the per-registration redirect URIs). */
export function clientFields(version = RECIPE_VERSION) {
  const f = {
    enabled: true, protocol: "openid-connect", publicClient: true,
    standardFlowEnabled: true, directAccessGrantsEnabled: false, implicitFlowEnabled: false, serviceAccountsEnabled: false,
    fullScopeAllowed: false, consentRequired: true,
    attributes: { "pkce.code.challenge.method": "S256", ...stamp(version) },
  };
  if (RECIPES[version].description) f.description = RECIPES[version].description;
  return f;
}

/** The default and optional client scopes of an MCP client under the recipe. */
export const scopeLists = (clientId) => ({
  defaults: [...BASE_DEFAULT, "victual-mcp-audience", "victual-groups", `${GRANT_SCOPE_PREFIX}${clientId}`],
  // offline_access lets the K1 cases test an offline grant; ADR-0055 says servers should not list it,
  // a client may still ask for it.
  optional: [...BUNDLES, "offline_access"],
});

/** Creates the grant scope with its mapper and returns its id. `owned` false leaves out the ownership stamp (fixtures). */
async function createGrantScope(clientId, version, owned = true) {
  const rep = grantScopeRep(clientId, version);
  if (!owned) for (const k of [OWNER_ATTR, RECIPE_ATTR, GRANT_CLIENT_ATTR]) delete rep.attributes[k];
  await admin("POST", "/client-scopes", { ...rep, protocolMappers: [grantMapper(clientId, version)] });
  return (await scopeIds())[`${GRANT_SCOPE_PREFIX}${clientId}`];
}

/**
 * Registers one MCP client. Returns the descriptor the probe uses: { clientId, id, public, redirect,
 * grantScope, grantScopeId, recipe }. The options other than `redirect`, `sidecarId` and `version`
 * build deliberate deviations for negative cases; such a client is a fixture, not a recipe object,
 * and carries no ownership stamp, so reconcile() never sees it.
 */
export async function registerMcpClient(clientId, { redirect = REDIRECT, sidecarId, consentRequired = true, bindAudience = true, grantScope = true, version = RECIPE_VERSION } = {}) {
  const ids = await scopeIds();
  const recipeBuilt = consentRequired && bindAudience && grantScope;
  const grantName = `${GRANT_SCOPE_PREFIX}${clientId}`;
  let grantId;
  if (grantScope) grantId = await createGrantScope(clientId, version, recipeBuilt);
  const fields = clientFields(version);
  if (!recipeBuilt) for (const k of [OWNER_ATTR, RECIPE_ATTR]) delete fields.attributes[k];
  const lists = scopeLists(clientId);
  await admin("POST", "/clients", {
    clientId, ...fields, consentRequired, redirectUris: [redirect],
    defaultClientScopes: lists.defaults.filter((n) => (bindAudience || n !== "victual-mcp-audience") && (grantScope || n !== grantName)),
    optionalClientScopes: lists.optional.filter((n) => ids[n]),
  });
  const [c] = await admin("GET", `/clients?clientId=${encodeURIComponent(clientId)}`);
  if (grantScope && sidecarId) await admin("PUT", `/clients/${sidecarId}/optional-client-scopes/${grantId}`);
  return { key: clientId, clientId, id: c.id, public: true, redirect, grantScope: grantScope ? grantName : null, grantScopeId: grantId, recipe: recipeBuilt ? version : null };
}

/** Removes a client and its per-grant scope (the adapter's delete path). */
export async function deregisterMcpClient(c) {
  await admin("DELETE", `/clients/${c.id}`, undefined, { raw: true });
  if (c.grantScopeId) await admin("DELETE", `/client-scopes/${c.grantScopeId}`, undefined, { raw: true });
}

/**
 * Lists the clients and client scopes this adapter owns. Clients are first asked for with the admin
 * API's attribute query (`q=victual.owner:<owner>`). Keycloak 26.8.1 refuses that query for an
 * attribute that is not configured as searchable (400 "not allowed for search"); the adapter then
 * lists every client and filters on the attribute. Client scopes have no query parameter, so all are
 * listed and filtered. `query` reports what the attribute query returned.
 */
export async function listOwned() {
  const r = await admin("GET", `/clients?q=${encodeURIComponent(`${OWNER_ATTR}:${OWNER}`)}&max=1000`, undefined, { raw: true });
  let all, query;
  if (r.ok) { all = await r.json(); query = `${r.status}, ${all.length} clients`; }
  else { query = `${r.status} ${(await r.text()).slice(0, 120)}`; all = await admin("GET", "/clients?max=1000"); }
  const clients = all.filter(isOwned);
  const scopes = (await admin("GET", "/client-scopes")).filter(isOwned);
  return { clients, scopes, query };
}

/**
 * Brings every object the adapter owns to recipe `version`: repairs drift in client fields, scope
 * lists, grant scopes and their mappers, the sidecar's optional-scope link and the recipe stamp;
 * recreates a missing grant scope; deletes owned grant scopes whose client no longer exists. Never
 * touches an object without the ownership attribute. Returns the list of changes it made; a second
 * run with nothing drifted returns an empty list.
 */
export async function reconcile({ sidecarId, version = RECIPE_VERSION } = {}) {
  const actions = [];
  const { clients, scopes } = await listOwned();
  const ids = await scopeIds();
  const sidecarOptional = sidecarId ? (await admin("GET", `/clients/${sidecarId}/optional-client-scopes`)).map((s) => s.name) : [];
  const ownedClientIds = new Set(clients.map((c) => c.clientId));
  for (const listed of clients) {
    const c = await admin("GET", `/clients/${listed.id}`);
    const want = clientFields(version);
    // Client fields and attributes the recipe fixes.
    const diffs = Object.keys(want).filter((k) => k !== "attributes" && c[k] !== want[k])
      .concat(Object.keys(want.attributes).filter((k) => c.attributes?.[k] !== want.attributes[k]).map((k) => `attributes.${k}`));
    if (diffs.length) {
      await admin("PUT", `/clients/${c.id}`, { ...c, ...want, attributes: { ...c.attributes, ...want.attributes } });
      actions.push(`client ${c.clientId}: set ${diffs.join(",")}`);
    }
    // The recipe puts no mapper on the client itself (its dedicated scope); remove any that appeared.
    for (const m of c.protocolMappers ?? []) {
      await admin("DELETE", `/clients/${c.id}/protocol-mappers/models/${m.id}`);
      actions.push(`client ${c.clientId}: removed client mapper ${m.name}`);
    }
    // The grant scope and its mapper.
    let gs = scopes.find((s) => s.attributes?.[GRANT_CLIENT_ATTR] === c.clientId);
    if (!gs) {
      const id = await createGrantScope(c.clientId, version);
      ids[`${GRANT_SCOPE_PREFIX}${c.clientId}`] = id;
      gs = { id, name: `${GRANT_SCOPE_PREFIX}${c.clientId}` };
      actions.push(`scope ${gs.name}: created (missing)`);
    } else {
      const wantScope = grantScopeRep(c.clientId, version);
      const sdiff = Object.keys(wantScope.attributes).filter((k) => gs.attributes?.[k] !== wantScope.attributes[k]);
      if (gs.name !== wantScope.name || sdiff.length) {
        await admin("PUT", `/client-scopes/${gs.id}`, { ...gs, name: wantScope.name, attributes: { ...gs.attributes, ...wantScope.attributes } });
        actions.push(`scope ${wantScope.name}: set ${[...(gs.name !== wantScope.name ? ["name"] : []), ...sdiff.map((k) => `attributes.${k}`)].join(",")}`);
      }
      const wantMapper = grantMapper(c.clientId, version);
      const mappers = await admin("GET", `/client-scopes/${gs.id}/protocol-mappers/models`);
      const keep = mappers.find((m) => m.name === wantMapper.name && m.protocolMapper === wantMapper.protocolMapper);
      for (const m of mappers.filter((x) => x !== keep)) {
        await admin("DELETE", `/client-scopes/${gs.id}/protocol-mappers/models/${m.id}`);
        actions.push(`scope ${wantScope.name}: removed mapper ${m.name}`);
      }
      if (!keep) {
        await admin("POST", `/client-scopes/${gs.id}/protocol-mappers/models`, wantMapper);
        actions.push(`scope ${wantScope.name}: created mapper ${wantMapper.name}`);
      } else {
        // Compare the keys the recipe sets. Keycloak adds defaults of its own (userinfo.token.claim=false)
        // to a stored mapper; those are not drift.
        const changed = Object.keys(wantMapper.config).filter((k) => keep.config[k] !== wantMapper.config[k]);
        if (changed.length) {
          await admin("PUT", `/client-scopes/${gs.id}/protocol-mappers/models/${keep.id}`, { ...keep, config: { ...keep.config, ...wantMapper.config } });
          actions.push(`scope ${wantScope.name}: mapper config ${changed.join(",")}`);
        }
      }
    }
    // Default and optional scope lists: add what is missing, remove what the recipe does not list.
    const lists = scopeLists(c.clientId);
    for (const [kind, want2] of [["default", lists.defaults], ["optional", lists.optional.filter((n) => ids[n])]]) {
      const have = (await admin("GET", `/clients/${c.id}/${kind}-client-scopes`)).map((s) => s.name);
      for (const n of want2.filter((x) => !have.includes(x))) {
        await admin("PUT", `/clients/${c.id}/${kind}-client-scopes/${ids[n]}`);
        actions.push(`client ${c.clientId}: added ${kind} scope ${n}`);
      }
      for (const n of have.filter((x) => !want2.includes(x))) {
        await admin("DELETE", `/clients/${c.id}/${kind}-client-scopes/${ids[n]}`);
        actions.push(`client ${c.clientId}: removed ${kind} scope ${n}`);
      }
    }
    // The sidecar must be able to ask for the grant scope at the exchange.
    if (sidecarId && !sidecarOptional.includes(gs.name)) {
      await admin("PUT", `/clients/${sidecarId}/optional-client-scopes/${gs.id}`);
      actions.push(`sidecar: added optional scope ${gs.name}`);
    }
  }
  // Orphans: owned grant scopes whose client is gone (or no longer owned by the adapter).
  for (const s of scopes.filter((x) => x.attributes?.[GRANT_CLIENT_ATTR] !== undefined && !ownedClientIds.has(x.attributes[GRANT_CLIENT_ATTR]))) {
    await admin("DELETE", `/client-scopes/${s.id}`);
    actions.push(`scope ${s.name}: deleted (orphan; client ${s.attributes[GRANT_CLIENT_ATTR]} absent)`);
  }
  return actions;
}
