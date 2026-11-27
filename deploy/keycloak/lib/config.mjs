// Loads and validates the realm configuration before anything is written to Keycloak.
//
// The configuration is JSON. A string may hold `${NAME}` placeholders, resolved from the
// environment. Every placeholder must resolve, and no value may be the literal `CHANGE-ME`, or the
// whole configuration is refused before the first admin call. Variables whose name ends in
// `_SECRET` are secrets: their values come from a Kubernetes Secret and are never printed.
//
// An entry of `identityProviders` (or any other list) may be `{ "$include": "<file>", "$when": "VAR" }`.
// The file is loaded relative to the configuration and included only when VAR is set and non-empty;
// otherwise it is left out, and the reconciler prunes the object if it owns one.
import { createHash } from "node:crypto";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";

/** The top-level keys the reconciler understands. Anything else is refused, so a typo is not ignored. */
export const TOP_LEVEL = new Set([
  "$comment", "format", "configVersion", "keycloakVersion", "realm", "realmRoles", "defaultRoles",
  "clientScopes", "realmDefaultClientScopes", "forbiddenClientScopes", "forbiddenClientScopesExempt", "authenticationFlows",
  "flowBindings", "identityProviders", "clients", "clientPolicies", "clientRegistrationPolicies",
  "userProfile", "registrationAdapter",
]);
export const FORMAT = "victual-keycloak-realm/1";

/** Error for a configuration the reconciler refuses before writing anything. */
export class ConfigError extends Error {}

/** Expands `$include` entries in every list of `node`, loading files relative to `dir`. */
function expandIncludes(node, dir, env, included) {
  if (Array.isArray(node)) {
    const out = [];
    for (const item of node) {
      if (item && typeof item === "object" && "$include" in item) {
        const extra = Object.keys(item).filter((k) => !["$include", "$when", "$comment"].includes(k));
        if (extra.length) throw new ConfigError(`$include entry has unknown keys: ${extra.join(", ")}`);
        if (item.$when && !env[item.$when]) { included.push({ file: item.$include, included: false, when: item.$when }); continue; }
        const path = resolve(dir, item.$include);
        included.push({ file: item.$include, included: true, when: item.$when ?? null });
        out.push(expandIncludes(JSON.parse(readFileSync(path, "utf8")), dirname(path), env, included));
      } else out.push(expandIncludes(item, dir, env, included));
    }
    return out;
  }
  if (node && typeof node === "object") {
    return Object.fromEntries(Object.entries(node).filter(([k]) => k !== "$comment").map(([k, v]) => [k, expandIncludes(v, dir, env, included)]));
  }
  return node;
}

/** Replaces `${NAME}` in every string of `node`; records used names and unresolved ones. */
function substitute(node, env, used, missing) {
  if (typeof node === "string") {
    return node.replace(/\$\{([A-Z][A-Z0-9_]*)\}/g, (_, name) => {
      used.add(name);
      const v = env[name];
      if (v === undefined || v === "") { missing.add(name); return ""; }
      return v;
    });
  }
  if (Array.isArray(node)) return node.map((x) => substitute(x, env, used, missing));
  if (node && typeof node === "object") return Object.fromEntries(Object.entries(node).map(([k, v]) => [k, substitute(v, env, used, missing)]));
  return node;
}

/** Collects the paths of every string equal to CHANGE-ME, so placeholders are never applied. */
function findPlaceholders(node, path = "", out = []) {
  if (node === "CHANGE-ME") out.push(path || "/");
  else if (Array.isArray(node)) node.forEach((x, i) => findPlaceholders(x, `${path}/${i}`, out));
  else if (node && typeof node === "object") for (const [k, v] of Object.entries(node)) findPlaceholders(v, `${path}/${k}`, out);
  return out;
}

/** Checks names that must be unique within a list, and that the realm block names a realm. */
function validateShape(cfg) {
  const errors = [];
  const unknown = Object.keys(cfg).filter((k) => !TOP_LEVEL.has(k));
  if (unknown.length) errors.push(`unknown top-level keys: ${unknown.join(", ")}`);
  if (cfg.format !== FORMAT) errors.push(`format must be ${FORMAT}, got ${cfg.format}`);
  if (!Number.isInteger(cfg.configVersion)) errors.push("configVersion must be an integer");
  if (!cfg.realm?.realm) errors.push("realm.realm must name the realm");
  for (const [list, key] of [["clients", "clientId"], ["clientScopes", "name"], ["realmRoles", "name"], ["authenticationFlows", "alias"], ["identityProviders", "alias"]]) {
    const names = (cfg[list] ?? []).map((x) => x[key]);
    const dup = names.filter((n, i) => names.indexOf(n) !== i);
    if (names.some((n) => !n)) errors.push(`${list}: every entry needs ${key}`);
    if (dup.length) errors.push(`${list}: duplicate ${key} ${[...new Set(dup)].join(", ")}`);
  }
  return errors;
}

/**
 * Loads the configuration at `file` against `env` (default process.env). Returns
 * { config, digest, secrets, used, included }. Throws ConfigError listing every problem found.
 * `digest` hashes the file text and the names (not values) of the variables, so it changes when the
 * versioned configuration changes and never reveals a secret.
 */
export function loadConfig(file, env = process.env) {
  const text = readFileSync(file, "utf8");
  let raw;
  try { raw = JSON.parse(text); } catch (e) { throw new ConfigError(`${file}: not valid JSON: ${e.message}`); }
  const included = [];
  const expanded = expandIncludes(raw, dirname(resolve(file)), env, included);
  const used = new Set(), missing = new Set();
  const config = substitute(expanded, env, used, missing);
  const errors = validateShape(config);
  if (missing.size) errors.push(`unset variables: ${[...missing].sort().join(", ")}`);
  const ph = findPlaceholders(config);
  if (ph.length) errors.push(`CHANGE-ME placeholders at: ${ph.join(", ")}`);
  if (errors.length) throw new ConfigError(errors.join("; "));
  const includedText = included.filter((i) => i.included).map((i) => readFileSync(resolve(dirname(resolve(file)), i.file), "utf8")).join("\n");
  const digest = createHash("sha256").update(text).update(includedText).update([...used].sort().join(",")).digest("hex").slice(0, 16);
  const secrets = [...used].filter((n) => n.endsWith("_SECRET")).map((n) => env[n]);
  return { config, digest, secrets, used: [...used].sort(), included };
}

/** Replaces every secret value in `text` with `***`, for log lines and error messages. */
export function redact(text, secrets) {
  let out = String(text);
  for (const s of secrets) if (s) out = out.split(s).join("***");
  return out;
}
