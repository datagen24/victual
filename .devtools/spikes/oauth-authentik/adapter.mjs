// Simulation of Victual's registration adapter for an MCP client (ADR-0055 decision 8, prerequisite 11).
// Victual would run these steps through the authorization server's admin API when it accepts a
// registration. setup.mjs writes what the adapter needs to state.json (`adapter`); probe.mjs calls
// registerMcpClient to create new clients the way the adapter would, and with `bound: false` to make
// the client an administrator (or another tool) might create by hand without the audience mapping.
//
// What one registration creates (everything is Authentik configuration, nothing is code in the image):
//   1. an OAuth2 provider: confidential, code + refresh grants only, strict redirect URI, one scope
//      mapping per scope the client may receive, plus the `mcp-aud` mapping for each of those scopes
//      that setup.mjs binds to the MCP audience. A scope mapping is also what makes Authentik grant a
//      scope at all, so the adapter must attach mcp-aud mappings only for scopes it grants (G8b);
//   2. an application with the same slug, whose policy engine mode is "all", so that no later passing
//      policy can outvote the S256 policy (G5b shows that in the default mode "any" it does);
//   3. the S256-only policy bound to that application (G4c);
//   4. the new provider added to the MCP sidecar's `jwt_federation_providers`, so the sidecar may
//      exchange its tokens (without this step the exchange is refused: G6a).
// It never adds federation to the MCP provider itself: that would let the sidecar mint tokens through it.
import { randomBytes } from "node:crypto";

const DEFAULT_SCOPES = ["openid", "profile", "victual:read", "victual:write", "offline_access"];

// Options: bound (attach the mcp-aud mappings), trust (add to the sidecar's federation list),
// scopes (the scope names this client may receive; default DEFAULT_SCOPES plus the household bundle).
export async function registerMcpClient(admin, adapter, name, { bound = true, trust = true, scopes = [...DEFAULT_SCOPES, adapter.bundle] } = {}) {
  const clientSecret = randomBytes(24).toString("base64url");
  const mappings = scopes.map((s) => adapter.byScope[s]);
  if (bound) for (const s of scopes) if (adapter.audByScope[s]) mappings.push(adapter.audByScope[s]);
  const p = await admin("POST", "/providers/oauth2/", {
    name: `${adapter.prefix}-${name}`, authorization_flow: adapter.authzFlow, invalidation_flow: adapter.invalFlow,
    client_type: "confidential", client_id: `${adapter.prefix}-${name}`, client_secret: clientSecret,
    signing_key: adapter.signingKey, issuer_mode: "global", sub_mode: "hashed_user_id",
    access_token_validity: "minutes=5", grant_types: ["authorization_code", "refresh_token"],
    property_mappings: mappings,
    redirect_uris: [{ matching_mode: "strict", url: adapter.redirectUri }],
  });
  const app = await admin("POST", "/core/applications/", { name: p.name, slug: p.name, provider: p.pk, policy_engine_mode: "all" });
  await admin("POST", "/policies/bindings/", { policy: adapter.pkcePolicy, target: app.pk, order: 0 });
  if (trust) {
    const sidecar = await admin("GET", `/providers/oauth2/${adapter.sidecarPk}/`);
    await admin("PATCH", `/providers/oauth2/${adapter.sidecarPk}/`, { jwt_federation_providers: [...sidecar.jwt_federation_providers, p.pk] });
  }
  return { pk: p.pk, client_id: p.client_id, client_secret: clientSecret, slug: p.name };
}

// Deregistration: removes the application and provider. The sidecar's federation list drops the pk by itself.
export async function deregisterMcpClient(admin, client) {
  await admin("DELETE", `/core/applications/${client.slug}/`);
  await admin("DELETE", `/providers/oauth2/${client.pk}/`);
}
