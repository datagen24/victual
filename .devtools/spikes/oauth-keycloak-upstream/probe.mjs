// Brokering probe: Keycloak issues every token, Authentik signs people in upstream (ADR-0055
// question 26, ADR-0056 question 9). Needs `./run.sh up && node setup.mjs`. Each case names the
// clause it would satisfy once the records are rewritten for this model; "pass" and "FAIL" compare
// against that clause, "obs" records behaviour no clause decides yet. Writes results.json and a
// Markdown table. About 40 seconds, including one Authentik outage.
import { spawnSync } from "node:child_process";
import { readFileSync, writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { admin, ADMIN_PASSWORD, claims, sleep } from "../oauth-keycloak/lib.mjs";
import { browser, kcLogin, kcRefresh, KC } from "./browser.mjs";

const HERE = fileURLToPath(new URL(".", import.meta.url));
const S = JSON.parse(readFileSync(new URL("./state.json", import.meta.url)));
const AKAPI = "http://localhost:9000/api/v3";
const H = { Authorization: "Bearer spike-only-bootstrap-token-0123456789", "Content-Type": "application/json" };
const results = [];

/** Calls Authentik's admin API; returns the parsed body or null. */
async function ak(method, path, body) {
  const r = await fetch(`${AKAPI}${path}`, { method, headers: H, body: body && JSON.stringify(body) });
  const text = await r.text();
  if (!r.ok) throw new Error(`${method} ${path} -> ${r.status} ${text.slice(0, 300)}`);
  return text ? JSON.parse(text) : null;
}
/** Appends one case result: pass, FAIL, or obs. */
function record(id, clause, expected, observed, ok) {
  results.push({ id, clause, expected, observed: String(observed), verdict: ok === "obs" ? "obs" : ok ? "pass" : "FAIL" });
  console.error(`${new Date().toISOString().slice(11, 19)} ${id} ${results.at(-1).verdict}`);
}
/** Short label of a login or token response. */
const st = (r) => (r.access_token ? "token" : `${r.error ?? r.status}${r.step ? `: ${String(r.step).slice(0, 110)}` : ""}${r.error_description ? ` (${r.error_description})` : ""}`);
/** The groups claim of an access token, as a comma list. */
const groupsOf = (r) => (r.access_token ? `[${[].concat(claims(r.access_token).groups ?? []).join(",")}]` : "no token");
/** The Keycloak user with this username, with credentials and federated identities, or undefined. */
async function kcUser(username) {
  const [u] = await admin("GET", `/users?username=${username}&exact=true`);
  return u && { ...u, credentials: await admin("GET", `/users/${u.id}/credentials`), federated: await admin("GET", `/users/${u.id}/federated-identity`) };
}
/** Runs a run.sh subcommand; throws with its output on failure. */
function sh(...args) {
  const r = spawnSync("./run.sh", args, { cwd: HERE, encoding: "utf8", timeout: 300_000 });
  if (r.status !== 0) throw new Error(`run.sh ${args.join(" ")}: ${r.stdout}${r.stderr}`);
}
const { alice, bob, carol, dave, erin } = S.up;

// U1, U2: a brokered login creates one Keycloak user, and a second login maps to the same subject.
const bA = browser();
const a1 = await kcLogin(bA, S.client, alice);
{
  const u = await kcUser(alice.username);
  const c = a1.access_token ? claims(a1.access_token) : {};
  const fed = u?.federated ?? [];
  record("U1", "ADR-0056 q9: a person signs in upstream and Keycloak issues the token", "token from Keycloak's issuer; sub is the Keycloak user id; the user holds no password and one federated identity at authentik",
    `${st(a1)}; iss ${c.iss}; sub = Keycloak user id: ${c.sub === u?.id}; password credentials: ${u?.credentials.filter((x) => x.type === "password").length}; federated: ${fed.map((f) => `${f.identityProvider}:${f.userName}`).join(",")}`,
    c.iss === `${KC}/realms/victual` && c.sub === u?.id && u.credentials.length === 0 && fed.length === 1 && fed[0].identityProvider === "authentik");
  const a2 = await kcLogin(browser(), S.client, alice);
  const users = await admin("GET", `/users?username=${alice.username}&exact=true`);
  record("U2", "ADR-0055 d4, ADR-0056 d3: one person, one stable subject", "a second login in a new browser yields the same sub and still one user and one federated identity",
    `${st(a2)}; same sub: ${a2.access_token && claims(a2.access_token).sub === c.sub}; users named ${alice.username}: ${users.length}; federated identities: ${(await kcUser(alice.username)).federated.length}; upstream visited: ${a2._upstream}`,
    a2.access_token && claims(a2.access_token).sub === c.sub && users.length === 1);
}

// U3: an upstream identity whose email equals a local Keycloak account's is refused, not linked.
{
  const r = await kcLogin(browser(), S.client, carol);
  const loc = await kcUser(S.local.carol.username);
  const created = await kcUser(carol.username);
  record("U3", "ADR-0056 d3 applied at Keycloak: an email match never links", "no token; the local account keeps zero federated identities; no new user is created",
    `${st(r)}; local-carol federated identities: ${loc.federated.length}; user ${carol.username} created: ${!!created}`, !r.access_token && loc.federated.length === 0 && !created);
}
// U3b: what Keycloak's shipped first-broker-login flow does with the same collision.
{
  await admin("PUT", "/identity-provider/instances/authentik", { ...(await admin("GET", "/identity-provider/instances/authentik")), firstBrokerLoginFlowAlias: "first broker login" });
  const r = await kcLogin(browser(), S.client, dave);
  const loc = await kcUser(S.local.dave.username);
  record("U3b", "observation: Keycloak's shipped first broker login flow on an email collision", "what the flow asks for; whether a link or a token results without the local password",
    `${r.access_token ? "token" : `${r.error}: ${r.step}`}; local-dave federated identities: ${loc.federated.length}`, "obs");
  await admin("PUT", "/identity-provider/instances/authentik", { ...(await admin("GET", "/identity-provider/instances/authentik")), firstBrokerLoginFlowAlias: S.flow });
}

// U4, U5: group membership at login, and how a removal at Authentik reaches Keycloak's tokens.
record("U4", "ADR-0056 prerequisite 4: upstream groups reach the token", `the token's groups claim holds ${S.group}`, groupsOf(a1), groupsOf(a1).includes(S.group));
{
  const alicePk = alice.pk;
  await ak("POST", `/core/groups/${S.groupPk}/remove_user/`, { pk: alicePk });
  const t0 = Date.now();
  const r1 = await kcRefresh(S.client, a1.refresh_token);
  record("U5a", "ADR-0056 d4 / prerequisite 4: a removal reaches an active caller within one access-token lifetime", "after removal at Authentik, a refreshed Keycloak token no longer carries the group",
    `refresh ${st(r1)}; groups ${groupsOf(r1)}`, r1.access_token && !groupsOf(r1).includes(S.group));
  const r2 = await kcLogin(bA, S.client, alice); // same browser: Keycloak's SSO session is live
  record("U5b", "ADR-0056 d4: a removal reaches the next login", "a login in the same browser (live Keycloak session) no longer carries the group",
    `${st(r2)}; upstream visited: ${r2._upstream}; groups ${groupsOf(r2)}`, r2.access_token && !groupsOf(r2).includes(S.group));
  const r3 = await kcLogin(browser(), S.client, alice); // new browser: Keycloak sends it upstream
  record("U5c", "ADR-0056 d4: a removal reaches the next login", "a login in a new browser goes upstream and no longer carries the group",
    `${st(r3)}; upstream visited: ${r3._upstream}; groups ${groupsOf(r3)}`, r3.access_token && !groupsOf(r3).includes(S.group));
  const r4 = await kcRefresh(S.client, r1.refresh_token ?? a1.refresh_token);
  const rt = claims(r1.refresh_token ?? a1.refresh_token);
  record("U5d", "observation: what one fresh upstream login does to other sessions", "a refresh in the first browser's session after U5c",
    `refresh ${st(r4)}; groups ${groupsOf(r4)}; ${Math.round((Date.now() - t0) / 1000)} s after the removal. Bound without a fresh upstream login: refresh token lifetime ${rt.exp - rt.iat} s (SSO idle), realm SSO maximum 36000 s`, "obs");
  await ak("POST", `/core/groups/${S.groupPk}/add_user/`, { pk: alicePk });
}

// U6: a user deactivated at Authentik.
{
  const bE = browser();
  const e1 = await kcLogin(bE, S.client, erin);
  await ak("PATCH", `/core/users/${erin.pk}/`, { is_active: false });
  await sleep(10_000); // room for any back-channel logout Authentik sends
  const u = await kcUser(erin.username);
  const sessions = await admin("GET", `/users/${u.id}/sessions`);
  const r1 = await kcRefresh(S.client, e1.refresh_token);
  const r2 = await kcLogin(bE, S.client, erin);
  const r3 = await kcLogin(browser(), S.client, erin);
  record("U6a", "observation: deactivation at Authentik and the live Keycloak session", "Keycloak sessions after 10 s; refresh; a login in the same browser",
    `Keycloak sessions of erin: ${sessions.length}; refresh ${st(r1)}; same-browser login ${st(r2)} (upstream visited: ${r2._upstream})`, "obs");
  record("U6b", "ADR-0056 d1 with q9: a deactivated upstream user cannot sign in anew", "a login in a new browser is refused upstream", `${st(r3)}`, !r3.access_token);
  await ak("PATCH", `/core/users/${erin.pk}/`, { is_active: true });
}

// U7: Authentik back-channel logout to Keycloak's realm endpoint when the upstream session ends.
{
  const b7 = browser();
  const r = await kcLogin(b7, S.client, bob);
  const u = await kcUser(bob.username);
  const before = (await admin("GET", `/users/${u.id}/sessions`)).length;
  const aks = (await ak("GET", `/core/authenticated_sessions/?user__username=${bob.username}`)).results;
  for (const s of aks) await ak("DELETE", `/core/authenticated_sessions/${s.uuid}/`);
  let after = before;
  const t0 = Date.now();
  while (Date.now() - t0 < 20_000 && after > 0) { await sleep(1000); after = (await admin("GET", `/users/${u.id}/sessions`)).length; }
  const ref = await kcRefresh(S.client, r.refresh_token);
  const logs = spawnSync("podman", ["logs", "--since", "60s", "victual-spike-kcup"], { encoding: "utf8" });
  const kcLog = `${logs.stdout}${logs.stderr}`.split("\n").filter((l) => /backchannel|logout/i.test(l) && /WARN|ERROR/.test(l)).map((l) => l.replace(/^.*?(WARN|ERROR)/, "$1").slice(0, 160)).slice(-2);
  record("U7", "ADR-0056 d7: back-channel logout ends the subject's sessions", "ending bob's Authentik session ends his Keycloak session; his refresh token is refused",
    `Authentik sessions deleted: ${aks.length}; Keycloak sessions before ${before}, after ${after} (${Math.round((Date.now() - t0) / 1000)} s); refresh ${st(ref)}${kcLog.length ? `; Keycloak log: ${kcLog.join(" / ")}` : ""}`,
    after === 0 && !ref.access_token);
}

// U8: Authentik down. Keycloak keeps serving what it already holds; its own local login is the
// recovery path while the upstream is unavailable.
{
  const b8 = browser();
  const a = await kcLogin(b8, S.client, alice);
  sh("ak-stop");
  const r1 = await kcRefresh(S.client, a.refresh_token);
  const r2 = await kcLogin(b8, S.client, alice);
  const r3 = await kcLogin(browser(), S.client, alice);
  const r4 = await kcLogin(browser(), S.client, S.local.breakglass, { local: true });
  const m = await fetch(`${KC}/realms/master/protocol/openid-connect/token`, { method: "POST", body: new URLSearchParams({ grant_type: "password", client_id: "admin-cli", username: "admin", password: ADMIN_PASSWORD }) });
  record("U8a", "ADR-0056 prerequisite 5 with q9: an upstream outage leaves issued grants working", "refresh at Keycloak with Authentik stopped", `refresh ${st(r1)}`, !!r1.access_token);
  record("U8b", "observation: a login inside a live Keycloak session during the outage", "same browser, Keycloak SSO cookie", `${st(r2)}; upstream visited: ${r2._upstream}`, "obs");
  record("U8c", "observation: a new brokered login during the outage", "what the user sees", st(r3), "obs");
  record("U8d", "ADR-0056 prerequisite 5 with q9: Keycloak's local login is the recovery path while the upstream is down", "a local Keycloak account signs in through Keycloak's own form; the master admin still gets a token",
    `local account ${st(r4)}; master admin ${m.status}`, !!r4.access_token && m.status === 200);
  sh("ak-start");
  await sleep(3000);
  const r5 = await kcLogin(browser(), S.client, alice);
  record("U8e", "ADR-0056 prerequisite 5: brokered login returns with the upstream", "a new-browser login after Authentik restarts", st(r5), !!r5.access_token);
}

writeFileSync(new URL("./results.json", import.meta.url), JSON.stringify({ when: new Date().toISOString(), results }, null, 2));
console.log("| ID | Clause | Expected | Observed | Verdict |\n|---|---|---|---|---|");
for (const r of results) console.log(`| ${r.id} | ${r.clause} | ${r.expected} | ${r.observed.replaceAll("|", "/")} | ${r.verdict} |`);
