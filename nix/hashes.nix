# Fixed-output hashes, in one file, because bootstrapping this flake means filling them
# in and a single-file edit is a smaller ceremony than hunting them through the tree.
#
# Both start as `lib.fakeHash`. The first build of each fails with a "hash mismatch"
# naming the real value; paste it here and build again. Each follows the files its
# derivation is built from — for the Composer one that is composer.json as well as
# composer.lock — and a changed input with an unchanged hash here is a build failure
# rather than a silently stale dependency set, which is the property we are buying.
#
#   nix build .#app       -> gives you composerVendor
#   nix build .#frontend  -> gives you yarnOfflineCache
#
# See nix/README.md, "Bootstrapping the hashes".
{
  # Hash of the Composer vendor tree built from composer.json + composer.lock.
  #
  # Re-bootstrapped 2026-09-19 for the first release: the tree records the root package's
  # version, which used to follow version.json (4.6.0) and is now pinned in nix/app.nix so
  # that this hash no longer moves on a release.
  #
  # Moved 2026-09-22 for the `autoload-dev.exclude-from-classmap` entry composer.json
  # gained. composer.lock did not change - its content hash does not cover autoload-dev -
  # and the `--no-dev` autoloader this derivation dumps is byte-identical with and without
  # that entry, so what moved the hash is composer.json, not anything it changed about the
  # dependency set. The note at the top of this file is narrower than the truth on that
  # point: the vendor hash follows both Composer files, not the lockfile alone. Taken from
  # the `flake` job's fixed-output failure on 74d3b1a and 4c85722, which reported the same
  # value, per nix/README.md's "Bootstrapping the hashes"; this sandbox has no nix.
  composerVendor = "sha256-SqYieyz5FkCNx/WRiBnMKlv/2ItNIRhMgKH4iDZVnQA=";

  # Hash of the yarn offline mirror built from yarn.lock.
  #
  # Updated for the fabric 5 -> 7 bump (issue #126). This sandbox has no nix, so rather
  # than guess it the previous commit reset this to the fakeHash placeholder and let the
  # `flake` CI job's fixed-output-derivation failure report the real value, per
  # nix/README.md's "Bootstrapping the hashes" - the same "got:" value a local
  # `nix build .#frontend` would have produced.
  yarnOfflineCache = "sha256-5jQ6uSjMasoAtL5wCPjaS9jzhj3sYb5HVCA/iMCIfow=";

  # Hash of the npm dependency tree for mcp/, built from mcp/package-lock.json.
  #
  # Filled 2026-09-19 (issue #86) from the first `nix build .#mcp` after the lockfile was
  # generated, the same fail-on-purpose loop as the two above. Changes whenever
  # mcp/package-lock.json does: re-run the build and take the "got:" value.
  mcpNpmDeps = "sha256-zUcj0IO+cX16TNSGV1sySK1v6IqHmqWkA8+oWqxGl6U=";
}
