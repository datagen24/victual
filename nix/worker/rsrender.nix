# The worker, in Rust: it renders a label and drives the device, and carries no interpreter.
#
# This replaces the Python worker after the packaging gate found what a Python one costs —
# nixpkgs' CPython references bash, and removing that reference means rebuilding the package
# set under an interpreter whose absent shell breaks the test suites of anything that shells
# out. The Brother driver is reimplemented against published constants rather than depended on.
{
  lib,
  rustPlatform,
  workerSource,
}:

rustPlatform.buildRustPackage {
  pname = "victual-label-worker";
  version = "0.0.0";

  src = workerSource;
  cargoLock.lockFile = workerSource + "/Cargo.lock";

  # No test suite in the spike; the render-and-print check in nix/checks.nix is what exercises
  # it, for the same reason the Python worker's did — a check that runs is worth more than a
  # phase that is configured.
  doCheck = false;

  meta = {
    description = "Victual label worker — renders a label and drives a Brother QL";
    mainProgram = "rsrender";
    license = lib.licenses.mit;
  };
}
