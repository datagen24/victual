// Offline checks of the committed realm configuration (plan 43): no Keycloak is needed, so CI runs
// them on every pull request. `node --test .devtools/keycloak-config/config.test.mjs`
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";
import { loadRecipe } from "../../deploy/keycloak/adapter.mjs";
import { ConfigError, loadConfig } from "../../deploy/keycloak/lib/config.mjs";

const ROOT = resolve(fileURLToPath(import.meta.url), "../../..");
const REALM = resolve(ROOT, "deploy/keycloak/realm.json");
const V1 = resolve(ROOT, ".devtools/keycloak-config/fixtures/realm-v1.json");
// Client scopes Keycloak 26.8.1 creates in every realm; a configuration may reference them.
const BUILT_IN = ["acr", "address", "basic", "email", "microprofile-jwt", "offline_access", "organization", "phone", "profile", "roles", "saml_organization", "service_account", "web-origins"];

/** The variables example.env names, with every value replaced by a harmless test value. */
function exampleVars() {
  const names = [...readFileSync(resolve(ROOT, "deploy/keycloak/example.env"), "utf8").matchAll(/^#?\s*([A-Z][A-Z0-9_]+)=/gm)].map((m) => m[1]);
  return Object.fromEntries(names.map((n) => [n, `test-${n.toLowerCase()}`]));
}

test("the committed configuration loads with every example.env variable set", () => {
  const { config, used } = loadConfig(REALM, exampleVars());
  assert.equal(config.realm.realm, "victual");
  for (const n of used) assert.ok(n in exampleVars(), `${n} is used by realm.json but not listed in example.env`);
});

test("example.env as shipped is refused: every value is CHANGE-ME", () => {
  const env = Object.fromEntries(Object.keys(exampleVars()).map((n) => [n, "CHANGE-ME"]));
  assert.throws(() => loadConfig(REALM, env), (e) => e instanceof ConfigError && /CHANGE-ME/.test(e.message));
});

test("an unset variable refuses the configuration and names the variable", () => {
  const env = { ...exampleVars(), SCIM_CLIENT_SECRET: "" };
  assert.throws(() => loadConfig(REALM, env), (e) => e instanceof ConfigError && /SCIM_CLIENT_SECRET/.test(e.message));
});

test("the Entra ID template is included only when ENTRA_TENANT_ID is set", () => {
  const without = { ...exampleVars() };
  delete without.ENTRA_TENANT_ID;
  assert.ok(!loadConfig(REALM, without).config.identityProviders.some((i) => i.alias === "entra-id"));
  assert.ok(loadConfig(REALM, exampleVars()).config.identityProviders.some((i) => i.alias === "entra-id"));
});

test("every client-scope reference names a declared or built-in scope", () => {
  for (const file of [REALM, V1]) {
    const { config } = loadConfig(file, exampleVars());
    const known = new Set([...BUILT_IN, ...config.clientScopes.map((s) => s.name)]);
    for (const c of config.clients) for (const n of [...(c.defaultClientScopes ?? []), ...(c.optionalClientScopes ?? [])]) assert.ok(known.has(n), `${file}: ${c.clientId} references ${n}`);
  }
});

test("no client is configured with a forbidden scope, the password grant or the implicit grant", () => {
  const { config } = loadConfig(REALM, exampleVars());
  for (const c of config.clients) {
    for (const n of [...(c.defaultClientScopes ?? []), ...(c.optionalClientScopes ?? [])]) assert.ok(!config.forbiddenClientScopes.includes(n), `${c.clientId} lists ${n}`);
    assert.equal(c.directAccessGrantsEnabled, false, `${c.clientId} has the password grant`);
    assert.equal(c.implicitFlowEnabled, false, `${c.clientId} has the implicit grant`);
  }
});

test("the adapter recipe the realm pins lists no forbidden scope", () => {
  const { config } = loadConfig(REALM, exampleVars());
  const recipe = loadRecipe(resolve(ROOT, "deploy/keycloak", config.registrationAdapter.recipe));
  const v = recipe.versions[recipe.version];
  for (const n of [...v.defaultScopes, ...v.optionalScopes]) assert.ok(!config.forbiddenClientScopes.includes(n), `recipe ${recipe.version} lists ${n}`);
});

test("the configuration holds no literal secret", () => {
  const { config } = loadConfig(REALM, exampleVars());
  for (const c of config.clients.filter((x) => "secret" in x)) assert.match(c.secret, /^test-[a-z_]+_secret$/, `${c.clientId}'s secret is not a variable`);
});
