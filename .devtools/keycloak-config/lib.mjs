// Helpers for the realm-configuration harness (plan 43): admin calls, the reconciler CLI, a scripted
// browser (authorization code with PKCE over fetch and a path-aware cookie jar), token calls, SCIM
// calls, and a stub Kubernetes service-account issuer. node: modules only, no npm dependencies.
import { execFile } from "node:child_process";
import { createHash, generateKeyPairSync, randomBytes, sign } from "node:crypto";
import { createServer } from "node:http";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";
import { adminClient } from "../../deploy/keycloak/lib/admin.mjs";

export const HERE = dirname(fileURLToPath(import.meta.url));
export const ROOT = resolve(HERE, "../..");
export const RECONCILE = resolve(ROOT, "deploy/keycloak/reconcile.mjs");
export const KC = "http://localhost:8180";
export const REALM = "victual";
export const ISSUER = `${KC}/realms/${REALM}`;
export const TOKEN = `${ISSUER}/protocol/openid-connect/token`;
export const SCIM = `${ISSUER}/scim/v2`;
export const REDIRECT = "http://127.0.0.1:8765/callback"; // never listened on: the code is read from the 302
export const TX = "urn:ietf:params:oauth:grant-type:token-exchange";
export const AT = "urn:ietf:params:oauth:token-type:access_token";
export const SA_ISSUER = "http://127.0.0.1:8199";

/**
 * Reads the harness environment without a shell: the generated credentials in .work/secrets.env
 * (written by run.sh), then env.sh's plain assignments, where `$NAME` names a generated value.
 */
export function harnessEnv() {
  const secrets = Object.fromEntries(readFileSync(resolve(HERE, ".work/secrets.env"), "utf8").trim().split("\n").map((l) => l.split("=")));
  const env = { ...secrets };
  for (const line of readFileSync(resolve(HERE, "env.sh"), "utf8").split("\n")) {
    for (const m of line.matchAll(/(?:export )?([A-Z_]+)=("?)([^"\s]*)\2/g)) env[m[1]] = m[3].startsWith("$") ? secrets[m[3].slice(1)] : m[3];
  }
  return { ...process.env, ...env };
}
export const ENV = harnessEnv();
export const kc = adminClient(REALM, ENV);
/** An admin client for another realm on the harness instance. */
export const kcRealm = (realm) => adminClient(realm, ENV);

/** Decodes a JWT payload without verifying it. */
export const claims = (t) => JSON.parse(Buffer.from(t.split(".")[1], "base64url"));
/** Resolves after `ms` milliseconds. */
export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** Runs reconcile.mjs with `args` and extra environment; returns { code, report, stderr }. */
export function reconcileCli(args = [], extraEnv = {}) {
  return new Promise((res) => {
    execFile(process.execPath, [RECONCILE, ...args], { env: { ...ENV, ...extraEnv }, maxBuffer: 1 << 24 }, (err, stdout, stderr) => {
      let report = null;
      try { report = JSON.parse(stdout); } catch { report = { raw: stdout }; }
      res({ code: err ? err.code ?? 1 : 0, report, stderr });
    });
  });
}

/**
 * A digest of the realm's configuration as Keycloak exports it (partial export with clients, groups
 * and roles; secrets masked by Keycloak). Two equal digests mean no configuration object changed.
 * Volatile fields Keycloak rewrites on its own are not part of the configuration and are removed.
 */
export async function configDigest(realm = REALM) {
  return (await configExport(realm)).digest;
}

/**
 * The partial export behind configDigest: { digest, doc }. Arrays of strings are sorted: Keycloak
 * stores them as sets and exports them in varying order (a client's default scopes came back in
 * two orders over 30 exports with no write in between, 2026-11-27). Arrays of objects, such as
 * flow executions, keep their order.
 */
export async function configExport(realm = REALM) {
  /** Drops notBefore and sorts string arrays. */
  const norm = (k, v) => (k === "notBefore" ? undefined : Array.isArray(v) && v.every((x) => typeof x === "string") ? [...v].sort() : v);
  const doc = JSON.parse(JSON.stringify(await kcRealm(realm).call("POST", "/partial-export?exportClients=true&exportGroupsAndRoles=true"), norm));
  return { digest: createHash("sha256").update(JSON.stringify(doc)).digest("hex").slice(0, 16), doc };
}

/**
 * Names where two exports differ: a top-level key, or for a list of named objects the entry's
 * name (clientId, alias or name) and the first differing key inside it. For diagnosing a case
 * whose export moved.
 */
export function exportDiff(a, b) {
  const out = [];
  for (const k of new Set([...Object.keys(a), ...Object.keys(b)])) {
    if (JSON.stringify(a[k]) === JSON.stringify(b[k])) continue;
    if (Array.isArray(a[k]) && Array.isArray(b[k])) {
      const id = (x) => x?.clientId ?? x?.alias ?? x?.name ?? JSON.stringify(x).slice(0, 40);
      const ma = new Map(a[k].map((x) => [id(x), x])), mb = new Map(b[k].map((x) => [id(x), x]));
      for (const n of new Set([...ma.keys(), ...mb.keys()])) {
        const x = ma.get(n), y = mb.get(n);
        if (JSON.stringify(x) === JSON.stringify(y)) continue;
        const inner = x && y && typeof x === "object" ? Object.keys({ ...x, ...y }).filter((q) => JSON.stringify(x[q]) !== JSON.stringify(y[q])) : [];
        out.push(`${k}[${n}]${x ? "" : " added"}${y ? "" : " removed"}${inner.length ? `.${inner.join(",")}` : ""}`);
      }
      if (!out.some((o) => o.startsWith(`${k}[`))) out.push(`${k} (order)`);
    } else out.push(k);
  }
  return out;
}

/** Form-encoded POST to the token endpoint of `realm`; returns { status, body }. */
export async function tokenCall(form, realm = REALM) {
  const r = await fetch(`${KC}/realms/${realm}/protocol/openid-connect/token`, { method: "POST", body: new URLSearchParams(form) });
  return { status: r.status, body: await r.json().catch(() => ({})) };
}

/** A path-aware cookie jar for one host, enough for Keycloak's realm-scoped cookies. */
export class Jar {
  /** Creates an empty jar. */
  constructor() { this.c = new Map(); }
  /** Stores the Set-Cookie headers of a response. */
  take(res) {
    for (const sc of res.headers.getSetCookie?.() ?? []) {
      const [pair, ...attrs] = sc.split(";").map((s) => s.trim());
      const [name, ...v] = pair.split("=");
      // A cookie without a Path attribute applies to the whole host here; Keycloak always sets one.
      const path = (attrs.find((a) => /^path=/i.test(a)) ?? "path=/").slice(5) || "/";
      const expired = attrs.some((a) => /^max-age=0$/i.test(a) || /^expires=thu, 01 jan 1970/i.test(a));
      if (expired) this.c.delete(`${name}@${path}`); else this.c.set(`${name}@${path}`, { name, value: v.join("="), path });
    }
  }
  /** Returns the Cookie header for `url`. */
  header(url) {
    const p = new URL(url).pathname;
    return [...this.c.values()].filter((x) => p.startsWith(x.path.replace(/\/$/, "")) || x.path === "/").map((x) => `${x.name}=${x.value}`).join("; ");
  }
}

/** Decodes the HTML entities Keycloak's templates use in attribute values. */
const unhtml = (s) => s.replace(/&amp;/g, "&").replace(/&#x3D;/g, "=").replace(/&#39;/g, "'").replace(/&quot;/g, '"');

/** Visible text of an HTML page, whitespace collapsed, for asserting on error messages. */
export const pageText = (html) => html.replace(/<script[\s\S]*?<\/script>/g, "").replace(/<style[\s\S]*?<\/style>/g, "").replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();

/** Fetches with the jar and follows redirects until one reaches `stop` (the client's redirect URI) or leaves Keycloak. */
async function walk(jar, url, init = {}, stop = REDIRECT) {
  for (let i = 0; i < 20; i++) {
    const r = await fetch(url, { ...init, redirect: "manual", headers: { ...(init.headers ?? {}), Cookie: jar.header(url) } });
    jar.take(r);
    if (r.status >= 300 && r.status < 400) {
      const loc = new URL(r.headers.get("location"), url).toString();
      if (loc.startsWith(stop) || !loc.startsWith(KC)) return { redirect: loc };
      url = loc; init = {}; continue;
    }
    return { status: r.status, html: await r.text(), url };
  }
  throw new Error("too many redirects");
}

/**
 * Drives one authorization-code login at `realm` for `clientId`. `steps` is a list of form posts:
 * each { username, password } fills the login form of the page reached so far. `params` adds
 * authorization parameters (kc_idp_hint, scope, code_challenge_method...). Returns { code, verifier,
 * redirect } on success, or { error, text } with the page text where Keycloak stopped.
 */
export async function browserLogin({ realm = REALM, clientId, steps = [], params = {}, jar = new Jar(), pkce = "S256", redirectUri = REDIRECT }) {
  const verifier = randomBytes(32).toString("base64url");
  const q = new URLSearchParams({ client_id: clientId, response_type: "code", redirect_uri: redirectUri, scope: "openid", state: "s", ...params });
  if (pkce === "S256") { q.set("code_challenge", createHash("sha256").update(verifier).digest("base64url")); q.set("code_challenge_method", "S256"); }
  if (pkce === "plain") { q.set("code_challenge", verifier); q.set("code_challenge_method", "plain"); }
  /** Posts a form found on the current page and follows the redirects that come back. */
  const post = (action, form) => walk(jar, new URL(unhtml(action), KC).toString(), { method: "POST", body: new URLSearchParams(form), headers: { "Content-Type": "application/x-www-form-urlencoded" } }, redirectUri);
  let page = await walk(jar, `${KC}/realms/${realm}/protocol/openid-connect/auth?${q}`, {}, redirectUri);
  for (const s of steps) {
    if (page.redirect) break;
    const m = /<form[^>]*id="kc-form-login"[^>]*action="([^"]+)"/.exec(page.html ?? "");
    if (!m) return { error: "no login form", text: pageText(page.html ?? "") };
    page = await post(m[1], s);
  }
  // A client with Consent required shows a grant screen; accept it, as a person approving would.
  const consent = /<form[^>]*action="([^"]+)"[^>]*>(?:(?!<\/form>)[\s\S])*name="accept"/.exec(page.html ?? "");
  if (!page.redirect && consent) page = await post(consent[1], { accept: "Yes" });
  if (page.redirect) {
    const u = new URL(page.redirect);
    if (u.searchParams.get("code")) return { code: u.searchParams.get("code"), verifier, redirect: page.redirect, jar };
    return { error: u.searchParams.get("error") ?? "redirect without code", text: u.searchParams.get("error_description") ?? "", jar };
  }
  return { error: `stopped at ${page.status}`, text: pageText(page.html ?? ""), jar };
}

/** Exchanges an authorization code for tokens at `clientId` (with a secret for a confidential client). */
export async function redeem(login, clientId, secret, realm = REALM, redirectUri = REDIRECT) {
  return tokenCall({ grant_type: "authorization_code", code: login.code, redirect_uri: redirectUri, client_id: clientId, code_verifier: login.verifier, ...(secret ? { client_secret: secret } : {}) }, realm);
}

/** Calls Keycloak's SCIM API with a bearer token; returns { status, body }. */
export async function scim(method, path, body, bearer) {
  const r = await fetch(`${SCIM}${path}`, { method, headers: { Authorization: `Bearer ${bearer}`, "Content-Type": "application/scim+json" }, body: body && JSON.stringify(body) });
  const text = await r.text();
  let parsed; try { parsed = JSON.parse(text); } catch { parsed = text; }
  return { status: r.status, body: parsed };
}

/**
 * A stub of the Kubernetes service-account issuer: OIDC discovery and a JWKS on SA_ISSUER, and
 * `mint(sub, aud)` to sign a projected-token-shaped JWT. It stands in for the cluster's issuer, which
 * Keycloak's `kubernetes` identity provider trusts (ADR-0057 question 6). Returns { mint, close }.
 */
export function startSaIssuer() {
  const { publicKey, privateKey } = generateKeyPairSync("rsa", { modulusLength: 2048 });
  const jwk = { ...publicKey.export({ format: "jwk" }), kid: "harness", use: "sig", alg: "RS256" };
  const server = createServer((req, res) => {
    const body = req.url.startsWith("/.well-known/openid-configuration")
      ? { issuer: SA_ISSUER, jwks_uri: `${SA_ISSUER}/openid/v1/jwks`, response_types_supported: ["id_token"], subject_types_supported: ["public"], id_token_signing_alg_values_supported: ["RS256"] }
      : req.url.startsWith("/openid/v1/jwks") ? { keys: [jwk] } : null;
    res.writeHead(body ? 200 : 404, { "Content-Type": "application/json" });
    res.end(JSON.stringify(body ?? {}));
  });
  server.listen(8199, "127.0.0.1");
  /** Signs a service-account token for `sub` with audience `aud`, valid for `ttl` seconds. */
  function mint(sub, aud = ISSUER, ttl = 600) {
    const now = Math.floor(Date.now() / 1000);
    /** base64url of an object's JSON. */
    const enc = (o) => Buffer.from(JSON.stringify(o)).toString("base64url");
    const head = enc({ alg: "RS256", kid: "harness", typ: "JWT" });
    const body = enc({ iss: SA_ISSUER, sub, aud: [aud], iat: now, nbf: now, exp: now + ttl, jti: randomBytes(8).toString("hex") });
    return `${head}.${body}.${sign("RSA-SHA256", Buffer.from(`${head}.${body}`), privateKey).toString("base64url")}`;
  }
  return { mint, close: () => server.close() };
}

/** Client-assertion form fields for a federated-jwt client. */
export const assertion = (jwt) => ({ client_assertion_type: "urn:ietf:params:oauth:client-assertion-type:jwt-bearer", client_assertion: jwt });
