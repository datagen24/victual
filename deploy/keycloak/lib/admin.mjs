// Keycloak admin REST client for the realm reconciler. Uses only `fetch`; no npm dependency.
//
// Credentials come from the environment, never from a file in the repository:
//   KEYCLOAK_ADMIN_URL           base URL the reconciler talks to (default KEYCLOAK_URL)
//   KEYCLOAK_ADMIN_REALM         realm the credential belongs to (default "master")
//   KEYCLOAK_ADMIN_CLIENT_ID     with KEYCLOAK_ADMIN_CLIENT_SECRET: a client-credentials grant
//   KEYCLOAK_ADMIN_USERNAME      with KEYCLOAK_ADMIN_PASSWORD: the bootstrap admin through admin-cli
// The chart mounts these from a Kubernetes Secret into the setup Job only (ADR-0058 decision 8).

/** Error raised for a refused admin call; carries the method, path, status and Keycloak's message. */
export class AdminError extends Error {
  /** Builds the error from the failed request and the response text. */
  constructor(method, path, status, text) {
    super(`${method} ${path} -> ${status} ${String(text).slice(0, 300)}`);
    this.method = method; this.path = path; this.status = status; this.body = text;
  }
}

/** Resolves after `ms` milliseconds. */
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** fetch with three retries on a dropped connection; HTTP error statuses are not retried. */
async function rfetch(url, opts) {
  for (let i = 0; ; i++) {
    try { return await fetch(url, opts); } catch (e) { if (i >= 3) throw e; await sleep(500 * (i + 1)); }
  }
}

/**
 * Creates an admin client bound to one realm. `env` defaults to process.env. The returned object has
 * `call(method, path, body, opts)` for realm-relative paths ("/clients"), `root(...)` for paths under
 * /admin/realms, and `serverVersion()`.
 */
export function adminClient(realm, env = process.env) {
  const base = (env.KEYCLOAK_ADMIN_URL ?? env.KEYCLOAK_URL ?? "").replace(/\/$/, "");
  if (!base) throw new Error("KEYCLOAK_ADMIN_URL or KEYCLOAK_URL must be set");
  const authRealm = env.KEYCLOAK_ADMIN_REALM ?? "master";
  let tok = { t: null, exp: 0 };

  /** Returns a cached admin access token, fetching a new one shortly before expiry. */
  async function token() {
    if (tok.exp > Date.now() + 10_000) return tok.t;
    const form = env.KEYCLOAK_ADMIN_CLIENT_ID
      ? { grant_type: "client_credentials", client_id: env.KEYCLOAK_ADMIN_CLIENT_ID, client_secret: env.KEYCLOAK_ADMIN_CLIENT_SECRET ?? "" }
      : { grant_type: "password", client_id: "admin-cli", username: env.KEYCLOAK_ADMIN_USERNAME ?? "", password: env.KEYCLOAK_ADMIN_PASSWORD ?? "" };
    const r = await rfetch(`${base}/realms/${authRealm}/protocol/openid-connect/token`, { method: "POST", body: new URLSearchParams(form) });
    const j = await r.json().catch(() => ({}));
    if (!j.access_token) throw new Error(`admin login to realm ${authRealm} failed: ${r.status} ${j.error ?? ""}`);
    tok = { t: j.access_token, exp: Date.now() + j.expires_in * 1000 };
    return tok.t;
  }

  /** Sends one admin request to an absolute admin path and returns the parsed body (or null). */
  async function send(method, path, body, { allow = [] } = {}) {
    for (let attempt = 0; ; attempt++) {
      const r = await rfetch(`${base}${path}`, {
        method,
        headers: { Authorization: `Bearer ${await token()}`, "Content-Type": "application/json" },
        body: body === undefined ? undefined : JSON.stringify(body),
      });
      if (r.status === 401 && attempt === 0) { tok = { t: null, exp: 0 }; continue; }
      const text = await r.text();
      if (!r.ok && !allow.includes(r.status)) throw new AdminError(method, path, r.status, text);
      if (!r.ok) return { status: r.status, missing: true };
      if (!text) return r.headers.get("location") ? { location: r.headers.get("location") } : null;
      try { return JSON.parse(text); } catch { return text; }
    }
  }

  return {
    realm,
    base,
    /** Calls a path relative to /admin/realms/<realm>. */
    call: (method, path, body, opts) => send(method, `/admin/realms/${realm}${path}`, body, opts),
    /** Calls a path relative to /admin/realms. */
    root: (method, path, body, opts) => send(method, `/admin/realms${path}`, body, opts),
    /** Returns the server's version string from /admin/serverinfo. */
    serverVersion: async () => (await send("GET", "/admin/serverinfo")).systemInfo.version,
  };
}
