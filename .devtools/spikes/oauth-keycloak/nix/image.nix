# Keycloak 26.8.1 as an ADR-0013-style image, for ADR-0055 prerequisite 3. Spike only: it is not
# part of the flake, and nix/images/ is untouched. It pins the same nixpkgs as flake.lock and
# follows nix/images/lib.nix by hand: uid 65532, no base image, no shell, read-only root.
#
#   nix-build image.nix -A image -o result && ./result | podman load    (inside the nix builder; see README)
#   nix-build image.nix -A keycloak / -A jre                             the two runtime store paths, for closure checks
#
# Three choices, each against what nixpkgs' keycloak package does by default:
#   - `kc.sh build` runs inside the derivation with the production build options (confFile), so
#     the image starts with `start --optimized` and never writes to its install tree.
#   - bin/ (kc.sh, kcadm.sh: bash scripts) is deleted and the entrypoint is the java command line
#     kc.sh would exec, so bash is not in the closure.
#   - data/ is a store symlink to /var/lib/keycloak/data, a mount point the runtime makes
#     writable (a tmpfs), because Keycloak writes its temporary files under the home directory.
{
  pkgs ? import (builtins.fetchTarball {
    # nixpkgs as flake.lock pins it (nixos-unstable, rev a5fc1be5).
    url = "https://github.com/NixOS/nixpkgs/archive/a5fc1be5b6c2d0c934143b57363aef9a15cce508.tar.gz";
    sha256 = "sha256-fmNFBoWUCUJdycc4kB+pMSXhnOOdeEDaxqfjzyyilK8=";
  }) { },
}:

let
  uid = 65532;
  gid = 65532;
  jre = pkgs.jre_headless;
  # Build-time options; runtime ones (database URL, credentials, hostname, cache stack) are
  # environment variables, as with the upstream image.
  conf = pkgs.writeText "keycloak.conf" ''
    db=postgres
    health-enabled=true
    cache=ispn
  '';
  keycloak = (pkgs.keycloak.override { confFile = conf; }).overrideAttrs (old: {
    postInstall = (old.postInstall or "") + ''
      rm -rf $out/bin
      rm -rf $out/data
      ln -s /var/lib/keycloak/data $out/data
    '';
    postFixup = ""; # it wrapped the bin/ scripts, which are gone
  });
  passwd = pkgs.dockerTools.fakeNss.override {
    extraPasswdLines = [ "keycloak:x:${toString uid}:${toString gid}:keycloak:/nonexistent:/noshell" ];
    extraGroupLines = [ "keycloak:!:${toString gid}:" ];
  };
  # The command kc.sh 26.8.1 execs for `start --optimized` (its JAVA_OPTS, JAVA_ADD_OPENS,
  # JAVA_LOCALE, SERVER_OPTS and the container heap defaults), written out.
  entrypoint = [
    "${jre}/bin/java"
    "-Dkc.config.built=true"
    "-Djava.util.concurrent.ForkJoinPool.common.threadFactory=io.quarkus.bootstrap.forkjoin.QuarkusForkJoinWorkerThreadFactory"
    "-XX:MetaspaceSize=96M" "-XX:MaxMetaspaceSize=256m" "-Dfile.encoding=UTF-8"
    "-Dsun.stdout.encoding=UTF-8" "-Dsun.err.encoding=UTF-8" "-Dstdout.encoding=UTF-8" "-Dstderr.encoding=UTF-8"
    "-XX:+ExitOnOutOfMemoryError" "-Djava.security.egd=file:/dev/urandom" "-XX:+UseG1GC"
    "-XX:FlightRecorderOptions=stackdepth=512" "-Djdk.tls.rejectClientInitiatedRenegotiation=true"
    "-XX:MaxRAMPercentage=70" "-XX:MinRAMPercentage=70" "-XX:InitialRAMPercentage=50"
    "--add-opens=java.base/java.util=ALL-UNNAMED" "--add-opens=java.base/java.util.concurrent=ALL-UNNAMED"
    "--add-opens=java.base/java.security=ALL-UNNAMED" "--add-opens=java.base/java.lang=ALL-UNNAMED"
    "--enable-native-access=ALL-UNNAMED" "-Duser.language=en" "-Duser.country=US"
    "-Djava.io.tmpdir=/tmp"
    "-Dkc.home.dir=${keycloak}" "-Djboss.server.config.dir=${keycloak}/conf"
    "-Djava.util.logging.manager=org.jboss.logmanager.LogManager" "-Dpicocli.disable.closures=true"
    "-Dquarkus-log-max-startup-records=10000"
    "-cp" "${keycloak}/lib/quarkus-run.jar" "io.quarkus.bootstrap.runner.QuarkusEntryPoint"
    "start" "--optimized"
  ];
in
{
  inherit keycloak jre;
  image = pkgs.dockerTools.streamLayeredImage {
    name = "localhost/victual-spike-keycloak-nix";
    tag = keycloak.version;
    maxLayers = 64;
    created = "1970-01-01T00:00:01Z";
    contents = [ passwd pkgs.dockerTools.caCertificates ];
    extraCommands = ''
      mkdir -p tmp var/lib/keycloak/data
      chmod 1777 tmp
    '';
    config = {
      User = "${toString uid}:${toString gid}";
      Entrypoint = entrypoint;
      WorkingDir = "/tmp";
      ExposedPorts = { "8080/tcp" = { }; "9000/tcp" = { }; };
      Labels = {
        "org.opencontainers.image.title" = "victual-spike-keycloak";
        "org.opencontainers.image.version" = keycloak.version;
        "org.opencontainers.image.licenses" = "Apache-2.0";
        "org.opencontainers.image.base.name" = "scratch";
      };
    };
  };
}
