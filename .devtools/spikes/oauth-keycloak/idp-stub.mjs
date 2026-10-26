// STUB. A tiny upstream OpenID Provider that stands in for Victual in the G-login cases: it
// implements discovery, authorize, token and jwks, nothing else. It has no login screen: it
// treats every authorize request as coming from an already signed-in Victual user, whose claims
// the probe sets with `stub.identity`. Not Victual code and not a model of Victual's design.
// Listens on 127.0.0.1 only; the Keycloak container reaches it as host.containers.internal.
import { createHash, createSign, generateKeyPairSync, randomBytes } from "node:crypto";
import http from "node:http";

export const PORT = 8190;
const b64 = (b) => Buffer.from(b).toString("base64url");

export function startStub() {
  const { privateKey, publicKey } = generateKeyPairSync("rsa", { modulusLength: 2048 });
  const jwk = { ...publicKey.export({ format: "jwk" }), kid: "stub-1", alg: "RS256", use: "sig" };
  const stub = {
    issuer: `http://localhost:${PORT}`,
    clientId: "keycloak-broker",
    clientSecret: "spike-only-broker-secret",
    identity: null, // { sub, preferred_username, email, given_name, family_name }
    log: [],
    codes: new Map(),
  };
  const sign = (payload) => {
    const head = b64(JSON.stringify({ alg: "RS256", kid: "stub-1", typ: "JWT" }));
    const body = b64(JSON.stringify(payload));
    const sig = createSign("RSA-SHA256").update(`${head}.${body}`).sign(privateKey);
    return `${head}.${body}.${b64(sig)}`;
  };
  const json = (res, status, obj) => { res.writeHead(status, { "Content-Type": "application/json" }); res.end(JSON.stringify(obj)); };
  stub.server = http.createServer(async (req, res) => {
    const u = new URL(req.url, stub.issuer);
    stub.log.push(`${req.method} ${u.pathname}`);
    if (u.pathname === "/.well-known/openid-configuration") {
      return json(res, 200, {
        issuer: stub.issuer, authorization_endpoint: `${stub.issuer}/authorize`,
        token_endpoint: `http://host.containers.internal:${PORT}/token`, jwks_uri: `http://host.containers.internal:${PORT}/jwks`,
        response_types_supported: ["code"], subject_types_supported: ["public"], id_token_signing_alg_values_supported: ["RS256"],
        code_challenge_methods_supported: ["S256"], token_endpoint_auth_methods_supported: ["client_secret_post"],
      });
    }
    if (u.pathname === "/jwks") return json(res, 200, { keys: [jwk] });
    if (u.pathname === "/authorize") {
      const q = u.searchParams;
      if (q.get("client_id") !== stub.clientId || !stub.identity) return json(res, 400, { error: "invalid_request" });
      const code = b64(randomBytes(12));
      stub.codes.set(code, { challenge: q.get("code_challenge"), redirect: q.get("redirect_uri"), nonce: q.get("nonce"), identity: stub.identity });
      const back = new URL(q.get("redirect_uri"));
      back.searchParams.set("code", code); back.searchParams.set("state", q.get("state"));
      res.writeHead(302, { Location: back.toString() }); return res.end();
    }
    if (u.pathname === "/token" && req.method === "POST") {
      const body = new URLSearchParams(await new Promise((r) => { let d = ""; req.on("data", (c) => (d += c)); req.on("end", () => r(d)); }));
      const c = stub.codes.get(body.get("code"));
      const pkceOk = c && c.challenge && b64(createHash("sha256").update(body.get("code_verifier") ?? "").digest()) === c.challenge;
      if (!c || body.get("client_secret") !== stub.clientSecret || body.get("client_id") !== stub.clientId || !pkceOk) return json(res, 400, { error: "invalid_grant" });
      stub.codes.delete(body.get("code"));
      const now = Math.floor(Date.now() / 1000);
      const id_token = sign({ iss: stub.issuer, aud: stub.clientId, iat: now, exp: now + 300, nonce: c.nonce, email_verified: true, ...c.identity });
      return json(res, 200, { access_token: b64(randomBytes(16)), token_type: "Bearer", expires_in: 300, id_token });
    }
    json(res, 404, { error: "not_found" });
  });
  return new Promise((resolve) => stub.server.listen(PORT, "127.0.0.1", () => resolve(stub)));
}
