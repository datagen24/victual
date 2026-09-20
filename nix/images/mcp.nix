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
      mcp.node
      imageLib.passwd
      imageLib.certificates
    ];
    extraCommands = imageLib.scaffold "";
    config = imageLib.commonConfig // {
      # Node itself, not a wrapper script — see nix/mcp.nix on why there is no bin/.
      Entrypoint = [
        "${mcp.node}/bin/node"
        mcp.entrypoint
      ];

      # /tmp because it is the only directory this image contains, the same answer
      # web.nix gives. scaffold creates it; nothing else exists to start in.
      WorkingDir = "/tmp";

      Env = imageLib.commonConfig.Env ++ [
        "MCP_PORT=${toString mcpPort}"
        "NODE_ENV=production"
      ];

      ExposedPorts = {
        "${toString mcpPort}/tcp" = { };
      };

      Labels = imageLib.labels "mcp";
    };
  }
)
