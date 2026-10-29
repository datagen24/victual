// Configure both sides of the brokering spike through their admin APIs. Idempotent: Authentik
// objects prefixed "victual-kcup" / users prefixed "kcup-" and the Keycloak realm `victual` are
// deleted and rebuilt. The prefix differs from the Authentik spike's "victual-spike", whose
// setup.mjs deletes everything carrying its own prefix. Writes state.json for probe.mjs.
//
// Authentik: one confidential OAuth2 provider Keycloak signs in through (per-provider issuer, so its
// issuer names it), with back-channel logout to Keycloak's realm endpoint; users and a group.
// Keycloak: realm `victual`; identity provider `authentik` (OIDC, sync mode FORCE) with a mapper that
// copies the upstream `groups` claim into a user attribute; a first-broker-login flow that creates a
// user only when no account holds the email or username, and never links one; a public client whose
// access token carries `groups` from that attribute; local users for the collision and break-glass cases.
import { writeFileSync } from "node:fs";
import { admin } from "../oauth-keycloak/lib.mjs";
import { AK, KC, REALM, REDIRECT } from "./browser.mjs";

const AKAPI = "http://localhost:9000/api/v3";
const H = { Authorization: "Bearer spike-only-bootstrap-token-0123456789", "Content-Type": "application/json" };
const P = "victual-kcup";
const SLUG = `${P}-broker`;
const ISSUER = `${AK}/application/o/${SLUG}/`;

/** Calls Authentik's admin API with the bootstrap token; throws on a non-2xx answer. */
async function ak(method, path, body) {
  const r = await fetch(`${AKAPI}${path}`, { method, headers: H, body: body && JSON.stringify(body) });
  const text = await r.text();
  if (!r.ok) throw new Error(`${method} ${path} -> ${r.status} ${text.slice(0, 400)}`);
  return text ? JSON.parse(text) : null;
}
/** One page of up to 200 objects of an Authentik collection. */
const list = async (path) => (await ak("GET", `${path}${path.includes("?") ? "&" : "?"}page_size=200`)).results;

// --- Authentik: clean up, then provider, application, group, users
for (const a of await list("/core/applications/")) if (a.slug.startsWith(P)) await ak("DELETE", `/core/applications/${a.slug}/`);
for (const p of await list("/providers/oauth2/")) if (p.name.startsWith(P)) await ak("DELETE", `/providers/oauth2/${p.pk}/`);
for (const u of await list("/core/users/?search=kcup")) if (u.username.startsWith("kcup-")) await ak("DELETE", `/core/users/${u.pk}/`);
for (const g of await list("/core/groups/")) if (g.name.startsWith(P)) await ak("DELETE", `/core/groups/${g.pk}/`);

const flows = await list("/flows/instances/");
/** The pk of an Authentik flow by slug. */
const flow = (slug) => flows.find((f) => f.slug === slug).pk;
let signingKey, builtin = [];
for (let i = 0; i < 90; i++) {
  signingKey = (await list("/crypto/certificatekeypairs/")).find((c) => c.name === "authentik Self-signed Certificate")?.pk;
  builtin = await list("/propertymappings/provider/scope/");
  if (signingKey && ["openid", "profile", "email"].every((n) => builtin.some((m) => m.scope_name === n && m.managed))) break;
  await new Promise((r) => setTimeout(r, 2000));
}
/** The pk of a built-in Authentik scope mapping. */
const scope = (name) => builtin.find((m) => m.scope_name === name && m.managed).pk;
const KC_BROKER = `${KC}/realms/${REALM}/broker/authentik/endpoint`;
// Authentik's server and worker reach Keycloak through the host, as Keycloak reaches Authentik.
const KC_BACKCHANNEL = `http://host.containers.internal:8180/realms/${REALM}/protocol/openid-connect/logout/backchannel-logout`;
const provider = await ak("POST", "/providers/oauth2/", {
  name: SLUG, authorization_flow: flow("default-provider-authorization-implicit-consent"), invalidation_flow: flow("default-provider-invalidation-flow"),
  client_type: "confidential", client_id: SLUG, client_secret: `${SLUG}-secret-0123456789`,
  signing_key: signingKey, issuer_mode: "per_provider", sub_mode: "hashed_user_id", access_token_validity: "minutes=5",
  grant_types: ["authorization_code", "refresh_token"],
  property_mappings: [scope("openid"), scope("profile"), scope("email")],
  redirect_uris: [{ matching_mode: "strict", url: KC_BROKER }],
  logout_method: "backchannel", logout_uri: KC_BACKCHANNEL,
});
await ak("POST", "/core/applications/", { name: SLUG, slug: SLUG, provider: provider.pk });
const adults = await ak("POST", "/core/groups/", { name: `${P}-adults` });
/** Creates an Authentik user with a password. */
async function akUser(key, email, groups = []) {
  const username = `kcup-${key}`;
  const u = await ak("POST", "/core/users/", { username, name: `${key} Upstream`, email, is_active: true, groups, path: P });
  const password = `${username}-pw-0123456789`;
  await ak("POST", `/core/users/${u.pk}/set_password/`, { password });
  return { pk: u.pk, username, password, email };
}
const up = {
  alice: await akUser("alice", "kcup-alice@example.invalid", [adults.pk]),
  bob: await akUser("bob", "kcup-bob@example.invalid", [adults.pk]),
  carol: await akUser("carol", "carol@example.invalid"), // same email as a Keycloak local account
  dave: await akUser("dave", "dave@example.invalid"), // same, for the shipped first-login flow
  erin: await akUser("erin", "kcup-erin@example.invalid", [adults.pk]),
};

// --- Keycloak: realm, user profile, first-broker-login flow, identity provider, client, local users
await admin("DELETE", `/${REALM}`, undefined, { realm: "", raw: true });
await admin("POST", "", {
  realm: REALM, enabled: true, accessTokenLifespan: 300, ssoSessionIdleTimeout: 1800, ssoSessionMaxLifespan: 36000,
  revokeRefreshToken: true, refreshTokenMaxReuse: 0, registrationAllowed: false, loginWithEmailAllowed: true, duplicateEmailsAllowed: false,
}, { realm: "" });
const prof = await admin("GET", "/users/profile");
// upstream_groups is an unmanaged attribute. Authentik sends no family_name, so first and last name
// stop being required; otherwise Keycloak stops the first brokered login at an "update account" form.
const attributes = prof.attributes.map((a) => (["firstName", "lastName"].includes(a.name) ? { ...a, required: undefined } : a));
await admin("PUT", "/users/profile", { ...prof, attributes, unmanagedAttributePolicy: "ENABLED" });
const FLOW = "victual-first-broker-nolink";
await admin("POST", "/authentication/flows", { alias: FLOW, description: "create if unique, never link", providerId: "basic-flow", topLevel: true, builtIn: false });
await admin("POST", `/authentication/flows/${FLOW}/executions/execution`, { provider: "idp-create-user-if-unique" });
for (const e of await admin("GET", `/authentication/flows/${FLOW}/executions`)) await admin("PUT", `/authentication/flows/${FLOW}/executions`, { ...e, requirement: "REQUIRED" });
await admin("POST", "/identity-provider/instances", {
  alias: "authentik", displayName: "Authentik (lab)", providerId: "oidc", enabled: true, trustEmail: false, storeToken: false,
  firstBrokerLoginFlowAlias: FLOW,
  config: {
    issuer: ISSUER, authorizationUrl: `${AK}/application/o/authorize/`, tokenUrl: `${AK}/application/o/token/`,
    userInfoUrl: `${AK}/application/o/userinfo/`, jwksUrl: `${AK}/application/o/${SLUG}/jwks/`, logoutUrl: `${AK}/application/o/${SLUG}/end-session/`,
    useJwksUrl: "true", validateSignature: "true", clientId: SLUG, clientSecret: `${SLUG}-secret-0123456789`, clientAuthMethod: "client_secret_post",
    pkceEnabled: "true", pkceMethod: "S256", defaultScope: "openid profile email", syncMode: "FORCE", backchannelSupported: "true",
  },
});
await admin("POST", "/identity-provider/instances/authentik/mappers", {
  name: "upstream-groups", identityProviderAlias: "authentik", identityProviderMapper: "oidc-user-attribute-idp-mapper",
  config: { claim: "groups", "user.attribute": "upstream_groups", syncMode: "INHERIT" },
});
await admin("POST", "/clients", {
  clientId: "victual-kcup-app", enabled: true, protocol: "openid-connect", publicClient: true, standardFlowEnabled: true,
  directAccessGrantsEnabled: false, redirectUris: [REDIRECT], attributes: { "pkce.code.challenge.method": "S256" },
  protocolMappers: [{
    name: "groups", protocol: "openid-connect", protocolMapper: "oidc-usermodel-attribute-mapper",
    config: { "user.attribute": "upstream_groups", "claim.name": "groups", "jsonType.label": "String", multivalued: "true",
      "access.token.claim": "true", "id.token.claim": "false", "introspection.token.claim": "true" },
  }],
});
const local = {};
for (const [key, email] of [["carol", "carol@example.invalid"], ["dave", "dave@example.invalid"], ["breakglass", "breakglass@example.invalid"]]) {
  const username = `local-${key}`, password = `spike-only-local-${key}`;
  await admin("POST", "/users", { username, email, emailVerified: true, enabled: true, firstName: key, lastName: "Local",
    credentials: [{ type: "password", value: password, temporary: false }] });
  const [u] = await admin("GET", `/users?username=${username}&exact=true`);
  local[key] = { id: u.id, username, password, email };
}

writeFileSync(new URL("./state.json", import.meta.url), JSON.stringify({
  issuer: ISSUER, client: "victual-kcup-app", flow: FLOW, group: adults.name, groupPk: adults.pk, provider: provider.pk, up, local,
  logoutMethod: provider.logout_method, logoutUri: provider.logout_uri,
}, null, 2));
console.log(`authentik: provider ${SLUG} (logout ${provider.logout_method} -> ${provider.logout_uri}), ${Object.keys(up).length} users; keycloak: realm ${REALM}, idp authentik, ${Object.keys(local).length} local users`);
