// Two-replica probe for ADR-0055 prerequisite 2: login, consent, refresh rotation and revocation
// with two Keycloak replicas sharing one PostgreSQL database, and a replica killed mid-flow.
// Needs `./run.sh ha-up`. This process runs a small HTTP proxy on 127.0.0.1:8180 standing in for
// a Kubernetes Service in front of the two pods: round-robin per request, skipping a replica
// that refuses the connection. It runs setup.mjs through that proxy, then the H cases, and
// writes results-ha.json and a Markdown table. Kills and restarts go through run.sh.
//
//   node ha.mjs        about 4 minutes with setup: three replica restarts and one full outage
import { execFileSync, spawn, spawnSync } from "node:child_process";
import http from "node:http";
import { readFileSync, writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { ADMIN_PASSWORD, authorize, claims, errd, exchange, finishCode, KC, login, OIDC, REALM, rsCheck, sleep, submitLogin, token } from "./lib.mjs";

const HERE = fileURLToPath(new URL(".", import.meta.url));
const NAME = "victual-spike-keycloak";
const NODES = { a: 8183, b: 8184 };
if (KC !== "http://localhost:8180") throw new Error(`ha.mjs proxies on :8180; KC_URL is ${KC}`);

// --- the Service stand-in. route: "rr" alternates per request and skips a refused replica;
// "a" or "b" pins every request to that replica with no failover.
let route = "rr";
let turn = 0;
const hits = { a: 0, b: 0 };
/** The replicas to try for the next request, in order. */
const order = () => (route === "rr" ? (turn++ % 2 ? ["b", "a"] : ["a", "b"]) : [route]);
/** Sends one buffered request to replica `n`; resolves with the upstream response, rejects on a connection error. */
const forward = (n, req, body) => new Promise((resolve, reject) => {
  const up = http.request({ host: "127.0.0.1", port: NODES[n], method: req.method, path: req.url, headers: req.headers, agent: false }, resolve);
  up.on("error", reject);
  up.end(body);
});
const proxy = http.createServer(async (req, res) => {
  const chunks = [];
  for await (const c of req) chunks.push(c);
  const body = Buffer.concat(chunks);
  let last;
  for (const n of order()) {
    try {
      const up = await forward(n, req, body);
      hits[n]++;
      res.writeHead(up.statusCode, up.headers);
      up.pipe(res);
      return;
    } catch (e) { last = e; }
  }
  res.writeHead(502, { "Content-Type": "text/plain" });
  res.end(`no replica answered: ${last?.code ?? last}`);
});
await new Promise((r) => proxy.listen(8180, "127.0.0.1", r));
/** Runs `fn` with requests pinned to `r` ("a", "b" or "rr"), then restores round-robin. */
async function via(r, fn) { const old = route; route = r; try { return await fn(); } finally { route = old; } }
/** Resets the per-replica hit counters and returns a reader for them. */
const counter = () => { hits.a = 0; hits.b = 0; return () => `a ${hits.a} / b ${hits.b} requests`; };

// --- replica control (run.sh) and cluster state from the replica's own log
/** Runs a run.sh subcommand; throws with its output on failure. */
function sh(...args) {
  const r = spawnSync("./run.sh", args, { cwd: HERE, encoding: "utf8", timeout: 300_000 });
  if (r.status !== 0) throw new Error(`run.sh ${args.join(" ")}: ${r.stdout}${r.stderr}`);
  return r.stdout.trim();
}
/** The member count of the last Infinispan cluster view a replica logged (ISPN000094), or 0. */
function viewSize(n) {
  const log = spawnSync("podman", ["logs", `${NAME}-ha-${n}`], { encoding: "utf8" });
  const views = `${log.stdout}${log.stderr}`.split("\n").filter((l) => l.includes("ISPN000094"));
  const m = views.at(-1)?.match(/\((\d+)\)\s*\[/);
  return { size: m ? Number(m[1]) : 0, line: views.at(-1)?.replace(/^.*ISPN000094: /, "").trim() ?? "none" };
}
/** Waits until both replicas log a two-member view; returns the views. */
async function waitClustered(ms = 120_000) {
  const end = Date.now() + ms;
  for (;;) {
    const v = { a: viewSize("a"), b: viewSize("b") };
    if ((v.a.size === 2 && v.b.size === 2) || Date.now() > end) return v;
    await sleep(2000);
  }
}
/** Runs one query against the replicas' database and returns the unaligned result. */
const sql = (q) => execFileSync("podman", ["exec", `${NAME}-pg`, "psql", "-U", "keycloak", "-d", "keycloak_ha", "-Atc", q], { encoding: "utf8" }).trim();
/** Kills replica `n` with SIGKILL. */
const kill = (n) => sh("ha-kill", n);
/** Starts replica `n` again and waits for a two-member cluster view. */
async function restart(n) { sh("ha-start", n); return waitClustered(); }

// --- direct per-replica admin reads (bypassing the proxy) for the session and consent views
let adminTok;
/** A master-realm admin token obtained through the proxy; valid on both replicas (one hostname). */
async function adminToken() {
  const r = await token({ grant_type: "password", client_id: "admin-cli", username: "admin", password: ADMIN_PASSWORD }, {}, `${KC}/realms/master/protocol/openid-connect/token`);
  adminTok = r.access_token;
  return adminTok;
}
/** GET on replica `n`'s admin API for the spike realm; returns the parsed body. */
async function nodeAdmin(n, path) {
  const r = await fetch(`http://127.0.0.1:${NODES[n]}/admin/realms/${REALM}${path}`, { headers: { Authorization: `Bearer ${adminTok ?? await adminToken()}` } });
  if (!r.ok) throw new Error(`replica ${n} GET ${path} -> ${r.status}`);
  return r.json();
}
/** DELETE through the proxy on the spike realm's admin API; returns the status. */
async function adminDelete(path) {
  const r = await fetch(`${KC}/admin/realms/${REALM}${path}`, { method: "DELETE", headers: { Authorization: `Bearer ${await adminToken()}` } });
  return r.status;
}

// --- fixtures from setup.mjs, run through the proxy. An async spawn: spawnSync would block this
// process's event loop, and with it the proxy setup.mjs talks to.
await new Promise((resolve, reject) => {
  const p = spawn(process.execPath, ["setup.mjs"], { cwd: HERE, stdio: ["ignore", "inherit", "inherit"] });
  p.on("exit", (code) => (code === 0 ? resolve() : reject(new Error(`setup.mjs exited ${code}`))));
});
const S = JSON.parse(readFileSync(new URL("./state.json", import.meta.url)));
const { consent, mcp, sidecar } = S.clients;
const { alice } = S.users;
const cred = { username: alice.username, password: alice.password };
const SCOPE = "openid profile victual:read";
const RS_API = { issuer: S.issuer, discovery: `${S.issuer}/.well-known/openid-configuration`, audience: S.apiAud };

const results = [];
/** Appends one case result: verdict pass, FAIL, or obs (an observation that decides nothing). */
function record(id, clause, expected, observed, ok) {
  results.push({ id, clause, expected, observed: String(observed), verdict: ok === "obs" ? "obs" : ok ? "pass" : "FAIL" });
  console.error(`${new Date().toISOString().slice(11, 19)} ${id} ${results.at(-1).verdict}`);
}
/** A consent-client login for alice (login form, consent screen, code redemption). */
const grant = () => login(consent, cred, { scope: SCOPE });
/** Redeems a refresh token as `client` (public clients send no secret). */
const refresh = (rt, client = consent) => token({ grant_type: "refresh_token", client_id: client.clientId, refresh_token: rt, ...(client.secret ? { client_secret: client.secret } : {}) });
/** Short label for a token-endpoint response. */
const st = (r) => (r.access_token ? "200 token" : errd(r));

// H0: the replicas form one cluster, and the cache configuration they share.
{
  const v = await waitClustered();
  const xml = execFileSync("podman", ["exec", `${NAME}-ha-a`, "cat", "/opt/keycloak/conf/cache-ispn.xml"], { encoding: "utf8" });
  const customCaches = (xml.match(/<(distributed|replicated|local)-cache /g) ?? []).length;
  const ping = sql("select count(*) from jgroups_ping");
  record("H0", "prerequisite 2: two replicas share state", "both replicas log a two-member Infinispan view (JDBC_PING over the shared database)",
    `a: ${v.a.line}; b: ${v.b.line}; jgroups_ping rows: ${ping}; caches defined in cache-ispn.xml: ${customCaches} (Keycloak's built-in defaults apply)`,
    v.a.size === 2 && v.b.size === 2);
}

// H1: login and consent with every request alternating between replicas.
{
  const read = counter();
  const runs = [];
  for (let i = 0; i < 6; i++) { const r = await grant(); runs.push(st(r)); }
  record("H1", "prerequisite 2: login and consent across replicas", "six login + consent + code redemptions, each request to the other replica than the last, all issue tokens",
    `${runs.filter((x) => x === "200 token").length}/6 issued (${read()})`, runs.every((x) => x === "200 token"));
}

// H2: refresh rotation across replicas; reuse of an old refresh token revokes the family on both.
{
  const g = await grant();
  const r1 = await via("a", () => refresh(g.refresh_token));
  const r2 = await via("b", () => refresh(r1.refresh_token));
  const reuse = await via("a", () => refresh(r1.refresh_token));
  const newestB = await via("b", () => refresh(r2.refresh_token));
  record("H2", "prerequisite 2: refresh rotation across replicas (decision 6)",
    "refresh on a, then on b; reusing the first rotated token on a is refused, and the newest is then refused on b",
    `rotate on a: ${st(r1)}; rotate on b: ${st(r2)}; reuse on a: ${st(reuse)}; newest on b afterwards: ${st(newestB)}`,
    !!r1.access_token && !!r2.access_token && !reuse.access_token && !newestB.access_token);
}

// H3: RFC 7009 revocation on one replica is observed on the other.
{
  const g = await grant();
  const rev = await via("a", () => fetch(`${OIDC}/revoke`, { method: "POST", body: new URLSearchParams({ token: g.refresh_token, token_type_hint: "refresh_token", client_id: consent.clientId }) }));
  const after = await via("b", () => refresh(g.refresh_token));
  const intro = await via("b", () => token({ token: g.access_token, client_id: sidecar.clientId, client_secret: sidecar.secret }, {}, `${OIDC}/token/introspect`));
  record("H3", "prerequisite 2: revocation across replicas", "a refresh token revoked on a (RFC 7009) is refused on b",
    `revoke on a: ${rev.status}; refresh on b: ${st(after)}; access token at introspection on b: active=${intro.active}`, rev.status === 200 && !after.access_token);
}

// H4: an administrative session deletion on one replica is observed on the other, and the
// session and consent lists the K1 lookups read are the same on both replicas.
{
  const g = await grant();
  const sid = claims(g.access_token).sid;
  const seen = {};
  for (const n of ["a", "b"]) {
    const sessions = await nodeAdmin(n, `/users/${alice.id}/sessions`);
    const consents = await nodeAdmin(n, `/users/${alice.id}/consents`);
    seen[n] = { sessions: sessions.map((s) => s.id).sort().join(","), consents: consents.map((c) => c.clientId).sort().join(",") };
  }
  const same = seen.a.sessions === seen.b.sessions && seen.a.consents === seen.b.consents && seen.a.sessions.includes(sid);
  record("H4a", "prerequisite 2; README K1 lookups: one view from both replicas", "alice's sessions and consents read directly from a and from b are identical and include the new session",
    `a: ${seen.a.sessions.split(",").length} sessions, consents [${seen.a.consents}]; b: ${seen.b.sessions.split(",").length} sessions, consents [${seen.b.consents}]; equal: ${same}`, same);
  const del = await via("b", () => adminDelete(`/sessions/${sid}`));
  const after = await via("a", () => refresh(g.refresh_token));
  record("H4b", "prerequisite 2: administrative revocation across replicas", "a session deleted through b's admin API refuses its refresh token on a",
    `delete on b: ${del}; refresh on a: ${st(after)}`, del === 204 && !after.access_token);
}

// H5: token exchange on the other replica than the one that issued the subject token.
{
  const u = await via("a", () => login(mcp, cred, { scope: SCOPE }));
  const x = await via("b", () => exchange(sidecar, u.access_token, { audience: S.apiAud, scope: "victual:read" }));
  const rs = x.access_token ? await rsCheck(x.access_token, RS_API) : "no token";
  record("H5", "prerequisite 2 with decision 7: exchange across replicas", "a user token issued by a is exchanged by the sidecar on b for an /api token /api accepts",
    `user token on a: ${st(u)}; exchange on b: ${st(x)}; /api check: ${rs}`, rs === "accepted");
}

// H6: a replica killed mid-login: the login form came from a, a dies, the credentials go to b.
{
  const a = await via("a", () => authorize(consent, { scope: SCOPE }));
  kill("a");
  const read = counter();
  const s = await submitLogin(a, cred);
  const fin = s.response ? await finishCode(a, s.response) : s;
  const t = fin.code ? await token({ grant_type: "authorization_code", client_id: consent.clientId, code: fin.code, redirect_uri: a.redirect, code_verifier: a.verifier }) : fin;
  record("H6", "prerequisite 2: killing a pod mid-flow loses no grant (login)", "login form served by a; a SIGKILLed; credentials, consent and code redemption on b issue a token",
    `${st(t)} (${read()})`, !!t.access_token);
  const v = await restart("a");
  record("H6r", "prerequisite 2: a killed replica rejoins", "the restarted replica logs a two-member view", `a: ${v.a.line}; b: ${v.b.line}`, v.a.size === 2 && v.b.size === 2);
}

// H7: a replica killed between issuing the authorization code and its redemption.
{
  const a = await via("b", () => authorize(consent, { scope: SCOPE }));
  const fin = await via("b", async () => { const s = await submitLogin(a, cred); return s.response ? finishCode(a, s.response) : s; });
  kill("b");
  const t = await token({ grant_type: "authorization_code", client_id: consent.clientId, code: fin.code, redirect_uri: a.redirect, code_verifier: a.verifier });
  record("H7", "prerequisite 2: killing a pod mid-flow loses no grant (code)", "code issued by b; b SIGKILLed; the code is redeemed on a",
    `code from b: ${fin.code ? "issued" : st(fin)}; redemption on a: ${st(t)}`, !!t.access_token);
  await restart("b");
}

// H8: a replica killed while holding the grant it issued; the grant keeps working and the
// restarted replica does not accept a refresh token rotated away during its absence.
{
  const g = await via("a", () => grant());
  kill("a");
  const r1 = await refresh(g.refresh_token); // only b answers
  await restart("a");
  const stale = await via("a", () => refresh(g.refresh_token));
  record("H8", "prerequisite 2: killing a pod loses no grant (refresh)", "grant issued by a; a SIGKILLed; refresh on b works; after a restarts, the pre-kill refresh token (rotated away) is refused on a",
    `refresh on b: ${st(r1)}; old token on restarted a: ${st(stale)}`, !!r1.access_token && !stale.access_token);
}

// H9: both replicas killed at once. Persistent user sessions keep the grant in the database;
// an authentication session (a login in progress) lives only in the cache.
{
  const g = await grant();
  const pending = await authorize(consent, { scope: SCOPE });
  const inDb = sql(`select count(*) from offline_user_session where user_session_id = '${claims(g.access_token).sid}' and offline_flag = '0'`);
  const authInDb = sql("select count(*) from root_auth_session");
  kill("a"); kill("b");
  sh("ha-start", "a"); sh("ha-start", "b");
  const v = await waitClustered();
  adminTok = null;
  const r = await refresh(g.refresh_token);
  const consents = (await nodeAdmin("a", `/users/${alice.id}/consents`)).map((c) => c.clientId);
  const rs = r.access_token ? await rsCheck(r.access_token, { ...RS_API, audience: S.mcpAud }) : "no token";
  record("H9", "prerequisite 2: full outage loses no grant", "both replicas SIGKILLed and restarted; a refresh token from before the outage works and the stored consent remains",
    `grant's session row in offline_user_session before the outage: ${inDb}; root_auth_session rows: ${authInDb}; views after restart: a (${v.a.size}), b (${v.b.size}); refresh: ${st(r)}; sidecar check of the new token: ${rs}; consents: [${consents.join(",")}]`,
    !!r.access_token && consents.includes(consent.clientId));
  const s = await submitLogin(pending, cred);
  const fin = s.response ? await finishCode(pending, s.response) : s;
  record("H9p", "observation: what a full outage loses", "a login whose form was served before the outage (cache-only authentication session)",
    fin.code ? "completed after the outage"
      : /Sign in to/.test(String(fin.step)) ? "the credentials were answered with the login form again: the authentication session did not survive"
        : `${fin.error ?? "stopped"}: ${String(fin.step ?? fin.error_description ?? "").slice(0, 90)}`, "obs");
}

proxy.close();
writeFileSync(new URL("./results-ha.json", import.meta.url), JSON.stringify({ when: new Date().toISOString(), results }, null, 2));
console.log("| Case | Checks | Expected | Observed | Verdict |\n|---|---|---|---|---|");
for (const r of results) console.log(`| ${r.id} | ${r.clause} | ${r.expected} | ${r.observed.replaceAll("|", "/")} | ${r.verdict} |`);
process.exit(0);
