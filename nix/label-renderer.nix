# The headless renderer, built from the revision this flake pins.
#
# It is a separate repository under ADR-0021 - the template document is Victual's and the
# rasterizing is not - and pinning by revision is what keeps "reproducible" true across that
# seam. What the pin buys is the same thing the application's Composer hash buys: a changed
# upstream with an unchanged hash here is a build failure rather than a silently different
# artifact.
{
  lib,
  rustPlatform,
  victualLabelSources,
}:

rustPlatform.buildRustPackage {
  pname = "victual-label-renderer";
  version = "0.1.0";

  src = victualLabelSources.renderer;

  cargoLock.lockFile = "${victualLabelSources.renderer}/Cargo.lock";

  # No build inputs and no native dependencies on purpose. The whole argument for this
  # runtime over the alternatives was closure: the 2026-09-07 comparison measured 62,632,768
  # bytes over seven paths with no shell and no interpreter, against 316 MB and one
  # interpreter reference for the Pillow environment. Adding a system library here would
  # spend that.
  doCheck = false;

  meta = {
    description = "Renders a Victual label template document to a validated indexed raster";
    homepage = "https://github.com/datagen24/victual-label-renderer";
    license = lib.licenses.bsd3;
    mainProgram = "victual-label-renderer";
  };
}
