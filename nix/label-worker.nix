# The delivery worker, built from the revision this flake pins.
#
# ADR-0019 decision item 1 puts the worker's source in its own repository while this flake
# keeps the image, the pin and the deployment. That split is not a tension with plan 20 piece
# 5's "the print drainer is an image in this flake": the image is here, the driver matrix is
# there, and a revision bump is a flake.lock change reviewed like any other.
{
  lib,
  rustPlatform,
  victualLabelSources,
}:

rustPlatform.buildRustPackage {
  pname = "victual-label-worker";
  version = "0.1.0";

  src = victualLabelSources.worker;

  cargoLock.lockFile = "${victualLabelSources.worker}/Cargo.lock";

  # The worker's own tests run in its repository, where a failure is attributable to the
  # change that caused it. Running them again here would make an unrelated flake.lock bump
  # fail for somebody else's reason.
  doCheck = false;

  meta = {
    description = "Claims Victual print jobs and drives the printer";
    homepage = "https://github.com/datagen24/victual-label-worker";
    license = lib.licenses.bsd3;
    mainProgram = "victual-label-worker";
  };
}
