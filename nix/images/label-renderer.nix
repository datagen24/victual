# The renderer image: one static-ish Rust binary on no base image.
#
# ADR-0013's rule is that a production image contains nothing the running process does not
# need, and this one is the easiest case in the tree to satisfy - there is no interpreter to
# strip, no shell to remove, and nothing that shells out. That was the deciding property in
# the 2026-09-07 runtime comparison rather than a happy accident: a Python renderer ships an
# executable shell through CPython's own subprocess.py, and removing it means rebuilding the
# package set under an interpreter that breaks the test suites of everything that shells out.
{
  dockerTools,
  imageLib,
  labelRenderer,
}:

dockerTools.streamLayeredImage (
  imageLib.common
  // {
    name = "victual-label-renderer";
    contents = [
      labelRenderer
      imageLib.passwd
      # Victual is reached over HTTPS in any deployment that is not a flat network, so the
      # trust store is not optional. Nothing else outbound exists to trust.
      imageLib.certificates
    ];
    extraCommands = imageLib.scaffold "";
    config = imageLib.commonConfig // {
      Entrypoint = [ "${labelRenderer}/bin/victual-label-renderer" ];
      Labels = imageLib.labels "label-renderer";
    };
  }
)
