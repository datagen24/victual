# Environment for running deploy/keycloak/reconcile.mjs against the harness instance.
# Source it: `. .devtools/keycloak-config/env.sh`, after ./run.sh up. The values below are for an
# instance on 127.0.0.1 holding no real data; every credential comes from the gitignored
# .work/secrets.env, which run.sh generates.
# shellcheck source=/dev/null
. "$(dirname "${BASH_SOURCE[0]}")/.work/secrets.env"
export KEYCLOAK_URL=http://localhost:8180
export KEYCLOAK_ADMIN_USERNAME=admin KEYCLOAK_ADMIN_PASSWORD="$ADMIN_PASS"
export VICTUAL_MCP_AUDIENCE=http://localhost:3000/mcp
export VICTUAL_API_AUDIENCE=http://localhost:8080/api
export VICTUAL_REDIRECT_URI=http://127.0.0.1:8765/callback
export VICTUAL_POST_LOGOUT_REDIRECT_URI=http://127.0.0.1:8765/
export VICTUAL_NAMESPACE=victual
export KUBERNETES_SA_ISSUER=http://127.0.0.1:8199
export VICTUAL_CLIENT_SECRET SCIM_CLIENT_SECRET
