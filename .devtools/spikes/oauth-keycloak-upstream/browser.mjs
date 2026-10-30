// A scripted browser for brokered logins: Keycloak on localhost:8180 sends it to Authentik, which it
// knows as host.containers.internal:9000, the name the Keycloak container uses too. node:http with a
// lookup that resolves that name to 127.0.0.1, because fetch cannot set a Host header, and Authentik
// derives its issuer from the Host the request carries. One cookie jar per host; manual redirects.
// Authentik's login is answered through its flow executor API, as the Authentik spike does.
import { createHash, randomBytes } from "node:crypto";
import http from "node:http";

export const AK_HOST = "host.containers.internal:9000";
export const AK = `http://${AK_HOST}`;
export const KC = "http://localhost:8180";
export const REALM = "victual";
export const REDIRECT = "http://127.0.0.1:8765/callback"; // never listened on: the code is read from the 302

/** Resolves every host this spike talks to as 127.0.0.1, answering both lookup call shapes. */
function lookup(_host, opts, cb) {
  if (typeof opts === "function") return opts(null, "127.0.0.1", 4);
  return opts?.all ? cb(null, [{ address: "127.0.0.1", family: 4 }]) : cb(null, "127.0.0.1", 4);
}

/** One HTTP request without redirects; resolves `{ status, headers, location, text }`. */
export function request(method, url, { headers = {}, body } = {}) {
  const u = new URL(url);
  return new Promise((resolve, reject) => {
    const len = body ? { "Content-Length": Buffer.byteLength(body) } : {};
    const r = http.request({ host: u.hostname, port: u.port, path: u.pathname + u.search, method, headers: { ...headers, ...len, Host: u.host }, lookup, agent: false }, (res) => {
      const chunks = [];
      res.on("data", (c) => chunks.push(c));
      res.on("end", () => resolve({ status: res.statusCode, headers: res.headers, location: res.headers.location, text: Buffer.concat(chunks).toString("utf8") }));
    });
    r.on("error", reject);
    if (body) r.write(body);
    r.end();
  });
}

/** A browser: per-host cookie jars and a `go` that sends cookies and stores Set-Cookie. */
export function browser() {
  const jars = new Map();
  /** The cookie jar of the URL's host. */
  const jar = (u) => { const h = new URL(u).host; if (!jars.has(h)) jars.set(h, new Map()); return jars.get(h); };
  /** One request with this browser's cookies for the URL's host. */
  async function go(method, url, { headers = {}, body } = {}) {
    const j = jar(url);
    const r = await request(method, url, { headers: { ...headers, Cookie: [...j].map(([k, v]) => `${k}=${v}`).join("; ") }, body });
    for (const c of [].concat(r.headers["set-cookie"] ?? [])) {
      const [kv] = c.split(";");
      const i = kv.indexOf("=");
      if (kv.slice(i + 1) === "" || /Max-Age=0/i.test(c)) j.delete(kv.slice(0, i)); else j.set(kv.slice(0, i), kv.slice(i + 1));
    }
    return r;
  }
  return { go, jar };
}

/** Undoes the HTML escaping Keycloak applies to form action URLs. */
const unescapeHtml = (s) => s.replaceAll("&amp;", "&").replaceAll("&#61;", "=").replaceAll("&quot;", '"');
/** The visible text of an HTML page, shortened. */
export const pageText = (html, n = 160) => html.replace(/<script[\s\S]*?<\/script>/g, " ").replace(/<style[\s\S]*?<\/style>/g, " ")
  .replace(/<[^>]+>/g, " ").replace(/&[a-z#0-9]+;/g, " ").replace(/\s+/g, " ").trim().slice(0, n);

/** Answers Authentik's identification, password and consent stages through the flow executor; returns the redirect target. */
async function authentikFlow(b, slug, query, user) {
  const ex = `${AK}/api/v3/flows/executor/${slug}/?query=${encodeURIComponent(query)}`;
  /** Calls the executor and follows its redirects back to itself; returns the next challenge. */
  const challenge = async (method, body) => {
    const headers = body ? { "Content-Type": "application/json", "X-authentik-CSRF": b.jar(AK).get("authentik_csrf") ?? "" } : {};
    let r = await b.go(method, ex, { headers, body: body && JSON.stringify(body) });
    for (let i = 0; i < 6 && r.status === 302 && r.location.startsWith("/api/v3/flows/executor/"); i++) r = await b.go("GET", new URL(r.location, AK).toString());
    if (r.status === 302) return { component: "xak-flow-redirect", to: r.location };
    return JSON.parse(r.text);
  };
  let c = await challenge("GET");
  for (let i = 0; i < 8; i++) {
    if (c.component === "xak-flow-redirect") return new URL(c.to, AK).toString();
    if (c.component === "ak-stage-access-denied") return { denied: c.error_message ?? "access denied" };
    const body = c.component === "ak-stage-identification" ? { uid_field: user.username }
      : c.component === "ak-stage-password" ? { password: user.password }
        : c.component === "ak-stage-consent" ? { token: c.token } : null;
    if (!body) return { denied: `unhandled stage ${c.component}` };
    c = await challenge("POST", body);
    if (c.response_errors && Object.keys(c.response_errors).length) return { denied: JSON.stringify(c.response_errors).slice(0, 120) };
  }
  return { denied: "too many stages" };
}

/**
 * Authorization code + PKCE at Keycloak with browser `b`, following Keycloak's redirect to Authentik
 * when it sends one. `user` answers Authentik's login (or Keycloak's own form when `local` is set).
 * Returns the token response plus `_upstream` (whether Authentik was visited), or `{ error, step }`.
 */
export async function kcLogin(b, client, user, { hint = "authentik", local = false, scope = "openid profile" } = {}) {
  const verifier = randomBytes(32).toString("base64url"), state = randomBytes(8).toString("hex");
  const q = new URLSearchParams({ client_id: client, response_type: "code", redirect_uri: REDIRECT, scope, state,
    code_challenge: createHash("sha256").update(verifier).digest("base64url"), code_challenge_method: "S256" });
  if (hint && !local) q.set("kc_idp_hint", hint);
  let url = `${KC}/realms/${REALM}/protocol/openid-connect/auth?${q}`;
  let upstream = false;
  /** Reads the code from a redirect to REDIRECT and redeems it at Keycloak. */
  const finish = async (next) => {
    if (next.searchParams.get("error")) return { error: next.searchParams.get("error"), step: next.searchParams.get("error_description") ?? "", _upstream: upstream };
    const t = await request("POST", `${KC}/realms/${REALM}/protocol/openid-connect/token`, { headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({ grant_type: "authorization_code", client_id: client, code: next.searchParams.get("code"), redirect_uri: REDIRECT, code_verifier: verifier }).toString() });
    return { ...JSON.parse(t.text), status: t.status, _upstream: upstream };
  };
  for (let i = 0; i < 30; i++) {
    if (url.startsWith(REDIRECT)) return finish(new URL(url));
    let r;
    try { r = await b.go("GET", url); } catch (e) { return { error: "unreachable", step: `${new URL(url).host}: ${e.code ?? e.message}`, _upstream: upstream }; }
    if (r.location) {
      const next = new URL(r.location, url);
      if (next.toString().startsWith(REDIRECT)) return finish(next);
      const flow = next.host === AK_HOST && next.pathname.match(/^\/if\/flow\/([^/]+)\//);
      if (flow) {
        upstream = true;
        const to = await authentikFlow(b, flow[1], next.search.slice(1), user);
        if (to.denied) return { error: "upstream_denied", step: to.denied, _upstream: upstream };
        url = to;
      } else url = next.toString();
      continue;
    }
    if (r.status >= 500 || r.status === 0) return { error: `http_${r.status}`, step: pageText(r.text), _upstream: upstream };
    // A Keycloak page: its own login form when `local`, otherwise an error or a prompt to stop at.
    const form = r.text.match(/<form[^>]*id="kc-form-login"[^>]*action="([^"]+)"/);
    if (local && form) {
      const p = await b.go("POST", unescapeHtml(form[1]), { headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ username: user.username, password: user.password, credentialId: "" }).toString() });
      if (!p.location) return { error: "local_login_refused", step: pageText(p.text), _upstream: upstream };
      url = new URL(p.location, url).toString();
      continue;
    }
    return { error: "page", step: pageText(r.text, 400), _upstream: upstream };
  }
  return { error: "too_many_redirects", _upstream: upstream };
}

/** A refresh at Keycloak for a public client. */
export async function kcRefresh(client, rt) {
  const r = await request("POST", `${KC}/realms/${REALM}/protocol/openid-connect/token`, { headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ grant_type: "refresh_token", client_id: client, refresh_token: rt }).toString() });
  return { status: r.status, ...JSON.parse(r.text) };
}
