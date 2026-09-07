# The label worker tier: a Python interpreter, the imaging stack, the Brother driver and
# the fonts. No shell, no package manager, uid 65532 — the same properties the three
# existing images carry, through the same imageLib.
#
# ADR-0019 acceptance gate 1 is exactly this image existing and passing `nix flake check`.
{
  dockerTools,
  labelWorkerRust,
  imageLib,
}:

dockerTools.streamLayeredImage (
  imageLib.common
  // {
    name = "victual-label-worker";

    contents = [
      imageLib.passwd
      imageLib.certificates
    ];

    extraCommands = imageLib.scaffold "";

    config = imageLib.commonConfig // {
      # The worker holds no database credential and makes no database connection
      # (ADR-0019 decision item 2). It reaches Victual over HTTP and a printer over TCP,
      # and both addresses arrive as configuration rather than being baked here.
      Cmd = [ "${labelWorkerRust}/bin/rsrender" ];
    };
  }
)
