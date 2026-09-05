#!/usr/bin/env bash
#
# Simulated time for the year phase.
#
# Sourced by ../bin/parity alongside stack.sh; not meant to be run directly.
#
# **Why a faked clock at all.** The application has no clock seam. `POST /consume`,
# `/open` and `/transfer` accept no date parameter, and `stock_log.used_date`
# (services/StockService.php:635,671), `stock.opened_date` (:1521,1530,1564,1574) and the
# `row_created_timestamp` column default on ~40 tables are all clock reads. Only
# purchased_date, best_before_date, tracked_time, done_time and meal_plan.day can be
# placed in simulated time through the API. A year built from those alone would be a year
# of state at a few minutes of chronology.
#
# **Three runtimes, one clock file.** victual-app (and victual-migrate), PostgreSQL — the
# `LOCALTIMESTAMP` behind row_created_timestamp lives there, not in PHP — and upstream,
# whose SQLite is in-process so faking its PHP also fakes CURRENT_TIMESTAMP. victual-web
# (nginx) and mosquitto read no clock the suite asserts on. All three read the same file,
# so a step moves them together.
#
# Measured on 2026-09-05, podman 6.0.2, aarch64, against victual-{app,migrate}:4.6.0,
# postgres:16 and linuxserver/grocy:version-v4.6.0. Every number and every workaround
# below is from that run; none of it is inference.

set -euo pipefail

FT_DIR="${FT_DIR:-$REPO_ROOT/.devtools/parity/.faketime}"
FT_CLOCK_DIR="$FT_DIR/clock"
FT_CLOCK_FILE="$FT_CLOCK_DIR/now"

# The nixpkgs revision the images were built from. The .so must come from *this* rev and
# no other: libfaketime's RUNPATH names an absolute glibc store path, and the one it names
# is only present in the image when both were built from the same pin. Building from
# today's nixpkgs would produce a library whose libc is not in the image, and LD_PRELOAD
# would be *ignored* rather than fail — see ft_assert_loaded for why that matters.
FT_NIXPKGS_REV="${FT_NIXPKGS_REV:-$(python3 -c "import json;print(json.load(open('$REPO_ROOT/flake.lock'))['nodes']['nixpkgs']['locked']['rev'])")}"
FT_NIX_BUILDER="${FT_NIX_BUILDER:-victual-nix-builder}"

# **Mounted at /ft, not at its store path, and that is not cosmetic.** Mounting a
# directory whose basename is 51 characters produced a mountpoint named with 49 — podman
# on this host silently truncates and mangles a long final path segment, so
# `/nix/store/32gfn…-libfaketime-0.9.11` arrived as `…-libfaketime-0.9o` and the preload
# could not be opened. A short target sidesteps it, and the library still resolves its own
# libc because that comes from RUNPATH, not from where the .so happens to be mounted.
FT_MOUNT="${FT_MOUNT:-/ft}"

FT_POSTGRES_IMAGE="${FT_POSTGRES_IMAGE:-localhost/parity-postgres-faketime:16}"
FT_UPSTREAM_IMAGE="${FT_UPSTREAM_IMAGE:-localhost/parity-upstream-faketime:4.6.0}"

# `FAKETIME_NO_CACHE=1` re-reads the timestamp file on every clock call and costs
# **521 us per call** — 200k PHP time() calls took 104s against 0.004s unpreloaded, a
# ~26000x slowdown that no request path survives. A one-second cache costs 0.05 us/call,
# which is the same order as the 0.02 us baseline. So the cache stays on, and the price of
# that is up to one real second of staleness after a step — which is exactly why
# ft_wait_until below is a bounded check rather than a sleep.
FT_CACHE_DURATION="${FT_CACHE_DURATION:-1}"

# How far past the target the observed clock may sit and still count as "arrived". The
# faked clock keeps running at real rate from wherever it was set, so an exact-equality
# check would never pass; an `observed >= target` check would pass *immediately against
# the real clock* whenever the target is historical, which it always is here. Both bounds
# or nothing.
FT_MAX_STEP_DRIFT_S="${FT_MAX_STEP_DRIFT_S:-120}"

# **Off unless asked for.** stack.sh is shared with the api, ui and side-effects phases,
# and none of them wants a faked clock — the year phase turns this on for itself.
ft_enabled() { [ "${PARITY_FAKETIME:-off}" = "on" ]; }

# The two derived images. Neither is the fork: PostgreSQL is infrastructure, and upstream
# is the one place the suite stops running the published image byte-for-byte — it gains a
# library and nothing else. Built on demand so a `parity year` on a clean machine works.
ft_build_images() {
	ft_enabled || return 0
	local dir="$REPO_ROOT/.devtools/parity/stack/faketime"
	if ! "$ENGINE" image exists "$FT_POSTGRES_IMAGE" 2>/dev/null; then
		log "faketime: building $FT_POSTGRES_IMAGE"
		"$ENGINE" build -q --build-arg "POSTGRES_IMAGE=$POSTGRES_IMAGE" \
			-f "$dir/Containerfile.postgres" -t "$FT_POSTGRES_IMAGE" "$REPO_ROOT" >/dev/null \
			|| die "could not build $FT_POSTGRES_IMAGE"
	fi
	if ! "$ENGINE" image exists "$FT_UPSTREAM_IMAGE" 2>/dev/null; then
		log "faketime: building $FT_UPSTREAM_IMAGE"
		"$ENGINE" build -q --build-arg "UPSTREAM_IMAGE=$UPSTREAM_IMAGE" \
			-f "$dir/Containerfile.upstream" -t "$FT_UPSTREAM_IMAGE" "$REPO_ROOT" >/dev/null \
			|| die "could not build $FT_UPSTREAM_IMAGE"
	fi
}

# Which image each side runs. Unfaked runs get the stock images, unchanged.
ft_postgres_image() { if ft_enabled; then printf '%s' "$FT_POSTGRES_IMAGE"; else printf '%s' "$POSTGRES_IMAGE"; fi; }
ft_upstream_image() { if ft_enabled; then printf '%s' "$FT_UPSTREAM_IMAGE"; else printf '%s' "$UPSTREAM_IMAGE"; fi; }

# --- The library ------------------------------------------------------------------------

# Builds libfaketime from the images' own nixpkgs pin and caches its lib/ directory under
# .faketime/. Uses the same persistent builder nix/build-in-podman.sh keeps, because a Mac
# cannot build a Linux artifact on its own and a throwaway container re-downloads the
# closure every time.
ft_prepare_lib() {
	local marker="$FT_DIR/.rev"
	if [ -d "$FT_DIR/lib" ] && [ "$(cat "$marker" 2>/dev/null || true)" = "$FT_NIXPKGS_REV" ]; then
		return 0
	fi

	"$ENGINE" container exists "$FT_NIX_BUILDER" 2>/dev/null \
		|| die "the nix builder '$FT_NIX_BUILDER' is not there — run nix/build-in-podman.sh first"
	"$ENGINE" start "$FT_NIX_BUILDER" >/dev/null 2>&1 || true

	log "faketime: building libfaketime from nixpkgs $FT_NIXPKGS_REV"
	local store_path
	store_path="$("$ENGINE" exec "$FT_NIX_BUILDER" nix \
		--extra-experimental-features 'nix-command flakes' \
		build --no-link --print-out-paths \
		"github:NixOS/nixpkgs/$FT_NIXPKGS_REV#libfaketime" 2>/dev/null | grep -v -- '-man$' | head -1)"
	[ -n "$store_path" ] || die "could not build libfaketime from nixpkgs $FT_NIXPKGS_REV"

	rm -rf "$FT_DIR/lib" "$FT_DIR/.staging"
	mkdir -p "$FT_DIR/.staging"
	# tar rather than `podman cp`: a store path is mode 0555, and `podman cp` recreates the
	# directory with that mode and then cannot write the files into it.
	"$ENGINE" exec "$FT_NIX_BUILDER" tar -cf - -C /nix/store "$(basename "$store_path")" \
		| tar -xf - -C "$FT_DIR/.staging"
	chmod -R u+w "$FT_DIR/.staging"
	mv "$FT_DIR/.staging/$(basename "$store_path")/lib" "$FT_DIR/lib"
	rm -rf "$FT_DIR/.staging"
	printf '%s\n' "$FT_NIXPKGS_REV" > "$marker"
}

# --- The clock --------------------------------------------------------------------------

# Writes the clock file. Must be called *before* stack_up: PostgreSQL initialises at
# whatever it first reads, and a clock that later jumps backwards past a running server is
# a corrupted run rather than a failed one.
ft_set() {
	local when="$1"   # 'YYYY-MM-DD HH:MM:SS'
	mkdir -p "$FT_CLOCK_DIR"
	printf '@%s\n' "$when" > "$FT_CLOCK_FILE"
}

ft_init() {
	ft_enabled || return 0
	ft_prepare_lib
	ft_build_images
	ft_set "$1"
}

# --- Environment for each runtime ---------------------------------------------------------

# The fork's images are *not* rebuilt for the clock: the library arrives as a read-only
# mount and the configuration as environment, so the suite still boots the artifact that
# ships, under the same --read-only --cap-drop ALL --security-opt no-new-privileges the
# manifest sets. (Those flags do not block this: no-new-privileges only makes ld.so ignore
# LD_PRELOAD for *setuid* binaries, and php-fpm is not one.)
ft_victual_args() {
	ft_enabled || return 0
	printf '%s\n' \
		-v "$FT_DIR/lib:$FT_MOUNT:ro" \
		-v "$FT_CLOCK_DIR:/clk:ro" \
		-e "LD_PRELOAD=$FT_MOUNT/libfaketime.so.1" \
		-e "FAKETIME_TIMESTAMP_FILE=/clk/now" \
		-e "FAKETIME_CACHE_DURATION=$FT_CACHE_DURATION"
}

# **`FAKETIME_DONT_FAKE_MONOTONIC=1`, and a 365-day run is what proved it necessary.**
# PostgreSQL schedules its checkpointer, autovacuum and latch waits on the monotonic clock.
# Faking that alongside the wall clock means a one-day step tells the postmaster that a day
# elapsed between two ticks — its own log reported `write=172800.002 s` for a checkpoint —
# and after roughly 190 steps it stopped accepting connections altogether: the application
# reported `SQLSTATE[08006] connection to server at "postgres" failed: timeout expired`, at
# 09:00:00 on scattered simulated days, which is exactly when the clock moves. A 31-day
# smoke run never reached it. What the suite needs faked is `LOCALTIMESTAMP`, which is wall
# clock; the monotonic clock can and should stay real.
#
# **No LD_PRELOAD here on purpose.** Setting it as container environment hangs the official
# postgres entrypoint: it re-execs itself through `gosu postgres` and the second pass never
# reaches bash's first traced command — empty logs, no children, and it took a
# SHELLOPTS=xtrace run to localise. Every piece works preloaded on its own (dash, bash,
# gosu, initdb, postgres --version); the combination does not. The image shims `postgres`
# and `initdb` instead, so the preload lands on the binaries that read the clock and never
# on env/bash/gosu. Only the clock configuration is passed here, and the shims inherit it.
ft_postgres_args() {
	ft_enabled || return 0
	printf '%s\n' \
		-v "$FT_CLOCK_DIR:/clk:ro" \
		-e "FAKETIME_TIMESTAMP_FILE=/clk/now" \
		-e "FAKETIME_CACHE_DURATION=$FT_CACHE_DURATION" \
		-e "FAKETIME_DONT_FAKE_MONOTONIC=1"
}

# Upstream is Alpine, so musl, so it cannot use the nixpkgs library at all — its image
# carries its own from apk. Faking its PHP is also what fakes SQLite, which runs in that
# same process; there is no second engine on this side.
ft_upstream_args() {
	ft_enabled || return 0
	printf '%s\n' \
		-v "$FT_CLOCK_DIR:/clk:ro" \
		-e "LD_PRELOAD=/usr/lib/faketime/libfaketime.so.1" \
		-e "FAKETIME_TIMESTAMP_FILE=/clk/now" \
		-e "FAKETIME_CACHE_DURATION=$FT_CACHE_DURATION"
}

# --- Stepping ---------------------------------------------------------------------------

# Epoch of a simulated instant, computed on the host. python3 rather than `date`, which
# spells this differently on macOS and Linux and this file runs on both.
ft_epoch() {
	python3 -c 'import sys,datetime;print(int(datetime.datetime.strptime(sys.argv[1],"%Y-%m-%d %H:%M:%S").replace(tzinfo=datetime.timezone.utc).timestamp()))' "$1"
}

# What one runtime currently believes the time to be, as an epoch, or empty when it cannot
# be asked. Every probe goes through a surface the application itself uses — there is no
# `podman exec … date` option here, because the fork's images are built from scratch and
# carry no shell and no PATH at all.
ft_observed_epoch() {
	case "$1" in
		postgres)
			"$ENGINE" exec "$c_pg" psql -U "$PGUSER_" -d "$PGDATABASE_" -At \
				-c "SELECT EXTRACT(EPOCH FROM LOCALTIMESTAMP)::bigint;" 2>/dev/null | tr -d '[:space:]'
			;;
		upstream)
			# A **long-lived php-fpm worker**, which is the case neither `postgres` nor `cli`
			# covers: both of those are fresh processes. Found the hard way — a step that
			# waited only on those two was observed by upstream one whole step late, because
			# the one-second cache had not yet lapsed in the worker that answered.
			ft_http_epoch "http://127.0.0.1:${UPSTREAM_PORT}"
			;;
		cli)
			# A *newly spawned* process, which is the case that would break if libfaketime
			# re-derived its origin per process. Measured: it does not — a child spawned
			# after a step reports the stepped time, same as its parent.
			local php_bin
			php_bin="$("$ENGINE" image inspect --format '{{index .Config.Cmd 0}}' "$VICTUAL_MIGRATE_IMAGE" 2>/dev/null)" || return 0
			local args=()
			while IFS= read -r a; do args+=("$a"); done < <(ft_victual_args)
			"$ENGINE" run --rm --read-only --tmpfs /tmp --cap-drop ALL \
				--security-opt no-new-privileges "${args[@]}" \
				"$VICTUAL_MIGRATE_IMAGE" "$php_bin" -r 'echo time();' 2>/dev/null | tr -d '[:space:]'
			;;
	esac
}

# The authenticated /api/system/time of a running instance. Both projects ship admin/admin
# from migration 0027, which is the same credential the harness logs in with.
ft_http_epoch() {
	local base="$1" jar body
	jar="$(mktemp)"
	curl -s -c "$jar" -o /dev/null -X POST -d 'username=admin&password=admin' "$base/login" 2>/dev/null || true
	body="$(curl -s -b "$jar" "$base/api/system/time" 2>/dev/null || true)"
	rm -f "$jar"
	printf '%s' "$body" | python3 -c 'import sys,json;print(int(json.load(sys.stdin)["timestamp"]))' 2>/dev/null || true
}

# **An ignored LD_PRELOAD is a no-op, not an error.** ld.so prints "cannot be preloaded …
# ignored" on stderr and the process runs on with the real clock — so a suite that does not
# check this would replay a whole simulated year at wall-clock time and report parity over
# it. This is the check that makes that impossible, and it belongs before the first booking
# rather than in a postmortem.
ft_assert_loaded() {
	ft_enabled || return 0
	local target_epoch observed
	target_epoch="$(ft_epoch "$1")"
	observed="$(ft_observed_epoch cli)"
	[ -n "$observed" ] || die "faketime: the CLI probe returned nothing — the clock cannot be verified"
	local delta=$(( observed - target_epoch ))
	if [ "$delta" -lt 0 ] || [ "$delta" -gt "$FT_MAX_STEP_DRIFT_S" ]; then
		die "faketime: LD_PRELOAD did not take effect — a fresh process reports $(date -u -r "$observed" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo "epoch $observed"), not $1. \
The usual cause is a library built from a different nixpkgs pin than the image, whose RUNPATH names a glibc the image does not carry."
	fi
}

# Waits for every runtime to have arrived at a simulated instant, **bounded on both sides**.
# A lower bound alone is not a check: the target is always historical, so `observed >=
# target` is satisfied by the real clock the moment the preload silently fails. The upper
# bound is what distinguishes "arrived" from "never faked".
#
# The one-second cache is why this polls at all; it is normally satisfied on the first or
# second attempt, so a year of steps costs seconds rather than the minutes a fixed sleep
# per step would.
ft_wait_until() {
	local when="$1"; shift
	local runtimes=("$@")
	# postgres and cli are fresh processes; upstream is a long-lived php-fpm worker, and a
	# step is not "arrived" until the thing that will serve the next request agrees.
	# The fork's own php-fpm is asserted by the year runner, which already holds a session.
	[ ${#runtimes[@]} -gt 0 ] || runtimes=(postgres cli upstream)
	ft_enabled || return 0

	local target; target="$(ft_epoch "$when")"
	local deadline=$(( SECONDS + 60 ))
	local rt observed delta pending
	while [ "$SECONDS" -lt "$deadline" ]; do
		pending=""
		for rt in "${runtimes[@]}"; do
			observed="$(ft_observed_epoch "$rt")"
			if [ -z "$observed" ]; then pending="$pending $rt(unreachable)"; continue; fi
			delta=$(( observed - target ))
			if [ "$delta" -lt 0 ] || [ "$delta" -gt "$FT_MAX_STEP_DRIFT_S" ]; then
				pending="$pending $rt(off by ${delta}s)"
			fi
		done
		[ -z "$pending" ] && return 0
		sleep 1
	done
	die "faketime: after 60s these runtimes had not reached $when:$pending"
}

# Steps the clock and waits for every runtime to have arrived, bounded on both sides.
# Callers step only on days the plan has operations for; the wait is normally one poll.
#
# **The clock only ever moves forward.** Stepping backwards past a running PostgreSQL is a
# corrupted run rather than a failed one, so it is refused here rather than trusted to the
# caller's ordering.
ft_step() {
	local when="$1"; shift
	if [ -s "$FT_CLOCK_FILE" ]; then
		local prev cur
		prev="$(ft_epoch "$(tr -d '@' < "$FT_CLOCK_FILE" | head -1)")"
		cur="$(ft_epoch "$when")"
		[ "$cur" -ge "$prev" ] || die "faketime: refusing to step the clock backwards ($when is before the current setting)"
	fi
	ft_set "$when"
	ft_wait_until "$when" "$@"
}
