// Create the spike realm through the admin REST API (bootstrap admin). Idempotent: an existing
// realm named `victual` is deleted and rebuilt. Writes state.json for probe.mjs.
// Every secret is a spike-only value for an instance on 127.0.0.1 with no real data.
// Nothing here enables a Preview feature: the preview-only cases are in probe.mjs --preview.
import { writeFileSync } from "node:fs";
import { admin, ISSUER, REALM, REDIRECT } from "./lib.mjs";

const MCP_AUD = "http://localhost:3000/mcp"; // the sidecar's canonical resource URI
const API_AUD = "http://localhost:8080/api"; // Victual /api canonical resource URI
const OTHER_AUD = "http://localhost:9999/other";
const NAMES = {
  mcp: "victual-spike-mcp-client",
  sidecar: "victual-spike-sidecar",
  uriSidecar: MCP_AUD, // a sidecar whose client id is its resource URI
  api: API_AUD, // the /api resource as a client: Keycloak's `audience` parameter names clients
  other: "victual-spike-other",
  rogue: "victual-spike-rogue-exchanger",
  noExchange: "victual-spike-no-exchange-grant",
  short: "victual-spike-mcp-short",
  plain: "victual-spike-default-scopes",
  consent: "victual-spike-mcp-consent",
};
const secretOf = (name) => `spike-only-${name.replace(/[^a-z]+/g, "-")}`;

await admin("DELETE", `/${REALM}`, undefined, { realm: "", raw: true }); // 204, or 404 on a cold start
await admin("POST", "", {
  realm: REALM, enabled: true,
  accessTokenLifespan: 300, // five-minute access tokens (ADR-0055 decision 6)
  ssoSessionIdleTimeout: 1800, ssoSessionMaxLifespan: 36000,
  revokeRefreshToken: true, refreshTokenMaxReuse: 0, // rotation with reuse detection
  registrationAllowed: false, verifyEmail: false, loginWithEmailAllowed: true,
}, { realm: "" });

// --- client scopes
const aud = (name, { client, custom }) => ({
  name, protocol: "openid-connect", protocolMapper: "oidc-audience-mapper",
  config: {
    ...(client ? { "included.client.audience": client } : {}), ...(custom ? { "included.custom.audience": custom } : {}),
    "access.token.claim": "true", "id.token.claim": "false", "introspection.token.claim": "true", "lightweight.claim": "false",
  },
});
async function scope(name, mappers = [], includeInScope = true) {
  await admin("POST", "/client-scopes", {
    name, protocol: "openid-connect", protocolMappers: mappers,
    attributes: { "include.in.token.scope": String(includeInScope), "display.on.consent.screen": "false" },
  });
  return (await admin("GET", "/client-scopes")).find((s) => s.name === name).id;
}
const scopes = {};
for (const n of ["victual:read", "victual:write", "victual:stock", "victual:stock:book"]) scopes[n] = await scope(n);
scopes.mcpAudience = await scope("victual-mcp-audience", [
  aud("mcp-resource-audience", { custom: MCP_AUD }),
  aud("sidecar-client-audience", { client: NAMES.sidecar }), // standard token exchange needs the requester in aud
], false);
scopes.rogueAudience = await scope("victual-rogue-audience", [aud("rogue-client-audience", { client: NAMES.rogue })], false);
scopes.apiAudience = await scope("victual-api-audience", [aud("api-resource-audience", { client: API_AUD })], false);
scopes.otherAudience = await scope("victual-other-audience", [aud("other-resource-audience", { custom: OTHER_AUD })], false);
scopes.groups = await scope("victual-groups", [{
  name: "groups", protocol: "openid-connect", protocolMapper: "oidc-group-membership-mapper",
  config: { "claim.name": "groups", "full.path": "false", "access.token.claim": "true", "id.token.claim": "false", "introspection.token.claim": "true" },
}], false);

// --- clients
const BASE_DEFAULT = ["basic", "acr", "profile", "email"];
async function client(key, { publicClient = false, extra = {}, attributes = {}, defaults = [], optional = [], raw = false } = {}) {
  const clientId = NAMES[key];
  const body = {
    clientId, enabled: true, protocol: "openid-connect", publicClient,
    standardFlowEnabled: false, directAccessGrantsEnabled: false, implicitFlowEnabled: false, serviceAccountsEnabled: false,
    fullScopeAllowed: false, redirectUris: [], attributes, ...extra,
  };
  if (!publicClient) body.secret = secretOf(clientId);
  if (!raw) { body.defaultClientScopes = [...BASE_DEFAULT, ...defaults]; body.optionalClientScopes = optional; }
  await admin("POST", "/clients", body);
  const [c] = await admin("GET", `/clients?clientId=${encodeURIComponent(clientId)}`);
  return { key, clientId, id: c.id, secret: publicClient ? undefined : secretOf(clientId), public: publicClient, redirect: REDIRECT };
}
const pkce = { "pkce.code.challenge.method": "S256" };
const flow = { standardFlowEnabled: true, redirectUris: [REDIRECT] };
const bundles = ["victual:read", "victual:write", "victual:stock", "victual:stock:book"];
const clients = {};
clients.mcp = await client("mcp", { publicClient: true, extra: flow, attributes: pkce,
  defaults: ["victual-mcp-audience", "victual-groups"], optional: [...bundles, "victual-rogue-audience"] });
clients.sidecar = await client("sidecar", { extra: { serviceAccountsEnabled: true }, attributes: { "standard.token.exchange.enabled": "true" },
  defaults: ["victual-api-audience", "victual-groups"], optional: bundles });
clients.uriSidecar = await client("uriSidecar", { attributes: { "standard.token.exchange.enabled": "true" },
  defaults: ["victual-api-audience", "victual-groups"], optional: bundles });
clients.api = await client("api"); // resource only: every flow off
clients.other = await client("other", { publicClient: true, extra: flow, attributes: pkce, defaults: ["victual-other-audience"], optional: bundles });
clients.rogue = await client("rogue", { attributes: { "standard.token.exchange.enabled": "true" }, defaults: ["victual-other-audience"], optional: bundles });
clients.noExchange = await client("noExchange", { extra: flow, attributes: { ...pkce, "standard.token.exchange.enabled": "false" },
  defaults: ["victual-mcp-audience"], optional: bundles });
clients.short = await client("short", { publicClient: true, extra: flow, attributes: { ...pkce, "access.token.lifespan": "3" },
  defaults: ["victual-mcp-audience"], optional: bundles });
clients.consent = await client("consent", { publicClient: true, extra: { ...flow, consentRequired: true }, attributes: pkce,
  defaults: ["victual-mcp-audience", "victual-groups"], optional: bundles }); // the user approves scopes on a consent screen
clients.plain = await client("plain", { publicClient: true, extra: flow, attributes: pkce, raw: true }); // realm default scopes, as Keycloak ships them

// --- users and a group
const group = await admin("POST", "/groups", { name: "victual-spike-household" }, { raw: true }).then(async () =>
  (await admin("GET", "/groups?search=victual-spike-household"))[0]);
const users = {};
for (const [key, username] of [["alice", "alice-spike"], ["bob", "bob-spike"]]) {
  const password = `spike-only-${key}-password`;
  await admin("POST", "/users", {
    username, enabled: true, email: `${username}@example.invalid`, emailVerified: true, firstName: key, lastName: "Spike",
    credentials: [{ type: "password", value: password, temporary: false }],
  });
  const [u] = await admin("GET", `/users?username=${username}&exact=true`);
  users[key] = { key, username, password, id: u.id };
}
await admin("PUT", `/users/${users.alice.id}/groups/${group.id}`);

writeFileSync(new URL("./state.json", import.meta.url), JSON.stringify({
  issuer: ISSUER, mcpAud: MCP_AUD, apiAud: API_AUD, otherAud: OTHER_AUD, clients, users, group, scopes,
}, null, 2));
console.log(`realm ${REALM}: ${Object.keys(clients).length} clients, ${Object.keys(scopes).length} client scopes, ${Object.keys(users).length} users`);
