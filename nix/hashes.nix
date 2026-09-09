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
  yarnOfflineCache = "sha256-rEr7NQDZLsRgKdDGfRN91T8dQM+DisDLHE133b9rt9E=";
}
