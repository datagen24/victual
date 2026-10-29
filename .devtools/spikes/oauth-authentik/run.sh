#!/usr/bin/env bash
# Start/stop Authentik for the compatibility spike (ADR-0055 prerequisites 1/3/4, ADR-0056
# prerequisites 2-4, ADR-0055 prerequisites 8-11). PostgreSQL, Authentik server, Authentik worker
# and a 64 MiB webhook receiver on one podman network, each memory-capped because the podman VM is
# shared (4 CPU / 8 GiB). Not one pod: the worker also binds :9000, collides with the server in a
# shared network namespace and shuts down.
#
#   ./run.sh up | down              start or remove everything this spike owns
#   ./run.sh logs <server|worker|hook|pg> [podman logs options]
#   ./run.sh hook-restart           restart the webhook receiver only (after editing HOOK below)
#   ./run.sh worker-stop | worker-start | worker-kill   stop the worker (SIGTERM), start it, or SIGKILL it (G5o to G5q)
#
# Throwaway credentials only.
set -euo pipefail
cd "$(dirname "$0")"
POD=victual-spike-authentik
TAG=${AUTHENTIK_TAG:-2026.8.5}
# Spike-only secrets: this instance holds no real data and listens on 127.0.0.1 only.
BOOTSTRAP_TOKEN=spike-only-bootstrap-token-0123456789
HOOK_SECRET=spike-only-hook-secret-0123456789
ENV=(-e AUTHENTIK_POSTGRESQL__HOST=$POD-pg -e AUTHENTIK_POSTGRESQL__NAME=authentik
     -e AUTHENTIK_POSTGRESQL__USER=authentik -e AUTHENTIK_POSTGRESQL__PASSWORD=spike-only-pg
     -e AUTHENTIK_SECRET_KEY=spike-only-secret-key-0123456789abcdef0123456789
     -e AUTHENTIK_BOOTSTRAP_TOKEN=$BOOTSTRAP_TOKEN
     -e AUTHENTIK_BOOTSTRAP_PASSWORD=spike-only-akadmin
     -e AUTHENTIK_ERROR_REPORTING__ENABLED=false -e AUTHENTIK_DISABLE_UPDATE_CHECK=true
     -e AUTHENTIK_DISABLE_STARTUP_ANALYTICS=true)
# Stand-in for the endpoint Victual would expose for reuse alerts (G5). This is probe code, not
# Victual code. It refuses any POST without the shared secret header (G5s), logs every POST as one
# "HOOK" line, and, while revocation is on (POST /control {"revoke": true}), revokes the family a
# reuse event names through Authentik's admin API only: it confirms the event through the events
# API, reads the reused refresh token, takes user, provider and the (sid, auth_time) pair from its
# id_token field, and deletes the family's refresh and access tokens, unrevoked refresh tokens
# first. A pass that deleted something is repeated at once; it stops after two consecutive empty
# passes 0.5 s apart (at most 50), so a successor saved after one listing is caught by the next
# (G5n). Each pass is logged as a "REVOKE" line.
HOOK='
import http.server, json, os, hmac, threading, time, urllib.request, urllib.error
API = os.environ["AK_API"]; TOK = os.environ["AK_TOKEN"]; SECRET = os.environ["HOOK_SECRET"]
STATE = {"revoke": False}
def log(kind, obj):
    obj["t"] = time.time()
    print(kind + " " + json.dumps(obj), flush=True)
def call(method, path):
    req = urllib.request.Request(API + path, method=method, headers={"Authorization": "Bearer " + TOK})
    try:
        with urllib.request.urlopen(req, timeout=10) as r:
            body = r.read()
            return r.status, (json.loads(body) if body else None)
    except urllib.error.HTTPError as e:
        return e.code, None
def rows(kind, user, provider):
    out, page = [], 1
    while True:
        st, b = call("GET", "/oauth2/%s/?user=%s&provider=%s&page_size=100&page=%d" % (kind, user, provider, page))
        if st != 200:
            raise RuntimeError("list %s -> %s" % (kind, st))
        out += b["results"]
        if not b["pagination"]["next"]:
            return out
        page += 1
def key(row):
    i = json.loads(row["id_token"])
    return [i.get("sid"), i.get("auth_time")]
def revoke(ev):
    tref = (ev.get("context") or {}).get("token") or {}
    if ev.get("action") != "suspicious_request" or tref.get("model_name") != "refreshtoken":
        return log("SKIP", {"event": ev.get("event_uuid"), "why": "not a refresh-token reuse"})
    st, e = call("GET", "/events/events/%s/" % ev.get("event_uuid"))
    if st != 200 or e["action"] != "suspicious_request" or (e["context"].get("token") or {}).get("pk") != tref.get("pk"):
        return log("SKIP", {"event": ev.get("event_uuid"), "why": "event not confirmed by the events API (%s)" % st})
    st, row = call("GET", "/oauth2/refresh_tokens/%s/" % tref.get("pk"))
    if st != 200:
        return log("SKIP", {"event": ev.get("event_uuid"), "why": "reused token row not found (%s)" % st})
    user, provider, fam = row["user"]["pk"], row["provider"]["pk"], key(row)
    passes = quiet = 0
    while passes < 50:
        rts = [r for r in rows("refresh_tokens", user, provider) if key(r) == fam]
        live = [r for r in rts if not r["revoked"]]
        n = 0
        for r in live + [r for r in rts if r["revoked"]]:
            n += call("DELETE", "/oauth2/refresh_tokens/%s/" % r["pk"])[0] == 204
        for r in [r for r in rows("access_tokens", user, provider) if key(r) == fam]:
            n += call("DELETE", "/oauth2/access_tokens/%s/" % r["pk"])[0] == 204
        passes += 1
        log("REVOKE", {"event": ev.get("event_uuid"), "token": tref.get("pk"), "pass": passes, "live": len(live), "deleted": n, "user": user, "provider": provider})
        quiet = quiet + 1 if n == 0 else 0
        if quiet >= 2:
            return
        if n == 0:
            time.sleep(0.5)
def safe_revoke(ev):
    try:
        revoke(ev)
    except Exception as exc:
        log("ERROR", {"error": str(exc)})
class H(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        body = self.rfile.read(int(self.headers.get("Content-Length", 0))).decode()
        ok = hmac.compare_digest(self.headers.get("X-Victual-Hook-Secret", ""), SECRET)
        log("HOOK", {"path": self.path, "auth": ok, "body": body})
        if not ok:
            self.send_response(401); self.end_headers(); return
        if self.path == "/control":
            STATE.update(json.loads(body))
            self.send_response(200); self.end_headers(); self.wfile.write(json.dumps(STATE).encode()); return
        self.send_response(204); self.end_headers()
        if STATE["revoke"]:
            try:
                ev = json.loads(body)
            except ValueError:
                return
            threading.Thread(target=safe_revoke, args=(ev,)).start()
    def log_message(self, *a): pass
http.server.ThreadingHTTPServer(("0.0.0.0", 8766), H).serve_forever()
'
# Starts the receiver container: 64 MiB, port 8766 published on 127.0.0.1 for the probe's direct POSTs (G5s).
start_hook() {
  podman run -d --network "$POD" --name "$POD-hook" --memory 64m -p 127.0.0.1:8766:8766 --entrypoint python3 \
    -e AK_API="http://$POD-server:9000/api/v3" -e AK_TOKEN=$BOOTSTRAP_TOKEN -e HOOK_SECRET=$HOOK_SECRET \
    "ghcr.io/goauthentik/server:$TAG" -u -c "$HOOK" >/dev/null
}
case "${1:-up}" in
  up)
    "$0" down >/dev/null 2>&1 || true
    podman network create "$POD" >/dev/null
    podman run -d --network "$POD" --name "$POD-pg" --memory 384m \
      -e POSTGRES_DB=authentik -e POSTGRES_USER=authentik -e POSTGRES_PASSWORD=spike-only-pg \
      docker.io/library/postgres:16-alpine >/dev/null
    sleep 5
    podman run -d --network "$POD" --name "$POD-server" --memory 1g -p 127.0.0.1:9000:9000 \
      "${ENV[@]}" "ghcr.io/goauthentik/server:$TAG" server >/dev/null
    podman run -d --network "$POD" --name "$POD-worker" --memory 1g "${ENV[@]}" \
      "ghcr.io/goauthentik/server:$TAG" worker >/dev/null
    start_hook
    for _ in $(seq 1 180); do
      # Ready means the worker has applied the default blueprints setup.mjs relies on.
      curl -fsS -H "Authorization: Bearer $BOOTSTRAP_TOKEN" \
        "http://localhost:9000/api/v3/flows/instances/?slug=default-provider-authorization-implicit-consent" 2>/dev/null \
        | grep -q '"count":1' && { echo "ready ($TAG)"; exit 0; }
      sleep 2
    done
    podman logs --tail 30 "$POD-server"; exit 1 ;;
  down)
    podman rm -f "$POD-hook" "$POD-worker" "$POD-server" "$POD-pg" >/dev/null 2>&1 || true
    podman network rm "$POD" >/dev/null 2>&1 || true ;;
  hook-restart) podman rm -f "$POD-hook" >/dev/null 2>&1 || true; start_hook ;;
  worker-stop) podman stop -t 10 "$POD-worker" >/dev/null ;;
  worker-start) podman start "$POD-worker" >/dev/null ;;
  worker-kill) podman kill --signal KILL "$POD-worker" >/dev/null ;;
  logs) podman logs "${@:3}" "$POD-${2:-server}" ;;
esac
