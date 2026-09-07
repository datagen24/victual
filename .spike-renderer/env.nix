# The two candidates' runtime environments, and the closure question the gate asks.
#
# Deliberately two separate derivations rather than one shell with everything in it: the
# comparison is partly about what each candidate drags into an image built on no base image,
# and a shared environment would hide exactly that.
{ pkgs ? import <nixpkgs> { } }:

let
  fonts = pkgs.runCommand "spike-fonts" { } ''
    mkdir -p $out
    cp ${../. }/nix/../.spike-renderer/fonts/*.ttf $out/ 2>/dev/null || true
  '';
in
{
  # Candidate A: Pillow does shaping, layout and rasterization in-process.
  pillow = pkgs.python3.withPackages (p: [ p.pillow p.qrcode ]);

  # Candidate B: the emitter measures from font metrics; resvg shapes and rasterizes.
  svgEmitter = pkgs.python3.withPackages (p: [ p.qrcode p.fonttools p.pillow ]);
  resvg = pkgs.resvg;

  # For reference only — the one runtime that could share the editor's engine.
  chromium = pkgs.chromium;
}
