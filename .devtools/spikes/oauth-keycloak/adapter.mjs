// Simulation of Victual's registration adapter (ADR-0055 decision 8) for Keycloak. The adapter
// creates one MCP client through the admin REST API and, in the same step:
//   1. binds the client to the dedicated MCP resource audience: the client scope
//      `victual-mcp-audience` is a DEFAULT scope of the client (prerequisite 11);
//   2. creates a per-grant client scope `victual-grant:<client id>` whose Hardcoded claim mapper
//      writes `victual_client` = the client id, makes it a default scope of the client, and an
//      optional scope of the sidecar, so the sidecar can ask for it at the exchange (K1);
//   3. turns Consent required on, so the user's grant is a stored consent that can be revoked
//      for this client alone.
// Used by setup.mjs (the two fixed K1 clients) and probe.mjs (the K2 clients). Not Victual code.
import { admin, REDIRECT } from "./lib.mjs";

export const GRANT_SCOPE_PREFIX = "victual-grant:";
export const GRANT_CLAIM = "victual_client";
const BASE_DEFAULT = ["basic", "acr", "profile", "email"];
const BUNDLES = ["victual:read", "victual:write", "victual:stock", "victual:stock:book"];

const scopeIds = async () => Object.fromEntries((await admin("GET", "/client-scopes")).map((s) => [s.name, s.id]));

// Returns the client descriptor the probe uses: { clientId, id, public, redirect, grantScope, grantScopeId }.
export async function registerMcpClient(clientId, { redirect = REDIRECT, sidecarId, consentRequired = true, bindAudience = true, grantScope = true } = {}) {
  const ids = await scopeIds();
  const grantName = `${GRANT_SCOPE_PREFIX}${clientId}`;
  let grantId;
  if (grantScope) {
    await admin("POST", "/client-scopes", {
      name: grantName, protocol: "openid-connect",
      attributes: { "include.in.token.scope": "true", "display.on.consent.screen": "false" },
      protocolMappers: [{
        name: "victual-grant-client", protocol: "openid-connect", protocolMapper: "oidc-hardcoded-claim-mapper",
        config: { "claim.name": GRANT_CLAIM, "claim.value": clientId, "jsonType.label": "String",
          "access.token.claim": "true", "id.token.claim": "false", "introspection.token.claim": "true", "lightweight.claim": "true" },
      }],
    });
    grantId = (await scopeIds())[grantName];
  }
  await admin("POST", "/clients", {
    clientId, enabled: true, protocol: "openid-connect", publicClient: true,
    standardFlowEnabled: true, directAccessGrantsEnabled: false, implicitFlowEnabled: false, serviceAccountsEnabled: false,
    fullScopeAllowed: false, consentRequired, redirectUris: [redirect],
    attributes: { "pkce.code.challenge.method": "S256" },
    defaultClientScopes: [...BASE_DEFAULT, ...(bindAudience ? ["victual-mcp-audience"] : []), "victual-groups", ...(grantScope ? [grantName] : [])],
    // offline_access lets the K1 cases test an offline grant; ADR-0055 says servers should not list it,
    // a client may still ask for it.
    optionalClientScopes: [...BUNDLES.filter((n) => ids[n]), ...(ids.offline_access ? ["offline_access"] : [])],
  });
  const [c] = await admin("GET", `/clients?clientId=${encodeURIComponent(clientId)}`);
  if (grantScope && sidecarId) await admin("PUT", `/clients/${sidecarId}/optional-client-scopes/${grantId}`);
  return { key: clientId, clientId, id: c.id, public: true, redirect, grantScope: grantScope ? grantName : null, grantScopeId: grantId };
}

// Removes a client and its per-grant scope (the adapter's delete path).
export async function deregisterMcpClient(c) {
  await admin("DELETE", `/clients/${c.id}`, undefined, { raw: true });
  if (c.grantScopeId) await admin("DELETE", `/client-scopes/${c.grantScopeId}`, undefined, { raw: true });
}
