# How large, and how shell-free, is a PDF-to-raster conversion path?
#
# The Brother QL advertises no page-description format, so a page-description artifact can
# only reach it through a converter. This asks what shipping that converter would cost
# against ADR-0013, using the same closureInfo the image checks use.
let
  flake = builtins.getFlake "/src";
  pkgs = flake.inputs.nixpkgs.legacyPackages.${builtins.currentSystem};
in
pkgs.closureInfo { rootPaths = [ pkgs.cups pkgs.cups-filters pkgs.ghostscript ]; }
