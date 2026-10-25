// Configure the spike's Authentik through its admin API (bootstrap token). Idempotent: it
// deletes and recreates everything it owns (names prefixed "victual-spike"). Writes the
// client ids, secrets and user app passwords to state.json for probe.mjs. Throwaway values.
//
// Shape (ADR-0055 decisions 3 and 7, ADR-0056 decisions 4 and 6):
//   victual-spike-mcp-client  the provider an MCP client registers as; user tokens come from here
//   victual-spike-sidecar     confidential; holds the token-exchange grant; trusts mcp-client
//   victual-spike-api         Victual's /api audience; trusts the sidecar to mint tokens for it
//   victual-spike-other       an unrelated provider, for the "untrusted issuer" negatives
import { writeFileSync } from "node:fs";

const AK = process.env.AK ?? "http://localhost:9000";
const H = { Authorization: "Bearer spike-only-bootstrap-token-0123456789", "Content-Type": "application/json" };
const P = "victual-spike";

async function api(method, path, body) {
  const r = await fetch(`${AK}/api/v3${path}`, { method, headers: H, body: body && JSON.stringify(body) });
  const text = await r.text();
  if (!r.ok) throw new Error(`${method} ${path} -> ${r.status} ${text.slice(0, 400)}`);
  return text ? JSON.parse(text) : null;
}
const list = async (path) => (await api("GET", `${path}${path.includes("?") ? "&" : "?"}page_size=200`)).results;

// --- clean up a previous run
for (const a of await list("/core/applications/")) if (a.slug.startsWith(P)) await api("DELETE", `/core/applications/${a.slug}/`);
for (const p of await list("/providers/oauth2/")) if (p.name.startsWith(P)) await api("DELETE", `/providers/oauth2/${p.pk}/`);
for (const m of await list("/propertymappings/provider/scope/")) if (m.name.startsWith(P)) await api("DELETE", `/propertymappings/provider/scope/${m.pk}/`);
for (const u of await list("/core/users/?search=spike")) if (u.username.startsWith("spike-")) await api("DELETE", `/core/users/${u.pk}/`);
for (const g of await list("/core/groups/")) if (g.name.startsWith(P)) await api("DELETE", `/core/groups/${g.pk}/`);

// --- shared pieces
const flows = await list("/flows/instances/");
const flow = (slug) => flows.find((f) => f.slug === slug).pk;
const authzFlow = flow("default-provider-authorization-implicit-consent");
const invalFlow = flow("default-provider-invalidation-flow");
const signingKey = (await list("/crypto/certificatekeypairs/")).find((c) => c.name === "authentik Self-signed Certificate").pk;
const builtin = await list("/propertymappings/provider/scope/");
const scope = (name) => builtin.find((m) => m.scope_name === name && m.managed).pk;

const mapping = async (scope_name) =>
  (await api("POST", "/propertymappings/provider/scope/", { name: `${P} ${scope_name}`, scope_name, expression: "return {}" })).pk;
const read = await mapping("victual:read");
const write = await mapping("victual:write");
const scopes = [scope("openid"), scope("profile"), read, write];

async function provider(name, extra) {
  const p = await api("POST", "/providers/oauth2/", {
    name: `${P}-${name}`, authorization_flow: authzFlow, invalidation_flow: invalFlow,
    client_type: "confidential", client_id: `${P}-${name}`, client_secret: `${P}-${name}-secret-0123456789`,
    signing_key: signingKey, issuer_mode: "global", sub_mode: "hashed_user_id",
    access_token_validity: "minutes=5", property_mappings: scopes,
    redirect_uris: [{ matching_mode: "strict", url: "http://localhost:8765/callback" }],
    ...extra,
  });
  await api("POST", "/core/applications/", { name: `${P}-${name}`, slug: `${P}-${name}`, provider: p.pk });
  return p;
}
const other = await provider("other", { grant_types: ["client_credentials", "password"] });
const mcp = await provider("mcp-client", { grant_types: ["authorization_code", "refresh_token", "client_credentials", "password"] });
const sidecar = await provider("sidecar", {
  grant_types: ["client_credentials", "urn:ietf:params:oauth:grant-type:token-exchange"],
  jwt_federation_providers: [mcp.pk],
});
const api_ = await provider("api", { grant_types: ["client_credentials"], jwt_federation_providers: [sidecar.pk] });
// A second exchanger that the api provider does NOT trust (negative: target_not_federated).
const rogue = await provider("rogue-exchanger", {
  grant_types: ["urn:ietf:params:oauth:grant-type:token-exchange"], jwt_federation_providers: [mcp.pk],
});
// A provider without the exchange grant (negative: grant not allowed).
const noGrant = await provider("no-exchange-grant", { grant_types: ["client_credentials"], jwt_federation_providers: [mcp.pk] });
// Two-second tokens, trusted by the sidecar, for the expired-token negatives.
const short = await provider("mcp-short", { grant_types: ["client_credentials", "password"], access_token_validity: "seconds=2" });
await api("PATCH", `/providers/oauth2/${sidecar.pk}/`, { jwt_federation_providers: [mcp.pk, short.pk] });

// --- users, group, app passwords
const group = await api("POST", "/core/groups/", { name: `${P}-adults` });
async function user(username, groups) {
  const u = await api("POST", "/core/users/", { username, name: username, is_active: true, groups, path: "victual-spike" });
  const ident = `${username}-app-password`;
  await api("POST", "/core/tokens/", { identifier: ident, intent: "app_password", user: u.pk, expiring: false });
  const { key } = await api("GET", `/core/tokens/${ident}/view_key/`);
  return { pk: u.pk, username, app_password: key };
}
const alice = await user("spike-alice", [group.pk]);
const bob = await user("spike-bob", []);

const state = {
  ak: AK, group: { pk: group.pk, name: group.name },
  providers: Object.fromEntries(
    Object.entries({ other, mcp, sidecar, api: api_, rogue, noGrant, short }).map(([k, p]) => [k, { pk: p.pk, client_id: p.client_id, client_secret: p.client_secret, slug: p.name }]),
  ),
  users: { alice, bob },
};
writeFileSync(new URL("./state.json", import.meta.url), JSON.stringify(state, null, 2));
console.log("configured:", Object.values(state.providers).map((p) => p.client_id).join(", "));
