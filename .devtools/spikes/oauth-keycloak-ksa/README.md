# Spike: Kubernetes service-account tokens as Keycloak client credentials

Throwaway. On 2026-10-30 the maintainer asked whether Keycloak should handle Victual's service
registration tokens (MCP sidecar, label renderer, label worker) instead of keys copied by hand into
a Secret. The proposal is
[ADR-0057](../../../docs/adr/0057-permissions-are-per-resource-leaves-assigned-through-roles-groups-and-service-packages.md)
question 6: a workload inside the cluster authenticates to Keycloak with its projected Kubernetes
service-account token, so no secret is stored. This spike checks that path. Pairing for devices
outside the cluster (question 7) is not tested here.

"pass" and "FAIL" compare against question 6's proposal; "obs" records behaviour it does not
decide yet. A FAIL is a result: no expectation was weakened to make a case pass.

Keycloak **26.8.1** (`start-dev`, embedded file database) runs inside the existing kind cluster
(Kubernetes 1.37.0), in its own namespace, with no `KC_FEATURES` set, run on 2026-10-30 on the
shared podman VM. Keycloak runs in-cluster so it reaches the API server's service-account issuer
discovery with its own token and trusts the cluster CA through `KC_TRUSTSTORE_PATHS`. The probe
reaches it through `kubectl port-forward` on `localhost:8190`, which is also Keycloak's hostname,
so the realm issuer is `http://localhost:8190/realms/victual`.

## Run

```bash
cd .devtools/spikes/oauth-keycloak-ksa
podman start kind-cluster-control-plane   # if kind is stopped
./run.sh up      # namespaces kcsa-spike and kcsa-other: Keycloak, five service accounts, a worker pod
node probe.mjs   # configures the realm, runs the S cases, writes results.json (about 1 minute)
./run.sh down    # deletes both namespaces; nothing else in the cluster is touched
```

Files: `k8s.yaml` (the namespaces, service accounts, Keycloak and the worker pod), `probe.mjs`,
`run.sh`. `run.sh` generates the Keycloak admin password on first use into a gitignored
`.secrets.env` and stores it in the `kcsa-admin` Secret; the probe reads the file. The test
user's password is random on every run. No credential is committed.

## Configuration under test

- **Identity provider** `kubernetes`: `providerId: kubernetes`, `config.issuer` = the cluster's
  service-account issuer (`https://kubernetes.default.svc.cluster.local` on kind).
- **One client per workload**: `clientAuthenticatorType: federated-jwt`, with attributes
  `jwt.credential.issuer: kubernetes` and `jwt.credential.sub:
  system:serviceaccount:<namespace>:<account>`. The worker and renderer have service accounts
  enabled for the client-credentials grant. The MCP sidecar's client has the token-exchange switch
  and the `/api` audience scope instead.
- **The workload** presents its token as `client_assertion` with `client_assertion_type
  urn:ietf:params:oauth:client-assertion-type:jwt-bearer`. The worker pod mounts a projected token
  with `audience` = the realm issuer and `expirationSeconds: 600`. The other cases use
  `kubectl create token` (TokenRequest), which signs the same kind of token without a pod.

## Results

| ID | Clause | Expected | Observed | Verdict |
|---|---|---|---|---|
| S0 | ADR-0055 d2: nothing required relies on Preview | both features DEFAULT and enabled with no flag | `KUBERNETES_SERVICE_ACCOUNTS` and `CLIENT_AUTH_FEDERATED`: DEFAULT, enabled | pass |
| S1 | ADR-0057 q6: an in-cluster workload authenticates with no stored secret | the pod's projected token gets a client-credentials token for `victual-label-worker` | 200, `azp victual-label-worker`, 300 s access token; the pod token is pod-bound and lives 600 s | pass |
| S2 | ADR-0057 q6 | a TokenRequest token gets a token for `victual-label-renderer` | 200, `azp victual-label-renderer` | pass |
| S3 | ADR-0057 q6: a token proves one service only | an account with no client is refused; the renderer's token naming the worker's client is refused | `401 invalid_client`; `400 invalid_client` ("client_id parameter does not match authenticated client") | pass |
| S4 | ADR-0057 q6 | the same account name in another namespace is refused | `401 invalid_client` | pass |
| S5 | ADR-0057 q6 | a token for the API server's audience, or another realm's, is refused | `400 invalid_client` ("Invalid token audience") for both | pass |
| S6 | observation: replay | the same assertion presented twice | 200 both times | obs |
| S7 | observation: a long-lived token | a 24 h TokenRequest token | 200 | obs |
| S8 | ADR-0057 d7: revocation | a disabled client is refused; re-enabled, it works | `401 invalid_client`; then 200 | pass |
| S9 | observation: an account deleted in Kubernetes | a token issued before the deletion | 200 | obs |
| S10 | observation: a pod deleted in Kubernetes | the deleted pod's projected token | 200, with 510 s left on the token | obs |
| S11 | ADR-0057 q6: no shared secret | a client secret for a `federated-jwt` client is refused | `401 invalid_client` | pass |
| S12 | ADR-0055 d7 with ADR-0057 q6: the sidecar exchanges with no stored secret | exchange authenticated by the sidecar's token gives `aud victual-api`, `azp victual-mcp`, the same `sub` | 200; `aud victual-api`; `azp victual-mcp`; same `sub` | pass |

## Findings

1. **Supported, with configuration only (S0 to S5, S11).** Keycloak 26.8.1 ships both features
   enabled. A workload's service-account token authenticates it as exactly one client: the account,
   its namespace and the audience must all match. No client secret exists to leak.
2. **The MCP sidecar's exchange works the same way (S12).** The sidecar can authenticate its RFC 8693
   exchange with its service-account token, so the confidential client ADR-0055 decision 7 requires
   needs no stored secret either.
3. **Kubernetes deletions do not reach Keycloak (S9, S10).** Keycloak checks the token's signature
   and claims offline; it does not ask the API server whether the account or pod still exists. A
   token keeps working until it expires. The lever that stops a workload at once is disabling or
   deleting its Keycloak client (S8). Once it has a token, a workload keeps working for that token's
   lifetime (300 s here).
4. **Keycloak does not cap the assertion's age, and accepts replays (S6, S7).** A 24 h token and a
   reused token both worked. Keycloak's blog lists replay as a known limit and recommends short
   tokens. The bound is therefore whatever the chart sets as the projected token's
   `expirationSeconds`, which Kubernetes lets go down to 600 s.
5. **Reachability is the deployment condition.** Here Keycloak ran in the same cluster and read the
   issuer discovery with its own service-account token. Outside the cluster, Keycloak needs the
   cluster's issuer discovery reachable without authentication, per Keycloak's blog. That was not
   tested, nor was the Talos cluster's issuer setup.

## Not tested

- Pairing and `private_key_jwt` for devices outside the cluster (ADR-0057 question 7).
- Keycloak outside the cluster, and the Talos cluster's issuer and discovery settings.
- Victual mapping a client to its service account and package version, and the real worker,
  renderer and sidecar images.
- Keycloak on PostgreSQL or two replicas in this shape; a Kubernetes token-rate limit.

## Sources read

- Keycloak blog, [Federated client authentication: no more secrets](https://www.keycloak.org/2026/01/federated-client-authentication)
  (January 2026): audience, discovery reachability, replay and token-lifetime caveats.
- Keycloak playground, `federated-client-authentication/kubernetes`
  (`gh api repos/keycloak/keycloak-playground/contents/federated-client-authentication/kubernetes`):
  the identity provider and client attribute names this spike uses.
