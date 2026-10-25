#!/usr/bin/env bash
# ADR-0055 prerequisite 4: does Hydra accept an RFC 8693 token-exchange grant?
# Needs only `./run.sh up` (no idp-stub, no rs.mjs). Prints each response and its HTTP status.
# Compares the exchange grant with a made-up grant type and with RFC 7523 jwt-bearer, so an
# "unsupported" answer is told apart from a malformed request.
set -euo pipefail
A=http://localhost:4445; P=http://localhost:4444
TX=urn:ietf:params:oauth:grant-type:token-exchange
API=http://localhost:8080/api; MCP=http://localhost:8080/mcp

echo "== hydra version: $(podman exec victual-spike-hydra hydra version | head -1)"
echo "== grant_types_supported:"
curl -fsS $P/.well-known/openid-configuration \
  | python3 -c 'import json,sys;print(json.load(sys.stdin)["grant_types_supported"])'

for id in xsidecar xsubj; do curl -s -X DELETE $A/admin/clients/$id >/dev/null; done
curl -fsS -X POST $A/admin/clients -H 'content-type: application/json' -o /dev/null -d "{
  \"client_id\":\"xsidecar\",\"client_secret\":\"xsidecar-secret-0123456789\",
  \"grant_types\":[\"client_credentials\",\"$TX\"],\"token_endpoint_auth_method\":\"client_secret_post\",
  \"scope\":\"victual.read victual.write\",\"audience\":[\"$API\",\"$MCP\"]}"
echo "== Hydra stored the exchange grant on the client without complaint (admin API 201)"
curl -fsS -X POST $A/admin/clients -H 'content-type: application/json' -o /dev/null -d "{
  \"client_id\":\"xsubj\",\"client_secret\":\"xsubj-secret-0123456789\",\"grant_types\":[\"client_credentials\"],
  \"token_endpoint_auth_method\":\"client_secret_post\",\"scope\":\"victual.read\",\"audience\":[\"$MCP\"]}"
SUBJ=$(curl -fsS $P/oauth2/token -d grant_type=client_credentials -d client_id=xsubj \
  -d client_secret=xsubj-secret-0123456789 -d scope=victual.read -d audience=$MCP \
  | python3 -c 'import json,sys;print(json.load(sys.stdin)["access_token"])')
echo "== subject token minted for $MCP (${#SUBJ} bytes)"

try() { echo "== $1"; shift; curl -s -w '\n   HTTP %{http_code}\n' $P/oauth2/token "$@" | sed 's/^/   /'; }
try "RFC 8693 token exchange, MCP-audience token -> /api audience" \
  -d grant_type=$TX -d client_id=xsidecar -d client_secret=xsidecar-secret-0123456789 \
  -d subject_token="$SUBJ" -d subject_token_type=urn:ietf:params:oauth:token-type:access_token \
  -d audience=$API
try "made-up grant type (baseline for 'no handler')" \
  -d grant_type=urn:example:no-such-grant -d client_id=xsidecar -d client_secret=xsidecar-secret-0123456789
try "RFC 7523 jwt-bearer (baseline for 'handler exists, client not allowed')" \
  -d grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer -d client_id=xsidecar \
  -d client_secret=xsidecar-secret-0123456789 -d assertion=x
