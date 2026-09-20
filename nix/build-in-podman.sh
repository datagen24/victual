#!/usr/bin/env bash
#
# Build the Nix images from a Mac, without installing Nix on the Mac.
#
#   nix/build-in-podman.sh bootstrap    fill nix/hashes.nix and write flake.lock
#   nix/build-in-podman.sh check        nix flake check
#   nix/build-in-podman.sh images       build the six images and `podman load` them
#   nix/build-in-podman.sh shell        an interactive nix shell in the builder
#   nix/build-in-podman.sh all          bootstrap, then check, then images
#
# This is option A of nix/README.md, "The awkward fact about macOS", turned into
# something repeatable. Container images are Linux artifacts and an Apple Silicon Mac
# builds aarch64-darwin by default, so the build happens inside a podman container
# running the official Nix image — which on this machine is already aarch64-linux,
# because the podman machine is a Linux VM.
#
# The one thing this adds over the README's one-liner is a **persistent store**. A
# throwaway container re-downloads the whole PHP closure on every attempt, and the hash
# bootstrap is by construction at least two attempts.
#
# **The store is a long-lived container, not a volume, and that is not a style choice.**
# The obvious shape — `-v victual-nix-store:/nix` — does not work: an empty named volume
# mounted over /nix hides the store that nix itself lives in, and seeding the volume by
# copying /nix into it produces a store whose `nix` segfaults (rc=139 on the first
# attempt here, before the binary prints its version). So the builder is a container that
# is created once and kept; /nix is its own writable layer, exactly as the image
# intended, and it survives between runs because the container is never `--rm`ed.
# `clean` removes it.
#
# `bootstrap` is the interesting one. Two derivations fetch from the network and so need
# a fixed-output hash, both of which start as lib.fakeHash — meaning the first build of
# each fails on purpose with the real value in the error. This reads that value out and
# writes it into nix/hashes.nix rather than asking a human to copy-paste it, which is the
# same loop, done the same way, without the transcription error.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

NIX_IMAGE="${NIX_IMAGE:-docker.io/nixos/nix:latest}"
BUILDER="${BUILDER:-victual-nix-builder}"
CONTAINER_ENGINE="${CONTAINER_ENGINE:-podman}"

# --extra-experimental-features on every invocation rather than in a nix.conf: the
# nixos/nix image ships neither flakes nor nix-command enabled, and relying on a config
# file inside the builder is one more thing to keep in step with this script.
NIX_FLAGS=(--extra-experimental-features 'nix-command flakes')

# `path:/src`, not the bare `.` the README uses, and the reason is worth writing down.
# A bare `.` makes nix treat the directory as a *git* flake, which means libgit2 opens
# /src/.git — and in a git worktree that is a file pointing at
# <main repo>/.git/worktrees/<name>, a path that does not exist inside the container. The
# first run failed with exactly that ("failed to resolve path … libgit2 error code = 2").
# `path:` reads the directory as a directory. It also has the side effect of not caring
# whether the tree is dirty, which is what you want while bootstrapping hashes that are,
# by definition, uncommitted.
FLAKE_REF="${FLAKE_REF:-path:/src}"

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

command -v "$CONTAINER_ENGINE" >/dev/null 2>&1 || die "$CONTAINER_ENGINE is not on PATH"

# ---------------------------------------------------------------------------------------
# The builder container
# ---------------------------------------------------------------------------------------

# Creates the builder if it is not there and makes sure it is running. The working tree
# is mounted read-write on purpose: `nix flake update` writes flake.lock into it and the
# bootstrap writes nix/hashes.nix, and both of those belong in the repository rather than
# in a container that is about to be deleted.
#
# :Z is deliberately absent — it is an SELinux relabel, which is a Linux-host concern and
# a no-op-with-a-warning on a Mac's podman machine.
ensure_store() {
	if ! "$CONTAINER_ENGINE" container exists "$BUILDER" 2>/dev/null; then
		log "creating builder container $BUILDER"
		"$CONTAINER_ENGINE" create \
			--name "$BUILDER" \
			-v "$REPO_ROOT:/src" \
			-w /src \
			-e NIX_CONFIG="experimental-features = nix-command flakes" \
			"$NIX_IMAGE" sleep infinity >/dev/null \
			|| die "could not create the builder container"
	fi

	if [ "$("$CONTAINER_ENGINE" inspect -f '{{.State.Running}}' "$BUILDER" 2>/dev/null)" != "true" ]; then
		log "starting builder container $BUILDER"
		"$CONTAINER_ENGINE" start "$BUILDER" >/dev/null || die "could not start $BUILDER"
	fi

	# The mount is fixed at create time, so a builder created against a different working
	# tree would silently build the wrong source. Catch that rather than debug it later.
	local mounted
	mounted="$("$CONTAINER_ENGINE" inspect -f '{{range .Mounts}}{{if eq .Destination "/src"}}{{.Source}}{{end}}{{end}}' "$BUILDER" 2>/dev/null)"
	if [ -n "$mounted" ] && [ "$mounted" != "$REPO_ROOT" ]; then
		die "$BUILDER is bound to $mounted, not $REPO_ROOT — run '$0 clean' first"
	fi
}

nix_exec() {
	"$CONTAINER_ENGINE" exec "$BUILDER" nix "${NIX_FLAGS[@]}" "$@"
}

# ---------------------------------------------------------------------------------------
# Hash bootstrap
# ---------------------------------------------------------------------------------------

# Reads the `got:` line out of a hash-mismatch failure. Nix has spelled this several ways
# across versions ("got:    sha256-…" and "specified/got" pairs), so the match is on the
# sha256- token following the word `got`, not on a fixed column.
extract_got_hash() {
	grep -oE 'got:[[:space:]]+sha256-[A-Za-z0-9+/=]+' "$1" \
		| tail -n1 \
		| grep -oE 'sha256-[A-Za-z0-9+/=]+' || true
}

# Rewrites one attribute in nix/hashes.nix. Anchored on the attribute name so the two
# hashes cannot be swapped, and it fails loudly if the attribute is not there — a silent
# no-op here would leave fakeHash in place and produce a second identical failure that
# looks like the build not converging.
set_hash() {
	local attr="$1" value="$2"
	grep -qE "^[[:space:]]*${attr}[[:space:]]*=" nix/hashes.nix \
		|| die "nix/hashes.nix has no attribute '$attr'"
	python3 - "$attr" "$value" <<'PY'
import re, sys
attr, value = sys.argv[1], sys.argv[2]
path = "nix/hashes.nix"
src = open(path).read()
new, n = re.subn(
    r'(^\s*%s\s*=\s*")[^"]*(";)' % re.escape(attr),
    lambda m: m.group(1) + value + m.group(2),
    src,
    count=1,
    flags=re.M,
)
if n != 1:
    sys.exit("could not rewrite %s in %s" % (attr, path))
open(path, "w").write(new)
PY
	log "nix/hashes.nix: $attr = $value"
}

# Builds one attribute, and if it fails on a fixed-output hash mismatch, writes the real
# hash into nix/hashes.nix and tries again. Two attempts, not a loop with no bound: one
# derivation has one hash, so a second mismatch on the same attribute means something
# other than the bootstrap is wrong and retrying would hide it.
build_with_hash() {
	local attr="$1" hash_attr="$2"
	local logfile
	logfile="$(mktemp)"

	for attempt in 1 2; do
		log "nix build $FLAKE_REF#$attr (attempt $attempt)"
		if nix_exec build "$FLAKE_REF#$attr" --no-link --print-out-paths 2>&1 | tee "$logfile"; then
			rm -f "$logfile"
			return 0
		fi

		local got
		got="$(extract_got_hash "$logfile")"
		if [ -z "$got" ]; then
			echo "--- build log tail ---" >&2
			tail -n 40 "$logfile" >&2
			rm -f "$logfile"
			die "$FLAKE_REF#$attr failed for a reason that is not a hash mismatch (see above)"
		fi

		set_hash "$hash_attr" "$got"
	done

	rm -f "$logfile"
	die "$FLAKE_REF#$attr still fails after its hash was filled in"
}

cmd_bootstrap() {
	ensure_store

	if [ ! -f flake.lock ]; then
		log "nix flake update (writes flake.lock — commit it)"
		nix_exec flake update --flake "$FLAKE_REF"
	else
		log "flake.lock already present, leaving it alone"
	fi

	# Frontend first: it is the cheaper of the two to get wrong, and a yarn failure is a
	# clearer error message than a composer one.
	build_with_hash frontend yarnOfflineCache
	build_with_hash app composerVendor

	log "bootstrap complete — nix/hashes.nix and flake.lock are filled in"
	git -C "$REPO_ROOT" --no-pager diff --stat -- nix/hashes.nix flake.lock 2>/dev/null || true
}

# ---------------------------------------------------------------------------------------
# The rest
# ---------------------------------------------------------------------------------------

cmd_check() {
	ensure_store
	log "nix flake check"
	nix_exec flake check "$FLAKE_REF" --print-build-logs
}

# `nix run .#load` cannot work here: it shells out to podman, which does not exist inside
# the builder. Instead the streamer writes the tar to stdout and the *host's* podman
# reads it — which is what nix/README.md's second snippet does, once per image.
cmd_images() {
	ensure_store
	# The two label images are here because they are deployment artifacts like the rest:
	# they were missing until 2026-09-20, so `images` left the label workloads at whatever
	# tag happened to be in podman already, and a manifest referencing the current tag found
	# no image. deploy/README.md's label table is what they are for.
	for img in image-app image-web image-migrate image-mcp image-label-renderer image-label-worker; do
		log "building .#$img and loading it into $CONTAINER_ENGINE"
		# The flags are spelled out rather than interpolated from NIX_FLAGS: `${NIX_FLAGS[*]}`
		# flattens `--extra-experimental-features` and its single argument
		# `'nix-command flakes'` into three words, and the shell inside the container then
		# reads `flakes` as the subcommand ("error: 'flakes' is not a recognised command").
		# NIX_CONFIG in the builder's environment already enables both, so this only needs
		# the pipeline.
		#
		# `xargs -I{} {}` is what nix/README.md suggests and it does not work in this
		# image: busybox's xargs will not substitute into the command position, so it
		# reports "{}: No such file or directory". Capturing the path and running it is
		# the same thing without relying on which xargs is installed.
		"$CONTAINER_ENGINE" exec "$BUILDER" sh -c \
			"streamer=\$(nix build '$FLAKE_REF#$img' --no-link --print-out-paths) && exec \"\$streamer\"" \
			| "$CONTAINER_ENGINE" load
	done
	log "loaded:"
	"$CONTAINER_ENGINE" images --filter reference='localhost/victual-*'
}

cmd_shell() {
	ensure_store
	"$CONTAINER_ENGINE" exec -it "$BUILDER" sh
}

cmd_clean() {
	log "removing builder container $BUILDER (this discards the warm nix store)"
	"$CONTAINER_ENGINE" rm -f "$BUILDER" >/dev/null 2>&1 || true
}

case "${1:-all}" in
	bootstrap) cmd_bootstrap ;;
	check)     cmd_check ;;
	images)    cmd_images ;;
	shell)     cmd_shell ;;
	clean)     cmd_clean ;;
	all)       cmd_bootstrap; cmd_check; cmd_images ;;
	*)         die "usage: $0 [bootstrap|check|images|shell|clean|all]" ;;
esac
