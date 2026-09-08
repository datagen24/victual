# The delivery worker image.
#
# Same shape as the renderer's and for the same reasons, with one difference worth stating:
# this is the workload that reaches a printer, and it is the only one in the deployment that
# holds an outbound destination at all. Victual itself never dials a device - it writes rows
# and answers requests - which is the property plan 25's security section claims, and the
# transport being a pull API is what keeps it true.
{
  dockerTools,
  imageLib,
  labelWorker,
}:

dockerTools.streamLayeredImage (
  imageLib.common
  // {
    name = "victual-label-worker";
    contents = [
      labelWorker
      imageLib.passwd
      imageLib.certificates
    ];
    extraCommands = imageLib.scaffold "";
    config = imageLib.commonConfig // {
      Entrypoint = [ "${labelWorker}/bin/victual-label-worker" ];
      Labels = imageLib.labels "label-worker";
    };
  }
)
