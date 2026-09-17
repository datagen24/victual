# Fixed-output hashes, in one file, because bootstrapping this flake means filling them
# in and a single-file edit is a smaller ceremony than hunting them through the tree.
#
# Both start as `lib.fakeHash`. The first build of each fails with a "hash mismatch"
# naming the real value; paste it here and build again. They change only when
# composer.lock or yarn.lock changes, and a changed lockfile with an unchanged hash here
# is a build failure rather than a silently stale dependency set — which is the property
# we are buying.
#
#   nix build .#app       -> gives you composerVendor
#   nix build .#frontend  -> gives you yarnOfflineCache
#
# See nix/README.md, "Bootstrapping the hashes".
{
  # Hash of the Composer vendor tree built from composer.json + composer.lock.
  composerVendor = "sha256-qpL24irDIKq3kf/AH12zksKDqwggBFUUuRvhL842n48=";

  # Hash of the yarn offline mirror built from yarn.lock.
  #
  # Updated for the fabric 5 -> 7 bump (issue #126). This sandbox has no nix, so rather
  # than guess it the previous commit reset this to the fakeHash placeholder and let the
  # `flake` CI job's fixed-output-derivation failure report the real value, per
  # nix/README.md's "Bootstrapping the hashes" - the same "got:" value a local
  # `nix build .#frontend` would have produced.
  yarnOfflineCache = "sha256-5jQ6uSjMasoAtL5wCPjaS9jzhj3sYb5HVCA/iMCIfow=";
}
