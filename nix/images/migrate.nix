# The migration tier: a Job or initContainer, not a server.
#
# It is the only workload that should ever hold a database role able to run DDL, and the
# only image whose PHP carries pdo_sqlite — bin/victual-db-import reads SQLite as an
# import format, which is the one thing ADR-0008 keeps it for.
#
# What separates these workloads is the credential each holds, not the bytes each
# carries. The application tree is identical in all three images and is *meant* to be:
# `migrations/` and `db/` are read on the request path, and the baked view cache's
# compiled file names hash the absolute path of the views directory, so a second,
# trimmed application root would warm a cache the serving image could not use.
#
# Overriding Cmd runs a different tool from the same image — bin/victual-db-import for a
# grocy SQLite migration, for instance.
{
  lib,
  dockerTools,
  phpMigrate,
  phpMigrateLauncher,
  appRoot,
  imageLib,
  version,

  dataPath ? "/data",
}:

dockerTools.streamLayeredImage (
  imageLib.common
  // {
    name = "victual-migrate";

    contents = [
      # /opt/victual/php, for a Job that runs another bin/ command from this image: the
      # upgrade preflight, which the Helm chart's pre-upgrade hook runs.
      phpMigrateLauncher
      imageLib.passwd
      imageLib.certificates
    ];

    extraCommands = imageLib.scaffold ''
      # Read-only and empty, as in the app image. bin/victual-migrate creates
      # VICTUAL_DATAPATH when it is missing — for a SQLite database file, which a pgsql
      # deployment never has — so the directory existing here is what keeps that mkdir
      # from being attempted against a read-only root filesystem.
      mkdir -p .${dataPath}
    '';

    config = imageLib.commonConfig // {
      WorkingDir = appRoot;

      # No Entrypoint; see nix/images/app.nix for why the one that was here is gone.
      Cmd = [
        "${phpMigrate}/bin/php"
        "${appRoot}/bin/victual-migrate"
      ];

      Env = imageLib.commonConfig.Env ++ [
        "VICTUAL_DATAPATH=${dataPath}"
      ];

      Labels = imageLib.labels "migrate";
    };
  }
)
