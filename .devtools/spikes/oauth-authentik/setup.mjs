// Configure the spike's Authentik through its admin API (bootstrap token). Idempotent: it
// deletes and recreates everything it owns (names prefixed "victual-spike"). Writes the
// client ids, secrets and user app passwords to state.json for probe.mjs. Throwaway values.
//
// Shape (ADR-0055 decisions 3 and 7, ADR-0056 decisions 4 and 6):
//   victual-spike-mcp-client  the provider an MCP client registers as; user tokens come from here
//   victual-spike-sidecar     confidential; holds the token-exchange grant; trusts mcp-client
//   victual-spike-api         Victual's /api audience; trusts the sidecar to mint tokens for it
//   victual-spike-other       an unrelated provider, for the "untrusted issuer" negatives
//   victual-spike-mcp-client-b, -sidecar-b, -mcp-scalar   second MCP client, second exchanger, scalar-aud variant
//
// Required deployment configuration (ADR-0055 decisions 3 and 7), part of the default setup:
//   * the scope-ceiling expression policy bound to the /api application (G3);
//   * a scope mapping on every /api scope that signs the exchanger's client_id into the token (G1);
//   * a scope mapping on every MCP client's provider that adds the dedicated MCP audience (G2);
//   * an expression policy on every MCP client application that refuses a code request without S256 (G4).
//   * a notification rule whose expression policy revokes a refresh-token family when an old refresh
//     token is reused (G5), and a second rule that posts the reuse event to an endpoint (G5, the way
//     Victual would observe it). Both are listed in the README's configuration inventory.
// adapter.mjs holds the steps Victual's registration adapter would run for each new MCP client (G6).
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
for (const p of await list("/policies/expression/?search=victual-spike")) await api("DELETE", `/policies/expression/${p.pk}/`);
for (const g of await list("/core/groups/")) if (g.name.startsWith(P)) await api("DELETE", `/core/groups/${g.pk}/`);
for (const r of await list("/events/rules/")) if (r.name.startsWith(P)) await api("DELETE", `/events/rules/${r.pk}/`);
for (const t of await list("/events/transports/")) if (t.name.startsWith(P)) await api("DELETE", `/events/transports/${t.pk}/`);
for (const m of await list("/propertymappings/notification/")) if (m.name.startsWith(P)) await api("DELETE", `/propertymappings/notification/${m.pk}/`);
for (const p of await list("/policies/event_matcher/")) if (p.name.startsWith(P)) await api("DELETE", `/policies/event_matcher/${p.pk}/`);

// --- shared pieces
const flows = await list("/flows/instances/");
const flow = (slug) => flows.find((f) => f.slug === slug).pk;
const authzFlow = flow("default-provider-authorization-implicit-consent");
const invalFlow = flow("default-provider-invalidation-flow");
let signingKey;
for (let i = 0; i < 90 && !signingKey; i++) {
  signingKey = (await list("/crypto/certificatekeypairs/")).find((c) => c.name === "authentik Self-signed Certificate")?.pk;
  if (!signingKey) await new Promise((r) => setTimeout(r, 2000));
}
// The worker applies the default blueprints in the background; `run.sh up` only waits for the first
// flow. Wait here for the built-in scope mappings this file uses (a cold start once failed without it).
let builtin = [];
for (let i = 0; i < 90; i++) {
  builtin = await list("/propertymappings/provider/scope/");
  if (["openid", "profile", "offline_access"].every((n) => builtin.some((m) => m.scope_name === n && m.managed))) break;
  await new Promise((r) => setTimeout(r, 2000));
}
const scope = (name) => builtin.find((m) => m.scope_name === name && m.managed).pk;

const mapping = async (scope_name, expression = "return {}", suffix = "") =>
  (await api("POST", "/propertymappings/provider/scope/", { name: `${P} ${scope_name}${suffix}`, scope_name, expression })).pk;
const read = await mapping("victual:read");
const write = await mapping("victual:write");
const admin = await mapping("victual:admin");
const BUNDLE = "victual:bundle:household";
const bundle = await mapping(BUNDLE);
const scopes = [scope("openid"), scope("profile"), read, write];
const MCP_RESOURCE = `${P}-mcp-resource`;
const TX = "urn:ietf:params:oauth:grant-type:token-exchange";

// G2: dedicated MCP audience. Scope mappings are merged by deepmerge, which appends lists, and
// the result replaces `aud` wholesale (id_token.py to_dict: id_dict.update(self.claims)). One
// mapping per bound scope would therefore list the audience several times, so only the mapping
// for the alphabetically first granted bound scope emits it. List form keeps the client_id
// in aud so the OIDC id_token still names its client.
const MCP_BOUND = ["openid", "victual:read", "victual:write", BUNDLE];
const audExpression = (scope_name) => `
bound = ${JSON.stringify(MCP_BOUND)}
granted = [s for s in token.scope if s in bound]
if not granted or min(granted) != ${JSON.stringify(scope_name)}:
    return {}
return {"aud": [provider.client_id, ${JSON.stringify(MCP_RESOURCE)}]}
`.trim();
const mcpAud = [];
for (const s_ of MCP_BOUND) mcpAud.push(await mapping(s_, audExpression(s_), " mcp-aud"));
// Scalar form, for the id_token comparison only (provider mcp-scalar).
const scalarAud = await mapping("openid", `return {"aud": ${JSON.stringify(MCP_RESOURCE)}}`, " mcp-aud-scalar");

// G1: sign the exchanger into the token. Runs while the token endpoint builds an exchanged token
// (grant_type token-exchange). extract_client_auth is the function TokenView.post used to find
// the provider whose secret TokenRequest.parse then verified, so this is the authenticated client.
// The subject token was verified before any mapping runs; it is only decoded here, for the
// originating-grant claim.
const EXCHANGER_EXPRESSION = `
import base64, json
from authentik.providers.oauth2.models import OAuth2Provider
from authentik.providers.oauth2.utils import extract_client_auth
req = http_request
if req is None or req.POST.get("grant_type") != ${JSON.stringify(TX)}:
    return {}
client_id, _secret = extract_client_auth(req)
exchanger = OAuth2Provider.objects.filter(client_id=client_id, client_type="confidential").first()
if exchanger is None:
    return {}
out = {"victual_exchanger": exchanger.client_id}
try:
    seg = req.POST.get("subject_token", "").split(".")[1]
    subject = json.loads(base64.urlsafe_b64decode(seg + "=" * (-len(seg) % 4)))
    out["victual_grant"] = {k: subject[k] for k in ("azp", "sid", "jti") if k in subject}
except Exception:
    pass
return out
`.trim();
const exchangerMappings = [];
for (const s_ of ["victual:read", "victual:write", "victual:admin"]) exchangerMappings.push(await mapping(s_, EXCHANGER_EXPRESSION, " exchanger"));

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
const CODE_GRANTS = ["authorization_code", "refresh_token", "client_credentials", "password"];
const mcpScopes = [...scopes, scope("offline_access"), bundle, ...mcpAud];
const other = await provider("other", { grant_types: ["authorization_code", "client_credentials", "password"] });
const mcp = await provider("mcp-client", { grant_types: CODE_GRANTS, property_mappings: mcpScopes });
// A second registered MCP client: same dedicated audience, different client_id (G2).
const mcpB = await provider("mcp-client-b", { grant_types: CODE_GRANTS, property_mappings: mcpScopes });
// A client whose mapping returns a scalar aud (G2: what that does to the id_token).
const mcpScalar = await provider("mcp-scalar", { grant_types: ["client_credentials", "password"], property_mappings: [...scopes, scalarAud] });
const sidecar = await provider("sidecar", {
  grant_types: ["client_credentials", "urn:ietf:params:oauth:grant-type:token-exchange"],
  jwt_federation_providers: [mcp.pk, mcpB.pk],
});
// A second legitimate exchanger, trusted by /api: its exchanged tokens must name it, not the sidecar (G1).
const sidecarB = await provider("sidecar-b", {
  grant_types: ["client_credentials", "urn:ietf:params:oauth:grant-type:token-exchange"],
  jwt_federation_providers: [mcp.pk],
});
const api_ = await provider("api", {
  grant_types: ["client_credentials"], jwt_federation_providers: [sidecar.pk, sidecarB.pk],
  property_mappings: [...scopes, admin, ...exchangerMappings],
});
// A second exchanger that the api provider does NOT trust (negative: target_not_federated).
const rogue = await provider("rogue-exchanger", {
  grant_types: ["urn:ietf:params:oauth:grant-type:token-exchange"], jwt_federation_providers: [mcp.pk],
});
// A provider without the exchange grant (negative: grant not allowed).
const noGrant = await provider("no-exchange-grant", { grant_types: ["client_credentials"], jwt_federation_providers: [mcp.pk] });
// Two-second tokens, trusted by the sidecar, for the expired-token negatives.
const short = await provider("mcp-short", { grant_types: ["client_credentials", "password"], access_token_validity: "seconds=2" });
await api("PATCH", `/providers/oauth2/${sidecar.pk}/`, { jwt_federation_providers: [mcp.pk, mcpB.pk, short.pk] });

// --- G3: the scope-ceiling policy is required deployment configuration (ADR-0055 decision 7, open
// question 7). It is bound to the /api application, which Authentik evaluates on every exchange
// into /api with the verified subject token as oauth_jwt. BUNDLES is the bundle-to-leaf table: a
// second copy of Victual's bundle definitions, which is the maintenance cost the README reports.
const CEILING_COMMON = `
TX = ${JSON.stringify(TX)}
jwt = request.context.get("oauth_jwt")
if not jwt or request.context.get("oauth_grant_type") != TX:
    return True
have = set(str(jwt.get("scope", "")).split())
`.trim();
const CEILING_TAIL = `
want = set(request.context.get("oauth_scopes") or [])
if not any(s.startswith("victual:") for s in want):
    return False
return want <= have
`.trim();
const CEILING_BUNDLES = `
BUNDLES = {${JSON.stringify(BUNDLE)}: {"victual:read", "victual:write"}}
for held in list(have):
    have |= BUNDLES.get(held, set())
`.trim();
const policy = await api("POST", "/policies/expression/", { name: `${P}-scope-ceiling`, expression: `${CEILING_COMMON}\n${CEILING_BUNDLES}\n${CEILING_TAIL}` });
const policyPlain = await api("POST", "/policies/expression/", { name: `${P}-scope-ceiling-plain`, expression: `${CEILING_COMMON}\n${CEILING_TAIL}` });
const apiApp = await api("GET", `/core/applications/${api_.name}/`);
const binding = await api("POST", "/policies/bindings/", { policy: policy.pk, target: apiApp.pk, order: 0 });

// --- PKCE S256 only (ADR-0055 decision 6). Authentik accepts a code request with no challenge, or with
// method plain, unless a policy refuses it. At /authorize the application's policies see the request's
// code_challenge and method (views/authorize.py modify_policy_request); at the token endpoint those keys
// are absent, so the policy only acts on /authorize. Bound to every MCP client application.
const pkcePolicy = await api("POST", "/policies/expression/", { name: `${P}-pkce-s256`, expression: `
if "oauth_code_challenge_method" not in request.context:
    return True
return bool(request.context.get("oauth_code_challenge")) and request.context.get("oauth_code_challenge_method") == "S256"
`.trim() });
const pkceBindings = {};
for (const [k, p] of Object.entries({ mcp, mcpB })) {
  const app = await api("GET", `/core/applications/${p.name}/`);
  // Mode "all": with the default "any", any other policy that passes outvotes the S256 policy (G5b).
  await api("PATCH", `/core/applications/${p.name}/`, { policy_engine_mode: "all" });
  pkceBindings[k] = (await api("POST", "/policies/bindings/", { policy: pkcePolicy.pk, target: app.pk, order: 0 })).pk;
}

// --- users, group, app passwords
const group = await api("POST", "/core/groups/", { name: `${P}-adults` });
async function user(username, groups) {
  const u = await api("POST", "/core/users/", { username, name: username, is_active: true, groups, path: "victual-spike" });
  const ident = `${username}-app-password`;
  await api("POST", "/core/tokens/", { identifier: ident, intent: "app_password", user: u.pk, expiring: false });
  const { key } = await api("GET", `/core/tokens/${ident}/view_key/`);
  const password = `${username}-pw-0123456789`;
  await api("POST", `/core/users/${u.pk}/set_password/`, { password });
  return { pk: u.pk, username, app_password: key, password };
}
const alice = await user("spike-alice", [group.pk]);
const bob = await user("spike-bob", []);

// --- G5: refresh-token family revocation (ADR-0055 prerequisite 9, question 12).
// Authentik rotates refresh tokens and marks the old one `revoked`. A presented revoked token is
// refused and raises a suspicious_request event whose context names the token (token/refresh_token.py).
// Nothing else happens. Two notification rules close the gap. Both run on the worker after the event
// is saved, so they are asynchronous (seconds):
//   1. revoke: an expression policy bound to a notification rule. Authentik evaluates it for every
//      event (request.context["event"]); it deletes every refresh and access token of the same family
//      and returns False, so no notification is created. A policy with side effects is a convention
//      this spike relies on, not a documented feature, and it imports internal models.
//   2. alert: the built-in event matcher policy bound to a rule with a webhook transport. That is how
//      Victual would observe reuse; it could then revoke through the admin API (G5i).
// Family key: Authentik stores no parent link or family id. Every refresh token minted by rotation
// copies provider, user, session and auth_time from its parent (views/token.py create_refresh_response),
// so those four fields identify the chain, and also the branches a concurrent refresh creates (G5f).
// Two grants of one client in one login session share the key: revoking one revokes both (fail closed).
const REVOKE_EXPRESSION = `
from authentik.providers.oauth2.models import AccessToken, RefreshToken
ev = request.context.get("event")
ref = (ev.context.get("token") or {}) if ev is not None else {}
if ev is None or ev.action != "suspicious_request" or ref.get("model_name") != "refreshtoken":
    return False
tok = RefreshToken.objects.filter(pk=ref.get("pk")).first()
if tok is None:
    return False
family = {"provider": tok.provider, "user": tok.user, "session": tok.session, "auth_time": tok.auth_time}
gone_r = RefreshToken.objects.filter(**family).delete()[0]
gone_a = AccessToken.objects.filter(**family).delete()[0]
ak_logger.warning("refresh token reuse: family revoked", provider=tok.provider_id, user=tok.user_id, refresh=gone_r, access=gone_a)
return False
`.trim();
const revokePolicy = await api("POST", "/policies/expression/", { name: `${P}-refresh-reuse-revoke`, expression: REVOKE_EXPRESSION });
const revokeRule = await api("POST", "/events/rules/", { name: `${P}-refresh-reuse-revoke`, severity: "alert" });
const revokeBinding = await api("POST", "/policies/bindings/", { policy: revokePolicy.pk, target: revokeRule.pk, order: 0 });
const HOOK_URL = "http://victual-spike-authentik-hook:8766/refresh-reuse";
const notifyGroup = await api("POST", "/core/groups/", { name: `${P}-notify` });
await api("POST", "/core/users/", { username: "spike-notify", name: "spike-notify", is_active: true, groups: [notifyGroup.pk], path: "victual-spike" });
const hookBody = await api("POST", "/propertymappings/notification/", { name: `${P}-reuse-body`, expression: `
ev = notification.event
return {"action": ev.action, "event_uuid": str(ev.event_uuid), "user": ev.user, "context": ev.context}
`.trim() });
const hookTransport = await api("POST", "/events/transports/", { name: `${P}-reuse-webhook`, mode: "webhook", webhook_url: HOOK_URL, webhook_mapping_body: hookBody.pk, send_once: true });
const alertMatcher = await api("POST", "/policies/event_matcher/", { name: `${P}-suspicious-request`, action: "suspicious_request" });
const alertRule = await api("POST", "/events/rules/", { name: `${P}-refresh-reuse-alert`, severity: "alert", transports: [hookTransport.pk], destination_group: notifyGroup.pk });
const alertBinding = await api("POST", "/policies/bindings/", { policy: alertMatcher.pk, target: alertRule.pk, order: 0 });

const state = {
  ak: AK, group: { pk: group.pk, name: group.name },
  providers: Object.fromEntries(
    Object.entries({ other, mcp, mcpB, mcpScalar, sidecar, sidecarB, api: api_, rogue, noGrant, short }).map(([k, p]) => [k, { pk: p.pk, client_id: p.client_id, client_secret: p.client_secret, slug: p.name }]),
  ),
  users: { alice, bob },
  policies: { ceiling: policy.pk, ceilingPlain: policyPlain.pk, apiApp: apiApp.pk, binding: binding.pk, pkce: pkcePolicy.pk, pkceBindings },
  mcpResource: MCP_RESOURCE, bundle: BUNDLE,
  reuse: { revokePolicy: revokePolicy.pk, revokeRule: revokeRule.pk, revokeBinding: revokeBinding.pk, alertRule: alertRule.pk, alertBinding: alertBinding.pk, matcher: alertMatcher.pk, hookUrl: HOOK_URL },
  // Everything adapter.mjs needs to register a client the way Victual's adapter would (G6).
  adapter: {
    prefix: P, authzFlow, invalFlow, signingKey, redirectUri: "http://localhost:8765/callback", pkcePolicy: pkcePolicy.pk,
    sidecarPk: sidecar.pk, bundle: BUNDLE, audByScope: Object.fromEntries(MCP_BOUND.map((s_, i) => [s_, mcpAud[i]])),
    byScope: { openid: scope("openid"), profile: scope("profile"), offline_access: scope("offline_access"), "victual:read": read, "victual:write": write, "victual:admin": admin, [BUNDLE]: bundle },
  },
};
writeFileSync(new URL("./state.json", import.meta.url), JSON.stringify(state, null, 2));
console.log("configured:", Object.values(state.providers).map((p) => p.client_id).join(", "));
