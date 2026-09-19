# The MCP sidecar image.
#
# Same shape as the label workloads' images (nix/images/label-worker.nix): one binary,
# no interpreter beyond the one it inherently needs (Node, in this case — the
# checks.nix no-shell assertion forbids a shell and the general-purpose scripting
# runtimes, not Node itself), no state. §2 of docs/mcp-interface-spec.md is what makes
# "two replicas indistinguishable" true here: nothing this image runs keeps anything
# on disk or in a session.
{
  dockerTools,
  imageLib,
  mcp,

  mcpPort ? 3000,
}:

dockerTools.streamLayeredImage (
  imageLib.common
  // {
    name = "victual-mcp";
    contents = [
      mcp
      imageLib.passwd
      imageLib.certificates
    ];
    extraCommands = imageLib.scaffold "";
    config = imageLib.commonConfig // {
      Entrypoint = [ "${mcp}/bin/victual-mcp" ];

      Env = imageLib.commonConfig.Env ++ [
        "MCP_PORT=${toString mcpPort}"
      ];

      ExposedPorts = {
        "${toString mcpPort}/tcp" = { };
      };

      Labels = imageLib.labels "mcp";
    };
  }
)
