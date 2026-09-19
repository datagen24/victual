#!/usr/bin/env bash
#
# The two stacks the parity suite compares, and everything they need.
#
# Sourced by ../bin/parity; not meant to be run directly, though every function here is
# safe to call on its own if you are debugging one container.
#
# **The fork side is the shipping artifact.** Since ADR-0013 the production images are
# the three Nix ones — `victual-migrate` runs and exits, then `victual-app` (php-fpm on
# loopback) and `victual-web` (nginx) serve together. This suite boots exactly those,
# with the same read-only root, the same dropped capabilities and the same in-container
# probes deploy/podman/victual.yaml uses, because comparing upstream against an image the
# fork does not ship is a worse question than comparing it against one the fork does.
# Before 2026-09-04 this built the `Dockerfile`'s `production` target, which no longer
# exists; issue #56 is the port.
#
# **Why a pod here but still not `podman kube play`.** php-fpm binds `127.0.0.1:9000`
# (nix/runtime/fpm-conf.nix), so `victual-app` and `victual-web` must share a network
# namespace for nginx's `fastcgi_pass` to mean anything — which is a pod, created here
# with `podman pod create` and joined with `--pod`. What it is *not* is
# deploy/podman/victual.yaml played through `podman kube play`, for the reason that has
# always been in this file: Kubernetes runs every initContainer to completion before any
# regular container starts, so a migrate initContainer in a pod that also contains
# PostgreSQL waits for a database that has not been started yet and never will be.
# Splitting infrastructure into a second pod would then need a shared network and would be
# two manifests describing one thing. `migrate_victual()` below is that ordering point,
# run as its own container once PostgreSQL is up. This file is a test fixture, not a
# deployment artifact, and it says so by not pretending to be one.
#
# Everything runs on one podman network with DNS aliases, so `postgres`, `mosquitto` and
# `influxdb` mean the same thing here as they would in a compose file. The pod joins that
# same network, which is what lets the app container resolve `postgres` at all.

set -euo pipefail

# --- Names and knobs -------------------------------------------------------------------

STACK_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="${REPO_ROOT:-$(cd "$STACK_DIR/../../.." && pwd)}"

PARITY_NETWORK="${PARITY_NETWORK:-victual-parity}"
PARITY_PREFIX="${PARITY_PREFIX:-parity}"

# The image tag the Nix build produces is version.json's `Version`, from nix/overlay.nix —
# the same string the application reports at /api/system/info. Reading it here rather than
# hard-coding 4.6.0 means a version bump does not leave this suite pointing at a tag that
# `nix run .#load` no longer writes.
VICTUAL_VERSION="${VICTUAL_VERSION:-$(sed -n 's/.*"Version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$REPO_ROOT/version.json")}"

VICTUAL_APP_IMAGE="${VICTUAL_APP_IMAGE:-localhost/victual-app:${VICTUAL_VERSION}}"
VICTUAL_WEB_IMAGE="${VICTUAL_WEB_IMAGE:-localhost/victual-web:${VICTUAL_VERSION}}"
VICTUAL_MIGRATE_IMAGE="${VICTUAL_MIGRATE_IMAGE:-localhost/victual-migrate:${VICTUAL_VERSION}}"
# The read-only MCP sidecar (mcp/, issue #86). Fork-only, like MQTT and InfluxDB: upstream
# has nothing to compare it with, so `parity mcp` checks it against the fork's own REST API.
VICTUAL_MCP_IMAGE="${VICTUAL_MCP_IMAGE:-localhost/victual-mcp:${VICTUAL_VERSION}}"

# Pinned to the release the fork was cut from rather than :latest, and that is the whole
# argument of this suite. grocy 4.6.0 / 2026-03-06 is the upstream image's own version.json,
# and it was the fork's too until its first release (0.1.0-MVP, 2026-09-19), so a difference
# the suite reports is a difference *this fork* introduced — not one upstream introduced in
# a release the fork has not merged. Comparing against
# :latest would produce a report full of upstream's changelog.
UPSTREAM_IMAGE="${UPSTREAM_IMAGE:-docker.io/linuxserver/grocy:version-v4.6.0}"

POSTGRES_IMAGE="${POSTGRES_IMAGE:-docker.io/library/postgres:16}"
MOSQUITTO_IMAGE="${MOSQUITTO_IMAGE:-docker.io/library/eclipse-mosquitto:2}"
INFLUX_IMAGE="${INFLUX_IMAGE:-docker.io/library/influxdb:2.7}"

VICTUAL_PORT="${VICTUAL_PORT:-8080}"

# Where the stack leaves what a later step reads: the migrate log the bootstrap handover
# parses, that handover's report, and the administrator password below. The reports
# directory is gitignored.
PARITY_STATE_DIR="${PARITY_STATE_DIR:-${PARITY_REPORTS:-$STACK_DIR/../reports}}"

# The fork's administrator password. Not admin/admin, which upstream still ships and the
# fork no longer does: a fresh Victual database has no password anybody knows, and the
# handover below changes it to this one. So the two sides differ in one credential,
# deliberately, and this is the one place that says so.
#
# **Random per stack, unless you set it.** It used to default to a fixed string, which with
# a published port was an administrator login anyone on the network could look up
# (CodeRabbit, PR #220). Now each fresh database gets a new random one, written to a mode-600
# file so that later `parity` invocations and harness/lib/instance.js - separate processes -
# can read it; it is never printed and never on a command line. Setting
# PARITY_VICTUAL_ADMIN_PASSWORD yourself still pins it.
PARITY_ADMIN_PASSWORD_FILE="$PARITY_STATE_DIR/.victual-admin-password"
if [ -n "${PARITY_VICTUAL_ADMIN_PASSWORD:-}" ]; then
	PARITY_ADMIN_PASSWORD_PINNED=yes
else
	PARITY_ADMIN_PASSWORD_PINNED=no
	PARITY_VICTUAL_ADMIN_PASSWORD="$(cat "$PARITY_ADMIN_PASSWORD_FILE" 2>/dev/null || true)"
fi
export PARITY_VICTUAL_ADMIN_PASSWORD

# **How that password gets there.** `generated`, the default, is what a deployment that sets
# nothing gets: the migrate container runs with no VICTUAL_BOOTSTRAP_ADMIN_PASSWORD, prints
# a generated one once on stderr and flags the account, and harness/bootstrap-admin.js reads
# it off that log and walks the forced change over the API to PARITY_VICTUAL_ADMIN_PASSWORD
# — asserting each step — before anything else logs in. `env` hands the migrate container
# the password directly instead, which is the other supported path and skips all of that.
PARITY_BOOTSTRAP_ADMIN="${PARITY_BOOTSTRAP_ADMIN:-generated}"
UPSTREAM_PORT="${UPSTREAM_PORT:-8081}"
INFLUX_PORT="${INFLUX_PORT:-8086}"
MQTT_PORT="${MQTT_PORT:-1883}"
MCP_PORT="${MCP_PORT:-8082}"

# Credentials for throwaway infrastructure, in the open for the same reason
# docker-compose.yml states: these databases exist for the length of a suite run, on a
# tmpfs, and every run creates them from nothing. Sweep finding S25 records exactly this
# shape as an Info-level observation. Changing them would move the secret, not remove it.
PGUSER_="${PGUSER_:-victual}"
PGPASSWORD_="${PGPASSWORD_:-victual}"
PGDATABASE_="${PGDATABASE_:-victual}"

INFLUX_TOKEN="${INFLUX_TOKEN:-victual-parity-token}"
INFLUX_ORG="${INFLUX_ORG:-victual}"
INFLUX_BUCKET="${INFLUX_BUCKET:-victual}"

ENGINE="${CONTAINER_ENGINE:-podman}"

c_pg="${PARITY_PREFIX}-postgres"
c_mqtt="${PARITY_PREFIX}-mosquitto"
c_influx="${PARITY_PREFIX}-influxdb"
c_upstream="${PARITY_PREFIX}-upstream"

# The fork is a pod with two containers in it, so it is three names rather than one.
p_victual="${PARITY_PREFIX}-victual"
c_victual_app="${PARITY_PREFIX}-victual-app"
c_victual_web="${PARITY_PREFIX}-victual-web"
c_victual_mcp="${PARITY_PREFIX}-victual-mcp"

# There is no victual data volume. There was one, seeded with a stub config.php to satisfy
# PrerequisiteChecker::checkForConfigFile(); the application no longer requires the file at
# all (issue #49) and the images mount /data read-only and empty. Upstream still needs its
# /config, because that is where its SQLite database lives.
v_upstream_data="${PARITY_PREFIX}-upstream-config"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m warn\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

# --- Small helpers ---------------------------------------------------------------------

# Simulated time. Sourced here rather than from bin/parity because every function below
# that starts a container needs its argument builders, and it needs this file's names,
# images and log helpers in return. It is inert unless PARITY_FAKETIME=on.
# shellcheck source=faketime.sh
. "$STACK_DIR/faketime.sh"

exists()  { "$ENGINE" container exists "$1" 2>/dev/null; }
running() { [ "$("$ENGINE" inspect -f '{{.State.Running}}' "$1" 2>/dev/null)" = "true" ]; }

rm_container() { "$ENGINE" rm -f "$1" >/dev/null 2>&1 || true; }

# Waits for a command to succeed, with a deadline. Every readiness gate in this file goes
# through here so that a hung container produces a named timeout rather than a suite that
# sits forever — the failure mode that makes a laptop run unusable.
wait_for() {
	local what="$1" timeout="$2"; shift 2
	local deadline=$(( SECONDS + timeout ))
	while [ "$SECONDS" -lt "$deadline" ]; do
		if "$@" >/dev/null 2>&1; then
			return 0
		fi
		sleep 1
	done
	warn "last output from the readiness check for $what:"
	"$@" 2>&1 | tail -n 20 >&2 || true
	die "$what was not ready within ${timeout}s"
}

# Whether admin logs in with the given password. **A 302 is not enough**: both applications
# answer refused credentials with a 302 too, to /login?invalid=true, so these gates used to
# pass on a wrong password. Success is a 302 anywhere else.
login_accepted() {
	local base="$1" password="$2" out
	# The password on stdin (`password@-`), not in curl's arguments, where `ps` would show it.
	out="$(printf '%s' "$password" | curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST \
		--data-urlencode username=admin --data-urlencode "password@-" "$base/login")"
	case "$out" in
		"302 "*invalid=*) return 1 ;;
		"302 "*) return 0 ;;
		*) return 1 ;;
	esac
}

# **Every published port is on loopback.** The stack holds an administrator login on both
# applications - upstream's is admin/admin and cannot be anything else - so nothing here is
# for another machine to reach.

# A new random administrator password for a fresh database, unless one was pinned.
new_admin_password() {
	[ "$PARITY_ADMIN_PASSWORD_PINNED" = yes ] && return 0
	mkdir -p "$PARITY_STATE_DIR"
	PARITY_VICTUAL_ADMIN_PASSWORD="$(od -An -N18 -tx1 /dev/urandom | tr -d ' \n')"
	[ "${#PARITY_VICTUAL_ADMIN_PASSWORD}" -eq 36 ] || die "could not generate an administrator password"
	( umask 077; printf '%s' "$PARITY_VICTUAL_ADMIN_PASSWORD" > "$PARITY_ADMIN_PASSWORD_FILE" )
	export PARITY_VICTUAL_ADMIN_PASSWORD
}

ensure_network() {
	"$ENGINE" network exists "$PARITY_NETWORK" 2>/dev/null \
		|| "$ENGINE" network create "$PARITY_NETWORK" >/dev/null
}

# --- Infrastructure --------------------------------------------------------------------

start_postgres() {
	rm_container "$c_pg"
	log "postgres"
	# tmpfs, no volume, for the reason docker-compose.yml gives: a stale volume is how a
	# passing suite starts lying.
	local ft=()
	while IFS= read -r a; do [ -n "$a" ] && ft+=("$a"); done < <(ft_postgres_args)
	"$ENGINE" run -d --name "$c_pg" \
		--network "$PARITY_NETWORK" --network-alias postgres \
		--tmpfs /var/lib/postgresql/data \
		-e POSTGRES_USER="$PGUSER_" \
		-e POSTGRES_PASSWORD="$PGPASSWORD_" \
		-e POSTGRES_DB="$PGDATABASE_" \
		${ft[@]+"${ft[@]}"} \
		"$(ft_postgres_image)" >/dev/null
	wait_for postgres 90 "$ENGINE" exec "$c_pg" pg_isready -U "$PGUSER_" -d "$PGDATABASE_"
}

start_mosquitto() {
	rm_container "$c_mqtt"
	log "mosquitto"
	# mosquitto 2.x refuses non-local connections unless told otherwise, so the config is
	# not optional. It is passed on stdin rather than bind-mounted because a bind mount of
	# a single file from a macOS host into the podman VM is the kind of thing that works
	# until it does not.
	"$ENGINE" run -d --name "$c_mqtt" \
		--network "$PARITY_NETWORK" --network-alias mosquitto \
		-p "127.0.0.1:${MQTT_PORT}:1883" \
		"$MOSQUITTO_IMAGE" \
		sh -c 'printf "listener 1883 0.0.0.0\nallow_anonymous true\npersistence false\n" > /tmp/mosquitto.conf && exec mosquitto -c /tmp/mosquitto.conf' >/dev/null
	wait_for mosquitto 60 "$ENGINE" exec "$c_mqtt" sh -c "nc -z 127.0.0.1 1883"
}

start_influx() {
	rm_container "$c_influx"
	log "influxdb"
	"$ENGINE" run -d --name "$c_influx" \
		--network "$PARITY_NETWORK" --network-alias influxdb \
		-p "127.0.0.1:${INFLUX_PORT}:8086" \
		-e DOCKER_INFLUXDB_INIT_MODE=setup \
		-e DOCKER_INFLUXDB_INIT_USERNAME=victual \
		-e DOCKER_INFLUXDB_INIT_PASSWORD=victual-parity \
		-e DOCKER_INFLUXDB_INIT_ORG="$INFLUX_ORG" \
		-e DOCKER_INFLUXDB_INIT_BUCKET="$INFLUX_BUCKET" \
		-e DOCKER_INFLUXDB_INIT_ADMIN_TOKEN="$INFLUX_TOKEN" \
		"$INFLUX_IMAGE" >/dev/null
	wait_for influxdb 120 curl -fsS "http://127.0.0.1:${INFLUX_PORT}/health"
}

# --- Victual ---------------------------------------------------------------------------

victual_env_args() {
	printf '%s\n' \
		-e VICTUAL_MODE=production \
		-e VICTUAL_DB_DRIVER=pgsql \
		-e VICTUAL_DB_HOST=postgres \
		-e VICTUAL_DB_PORT=5432 \
		-e "VICTUAL_DB_NAME=$PGDATABASE_" \
		-e "VICTUAL_DB_USER=$PGUSER_" \
		-e "VICTUAL_DB_PASSWORD=$PGPASSWORD_" \
		-e "VICTUAL_BASE_URL=http://127.0.0.1:${VICTUAL_PORT}" \
		-e TZ=UTC \
		-e VICTUAL_MQTT_ENABLED=true \
		-e VICTUAL_MQTT_HOST=mosquitto \
		-e VICTUAL_MQTT_PORT=1883 \
		-e VICTUAL_MQTT_TOPIC_PREFIX=victual \
		-e VICTUAL_MQTT_CLIENT_ID=victual-parity \
		-e VICTUAL_INFLUXDB_ENABLED=true \
		-e VICTUAL_INFLUXDB_URL=http://influxdb:8086 \
		-e "VICTUAL_INFLUXDB_TOKEN=$INFLUX_TOKEN" \
		-e "VICTUAL_INFLUXDB_ORG=$INFLUX_ORG" \
		-e "VICTUAL_INFLUXDB_BUCKET=$INFLUX_BUCKET" \
		-e VICTUAL_FILE_STORAGE=database
	ft_victual_args
}

# The security flags the manifest sets, said the way `podman run` says them. Not decoration:
# these images are built to run this way, and deploy/README.md is explicit that turning
# `readOnlyRootFilesystem` off to get a green container is a finding rather than a
# workaround. A fixture that ran them with a writable root would not be testing what ships.
#
# `--tmpfs /tmp` is spelled out rather than left to podman's `--read-only-tmpfs` default,
# because the manifest mounts one per container and this should not depend on which way
# that default happens to be set.
victual_hardening_args() {
	printf '%s\n' \
		--read-only \
		--tmpfs /tmp \
		--cap-drop ALL \
		--security-opt no-new-privileges
}

# Migrations are a step, not a side effect of the first request — that is what plan 10
# bought and what SchemaVersionMiddleware enforces. Running it here as its own container
# rather than as the pod's initContainer is the ordering point this whole file exists for:
# PostgreSQL is already up, which is exactly what an initContainer cannot arrange.
migrate_victual() {
	log "victual: migrate"
	local args=()
	while IFS= read -r a; do args+=("$a"); done < <(victual_env_args)
	while IFS= read -r a; do args+=("$a"); done < <(victual_hardening_args)
	# No command: the image's Cmd is already bin/victual-migrate. The bootstrap password, when
	# there is one, goes to this container only, as it does in deploy/: the serving
	# containers never hold it. Under `generated` there is none, and the log is kept because
	# it is the only place the generated password exists (PARITY_BOOTSTRAP_ADMIN above).
	new_admin_password
	# `-e NAME` with no value passes the variable through from this process's environment,
	# which keeps the password off podman's command line.
	local bootstrap=()
	case "$PARITY_BOOTSTRAP_ADMIN" in
		env) bootstrap=(-e VICTUAL_BOOTSTRAP_ADMIN_PASSWORD) ;;
		generated) ;;
		*) die "PARITY_BOOTSTRAP_ADMIN is '$PARITY_BOOTSTRAP_ADMIN'; it is 'generated' or 'env'" ;;
	esac
	mkdir -p "$PARITY_STATE_DIR"
	local migrate_log="$PARITY_STATE_DIR/migrate.log"
	if ! VICTUAL_BOOTSTRAP_ADMIN_PASSWORD="$PARITY_VICTUAL_ADMIN_PASSWORD" "$ENGINE" run --rm --network "$PARITY_NETWORK" \
		"${args[@]}" \
		${bootstrap[@]+"${bootstrap[@]}"} \
		"$VICTUAL_MIGRATE_IMAGE" >"$migrate_log" 2>&1; then
		cat "$migrate_log" >&2
		die "victual migration failed"
	fi
	# Echoed, generated password included: the database is on a tmpfs and is thrown away
	# with the run, and this is the operator's view of a first boot.
	cat "$migrate_log"
}

# The first administrator's forced password change, walked over the API. Only under
# `generated`: under `env` the account was never flagged and there is nothing to walk.
bootstrap_admin_handover() {
	[ "$PARITY_BOOTSTRAP_ADMIN" = generated ] || return 0
	command -v node >/dev/null 2>&1 \
		|| die "node is not on PATH; the generated-password handover needs it (or set PARITY_BOOTSTRAP_ADMIN=env)"
	log "victual: first login with the generated administrator password"
	node "$STACK_DIR/../harness/bootstrap-admin.js" \
		--victual "http://127.0.0.1:${VICTUAL_PORT}" \
		--migrate-log "$PARITY_STATE_DIR/migrate.log" \
		--out "$PARITY_STATE_DIR" \
		|| die "the generated-password handover failed; see above and $PARITY_STATE_DIR/bootstrap-admin.json"
}

# The pod exists for one reason: php-fpm binds 127.0.0.1:9000 and nginx's `fastcgi_pass`
# names that address, so the two containers have to be in the same network namespace. It
# joins the parity network so that the app container can still resolve `postgres`,
# `mosquitto` and `influxdb`, and it is the pod — not either container — that publishes
# 8080, because a pod's ports live on its infra container.
create_victual_pod() {
	"$ENGINE" pod rm -f "$p_victual" >/dev/null 2>&1 || true
	"$ENGINE" pod create --name "$p_victual" \
		--network "$PARITY_NETWORK" --network-alias victual \
		-p "127.0.0.1:${VICTUAL_PORT}:8080" >/dev/null
}

start_victual() {
	create_victual_pod
	log "victual: app (php-fpm)"
	local args=()
	while IFS= read -r a; do args+=("$a"); done < <(victual_env_args)
	while IFS= read -r a; do args+=("$a"); done < <(victual_hardening_args)
	"$ENGINE" run -d --name "$c_victual_app" --pod "$p_victual" \
		"${args[@]}" \
		"$VICTUAL_APP_IMAGE" >/dev/null

	# The manifest's startupProbe, run the same way. /opt/victual/healthcheck is a PHP
	# script with the interpreter in its shebang, so `podman exec` runs it with no shell
	# involved — which is the only way to ask this container anything, since it has no
	# shell to ask with. It is also the only probe that works: the pool listens on
	# loopback, so nothing outside this pod's namespace can open the port at all.
	wait_for "victual app" 120 "$ENGINE" exec "$c_victual_app" /opt/victual/healthcheck

	log "victual: web (nginx)"
	local web_args=()
	while IFS= read -r a; do web_args+=("$a"); done < <(victual_hardening_args)
	# No environment at all, and that is the split ADR-0010 asks for: the web tier holds
	# the document root and no credential.
	"$ENGINE" run -d --name "$c_victual_web" --pod "$p_victual" \
		"${web_args[@]}" \
		"$VICTUAL_WEB_IMAGE" >/dev/null

	# /login rather than / — it renders through Blade, touches the database and passes the
	# schema gate, so a 200 here means the whole stack answered, not that a socket is open.
	# This is the manifest's readinessProbe asked from outside instead of inside, which is
	# the stronger question here because it also proves the published port works.
	wait_for victual 120 curl -fsS -o /dev/null "http://127.0.0.1:${VICTUAL_PORT}/login"

	bootstrap_admin_handover

	# The same assertive gate the upstream side has, for the same reason: the suite's first
	# action is a login, so the stack is not "up" until one succeeds.
	wait_for "victual login" 90 login_accepted "http://127.0.0.1:${VICTUAL_PORT}" "$PARITY_VICTUAL_ADMIN_PASSWORD"
}

# The MCP sidecar, the way deploy/k3s/victual-mcp.yaml runs it: read-only root, a /tmp,
# no capabilities, and the ConfigMap's four settings. It reaches Victual by the pod's
# network alias, as the k3s one reaches the `victual` Service, and holds no credential of
# its own: every request carries the caller's key through to Victual (spec §8).
start_mcp() {
	rm_container "$c_victual_mcp"
	log "victual: mcp sidecar"
	local args=()
	while IFS= read -r a; do args+=("$a"); done < <(victual_hardening_args)
	"$ENGINE" run -d --name "$c_victual_mcp" \
		--network "$PARITY_NETWORK" --network-alias victual-mcp \
		-p "127.0.0.1:${MCP_PORT}:3000" \
		"${args[@]}" \
		-e VICTUAL_BASE_URL=http://victual:8080 \
		-e MCP_ENABLED_TOOLS=all-read \
		-e MCP_REQUEST_TIMEOUT_MS=10000 \
		-e LOG_LEVEL=info \
		"$VICTUAL_MCP_IMAGE" >/dev/null
	# /healthz is the manifest's livenessProbe; the image has no shell to probe from inside.
	wait_for "victual mcp" 60 curl -fsS -o /dev/null "http://127.0.0.1:${MCP_PORT}/healthz"
}

# Runs one of the application's `bin/` CLI entry points against this stack, from the
# migrate image — the only one of the three that carries them and the only one with a plain
# PHP CLI rather than php-fpm. That is also how a deployment would run one: a Job or a
# CronJob from the CLI image, not `kubectl exec` into a request handler.
#
# The interpreter and the application root are read out of the image's own `Cmd`
# (`<php> /app/bin/victual-migrate`) rather than hard-coded. These images have no `PATH` —
# every entry point names an absolute store path — so `php bin/…` finds nothing, and a
# store path is not something this file can know. Asking the image is the only answer that
# survives a PHP version bump.
victual_cli() {
	local tool="$1"; shift
	local php_bin app_bin
	php_bin="$("$ENGINE" image inspect --format '{{index .Config.Cmd 0}}' "$VICTUAL_MIGRATE_IMAGE")" \
		|| die "$VICTUAL_MIGRATE_IMAGE is not loaded"
	app_bin="$(dirname "$("$ENGINE" image inspect --format '{{index .Config.Cmd 1}}' "$VICTUAL_MIGRATE_IMAGE")")"

	local args=()
	while IFS= read -r a; do args+=("$a"); done < <(victual_env_args)
	while IFS= read -r a; do args+=("$a"); done < <(victual_hardening_args)
	"$ENGINE" run --rm --network "$PARITY_NETWORK" \
		"${args[@]}" \
		"$VICTUAL_MIGRATE_IMAGE" "$php_bin" "$app_bin/$tool" "$@"
}

# Read-only SQL against the parity PostgreSQL, for the things the API cannot answer. Plan
# 18's `outbox` is deliberately not an ExposedEntity, so "did every event drain" has no HTTP
# surface at all; neither does "is api_keys.api_key a hash at rest" (plan 11).
#
# **Read-only is enforced, not intended.** `default_transaction_read_only` is set on the
# connection itself rather than by a leading `SET` statement, so there is no way to issue a
# query that runs before it and no `SET` tag in the output to filter back out. A mistake
# here fails instead of mutating the database the suite is measuring — this is a fixture
# with a psql in it, and the next person to reach for it will not be reading this comment.
pg_query() {
	"$ENGINE" exec -i -e PGOPTIONS='-c default_transaction_read_only=on' "$c_pg" \
		psql -U "$PGUSER_" -d "$PGDATABASE_" -At -v ON_ERROR_STOP=1 -c "$*"
}

# --- Upstream grocy --------------------------------------------------------------------

start_upstream() {
	rm_container "$c_upstream"
	"$ENGINE" volume rm -f "$v_upstream_data" >/dev/null 2>&1 || true
	"$ENGINE" volume create "$v_upstream_data" >/dev/null
	log "upstream grocy"
	local ft=()
	while IFS= read -r a; do [ -n "$a" ] && ft+=("$a"); done < <(ft_upstream_args)
	# SQLite, which is what upstream is: ADR-0001 put PostgreSQL *alongside* it and
	# ADR-0008 retired it here, but upstream never had it. Comparing the fork on its engine
	# against upstream on its own is the comparison worth making — anything else would be
	# testing a configuration nobody runs.
	"$ENGINE" run -d --name "$c_upstream" \
		--network "$PARITY_NETWORK" --network-alias upstream \
		-p "127.0.0.1:${UPSTREAM_PORT}:80" \
		-v "$v_upstream_data:/config" \
		-e PUID=1000 -e PGID=1000 -e TZ=UTC \
		-e GROCY_MODE=production \
		-e "GROCY_BASE_URL=http://127.0.0.1:${UPSTREAM_PORT}" \
		${ft[@]+"${ft[@]}"} \
		"$(ft_upstream_image)" >/dev/null
	# **`/`, not `/login`, and this is the ordering trap of the whole file.** Upstream grocy
	# has no migrate command: `SystemController::Root` is what calls `MigrateDatabase()`, so
	# the schema is created by the first request to `/` and by nothing else. Waiting on
	# `/login` returns 200 against an *empty* database — the login form renders without
	# touching a table — and the first POST to it then dies with
	# "no such table: migrations", which is exactly what happened here before this line said
	# `/`. The fork does not have this problem, because plan 10 made migrating a step
	# (`bin/victual-migrate`) rather than a side effect of whoever knocks first.
	# **`-L` is load-bearing.** Unauthenticated `/` answers 302 to `/login`, and `curl -f`
	# treats a 302 as success — so without `-L` this gate passes on a redirect that never
	# reached the route, the schema is never created, and the failure surfaces later as
	# "login failed (HTTP 500)" from the harness. Following the redirect is what makes the
	# request actually execute SystemController::Root.
	wait_for upstream 180 curl -fsSL -o /dev/null "http://127.0.0.1:${UPSTREAM_PORT}/"

	# And then assert the thing the suite actually needs rather than a proxy for it: that
	# admin/admin logs in. A 302 to the app is a successful login; refused credentials are a
	# 302 to /login?invalid=true, and a missing schema is a 500 — login_accepted tells all
	# three apart.
	wait_for "upstream login" 90 login_accepted "http://127.0.0.1:${UPSTREAM_PORT}" admin
}

# --- Lifecycle -------------------------------------------------------------------------

stack_up() {
	ensure_network
	start_postgres
	start_mosquitto
	start_influx
	migrate_victual
	start_victual
	start_mcp
	start_upstream
	log "up:  victual http://127.0.0.1:${VICTUAL_PORT}   upstream http://127.0.0.1:${UPSTREAM_PORT}   mcp http://127.0.0.1:${MCP_PORT}/mcp"
}

stack_down() {
	log "tearing down"
	# The pod first: `pod rm -f` takes its containers and its infra container with it, and
	# removing a member container on its own would leave the pod holding the published port.
	"$ENGINE" pod rm -f "$p_victual" >/dev/null 2>&1 || true
	for c in "$c_victual_mcp" "$c_upstream" "$c_influx" "$c_mqtt" "$c_pg"; do
		rm_container "$c"
	done
	"$ENGINE" volume rm -f "$v_upstream_data" >/dev/null 2>&1 || true
	"$ENGINE" network rm -f "$PARITY_NETWORK" >/dev/null 2>&1 || true
	rm -f "$PARITY_ADMIN_PASSWORD_FILE"
}

stack_status() {
	printf '%-22s %-10s %s\n' CONTAINER STATE IMAGE
	for c in "$c_pg" "$c_mqtt" "$c_influx" "$c_victual_app" "$c_victual_web" "$c_victual_mcp" "$c_upstream"; do
		if exists "$c"; then
			printf '%-22s %-10s %s\n' "$c" \
				"$("$ENGINE" inspect -f '{{.State.Status}}' "$c")" \
				"$("$ENGINE" inspect -f '{{.ImageName}}' "$c")"
		else
			printf '%-22s %-10s %s\n' "$c" absent -
		fi
	done
}

# A cold start is a first-class check, not a convenience: plan 10 is about what happens on
# the first request after a scale-up, and the only way to test it is to have a first
# request. This throws both databases away and rebuilds them from nothing.
stack_reset() {
	"$ENGINE" pod rm -f "$p_victual" >/dev/null 2>&1 || true
	rm_container "$c_upstream"
	start_postgres
	migrate_victual
	start_victual
	start_upstream
}
