# **A deliberately broken image, built only to be rejected.**
#
# The no-shell check is worth exactly as much as the proof that it fires. This is the worker
# image with bash added to `contents`, and `nix/checks.nix`'s negative control passes only when
# the scan finds it. Nothing else references this, and it is never loaded or run.
{
  dockerTools,
  labelWorkerRust,
  imageLib,
  bash,
}:

dockerTools.streamLayeredImage (
  imageLib.common
  // {
    name = "victual-label-worker-with-shell";

    contents = [
      imageLib.passwd
      imageLib.certificates
      bash # the whole point
    ];

    extraCommands = imageLib.scaffold "";

    config = imageLib.commonConfig // {
      Cmd = [ "${labelWorkerRust}/bin/rsrender" ];
    };
  }
)
