# The application tier: php-fpm and nothing else.
#
# It listens on loopback for FastCGI and never speaks HTTP. That is not a stylistic
# choice — it is what lets the web tier hold the document root and the app tier hold the
# database credential, with neither able to do the other's job.
#
# What is in the image: the PHP interpreter with the extensions nix/php.nix names, the
# application and its baked view cache at their store path, /etc/passwd for uid 65532, and
# CA roots. There is no shell, no package manager, no composer, no git. `kubectl exec … sh`
# will not work here, which is the intended cost — see
# docs/adr/0013-nix-built-container-images.md, "Consequences".
#
# Note how the application gets into the image: not through `contents`, which would
# splice its `share/` into `/`, but through the store path named in `Env` below.
# streamLayeredImage takes its closure roots from the image config as well as from
# `contents`, so naming the view cache is enough to pull the whole tree in.
{
  lib,
  dockerTools,
  php,
  app,
  appRoot,
  healthcheckBin,
  phpLauncher,
  imageLib,
  runtime,
  version,

  dataPath ? "/data",
  fpmPort ? 9000,
}:

dockerTools.streamLayeredImage (
  imageLib.common
  // {
    name = "victual-app";

    contents = [
      healthcheckBin
      phpLauncher
      imageLib.passwd
      imageLib.certificates
    ];

    extraCommands = imageLib.scaffold ''
      # A read-only mount point, and empty on purpose. Nothing writes here: config.php is
      # optional (app.php), the view cache is baked into the store path below, and
      # FILE_STORAGE=database keeps uploads out of the filesystem. It exists so that a
      # deployment which *wants* to supply a config.php or a settingoverrides directory
      # has somewhere to mount one — see deploy/README.md.
      mkdir -p .${dataPath}
    '';

    config = imageLib.commonConfig // {
      WorkingDir = appRoot;

      # No Entrypoint. There used to be one — nix/runtime/entrypoint.php — whose whole
      # job was seeding a config.php into the data directory and then pcntl_exec-ing this
      # command. The application no longer requires that file, so the process below is
      # PID 1 directly: no wrapper in the address space, no seed layer, no writable data
      # directory, and no pcntl in the build. See issue #49.
      Cmd = [
        "${php}/bin/php-fpm"
        "--nodaemonize"
        "--fpm-config"
        "${runtime.fpmConf}"
      ];

      Env = imageLib.commonConfig.Env ++ [
        "VICTUAL_DATAPATH=${dataPath}"

        # Baked at build time by nix/app.nix, read-only, and the reason this image needs
        # no writable path outside ${dataPath}. This is also the reference that puts the
        # application closure into the image.
        "VICTUAL_VIEWCACHE_PATH=${app}/share/php/victual/viewcache"
      ];

      ExposedPorts = {
        "${toString fpmPort}/tcp" = { };
      };

      # php-fpm treats SIGQUIT as "finish the requests you have, then stop" and SIGTERM
      # as "stop now". Docker and Podman honour this field. Kubernetes does not read it
      # — it sends SIGTERM unless the pod sets `lifecycle.stopSignal` — so the manifest
      # has to say the same thing again; deploy/README.md says so.
      StopSignal = "SIGQUIT";

      Labels = imageLib.labels "app";
    };
  }
)
