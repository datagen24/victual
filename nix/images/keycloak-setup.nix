# The Keycloak setup image: the realm reconciler (plan 43) and the Node that runs it.
#
# The chart runs it as an opt-in post-install and post-upgrade hook Job
# (deploy/helm/victual/templates/keycloak-setup.yaml). It talks to Keycloak's admin REST API
# and nothing else: no database credential, no Victual API key, no state on disk. Same
# shape as the MCP sidecar's image: one interpreter it inherently needs, no shell
# (nix/checks.nix, keycloak-setup-image-has-no-shell).
{
  dockerTools,
  imageLib,
  keycloakSetup,
}:

dockerTools.streamLayeredImage (
  imageLib.common
  // {
    name = "victual-keycloak-setup";
    contents = [
      keycloakSetup
      keycloakSetup.node
      imageLib.passwd
      imageLib.certificates
    ];
    extraCommands = imageLib.scaffold "";
    config = imageLib.commonConfig // {
      # `--check` and the other flags are the Job's args, appended to this.
      Entrypoint = [
        "${keycloakSetup.node}/bin/node"
        keycloakSetup.entrypoint
      ];
      WorkingDir = "/tmp";
      Env = imageLib.commonConfig.Env ++ [ "NODE_ENV=production" ];
      Labels = imageLib.labels "keycloak-setup";
    };
  }
)
