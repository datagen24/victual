// Spike resource server: the shape the MCP sidecar takes once the AS is external (spec §4.1).
// RFC 9728 metadata, a WWW-Authenticate challenge pointing at it, and local JWT validation
// (signature via JWKS, iss, exp, nbf, aud) in place of src/auth/resolver.ts forwarding a key.
import { createServer } from "node:http";
import { createPublicKey, verify } from "node:crypto";
import { createMcpHandler, McpServer } from "@modelcontextprotocol/server";

const PORT = 8080, BASE = `http://localhost:${PORT}`, RESOURCE = `${BASE}/mcp`;
const ISSUER = process.env.SPIKE_ISSUER ?? "http://localhost:4444";
const PRM = `${BASE}/.well-known/oauth-protected-resource/mcp`;
const log = (...a) => console.log(new Date().toISOString().slice(11, 23), ...a);

let jwks = { at: 0, keys: [] };
async function key(kid) {
  if (!jwks.keys.some((k) => k.kid === kid) && Date.now() - jwks.at > 10_000) {
    const meta = await (await fetch(`${ISSUER}/.well-known/openid-configuration`)).json();
    jwks = { at: Date.now(), keys: (await (await fetch(meta.jwks_uri)).json()).keys };
  }
  return jwks.keys.find((k) => k.kid === kid);
}

async function validate(token) {
  const [h, p, s] = token.split(".");
  if (!s) throw new Error("not a JWT");
  const header = JSON.parse(Buffer.from(h, "base64url")), claims = JSON.parse(Buffer.from(p, "base64url"));
  const jwk = await key(header.kid);
  if (!jwk) throw new Error("unknown kid");
  if (header.alg !== "RS256") throw new Error("alg");
  if (!verify("RSA-SHA256", Buffer.from(`${h}.${p}`), createPublicKey({ key: jwk, format: "jwk" }), Buffer.from(s, "base64url"))) throw new Error("bad signature");
  const now = Date.now() / 1000;
  if (claims.iss !== ISSUER) throw new Error("iss");
  if (claims.exp <= now || (claims.nbf ?? 0) > now + 5) throw new Error("expired/nbf");
  if (![].concat(claims.aud ?? []).includes(RESOURCE)) throw new Error(`aud ${JSON.stringify(claims.aud)} does not include ${RESOURCE}`);
  return claims;
}

const handler = createMcpHandler(({ authInfo }) => {
  const server = new McpServer({ name: "spike-rs", version: "0" }, { capabilities: { tools: {} } });
  server.registerTool("whoami", { description: "Return the Victual user the token maps to", inputSchema: {} }, async () => ({
    content: [{ type: "text", text: JSON.stringify({ sub: authInfo.extra.sub, victual_user_id: authInfo.extra.victual_user_id, scopes: authInfo.scopes }) }],
  }));
  return server;
});

createServer(async (req, res) => {
  const url = new URL(req.url, BASE);
  const send = (status, headers, body) => { log(req.method, url.pathname, "->", status); res.writeHead(status, headers); res.end(body); };
  if (url.pathname.startsWith("/.well-known/oauth-protected-resource")) {
    return send(200, { "content-type": "application/json" }, JSON.stringify({
      resource: RESOURCE, authorization_servers: [ISSUER], scopes_supported: ["victual.read", "victual.write"], bearer_methods_supported: ["header"],
    }));
  }
  if (url.pathname !== "/mcp") return send(404, {});
  const auth = req.headers.authorization ?? "";
  const challenge = (error) => ({ "www-authenticate": `Bearer resource_metadata="${PRM}"${error ? `, error="invalid_token", error_description="${error}"` : ""}`, "content-type": "application/json" });
  if (!auth.toLowerCase().startsWith("bearer ")) return send(401, challenge(), "{}");
  let claims;
  try { claims = await validate(auth.slice(7).trim()); } catch (e) { log("token rejected:", e.message); return send(401, challenge(e.message), "{}"); }
  const chunks = []; for await (const c of req) chunks.push(c);
  const request = new Request(new URL(url.pathname, BASE), { method: req.method, headers: Object.entries(req.headers).flatMap(([k, v]) => v === undefined ? [] : [[k, [].concat(v).join(", ")]]), body: req.method === "POST" ? Buffer.concat(chunks) : undefined });
  const response = await handler.fetch(request, { authInfo: { token: "", clientId: claims.client_id, scopes: claims.scp ?? [], extra: { sub: claims.sub, victual_user_id: claims.ext?.victual_user_id } } });
  log(req.method, "/mcp (authorised)", "->", response.status);
  res.writeHead(response.status, Object.fromEntries(response.headers));
  if (response.body) for await (const c of response.body) res.write(c);
  res.end();
}).listen(PORT, () => log("spike resource server on", RESOURCE));
