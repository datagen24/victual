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
  composerVendor = "sha256-+jbt34VCyW44W3MhiZSV+8DldnrEb0LhDhbOZX/PyKk=";

  # Hash of the yarn offline mirror built from yarn.lock.
  #
  # Reset to the fakeHash placeholder for the fabric 5 -> 7 bump (issue #126): this
  # sandbox has no nix, so the real value has to come from `nix build .#frontend` per
  # nix/README.md's "Bootstrapping the hashes" - pasting a guessed value here would be
  # worse than the documented failure mode, since a wrong-but-plausible-looking hash
  # would not obviously say so.
  yarnOfflineCache = "sha256-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=";
}
