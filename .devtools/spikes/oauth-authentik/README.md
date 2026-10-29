# Spike: Authentik compatibility with ADR-0055 and ADR-0056

Throwaway. This spike checks what Authentik does against the clauses of the Proposed
[ADR-0055](../../../docs/adr/0055-api-and-mcp-callers-present-oauth-access-tokens-from-a-swappable-authorization-server.md)
and [ADR-0056](../../../docs/adr/0056-an-external-identity-provider-replaces-the-built-in-authorization-server.md)
that depend on the authorization server. Each case has a positive or negative expectation
taken from a named clause, and the probe records what happened. It writes no Victual code
and accepts no record.

Authentik **2026.8.5** (`ghcr.io/goauthentik/server:2026.8.5`, newest release on
2026-10-25), PostgreSQL 16, run on 2026-10-25 and again on 2026-10-26 (third pass, gates G5
and G6) and 2026-10-28 (fourth pass, the maintainer's answers 15 to 17) on the shared podman
VM (4 CPU / 8 GiB). Server and worker are capped at 1 GiB each, PostgreSQL at 384 MiB, and a
small python container that stands in for Victual's reuse endpoint at 64 MiB. On 2026-10-28
2026.8.5 was still the newest release (GitHub releases: 2026.8.5 and 2026.5.8, both
2026-10-21), so the fourth pass stays on it.

## Run

```bash
cd .devtools/spikes/oauth-authentik
./run.sh up          # PostgreSQL + Authentik server + worker + reuse endpoint on one podman network, :9000
node setup.mjs       # providers, applications, scopes, mappings, policies, rules, users, via the admin API
node probe.mjs       # cases below; prints the table and writes results.json
./run.sh down
```

No npm install is needed: the probe uses `node:crypto`, `node:child_process` and `adapter.mjs`
(the registration adapter simulation that `probe.mjs` calls for G5 and G6). Every secret in
these files is a spike-only value for an instance on `127.0.0.1` with no real data. The
client secrets of the clients the adapter creates are generated at run time.

- The expiry cases sleep 66 s and each scripted login takes about 1 s. A full cold start, setup
  and probe takes about 15 minutes since the fourth pass. Run the probe under `timeout 2400`.
- `./run.sh logs hook` shows what the webhook receiver received and revoked (`HOOK`, `REVOKE`
  and `SKIP` lines). `./run.sh hook-restart` restarts only the receiver.
- `./run.sh worker-stop`, `worker-start` and `worker-kill` stop, start and SIGKILL the Authentik
  worker. The probe uses them in G5o and G5q, and leaves the worker running.
- The probe rewrites `results.json` after every case, so a run that dies part-way leaves the
  cases it finished (`"partial": true`).

## Gates (2026-10-25 second pass, 2026-10-26 third pass, 2026-10-28 fourth pass)

The first report left three gates open. Each line says what "supported configuration" means
here: settings, scope mappings, expression policies and notification rules created through the
admin API. None of it needed `ak shell`, a patched image or an Enterprise licence, except case
P8 as before. The third pass adds G5 and G6, which test ADR-0055 acceptance prerequisites 9 and
11, and the [configuration inventory](#configuration-inventory-prerequisite-8), which covers
prerequisite 8.

The fourth pass applies the maintainer's 2026-10-26 answers 15 to 17 (see
[Fourth pass](#fourth-pass-2026-10-28-answers-15-to-17)). No expression may import
`authentik.*` or delete rows directly. The revocation delay and the concurrent-refresh sweep
need repeated evidence.

| Gate | Result |
|---|---|
| G1 Exchanger identity | met-with-config: a scope mapping on every `/api` scope signs `victual_exchanger` (the authenticated exchanging client) and `victual_grant` (`azp`, `sid`, `jti` of the subject token) into each exchanged token. Not `act` (G1f, P7, P8). Fourth pass: rewritten on documented expression context, since the third-pass imports do not qualify under answer 15; G1a to G1h and G8a pass unchanged |
| G2 Dedicated MCP audience | met-with-config: a scope mapping on each MCP client's provider returns `aud` as `[client_id, <MCP audience>]`. Victual's registration adapter must attach that mapping to every provider it creates. The mapping reads `token.scope` and `provider.client_id`; the 2026.8 docs name `token` and `provider` as variables a provider may add, without describing them |
| G3 Scope ceiling as required configuration | met-with-config: an expression policy bound to the `/api` application by `setup.mjs`. It can hold a bundle-to-leaf table (G3a); that table is a second copy of Victual's bundles. Fourth pass: the policy never imported anything. It reads `oauth_jwt`, `oauth_scopes` and `oauth_grant_type`, which the 2026.8 machine-to-machine page documents. Grade unchanged under answer 15 |
| G4 Code flow with PKCE, scripted login | met: a headless login through the flow executor API replaces the app-password grant for user tokens (G4a, G4b). S256-only is met-with-config (an expression policy, G4c); the policy imports nothing, but the context keys it reads (`oauth_code_challenge`, `oauth_code_challenge_method`) are not in the 2026.8 docs. Refresh-token reuse does not revoke the newest token natively: not met (G4d); see G5 |
| G5 Refresh-family revocation (prerequisite 9) | Sequential reuse: met-with-config with a bounded delay while the worker is healthy, without Authentik internals (G5d, G5h, G5m, G5n). Unbounded while the worker is stopped (G5o, G5p). Concurrent refresh: not met (G5f); a sweep found every descendant (G5r). A holder who keeps refreshing is not stopped (G5n2, G5r2). Receiver and sweep are probe code: in Victual, evidence, not met |
| G6 MCP audience through the registration adapter (prerequisite 11) | met-with-config. Adapter clients get `aud` = `[client_id, MCP audience]`, cannot pick another audience (G6b), exchange only to `/api` (G6d) and cannot exchange again (G6e). A client without the mapping is refused at the sidecar (G6c). See the notes below |

Overall (fourth pass): 65 of 76 cases pass on a cold start, twice (2026-10-28, 18:30Z and
18:45Z). The eleven that do not are P1, P7, N11, G4c0, G4d, G5a, G5b, G5c, G5f, G5n2 and
G5r2. The first nine failed in the third pass for the same reasons. G5n2 and G5r2 are new: a
holder who refreshes in a tight loop outruns revocation through the admin API. Third pass,
2026-10-26: 57 of 66 on a cold start, twice.

Notes on G5 (prerequisite 9), fourth pass:

- Two mechanisms revoke a family, and neither uses Authentik internals. The in-Authentik
  notification rule now calls Authentik's admin API through the documented `requests` object
  (G5d, G5g, G5h, G5m2). The webhook receiver in `run.sh` does the same from outside, as Victual
  would (G5i, G5m, G5n to G5s). Both delete a family's unrevoked refresh tokens first, repeat a
  pass that deleted something at once, and stop after two empty passes.
- The delay from the reuse response to the family's refresh tokens being gone, over ten reuse
  events each: webhook receiver 746 / 788 / 935 ms (min / median / max); in-Authentik rule
  676 / 906 / 1221 ms (50 ms polling of the admin API). The third pass measured 0.5 to 0.6 s
  for the rule that deleted rows directly.
- A refresh with the newest token inside that window succeeds. Its descendant is revoked with the
  family (G5n).
- With the worker stopped nothing is revoked, and the window lasts until the worker runs again.
  The event and its tasks wait in PostgreSQL and run after the start (G5o, G5q). A burst of
  queued events delays revocation by tens of seconds or more (G5p).
- A holder who refreshes continuously stays ahead of list-and-delete. Each pass deletes the token
  it listed, and by then a new one exists (G5n2, G5r2). The holder stopped only when its own loop
  gave up after 10 s.
- The webhook receiver and the sweep are probe code. Victual has no receiver and no sweep, so
  prerequisite 9 stays "evidence, not met" in Victual until it has them.

Notes on G6 (prerequisite 11):

- The adapter's work has two independent steps in Authentik: the `mcp-aud` mapping and the
  trust at the sidecar (G6a).
- A client without the trust step fails closed at the exchange.
- A client without the mapping gets no MCP audience. Only the sidecar's audience check
  refuses it, because Authentik's exchange does not look at the MCP audience (G6c).
- A scope mapping also grants its scope, so the adapter attaches an audience mapping only for
  scopes it grants (G8b).

## Shape under test

| Provider (Authentik "client") | Plays | Grants |
|---|---|---|
| `victual-spike-mcp-client`, `-mcp-client-b` | Two registered MCP clients; user tokens come from here | code, refresh, client credentials, password |
| `victual-spike-sidecar` | The MCP sidecar, ADR-0055 decision 7 | client credentials, token exchange; trusts both MCP clients |
| `victual-spike-sidecar-b` | A second legitimate exchanger, for G1 | as the sidecar; trusts `mcp-client` |
| `victual-spike-api` | Victual's `/api` audience | trusts both exchangers to mint tokens for it |
| `victual-spike-other`, `-rogue-exchanger`, `-no-exchange-grant`, `-mcp-short`, `-mcp-scalar` | Negative cases and the scalar `aud` comparison | as named |
| `victual-spike-g5-candidate`, `-g6-a`, `-g6-b`, `-g6-c-untrusted`, `-g6-d-unbound`, `-g6-e-admin`, `-g6-f-readonly` | Clients that `probe.mjs` creates through `adapter.mjs` while it runs G5 and G6, and deletes afterwards | code and refresh only |
| `victual-spike-authentik-hook` (container, `127.0.0.1:8766`) | Stands in for the endpoint Victual would expose for reuse alerts. Probe code, not Victual code. Refuses a POST without the shared-secret header, logs each POST, and when switched on revokes the named family through the admin API (`HOOK` in `run.sh`) | none |

All providers use `issuer_mode: global` (one `iss` for the install, ADR-0055 decision 1),
`sub_mode: hashed_user_id`, the same signing key and five-minute access tokens.

`setup.mjs` now creates this required deployment configuration, so the probe tests the
configuration the ADRs would require:

- A scope-ceiling expression policy bound to the `/api` application (G3).
- A scope mapping named `exchanger` on each `/api` scope (`victual:read`, `victual:write`,
  `victual:admin`), whose expression is `EXCHANGER_EXPRESSION` in `setup.mjs` (G1).
- A scope mapping named `mcp-aud` on each of the MCP providers' scopes (`openid`,
  `victual:read`, `victual:write`, the bundle scope), returning `aud` (G2).
- A PKCE policy bound to the two MCP client applications (G4c), whose policy engine mode is
  `all` (G5b shows why).
- A notification rule `refresh-reuse-revoke` with an expression policy that revokes a
  refresh-token family through the admin API (G5).
- A rule `refresh-reuse-alert` with an event matcher policy, a webhook transport, a webhook body
  mapping and a webhook header mapping. It posts reuse events, with a shared secret, to the hook
  container (G5). The revoke rule is bound by default. The receiver starts with revocation off,
  and the probe switches between them.
- The data `adapter.mjs` needs, written to `state.json`: flow and key ids, the scope mappings
  by scope name, the `mcp-aud` mapping by scope name, the sidecar and the PKCE policy (G6).

User tokens come from authorization code with PKCE S256 and a scripted login for the
providers that have the code grant (`mcp-client`, `mcp-client-b`, `other`). The app-password
grant remains for `mcp-short` (two-second tokens), for the Actor in P8, and for a few G cases
that need a token from a provider without a login.

The resource-server check in `probe.mjs` is the one ADR-0055 decision 3 describes. Its
configuration is an issuer, a discovery URL and one audience. It checks RS256 against the
JWKS, `iss`, `exp` and `nbf` with 60 s skew, and `aud`, and it refuses an empty `aud`. It
contains no Authentik-specific code. The sidecar's copy now holds the one fixed MCP audience
(`victual-spike-mcp-resource`) instead of one client's `client_id`.

## Changes to the existing cases

Setup changed three things on purpose. No verdict changed: P1, P7 and N11 failed before and
fail now; the other 22 passed before and pass now.

- The scope-ceiling policy is bound by default. N11 now unbinds it for the length of the case
  and rebinds it, so N11 still shows Authentik's native escalation. N11b runs against the
  default binding instead of creating and deleting a policy.
- The sidecar's resource-server check uses the MCP audience, and the MCP providers' tokens carry
  it. P2 shows the new `aud`; P4, N1 and N2 are checked against the new audience.
- User tokens in the P, N and G cases use the code flow (described under "Shape under test").

The third pass changed two more things. No earlier verdict changed.

- G4d now unbinds the G5 revoke rule for the length of the case (like N11 does for the
  ceiling policy), so G4d still shows Authentik's native behaviour. G5d is the same sequence
  with the rule bound.
- `setup.mjs` sets the policy engine mode of the two MCP client applications to `all`. In the
  default mode `any` a second, passing policy outvotes the S256 policy (G5b). G4c and G4c0
  are unaffected, since each application has one policy.

The fourth pass changed five things. No earlier verdict changed.

- The `exchanger` mapping and the `refresh-reuse-revoke` policy were rewritten without
  `authentik.*` imports (see [Fourth pass](#fourth-pass-2026-10-28-answers-15-to-17)). G1a to
  G1h, G8a, G5d, G5g and G5h ran unchanged against the rewrites. The test-only `act` mapping in
  G1f was rewritten the same way.
- `withoutRevoke` now also keeps the webhook receiver from revoking, and `viaWebhook` makes the
  receiver the only mechanism. G4d, G5i and G5l run with neither.
- The admin API listings follow pagination. The third pass read one page of 200 rows, which a
  sweep cannot afford (answer 17).
- G5g waits up to 10 s for the branch access tokens to become inactive and reports how many were
  still active when the refresh rows were gone. Revocation through the admin API deletes refresh
  tokens before access tokens, so on a trial run on 2026-10-28 5 of 5 were still active at that
  moment and G5g failed; the expectation itself is unchanged. G5j likewise waits until the
  family's access-token rows are gone before it checks introspection, and reports what
  introspection said when only the refresh rows were gone. On an earlier full cold start
  (2026-10-28 18:14Z, before that change and before the two counted runs), G5j failed for this
  reason (both access tokens still active).
- The sweep (`victualSweep`) reads every provider instead of `mcp-client` only, and G5l uses it.

## Configuration inventory (prerequisite 8)

ADR-0055 prerequisite 8 asks that every provider policy and mapping the swap test relies on
uses a supported provider interface, lives in versioned deployment configuration, and has a
positive and a negative test. `setup.mjs` is that versioned configuration here. This table
lists everything it installs. "Interface" says what kind of Authentik object it is.

"Answer 15" says, for the fourth pass, whether the object uses only what the 2026.8 docs
describe for that object type (see [Sources read](#sources-read)). "yes" means no
`authentik.*` import, no direct row change, and only documented variables, helpers and context
keys. "partly" names what is not documented.

| Object (prefix `victual-spike`) | Interface | Answer 15 | What it enforces | Positive case | Negative case |
|---|---|---|---|---|---|
| `scope-ceiling`, bound to the `/api` application | Expression policy | yes. No import, in this pass or before; `oauth_jwt`, `oauth_scopes`, `oauth_grant_type` are documented on the machine-to-machine page. That page describes them for JWT client authentication; that they are set on the exchange path is a source read (`token/base.py`, `check_policy_access`) confirmed by N11b | An exchanged token carries no scope the subject token lacked, understands the household bundle, and needs at least one `victual:*` scope | N11b, G3a, G1e | N11 (unbound control), N11b widening, G3a outside leaf, G1e no `victual:*` scope |
| `scope-ceiling-plain` | Expression policy, unbound | as `scope-ceiling` | Comparison variant for G3b only. Not deployment configuration | G3b | G3b |
| `pkce-s256`, bound to `mcp-client`, `mcp-client-b` and every adapter client | Expression policy | partly. No import; `oauth_code_challenge` and `oauth_code_challenge_method` are not in the docs (the machine-to-machine page lists `oauth_code_verifier`) | A code request without a challenge, or with method `plain`, is refused at `/authorize` | G4a, G4c, G6f | G4c, G4c0 (unbound control), G6f |
| Policy engine mode `all` on the MCP applications | Application setting | yes | A second, passing policy cannot outvote the S256 policy | G4c, G5b (mode `all` denies) | G5b (mode `any` is outvoted) |
| `refresh-reuse-revoke`, bound to rule `refresh-reuse-revoke` | Expression policy on a notification rule | partly. No import and no direct row change; reads the documented `request.context["event"]` and calls the admin API with the documented `requests`. Undocumented: the event's token fields, the `id_token` content, side effects. Holds a superuser token (finding 20). The third-pass version imported models and deleted rows | Revokes the refresh and access tokens of the family named by a refresh-token reuse event | G5d, G5g, G5h, G5m2 | G5k (other families unaffected), G5d (a normal rotation leaves the family and its rows intact) |
| `suspicious-request`, bound to rule `refresh-reuse-alert` | Event matcher policy | yes | Selects `suspicious_request` events | G5e | G5e (no post on a normal rotation) |
| `refresh-reuse-alert` rule, `reuse-webhook` transport, `reuse-body` and `reuse-headers` mappings | Notification rule, webhook transport, webhook body and header mappings | yes. The receiver depends on the event's undocumented `context.token` fields, as the rule does | Posts the event, with the refresh token pk, provider and user, to the endpoint, with the shared-secret header | G5e, G5s | G5e (no post on a normal rotation), G5s (without the header mapping the receiver refuses the post) |
| `victual:read` and `victual:write`, on every MCP provider and on `/api` | Scope mapping (`return {}`) | yes | Defines the scope; Authentik grants a scope only if the provider has a mapping for it | G4a, N11b, G8b | G8b (a read-only client does not get `victual:write`) |
| `victual:bundle:household` | Scope mapping (`return {}`) | yes | Defines the bundle scope on the MCP providers | G3a | G8b (absent on a read-only client) |
| `victual:admin`, on `/api` only | Scope mapping (`return {}`) | yes | Defines the admin scope on `/api` | G8a | G6b (an adapter client asking for it does not get it) |
| `mcp-aud` for `openid`, `victual:read`, `victual:write`, bundle | Scope mapping returning `aud` | partly. No import; reads `token.scope` and `provider.client_id`, which the docs name only as variables a provider may add | Adds the MCP audience to tokens of MCP providers that carry it | G2a, G6a, G8c | G2b, G2c, G6b, G6c |
| `mcp-aud-scalar` | Scope mapping, on `mcp-scalar` only | yes | Comparison variant for G2d only. Not deployment configuration | G2d | G2d |
| `exchanger` for `victual:read`, `victual:write`, `victual:admin`, on `/api` | Scope mapping | yes, fourth pass. Reads `request.http_request` and calls `regex_match`, both documented, plus Python's `base64`, `json` and `urllib.parse`. It repeats Authentik's client-authentication rule; that rule is not documented as a contract (finding 18). Third pass: imported `authentik.providers.oauth2.utils` and `.models` (does not qualify) | Signs the authenticated exchanger and the originating grant into exchanged tokens | G1a, G1e (read, write), G8a (admin) | G1b, G1c, G1d, G1e (absent from other tokens) |
| Federation lists: sidecar trusts the MCP providers, `/api` trusts both exchangers | Provider setting (`jwt_federation_providers`) | yes | Only trusted subject providers and exchangers produce tokens for `/api` | P5, P6, G6a | N6, N8, G6a (client without the trust step), G6e |

Gaps the table cannot close with a case in this spike:

- `refresh-reuse-revoke` and the receiver have no negative case for a `suspicious_request`
  event that does not come from a refresh token. Both filter on
  `context.token.model_name == "refreshtoken"`. The only other source in 2026.8.5 is a revoked
  access token presented at the userinfo endpoint (`authentik/providers/oauth2/utils.py`); it
  was read, not run. The event matcher selects every `suspicious_request`, so a receiver must
  filter on the same field.
- Every expression and the receiver depend on field names that the docs do not describe: the
  event context, the `id_token` field's content, and for `mcp-aud` the `token` and `provider`
  variables. Each Authentik upgrade needs a rerun of this probe.

Objects the probe creates and deletes itself are test-only and are not deployment
configuration: the clients from `adapter.mjs`, the deny policy and two mappings in G5b and
G5c, the attribute-spreading mapping in G1d and the `act` mapping in G1f.

## Fourth pass (2026-10-28): answers 15 to 17

The maintainer answered questions 15 to 17 of ADR-0055 on 2026-10-26. This pass tests the
configuration and the Victual-side stand-ins against those answers. Case IDs refer to the
results table. Numbers are from the second cold start of 2026-10-28.

**Answer 15: no `authentik.*` imports, no direct row deletion.** The README's third-pass
inventory marked three objects.

| Object | Third pass | Fourth pass | Cases | Verdict |
|---|---|---|---|---|
| `exchanger` scope mapping (G1) | Imported `authentik.providers.oauth2.utils.extract_client_auth` and `.models.OAuth2Provider` | Reads `request.http_request` and calls `regex_match`. Parses the Basic header or the form fields as `extract_client_auth` does, and emits the claim only when a secret was presented | G1a to G1h, G8a: all pass, unchanged | met on documented surface. The provider lookup is gone, so the mapping no longer skips a public client; only exchangers in `/api`'s `jwt_federation_providers` reach an `/api` token, and both are confidential |
| `scope-ceiling` expression policy (G3) | No import. The third-pass README was wrong to say it depended on internal modules | Unchanged | N11b, G3a, G3b, G1e: pass | met; no change needed |
| `refresh-reuse-revoke` notification-rule policy (G5) | Imported `authentik.providers.oauth2.models`, deleted rows | Reads `request.context["event"]`, revokes through the admin API with `requests` | G5d, G5g, G5h, G5m2: pass | partly met. No internals and no direct row change, but a side-effecting policy holding a superuser API token, and undocumented event fields. Whether that counts as supported configuration is the maintainer's call |

A policy that only reads documented context cannot revoke anything. The documented helpers
(`ak_user_by`, `ak_create_event`, `ak_call_policy` and the others) offer no token operation.
The rewrite therefore needs the documented `requests` object and an admin API token. The
admin API deletes refresh and access tokens through the same `DELETE` the admin interface
uses. It records no audit event for them: `RefreshToken` and `AccessToken` are
`InternallyManagedMixin` models, which `should_log_model` excludes (`events/middleware.py`,
static read). The third-pass ORM delete recorded none either. Audit is the revoker's job,
whichever mechanism runs.

**Answer 16: bounded delay, webhook path.** The receiver revokes through the admin API after
it confirms the event through the events API.

- Timing over ten reuse events each (G5m, G5m2): webhook 746 / 788 / 935 ms (min / median /
  max); in-Authentik rule 676 / 906 / 1221 ms. Measured from the reuse response to the family's last refresh-token row being gone,
  polling the admin API every 50 ms. The newest refresh token was refused afterwards in all 20.
- A refresh inside the window (G5n): one refresh with the newest token, 0 to 1000 ms after the
  reuse response. At 0 to 400 ms it succeeded (5 of 5); at 600, 800 and 1000 ms it was refused. Every descendant was refused afterwards and its access token was
  inactive.
- A holder refreshing in a tight loop (G5n2): 117 to 138 successful refreshes in each of three runs. The loop was never refused; it ran until it
  gave up at 10 s. Each receiver pass deletes the live token it listed, and by then the holder has
  rotated to a new one.
- Worker stopped (G5o): for 20 s after a reuse no post went out, the newest access token stayed
  active, and a refresh by its holder succeeded. After `worker-start` the post arrived at 5.3 s
  and the family, with that descendant, was gone at 6.6 s.
- Worker lagging (G5p): 300 events posted through the events API ahead of the reuse delayed
  revocation to 50.5 s; 1000 events to 166.6 s (43.3 s and 121.6 s on the first cold start).
- Worker killed just after a reuse (G5q): SIGKILL 0, 50 and 100 ms after the reuse. Nothing was
  posted before the kill, and the family was revoked 5.5 to 6.4 s after `worker-start`.
- Events and tasks survive a worker outage and a SIGKILL: the event is stored by the server, and
  the dispatch and transport tasks wait in PostgreSQL (`authentik/tasks/broker.py`,
  `PostgresBroker`) until a worker takes them (G5o, G5q).

Verdict: a documented bounded delay holds only while the worker is healthy and only against a
holder who does not refresh continuously. Its bound is the worker's health, not a constant.

**Answer 17: concurrent refresh.** G5f is unchanged: five fully parallel refreshes with
one token all succeeded, raised no event and left five live refresh tokens. The sweep (`victualSweep` in
`probe.mjs`) is probe code over the admin API.

- How it identifies a family: for each OAuth2 provider (`/providers/oauth2/`, all pages) it lists
  `/oauth2/refresh_tokens/?provider=<pk>` (all pages) and groups unrevoked rows by `user.pk` and
  the `sid` and `auth_time` inside the `id_token` field. The fields `pk`, `revoked`, `user`,
  `provider` and `id_token` are in the API schema. The schema types `id_token` as a string; its
  JSON content is not documented. A group with more than one unrevoked row is a fork, because
  rotation revokes the parent.
- How it revokes: `DELETE /oauth2/refresh_tokens/<pk>/` and `DELETE /oauth2/access_tokens/<pk>/`
  for every row with the family key, unrevoked refresh tokens first. A pass that marked or deleted
  something is repeated at once; the sweep stops after two empty passes.
- Result (G5r): 6 of 6 runs left no row of the fork. Every branch was refused, its access token
  inactive, and the three controls (another login, another user, another client) kept working.
  Without an active holder a sweep over all providers took 4.0 to 4.7 s.
- With a holder refreshing during the sweep (G5r, runs 4 to 6, judged as G5r2): 123 to 154 refreshes per run, the last at about
  10.0 s, when the holder's loop gave up. The sweep finished at 21.8 to 22.9 s. It cleared the
  family only after the holder stopped.
- Failure modes:
  - The fork lives until the next sweep. A schedule is a periodic cleanup, which answer 17
    rejects; a sweep on each exchange covers only tokens that reach Victual.
  - A holder who keeps refreshing outruns it (G5r2).
  - It needs a superuser API token, because the token endpoints show a non-superuser only that
    user's own tokens (finding 20).
  - Tokens without `sid` (the app-password grant) share a key per `auth_time`.
  - Two grants of one client in one login session share a key and are revoked together (answer 19
    accepts that).
  - A listing that stops at one page misses families; the third pass's did.

Verdict: the sweep reliably identifies every descendant of a fork through supported API calls,
in 6 of 6 runs. It does not revoke them promptly, and it cannot stop a holder who keeps
refreshing. That is evidence for asking upstream for atomic rotation, or for an ADR exception.
The webhook receiver and the sweep are probe code; in Victual prerequisite 9 is evidence, not
met.

## Results (2026-10-28, 65 of 76 pass)

| ID | Clause | Expected | Observed | Verdict |
|---|---|---|---|---|
| P1 | 0055 d3: resource servers fetch discovery from the issuer | Discovery reachable from the issuer URL | `iss` is `http://localhost:9000/`; `/.well-known/openid-configuration` there is **404**. Per-provider discovery (`/application/o/<slug>/…`) is 200 and names the same issuer | **FAIL** |
| P2 | 0055 d4, 0056 d3: stable opaque subject | 200; `sub` opaque, no email | 200; hashed `sub`; `aud` = `[victual-spike-mcp-client, victual-spike-mcp-resource]`; 300 s lifetime | pass |
| P3 | 0055 d4: one subject per user across clients | Same `sub` on reissue and from another provider | Same on both | pass |
| P4 | 0055 d3: sidecar accepts its own audience | Accepted | Accepted (fixed MCP audience) | pass |
| P5 | 0055 d7: exchange MCP token for an `/api` token | 200; same `sub` and `iss`; `aud` = `/api` | As expected | pass |
| P6 | 0055 d3: `/api` accepts the exchanged token | Accepted | Accepted | pass |
| P7 | 0055 d7: exchanged token records the sidecar in `act` | `act` present | No `act`; `azp` is the target (`victual-spike-api`), so nothing in Authentik's own claims names the sidecar. The G1 claim `victual_exchanger` does | **FAIL** |
| P8 | 0055 d7: delegation records the sidecar in `act` | 200; `act.sub` set | 200; `act.sub` set. **The Actor had to be created with `ak shell`**: see finding 3 | pass, unsupported setup |
| P9 | 0056 d4 and prerequisite 4: group changes reach the next token | Present, absent after removal, present after re-adding | As expected | pass |
| N1–N3 | 0055 d3: a token for another audience is refused | Refused on `aud` | All refused on `aud`, including a token from an unrelated provider of the same issuer | pass |
| N4 | 0055 d3: tampered token refused | Refused | Bad signature | pass |
| N5 | 0055 d3: `resource` not silently ignored on exchange | `invalid_target` | `400 invalid_target` | pass |
| N6 | 0055 d7: only the trusted sidecar mints `/api` tokens | `invalid_target` | `400 invalid_target` | pass |
| N7 | 0055 d7: a client without the exchange grant cannot exchange | Refused | `400 invalid_grant` | pass |
| N8 | 0055 d7: subject token from an untrusted provider refused | `invalid_grant` | `400 invalid_grant` | pass |
| N9 | 0055 d7: tampered subject token refused | `invalid_grant` | `400 invalid_grant` | pass |
| N10 | 0055 d3: one audience per token | `invalid_target` | `400 invalid_target` | pass |
| N11 | 0055 d7: exchange adds no scope the subject token lacked, scope-ceiling policy unbound | `victual:write` refused or dropped for a read-only subject token | **200, `scope=victual:write victual:read openid`** | **FAIL** |
| N11b | N11 with the policy that `setup.mjs` binds | Widening refused; equal scope still exchanged | Widening `400 invalid_grant`; equal scope 200; write-to-write 200 | pass |
| N12 | 0055 d4: a disabled user's live token cannot be exchanged | Refused | `400 invalid_grant` | pass |
| N13 | 0055 d7: expired subject token refused | `invalid_grant` | `400 invalid_grant` | pass |
| N14 | 0055 d3: expired token refused after 60 s skew | Refused | Expired | pass |
| N15 | 0056 prerequisite 2: registration closed to anonymous callers | Refused | 401 without a token and 401 with a user token that lacks the registration scope | pass |
| G1a | 0055 d7, open question 8: a signed claim names the authenticated exchanger | `victual_exchanger` is the exchanging client's id; a second exchanger gets its own; `/api` accepts | `victual-spike-sidecar` and `victual-spike-sidecar-b` respectively; `/api` accepts | pass |
| G1b | Same: callers cannot inject or override it | Basic header wins over a body `client_id` and over claim-shaped form fields | Basic sidecar with body `client_id` of sidecar-b and fields `victual_exchanger`, `victual_grant`, `act`, `azp`, `aud`, `sub`: claim names the sidecar, no `act`, `aud` and `sub` unchanged. Basic sidecar-b with body sidecar: names sidecar-b. Duplicate body `client_id`: names the client whose secret matched | pass |
| G1c | Same: a `client_id` without a valid secret gets no token | All refused | Wrong secret, another client's secret, no secret, mismatched Basic: all `400 invalid_client` | pass |
| G1d | Same: a user attribute with the claim's name cannot override it | Claim keeps the authenticated value although a mapping spreads `user.attributes` | Claim and `victual_grant.azp` kept. An attribute named `act` did get through, because Authentik reserves no claim names | pass |
| G1e | 0055 d7: on every exchanged `/api` token, on no other | Present for each accepted scope set; exchanges without a `victual:*` scope refused; absent elsewhere | Present for four scope sets; `openid` only, no scope and an unknown scope: `400 invalid_grant`; absent from the `/api` provider's own token, the MCP user token and an exchange issued without `audience` | pass |
| G1f | Same: `act` populated by the same derivation | `act.sub` is the authenticated exchanger even when the body names another client and sets `act` | `act.sub` is the sidecar in both | pass |
| G1g | 0055 d7, 0056 d11: the token identifies the originating grant | `victual_grant.azp` is the MCP client; `sid` stable across a refresh and new for a second login; `jti` new per token | As expected | pass |
| G1h | 0055 d6: revoking the grant at the authorization server stops exchanges | Exchange of a deleted subject access token refused; an exchanged token already issued stays valid | `400 invalid_grant`; the earlier exchanged token is still accepted by `/api` | pass |
| G2a | 0055 d3, open question 9: one fixed MCP audience accepts two registered clients | Both accepted by a sidecar configured with the single audience | Both accepted. A sidecar pinned to `mcp-client`'s `client_id` refuses `mcp-client-b` (the old behaviour) | pass |
| G2b | Same: the sidecar refuses tokens without the audience | Refused: unbound client, `/api` client, exchanged token, bound client's token with no bound scope, unsigned token | All refused on `aud` or signature | pass |
| G2c | 0055 d3: a client not bound to the resource cannot obtain it | Unbound client asking `resource` and `audience`: no MCP audience. Bound client sending other values: `aud` unchanged | As expected | pass |
| G2d | 0055 d3: shape of `aud` | List form keeps the `client_id` in the `id_token`; scalar form drops it | List: `id_token` `aud` has both values. Scalar: `id_token` `aud` is only the MCP audience | pass |
| G2e | 0055 d7: the override does not break the exchange | The sidecar exchanges the second client's token; `/api` accepts | 200; accepted | pass |
| G3a | 0055 d7, open question 7: bundle-aware ceiling | Bundle held: leaves inside accepted, a leaf outside refused | `victual:read` and `victual:write` 200 with the leaf scope; `victual:admin` `400 invalid_grant` | pass |
| G3b | Same: a plain set comparison with bundles | The covered leaf wrongly refused; the outside leaf refused | Both `400 invalid_grant` | pass |
| G4a | 0055 d6: code flow with PKCE S256 and a scripted login | 200; `sid`; `amr` `pwd`; same `sub` as the app-password grant | As expected | pass |
| G4b | Same: verifier, replay and password enforced | All refused | Wrong verifier `invalid_grant`; replayed code `invalid_grant`; wrong password refused in the flow | pass |
| G4c | 0055 d6: S256 only, with the policy `setup.mjs` binds | No challenge and `plain` refused at `/authorize`; S256 works | Refused (the authorize page shows no redirect); S256 200 | pass |
| G4c0 | Same, policy unbound | Refused | Both **200**: Authentik accepts a code request with no challenge, or with `plain` | **FAIL** |
| G4d | 0055 d6: refresh rotation with reuse revoking the family, Authentik's native behaviour (G5 revoke rule unbound) | Old token refused after rotation; newest also refused after the reuse | Rotation works; old refresh token `invalid_grant`; the newest refresh token still **200** | **FAIL** |
| G5a | 0055 prerequisite 9, candidate: provider settings | A setting under which reuse is refused and the newest token is refused too | `refresh_token_threshold` of one day: a refresh returns no new refresh token and the same token works again (200, 200). Default: reuse refused. The setting only decides whether a refresh rotates the token | **FAIL** |
| G5b | 0055 prerequisite 9, candidate: a deny policy bound to the application | The policy refuses the refresh grant | Engine mode `any`: a new login still succeeds, because the S256 policy passes. Mode `all`: a new login is refused, and refresh with a valid token is still 200. Refresh grants never evaluate application policies | **FAIL** |
| G5c | 0055 prerequisite 9, candidate: a scope mapping | A mapping refuses the grant or sees a reused token | The mapping sees the `refresh_token` parameter. A mapping that raises is skipped and the token is issued (200). A reused token is refused before any mapping runs | **FAIL** |
| G5d | 0055 prerequisite 9: reuse invalidates the whole family (revoke rule bound by `setup.mjs`, fourth pass: through the admin API) | Old token refused; newest refused afterwards; no row of the family left | Rotation 200 (2 rows, 1 revoked); reuse `invalid_grant`; refresh rows gone after 539 ms (worker); newest `invalid_grant` | pass |
| G5e | 0055 prerequisite 9: Victual can observe reuse (webhook transport) | One post naming the refresh token, provider and user on reuse; none on a normal rotation | 0 posts on rotation; one post 396 ms after the reuse response with the reused row's `pk`, `model_name` `refreshtoken`, the provider and `spike-alice` | pass |
| G5j | 0055 d6: access tokens of a revoked family | Stateless check accepts until `exp`; the issuing client's introspection says inactive; the exchange is refused | When the refresh rows were gone both access tokens were still active; their rows were gone 690 ms later. Then: stateless check accepts both; introspection by the issuing client inactive; exchange `invalid_grant`. Another client's and the sidecar's introspection said inactive for the valid token too | pass |
| G5k | 0055 prerequisite 9: negative control | Another login of the same user and client, another user and another client keep working | All three: access token active and refresh 200 after the reuse | pass |
| G5f | 0055 prerequisite 9: concurrent refresh with one token mints no second chain | One of five parallel refreshes succeeds; at most one live refresh token remains | Attempt 1: 5 of 5 succeeded, no event, 5 live refresh tokens with active access tokens. The same on the first cold start | **FAIL** |
| G5g | 0055 prerequisite 9: a later reuse revokes every branch of a fork | After the original token is replayed every branch is refused and its access token inactive | Replay `invalid_grant`; refresh rows gone after 1051 ms; the 5 branches refused; their 5 access tokens still active at that moment, inactive 239 ms later | pass |
| G5h | 0055 prerequisite 9: the old and the newest token at the same moment | Old refused; the newest's successor is revoked with the family | Three runs: old 400, newest 200, rows gone after 980 to 1372 ms, successor 400. A save after the last pass was not seen | pass |
| G5i | 0055 prerequisite 9, Victual-side: webhook, then admin API | Family revoked by Victual with the rule unbound | Post after 232 ms; Victual deleted 4 rows; newest refused; access token inactive; control login 200 | pass |
| G5l | 0055 prerequisite 9, Victual-side: sweep for forks | A family with more than one live refresh token is revoked; others are untouched | 4 live rows; the sweep (all providers) revoked 1 family; the 4 branches refused; another user's family 200 | pass |
| G5m | 0055 prerequisite 9, question 16: bounded delay, webhook receiver | 10 reuse events; family gone and newest refused in each | 746 / 788 / 935 ms (min / median / max), 50 ms polling; newest 400 in all 10 | pass |
| G5m2 | Same, in-Authentik rule rewritten without internals | As G5m | 676 / 906 / 1221 ms; newest 400 in all 10 | pass |
| G5n | Question 16: one refresh with the newest token inside the window, and its descendant | Reuse refused; whatever the refresh issued is revoked with the family | Fired 0, 100, 200, 300, 400 ms after the reuse response: 200 each time; the descendant refused and its access token inactive afterwards. At 600, 800, 1000 ms: 400. No row left in any run | pass |
| G5n2 | Question 16, adversarial: the newest token's holder refreshes in a tight loop | The loop is refused; no row left; the last tokens refused and inactive | 138, 117 and 135 successful refreshes; never refused; the loop gave up at 10 s. 4 s later 11 to 27 revoked rows were left, none live; the last 5 access tokens still active | **FAIL** |
| G5o | Question 16: worker stopped (webhook receiver) | Nothing revoked during the outage; the event persists; after the start the family, with a descendant issued during the outage, is revoked | 20 s stopped: event stored, 0 posts, newest active, a refresh by its holder 200, 2 tasks queued. After `worker-start`: post at 5.3 s, family gone at 6.6 s; newest and outage descendant 400, descendant access token inactive | pass |
| G5p | Question 16: worker lagging behind an event burst | Revocation lands; the delay is reported | 300 events (posted in 9.5 s, about 590 tasks queued): family gone after 50.5 s. 1000 events (26 s, about 2100 tasks): 166.6 s. Newest 400 both times. First cold start: 43.3 s and 121.6 s | pass |
| G5q | Question 16: worker killed (SIGKILL) 0, 50 and 100 ms after a reuse, then started | The task is not lost | No post before the kill in all three; family still live 5 s later; gone 5.5 to 6.4 s after `worker-start`; newest 400 | pass |
| G5r | Question 17: Victual-side sweep over the G5f topology, 6 runs | No row of the fork left; branches and holder tokens refused and inactive; three controls unaffected | 6 of 6: forks of 4 or 5 live tokens, 1 family swept, 0 rows left, all refused, 0 active, controls 200. Runs 1 to 3 took 4.0 to 4.7 s; runs 4 to 6, with a holder refreshing, 21.8 to 22.9 s | pass |
| G5r2 | Question 17, adversarial: G5r runs 4 to 6, judged on stopping the refreshing holder | The holder is refused before its 10 s loop gives up | 123, 154 and 123 refreshes; last success at about 10.0 s each time; the loop gave up; the sweep finished after it | **FAIL** |
| G5s | 0055 prerequisite 9: the webhook sender is authenticated; the receiver acts only on confirmed events | 401 and no revocation without or with a wrong header; no revocation for an unknown event; the real event revokes; Authentik's post without the header mapping refused | 401, 401; unknown `event_uuid` skipped; family intact after those; the real post 204 and the family gone after 836 ms; with the header mapping removed Authentik's post got 401 and the family stayed live | pass |
| G6a | 0055 prerequisite 11: adapter clients are accepted at the sidecar and exchange | Two new clients: `aud` = `[client_id, MCP audience]`, accepted, exchanged to `/api`. A client without the trust step: accepted, exchange refused | As expected for both clients; the client without trust got `invalid_grant` at the exchange | pass |
| G6b | 0055 prerequisite 11: an adapter client cannot select another audience | `audience` and `resource` at `/authorize`, `/token` and on refresh, and the `/api` scope, leave `aud` unchanged | `aud` stayed `[client_id, MCP audience]` in all three places. `victual:admin` without a mapping was dropped (alone: empty scope, no MCP audience). With the admin mapping the scope was granted and `aud` unchanged. `client_credentials` and self-exchange refused | pass |
| G6c | 0055 prerequisite 11: a client without the mapping gets no MCP audience | No MCP audience however requested; the sidecar refuses; an unrelated provider is refused | `aud` was only its `client_id`, also when asking for the MCP audience; the sidecar refused all three on `aud`. Its exchange still succeeded, because the trust step was run | pass |
| G6d | 0055 prerequisite 11: exchanged tokens target only `/api` | `aud` is the `/api` client; the sidecar refuses them; `/api` accepts them | Three clients: `aud` `victual-spike-api`, sidecar refused on `aud`, `/api` accepted. With no audience: `aud` is the sidecar's own client | pass |
| G6e | 0055 prerequisite 11: no second exchange into the MCP audience | Every attempt refused | Five targets (MCP client, MCP resource string, adapter client, `resource`, none): `invalid_target` or `invalid_grant`. The second exchanger: `invalid_target` | pass |
| G6f | 0055 d6 on an adapter client: S256 only | No challenge and `plain` refused; S256 works | Both refused at `/authorize`; S256 200 | pass |
| G8a | 0055 prerequisite 8: the `victual:admin` mapping and the exchanger mapping on it | `/api` issues the scope; an admin subject exchanges with the exchanger claim | Scope issued; exchange 200 with `victual_exchanger` the sidecar | pass |
| G8b | 0055 prerequisite 8: scope mappings belong to the provider | A read-only client gets read and the MCP audience, not write or the bundle | Scope `victual:read openid`; `aud` as expected | pass |
| G8c | 0055 prerequisite 8: each `mcp-aud` mapping emits once | Scope sets without `openid` give the MCP audience once | Six scope sets (read, write, bundle, read and write, write and bundle, `openid` and write): `aud` `[client_id, MCP audience]` each time | pass |

`results.json` holds the exact responses. The G5 rows above are condensed from it, using the
second cold start of 2026-10-28; the other rows were unchanged from the second cold start of
2026-10-26.

## Findings

1. **Token exchange works (ADR-0055 prerequisite 4, Authentik half: met for the core
   path).** P5, P6 and N5 to N13 show it. The exchanged token keeps the user's `sub` and the
   install's `iss`, and takes the target provider's `client_id` as `aud`. Trust is explicit:
   the sidecar must federate with the client's provider, and `/api` must federate with the
   sidecar. Anything else is refused with the error the RFC names.
2. **Exchange does not cap scope at the subject token (N11), and one policy closes it (N11b,
   G3).** The exchanged token's scopes are bounded only by the target provider's scope mappings.
   `setup.mjs` now binds the ceiling policy, so the deployment configuration is the tested
   one. The policy sees the verified subject token as `oauth_jwt` and the scopes that will be
   granted as `oauth_scopes` (`authentik/providers/oauth2/token/base.py`, `check_policy_access`;
   `token_exchange.py`, `parse`). It also requires at least one `victual:*` scope, which G1
   relies on. Without the policy, a read-only token became a write token. The policy is
   Authentik configuration, not standard protocol surface, so the swap test has to name it.
3. **Bundles are the policy's weak point (G3a, G3b).** The policy can hold a bundle-to-leaf
   table in its expression (`BUNDLES` in `setup.mjs`). With it, a leaf inside the held bundle
   is accepted and a leaf outside is refused. Without it, a plain set comparison refuses the
   covered leaf. Whether to keep the table there is a maintainer decision. The cost is a second
   copy of Victual's bundle definitions, edited by hand in an admin policy and not covered by
   Victual's tests. A convention such as "bundle `victual:bundle:x` covers `victual:x:*`" would
   remove the table, but it fixes Victual's scope naming to what a string prefix can express.
   Victual's own expansion stays authoritative: decision 7 already has Victual and the sidecar
   re-check the result. The policy is a second fence for a compromised sidecar, and it can be
   coarser than Victual's.
4. **The exchanger can be signed into the token by configuration (G1).** The scope-mapping
   expression receives `user`, `http_request`, `provider` (the target) and `token` (the access
   token being built); it receives nothing that names the requesting provider
   (`authentik/providers/oauth2/models.py`, `ScopeMapping.evaluate`; `views/userinfo.py`,
   `get_claims`). The only trace is the HTTP request. The expression therefore calls
   `extract_client_auth`, the function `TokenView.post` used to find the provider
   (`authentik/providers/oauth2/utils.py`), so it reads the Basic header first and the form
   `client_id` only without one. `TokenRequest.parse` has already compared that provider's
   secret for the exchange grant (`token/base.py`) before any mapping runs. That ordering is why
   the Basic-header-plus-body-`client_id` injection in G1b fails to move the claim, and why a
   `client_id` without a valid secret never reaches a mapping (G1c). The expression emits
   nothing for a public client, because its `client_id` is not authenticated.
   - Claims from mapping output replace dataclass fields. `IDToken.to_dict` applies
     `id_dict.update(self.claims)` after `act` and `aud` are set (`id_token.py`), which is
     what makes both G1f (`act`) and G2 (`aud`) possible.
   - The claim is not `act` by default. A mapping's `act` replaces the Actor's `act` (static
     read of `to_dict`, not run), which would hide an Actor-based delegation (P8). Emitting `act`
     is available if the maintainer wants it (G1f). Using a separate claim name fits ADR-0056's
     `attribution_source` column, which names the claim.
   - Authentik reserves no claim names, and merges mappings in `scope_name` order
     (`get_claims`, `order_by("scope_name")`, so the database collation decides). The exchanger
     mapping sits on the `victual:*` scopes, which sort after `profile` and `openid`. G1d
     shows a mapping that spreads user attributes on `profile` cannot override it. A mapping
     that emits the same key on a scope sorting later would, and an attribute named `act`
     already got through in G1d. This is a rule for administrators: no mapping may emit
     `victual_exchanger`, `victual_grant`, `act` or `aud` from user data.
   - In the third pass the expression imported `authentik.providers.oauth2.utils` and
     `...models`. Under answer 15 that does not qualify. The fourth pass rewrote it without them
     (finding 18); the reasoning above still holds, because the rewrite applies the same rule as
     `extract_client_auth`.
5. **The originating grant is identified by `azp`, `sid` and `jti` of the subject token (G1g).**
   Exchange issues a token with no session, so `sid` is not carried over
   (`views/token.py`, `create_token_exchange_response`); the mapping copies it, along with `azp`
   and `jti`, from the subject token that the exchange has already verified (the expression
   only decodes the payload). `azp` is the MCP client, which is what decision 6's per-client
   revocation keys on. `sid` is the login session: it stayed the same after a refresh and
   changed on a second login. `jti` changes on every token. Nothing identifies the refresh token
   or an Authentik grant row. Two caveats: tokens from the app-password grant carry no `sid`,
   and the refresh token outlives the login session in Authentik (`RefreshToken.session` is
   `SET_DEFAULT`, static read), so ending a session does not revoke the grant. Revoking at the
   authorization server means deleting the access and refresh token rows (admin API
   `/oauth2/access_tokens/`, `/oauth2/refresh_tokens/`): after the access token row is deleted,
   the exchange is refused (G1h), while exchanged tokens already issued stay valid until they
   expire. That fits decision 6's five-minute worst case.
6. **A dedicated MCP audience is configuration plus an adapter step (G2).** The scope mapping
   returns `aud` as `[client_id, <MCP audience>]`, replacing `aud`
   (`id_token.py`, `to_dict`). Two registered clients are accepted by a sidecar configured
   with the single audience; an unbound client, the `/api` client, an exchanged token and an
   unsigned token are refused (G2a, G2b). A client not bound to the mapping cannot obtain the
   audience by any request parameter (G2c): scope mappings belong to the provider.
   - The list form keeps the client's own `client_id` in `aud`, so the OIDC `id_token` still names
     its client. The scalar form puts only the MCP audience into the `id_token`, which an OIDC
     client validating `aud` against its `client_id` would reject (G2d). The list has no `azp`
     in the `id_token`, which OIDC Core recommends for a multi-valued `aud`; MCP clients that
     use only the access token are not affected, and none was run here.
   - Authentik merges lists by appending, so one mapping per scope would repeat the audience.
     Each bound scope's mapping emits it only when it is the alphabetically first granted
     bound scope (`audExpression` in `setup.mjs`). A token granted none of the bound scopes
     (for example `profile` only) carries no MCP audience and the sidecar refuses it (G2b).
     That fails closed.
   - "Binding" means: the provider's `property_mappings` list includes the `mcp-aud` mappings for
     the scopes the client can receive. That is configuration. Creating it for every client is
     the adapter's work, because registration policy is Victual's (ADR-0055 decision 8). The
     adapter would POST the provider with those mapping ids through the admin API. Authentik's
     dynamic client registration has `override_property_mappings`
     (`authentik/providers/oauth2/models.py`, `OAuth2DynamicClientRegistration`) that could carry
     the same mappings; that was read and not run. Verdict: configuration in Authentik, adapter
     in Victual.
7. **Authorization code with PKCE works headless (G4a, G4b), with two gaps.** The login runs
   through `/api/v3/flows/executor/<slug>/` with a hand-kept cookie jar and the CSRF header; the
   default authentication flow needs only the identification and password stages here. S256 is
   not enforced by Authentik: a code request with no challenge, or with `plain`, succeeds
   (G4c0; `token/authorization_code.py`). The authorize-time policy context carries the challenge
   and its method (`views/authorize.py`, `modify_policy_request`), so a bound expression policy
   refuses both (G4c). The refusal shows as an authorize page, not an OAuth error redirect.
   Refresh tokens rotate, and the old one is refused, but reuse does not revoke the newest one
   (G4d; `views/token.py`, `create_refresh_response` marks only the used token revoked and
   raises a suspicious-request event in `refresh_token.py`). ADR-0055 decision 6 asks for
   family revocation. No setting offered it. The consequence: an attacker who replays a stolen
   old refresh token gets a refusal, and the legitimate holder's chain stays valid.
8. **The issuer does not serve discovery (P1).** With `issuer_mode: global`, `iss` is the
   install root, and no discovery document is served there. Each provider has its own,
   naming that same issuer. A resource server therefore needs a discovery URL configured
   beside the issuer. That is still configuration only, and ADR-0055 decision 3 now permits
   it. With `per_provider` (the default), discovery works but each provider is its own issuer,
   which conflicts with decision 1's one issuer.
9. **With one issuer and one signing key, `aud` is the only barrier between providers (N3).**
   A token from an unrelated provider verifies against the same JWKS and is refused only on
   `aud`. A resource server that skips the `aud` check accepts every other client's tokens.
10. **`act` still needs an Actor that open-source Authentik cannot create (P7, P8).** Delegation
    sets `act.sub`, but only with an actor token for an Authentik Actor. Ownerless Actors are
    created through the Agents API, which requires an Enterprise licence
    (`authentik/enterprise/agents/api.py`, `EnterpriseRequiredMixin`). P8 used `ak shell`, which
    is not a supported setup. The G1 claim replaces what `act` would have said for the
    exchanger.
11. **Registration needs a bearer token (N15).** Authentik's dynamic registration refuses
    anonymous callers. Claude Code registers anonymously (Hydra spike), so it cannot register
    with Authentik directly. That fits ADR-0055 decision 8 (Victual serves the registration
    policy and creates the client through the authorization server's admin API). With
    Authentik that adapter creates one provider per client and attaches the mappings from
    finding 6.
12. **Authentik detects refresh-token reuse and does nothing else (G4d, G5).** A refresh
    marks the used token `revoked` and issues a new one (`views/token.py`,
    `create_refresh_response`). Presenting the revoked token returns `invalid_grant` and
    writes a `suspicious_request` event whose context names the token by `pk`, plus the
    provider (`token/refresh_token.py`). The tokens that rotation produced stay valid. There
    is no family id: each new refresh token copies provider, user, session and `auth_time`
    from its parent, so those four fields (visible through the admin API as `sid` and
    `auth_time` in the `id_token` field) are the only family key. Two grants of one client
    in one login session share it, so revoking one revokes both. That fails closed.
13. **Candidates for prerequisite 9, supported or not (G5a to G5c, G5d, G5e, G5i).**

    | Candidate | Available in the open-source edition | Result |
    |---|---|---|
    | Provider settings `refresh_token_threshold`, `refresh_token_validity` | Yes | No mitigation. With a threshold above zero a refresh returns no new refresh token and the same token works repeatedly, so reuse is not even detected (G5a) |
    | Expression policy bound to the application | Yes | Cannot work. Refresh grants never evaluate application policies, by static read of `token_exchange.py`, `client_credentials.py` and `refresh_token.py`, and by test (G5b). In the default engine mode `any` a deny policy is also outvoted by any passing policy |
    | Scope mapping on the provider | Yes | No mitigation. It runs after the revoked check, so it never sees a reused token. It can read the `refresh_token` parameter, and it cannot deny: a mapping that raises is skipped and the token is issued (G5c) |
    | Notification rule with an expression policy that deletes the family | Yes. Third pass: the deletion inside a policy and the import of internal models are not an extension API, and answer 15 rules them out. Fourth pass: the policy calls the admin API through the documented `requests` object instead | Works for sequential reuse and for the race between the old and the newest token (G5d, G5h, G5m2). Asynchronous. Does not see concurrent refresh (G5f). Cannot stop a continuously refreshing holder (finding 22) |
    | Event matcher, notification rule and webhook transport | Yes | Victual can observe detected reuse: the post names the refresh token `pk`, provider and user, and arrived 0.2 to 0.4 s after the reuse response (G5e, G5i; the probe polls the hook's log every 0.1 s). It cannot observe concurrent refresh |
    | Revoking through the admin API from Victual | Yes. This is a Victual-side mitigation; here it is probe code | Works (G5i, G5m, G5n); the sweep finds every fork descendant (G5l, G5r). Neither stops a continuously refreshing holder (G5n2, G5r2) |

    I did not examine Enterprise features, and nothing here depends on them.
14. **The in-Authentik mitigation, with its limits (G5d, G5h, G5j, G5k).** The rule runs on
    the worker after the event is saved, so the family disappears later. In the third pass,
    when the rule deleted rows directly, that took 0.5 to 0.6 s. In the fourth, through the
    admin API, it took 0.7 to 1.2 s (G5m2, finding 19).
    Within that time the newest token still works. In G5h the old and the newest token were
    presented at the same moment three times: the newest request succeeded, minted a
    successor, and the rule deleted the successor with the rest of the family. A refresh
    request that saves its successor after the delete would survive; that window was not
    seen and cannot be excluded. Access tokens of the family are deleted too, so the issuing
    client's introspection answers inactive and the sidecar's exchange of them is refused
    (G5j). The stateless check still accepts them until `exp`, at most five minutes, which
    matches decision 6. Introspection works only with the issuing client's own credentials:
    another client and the sidecar get `active: false` for a valid token, so the sidecar
    cannot use introspection as a revocation check for MCP tokens. Other users, other logins
    of the same user and other clients keep working (G5k).
15. **Concurrent refresh with one token is not fully covered (G5f, G5g, G5l).**
    `RefreshTokenTokenRequest.parse` reads the token and checks `revoked` without a lock, and
    `create_refresh_response` marks it revoked afterwards. The outcome depends on how much the
    parallel requests overlap. In three of five complete runs on 2026-10-26 the first attempt
    had all five requests return 200 and create five unrevoked refresh tokens with one family
    key. No request saw a revoked token, so no event fired and neither rule ran: all five
    branches and their access tokens stayed valid. In the other two the overlap was partial: 2
    of 5 and 4 of 5 requests succeeded, and the rest presented a revoked token and raised
    reuse events (3 and 1). Four seconds later the family had no rows left, because the
    revoke rule had deleted it. Both outcomes fail G5f (more than one request succeeded), and only the
    full overlap leaves a durable fork. The probe retries until an attempt leaves a fork,
    reports every attempt, and runs G5g on that fork. A later replay of any old token deletes
    every branch (G5g). Without a replay only a sweep finds the fork (G5l). A family with more than one live
    refresh token is always a fork, because rotation revokes the parent (the default
    `refresh_token_threshold` is zero).
16. **What Victual would do, and what it can see.** Victual can observe reuse: the webhook
    transport posts the event, and `/events/events/?action=suspicious_request` lists the same
    events. It cannot observe concurrent refresh as an event. To meet prerequisite 9 itself,
    Victual would run the steps G5i and G5l show with the admin API: on a reuse post whose
    `context.token.model_name` is `refreshtoken`, read `/oauth2/refresh_tokens/<pk>/`,
    compute `sid` and `auth_time` from its `id_token`, and delete the refresh and access
    tokens of that user and provider with the same pair. For forks it lists the provider's
    unrevoked refresh tokens on a schedule, or on each exchange, and revokes any family with
    more than one. The exchanged token already carries `victual_grant.sid` and
    `victual_grant.azp` (G1g), so Victual's client-grant rows can store the pair and revoke by
    key. This needs a superuser API token (finding 20), an authenticated receiver for the
    webhook (finding 23), and a trigger for the sweep. The fourth pass ran these steps as probe
    code (G5m to G5s); findings 21 and 22 give their limits. If neither the in-Authentik rule
    nor the Victual-side steps are accepted, prerequisite 9 is not met and needs an explicit ADR
    exception.
17. **Audience binding is two adapter steps and one sidecar check (G6, G8).**
    - The `mcp-aud` mappings bind a client to the MCP audience. A client the adapter created
      with them gets `aud` = `[client_id, MCP audience]` however it asks (G6b). `audience` and
      `resource` at `/authorize`, at `/token` and on refresh change nothing. A scope with no
      mapping on the client, such as `victual:admin`, is dropped without an error; asking for
      only that scope gives a token with empty scope and no MCP audience, which the sidecar
      refuses. A client that holds the admin mapping gets the scope and still the same `aud`.
    - A scope mapping is also what lets Authentik grant a scope. Attaching the `mcp-aud`
      mapping of `victual:write` to a client that should not have that scope grants it. The
      first G8b run did this by mistake. The adapter therefore attaches an audience mapping
      only for scopes it grants (`adapter.mjs`).
    - The trust step is separate: the adapter must add each client to the sidecar's
      `jwt_federation_providers`. Without it the sidecar accepts the token at its own check
      and Authentik refuses the exchange (G6a). With the trust step but without the audience
      mapping the sidecar's check refuses the token, but Authentik's exchange succeeds, since
      it does not look at the MCP audience (G6c). The sidecar's audience check before it
      exchanges is therefore the control.
    - Exchanged tokens carry the `/api` audience only (G6d) and cannot be exchanged again: the
      `/api` provider is not a trusted subject provider, and the MCP providers do not trust the
      sidecar, so every target is refused (G6e). The adapter must never add federation to an
      MCP provider.
    - The S256 policy needs policy engine mode `all` on each application. The adapter sets it
      (G5b, G6f).
    - Each `mcp-aud` mapping emits the audience once when its scope is the first granted bound
      scope; G8c asks without `openid` to run each one.
18. **The exchanger mapping can work on documented context alone (G1, fourth pass).** The
    rewrite reads `request.http_request` and applies the rule `TokenView.post` uses: the Basic
    header if it matches `^Basic\s{1}.+$` (checked with the documented `regex_match`), else the
    form `client_id` and `client_secret` (`authentik/providers/oauth2/utils.py`,
    `extract_client_auth`). For the token-exchange grant Authentik authenticates a client only
    by that secret (`token/base.py`; there is no client assertion), so the copy and Authentik see
    the same client. The rule itself is not a documented contract. If Authentik changes how it
    reads client credentials, the claim could name a different client from the one Authentik
    authenticated; G1b and G1c would catch that on an upgrade. Without the provider lookup the
    mapping cannot tell a public client from a confidential one, so it requires a presented
    secret and relies on `/api`'s `jwt_federation_providers` holding confidential exchangers
    only.
19. **Revocation through the admin API is a sequence of single-row deletes (G5d, G5m, G5m2,
    G5n, G5n2).** Authentik's API has no family operation and no bulk delete: each refresh and
    access token is one `DELETE`. Both mechanisms list the family, delete its unrevoked refresh
    tokens first, then the rest and the access tokens, and repeat until two passes find nothing.
    In the receiver's log of the second cold start, the first pass over a small family ended
    0.56 to 1.15 s after the post arrived (median 0.79 s, 28 events). That time covers the event
    confirmation, the row read, two listings and the single-row deletes. The in-Authentik rule
    also sleeps 0.5 s between its two closing empty passes, so one reuse holds one of the
    worker's two threads (`lib/default.yml`) for about a second or more, and concurrent reuses
    queue behind it. The receiver runs outside the worker and has no such cost. Refresh-token rows go before access-token rows, so for a short time a family's
    access tokens are still active while its refresh tokens are gone (G5g). The deletes leave no
    Authentik audit event (`events/middleware.py`, `should_log_model`; static read), so the
    revoker must record what it revoked.
20. **The revoking token must belong to a superuser.** The token endpoints return all rows only
    to a superuser; anyone else sees their own tokens
    (`authentik/providers/oauth2/api/tokens.py`, `get_queryset`; static read, not run). RBAC
    permissions on the token models do not change that queryset. Victual's receiver and sweep,
    and the in-Authentik rule, therefore hold a superuser token. In the rule it is stored in the
    policy expression, readable by every administrator who can read policies.
21. **The worker bounds the delay (G5o, G5p, G5q).** Notification rules and transports run as
    worker tasks. The server stores the event and enqueues the dispatch task in PostgreSQL
    (`authentik/tasks/broker.py`); nothing happens until a worker takes it. With the worker
    stopped for 20 s the newest token kept working and a refresh by its holder succeeded; the
    family, including that descendant, was revoked 6.6 s after the worker started. A
    burst of events queued ahead of the reuse delayed revocation to 50.5 s (300 events) and
    166.6 s (1000 events). `/tasks/workers/` still listed the stopped worker, so it is not a
    liveness check. A deployment that wants a bounded delay needs a worker health alarm and must
    state the bound as "worker healthy".
22. **A holder who refreshes continuously outruns list-and-delete (G5n2, G5r2).** Each refresh
    revokes the presented token and saves a successor with the same family key. A pass lists
    the family and deletes the live token it saw; by the time the `DELETE` lands, the holder has
    rotated. In G5n2 the holder refreshed 117 to 138 times in 10 s and was never refused; the loop
    ended because the probe stopped it. The sweep in G5r2 behaved the same way. Only an atomic
    family revocation inside Authentik, or a refusal at the refresh itself, would stop this. A
    stolen branch token used this way stays valid until its holder stops, so this is the case
    the ADR exception or an upstream change would have to cover.
23. **The webhook sender can be authenticated, and the receiver can confirm the event (G5s).**
    The documented "Webhook Header Mapping" (API field `webhook_mapping_headers`) adds a
    shared-secret header. The receiver refused POSTs without it or with a wrong value (401) and
    revoked nothing; with the mapping removed it refused Authentik's own post. It also reads the
    event back through `/events/events/<event_uuid>/` and checks the action and the token pk
    before it revokes, so an authenticated but forged post naming a nonexistent event revoked
    nothing. The secret sits in the header mapping, readable by administrators.
24. **Load on the capped VM.** Two trial runs on 2026-10-28 failed before the final ones. In
    one, PostgreSQL (384 MiB) had a backend OOM-killed while a revocation pass listed a family
    of about 200 rows during a tight refresh loop. In the other, a few hundred parallel refreshes
    and introspections from the probe exhausted PostgreSQL's connections ("sorry, too many clients
    already") and the token endpoint answered 500. The probe now checks those tokens one at a
    time and caps the adversarial loops at 10 s. Neither was seen in the final runs.

## Not tested

- The device flow, and the S256 policy and `mcp-aud` mappings on a public client.
- Claude Code or any real MCP client against Authentik (ADR-0055 prerequisite 5, ADR-0056
  prerequisite 2), and Victual's registration adapter, which does not exist.
- The full swap test (ADR-0055 prerequisite 1) with Victual and the sidecar images. Only the
  probe's resource-server check was pointed at Authentik.
- A Victual webhook receiver and sweep. The ones here are probe code.
- Behaviour when a policy expression raises an error. Static read only: a policy that raises
  returns a failing result (`authentik/policies/expression/evaluator.py`). A mapping that
  raises is logged and skipped and the token is issued (run in G5c), so its claims are
  missing. A token without `victual_exchanger` must therefore make Victual refuse it.
- G5 with two workers, and across a server restart. Worker stop, lag and SIGKILL were run (G5o
  to G5q). Worker lag was produced only by an event burst through the events API.
- A least-privilege API token for revocation. The superuser requirement is a static read
  (finding 20).
- Stopping a continuously refreshing holder by other supported operations, such as deactivating
  the user or rotating the client secret. Both would also cut off every other grant of that
  user or client.
- A refresh whose save lands after the last revocation pass. Repeated passes caught every
  successor in G5h and G5n; the tight loop in G5n2 shows the window is real under load.
- A `suspicious_request` event from a revoked access token presented at the userinfo
  endpoint (static read only).
- How real MCP clients refresh. A client that refreshes in parallel with one token would
  hit G5f in normal use.
- The branch of the exchanger expression that a public client would take.
- Delegation combined with the exchanger mapping; a mapping's `act` replacing the Actor's.
- Break-glass with Authentik stopped (ADR-0056 prerequisite 5), back-channel logout, and an
  Entra ID upstream source.
- Two Authentik replicas, and any version other than 2026.8.5. On 2026-10-28 no newer release
  existed.

## Sources read

Authentik 2026.8 documentation (version selector "2026.8"), read on 2026-10-28:

- [Expression policies](https://docs.goauthentik.io/customize/policies/expression/) and the
  [expression reference](https://docs.goauthentik.io/customize/policies/types/expression/reference/):
  the helpers (`regex_match`, `ak_user_by`, `ak_create_event`, `ak_call_policy` and the rest),
  the `ak_logger` and `requests` objects, and `request.user`, `request.http_request`,
  `request.obj` and `request.context`. No `oauth_*` key and no top-level `http_request` are
  documented there.
- [Property mapping expressions](https://docs.goauthentik.io/add-secure-apps/providers/property-mappings/expression/):
  the same helpers and objects, `request.http_request`, `user`, and "Providers can add more
  variables, such as `provider`, `token`, or `endpoint`".
- [Property mappings](https://docs.goauthentik.io/add-secure-apps/providers/property-mappings/):
  scope mappings return a dictionary and grant their scope.
- [OAuth 2.0 provider](https://docs.goauthentik.io/add-secure-apps/providers/oauth2/):
  `request.context["oauth_scopes"]` in application policies; refresh-token rotation; no
  description of reuse handling.
- [Machine-to-machine authentication](https://docs.goauthentik.io/add-secure-apps/providers/oauth2/machine_to_machine/):
  `oauth_scopes`, `oauth_grant_type`, `oauth_code_verifier` and `oauth_jwt` in the policy
  context.
- [Token exchange](https://docs.goauthentik.io/add-secure-apps/providers/oauth2/token_exchange/):
  the exchanging client is identified by `client_id` and authenticated by its secret; target
  scope mappings apply with `audience`.
- [Notification rules](https://version-2026-8.goauthentik.io/sys-mgmt/events/notifications/):
  event matcher and expression policies on a rule; `request.context["event"]`.
- [Notification transports](https://version-2026-8.goauthentik.io/sys-mgmt/events/transports/):
  webhook body and header mappings, `request.context['notification']`.
- The running server's API schema (`/api/v3/schema/`) for `oauth2/refresh_tokens`,
  `oauth2/access_tokens`, `events/events`, `events/transports` (`webhook_mapping_headers`) and
  `tasks/tasks`.

Source read in the 2026.8.5 image (`/authentik`): `providers/oauth2/utils.py`
(`extract_client_auth`), `providers/oauth2/views/token.py`, `providers/oauth2/token/base.py`,
`providers/oauth2/token/refresh_token.py`, `providers/oauth2/api/tokens.py`, `events/tasks.py`,
`events/signals.py`, `events/middleware.py`, `events/models.py` (`send_webhook`),
`core/expression/evaluator.py`, `tasks/broker.py`, `lib/default.yml` (worker: 1 process, 2
threads).
