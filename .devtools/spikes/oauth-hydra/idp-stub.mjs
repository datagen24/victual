// Stand-in for the part of Victual that Hydra delegates to: the login and consent screens.
// Hydra redirects the browser here with a *_challenge; this app decides, then tells Hydra
// through the admin API. No credentials ever reach Hydra. Auto-accepts a fixed subject, which
// is enough to prove the delegation seam (a password form would change nothing about it).
import { createServer } from "node:http";

const ADMIN = process.env.HYDRA_ADMIN ?? "http://localhost:4445";
// RFC 8707 shim: Hydra ignores `resource`, but the consent request still carries the original
// authorize URL in `request_url`. The consent app is therefore the place that turns an allowed
// `resource` into the token audience, and the only thing stopping a client naming any resource.
const RESOURCES = (process.env.SPIKE_RESOURCES ?? "http://localhost:8080/mcp").split(",");
const SUBJECT = process.env.SPIKE_SUBJECT ?? "victual-user:1";

async function admin(method, path, params, body) {
  const url = new URL(ADMIN + path);
  for (const [k, v] of Object.entries(params ?? {})) url.searchParams.set(k, v);
  const res = await fetch(url, {
    method,
    headers: { "content-type": "application/json" },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${text}`);
  return text ? JSON.parse(text) : {};
}

const redirect = (res, to) => { res.writeHead(302, { location: to }); res.end(); };

createServer(async (req, res) => {
  const url = new URL(req.url, "http://localhost:3000");
  try {
    if (url.pathname === "/login") {
      const challenge = url.searchParams.get("login_challenge");
      const request = await admin("GET", "/admin/oauth2/auth/requests/login", { login_challenge: challenge });
      console.log("login request", JSON.stringify({ client: request.client.client_id, skip: request.skip, scope: request.requested_scope, aud: request.requested_access_token_audience }));
      const done = await admin("PUT", "/admin/oauth2/auth/requests/login/accept", { login_challenge: challenge }, { subject: SUBJECT, remember: false });
      return redirect(res, done.redirect_to);
    }
    if (url.pathname === "/consent") {
      const challenge = url.searchParams.get("consent_challenge");
      const request = await admin("GET", "/admin/oauth2/auth/requests/consent", { consent_challenge: challenge });
      console.log("consent request", JSON.stringify({ subject: request.subject, scope: request.requested_scope, aud: request.requested_access_token_audience }));
      const asked = new URL(request.request_url).searchParams.getAll("resource");
      console.log("consent resource param(s):", JSON.stringify(asked));
      const bad = asked.filter((r) => !RESOURCES.includes(r));
      if (bad.length) {
        const denied = await admin("PUT", "/admin/oauth2/auth/requests/consent/reject", { consent_challenge: challenge }, { error: "invalid_target", error_description: `unknown resource ${bad.join(",")}` });
        return redirect(res, denied.redirect_to);
      }
      const done = await admin("PUT", "/admin/oauth2/auth/requests/consent/accept", { consent_challenge: challenge }, {
        grant_scope: request.requested_scope,
        grant_access_token_audience: [...new Set([...request.requested_access_token_audience, ...asked])],
        remember: false,
        // Claims added to the JWT access token: the seam for "token -> Victual user".
        session: { access_token: { victual_user_id: 1 }, id_token: {} },
      });
      return redirect(res, done.redirect_to);
    }
    if (url.pathname === "/register" && req.method === "POST") {
      // RFC 7591 registration, owned by the app instead of Hydra: the app decides who may register
      // what (public clients, authorization_code only, known scopes, loopback or https redirects)
      // and returns a response without the empty/null fields Hydra emits.
      const chunks = []; for await (const c of req) chunks.push(c);
      const m = JSON.parse(Buffer.concat(chunks).toString() || "{}");
      const refuse = (error_description) => { res.writeHead(400, { "content-type": "application/json" }); res.end(JSON.stringify({ error: "invalid_client_metadata", error_description })); };
      if ((m.token_endpoint_auth_method ?? "none") !== "none") return refuse("only public clients (token_endpoint_auth_method none)");
      const grants = m.grant_types ?? ["authorization_code"];
      if (grants.some((g) => !["authorization_code", "refresh_token"].includes(g))) return refuse("grant_types limited to authorization_code and refresh_token");
      const uris = m.redirect_uris ?? [];
      const okUri = (u) => { try { const p = new URL(u); return (p.protocol === "http:" && ["localhost", "127.0.0.1", "[::1]"].includes(p.hostname)) || p.protocol === "https:"; } catch { return false; } };
      if (!uris.length || !uris.every(okUri)) return refuse("redirect_uris must be loopback http or https");
      const allowed = ["victual.read", "victual.write", "offline_access"];
      const scope = (m.scope ?? "victual.read").split(" ").filter((s) => allowed.includes(s)).join(" ");
      const created = await admin("POST", "/admin/clients", {}, {
        client_name: String(m.client_name ?? "unnamed").slice(0, 100), redirect_uris: uris, grant_types: grants, response_types: ["code"],
        token_endpoint_auth_method: "none", scope,
      });
      console.log("registered", JSON.stringify({ name: created.client_name, redirect_uris: uris, scope }));
      res.writeHead(201, { "content-type": "application/json" });
      return res.end(JSON.stringify({ client_id: created.client_id, client_id_issued_at: Math.floor(Date.parse(created.created_at) / 1000), client_name: created.client_name,
        redirect_uris: uris, grant_types: grants, response_types: ["code"], token_endpoint_auth_method: "none", scope }));
    }
    if (url.pathname === "/device") {
      // Device flow: Hydra sends the browser here after the user types the code at /oauth2/device/verify.
      const challenge = url.searchParams.get("device_challenge");
      const userCode = url.searchParams.get("user_code");
      if (!challenge) { res.writeHead(200); return res.end("enter your code at http://localhost:4444/oauth2/device/verify"); }
      console.log("device request", JSON.stringify({ challenge: challenge.slice(0, 8), userCode }));
      const done = await admin("PUT", "/admin/oauth2/auth/requests/device/accept", { device_challenge: challenge }, { user_code: userCode });
      return redirect(res, done.redirect_to);
    }
    if (url.pathname === "/device/success") { res.writeHead(200); return res.end("device authorised"); }
    res.writeHead(404); res.end();
  } catch (e) {
    console.error(String(e)); res.writeHead(500); res.end(String(e));
  }
}).listen(3000, () => console.log("idp-stub on :3000"));
