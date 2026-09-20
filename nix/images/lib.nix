# Plumbing every image shares.
#
# The properties ADR-0010 asks of a workload that an *image* can carry — non-root, and
# nothing in it that the process does not need — are set here once rather than in three
# places where two of them can drift.
{
  lib,
  dockerTools,
  version,
}:

rec {
  # 65532 is the distroless "nonroot" convention. Picking a number everyone else already
  # picked means a shared volume between this image and any other nonroot image works
  # without an argument about ownership.
  uid = 65532;
  gid = 65532;
  userName = "victual";

  # /etc/passwd, /etc/group and /etc/nsswitch.conf. Without them getpwuid(65532) fails,
  # and the failure surfaces somewhere unrelated — nginx refusing to start, PHP
  # reporting no home directory — rather than as "this image has no users".
  passwd = dockerTools.fakeNss.override {
    extraPasswdLines = [
      "${userName}:x:${toString uid}:${toString gid}:${userName}:/nonexistent:/noshell"
    ];
    extraGroupLines = [
      "${userName}:!:${toString gid}:"
    ];
  };

  # TLS roots, for outbound HTTPS: the barcode-lookup plugins, and libpq when
  # DB_SSLMODE asks for verification.
  certificates = dockerTools.caCertificates;

  # Labels are how an operator answers "what is this and where did it come from"
  # without a shell to run inside it. That question gets asked at the worst possible
  # moment, so the answer is baked in.
  labels = component: {
    "org.opencontainers.image.title" = "victual-${component}";
    "org.opencontainers.image.version" = version;
    "org.opencontainers.image.source" = "https://github.com/datagen24/victual";
    "org.opencontainers.image.licenses" = "MIT";
    "org.opencontainers.image.description" =
      "Victual ${component} — built with Nix, see docs/adr/0013-nix-built-container-images.md";
    "org.opencontainers.image.base.name" = "scratch";
  };

  # Directories the runtime needs to exist before anything mounts over them. /tmp is
  # 1777 because it is a tmpfs in every deployment shape and something has to declare
  # the mode; the rest are mount points that would otherwise be created by the runtime
  # with ownership nobody chose.
  scaffold = extra: ''
    mkdir -p tmp
    chmod 1777 tmp
    ${extra}
  '';

  # Applied to every image. `maxLayers` well under Docker's 125 leaves room for the
  # store paths to land one-per-layer, which is what makes a rebuild after a code change
  # push only the changed layer.
  common = {
    tag = version;
    maxLayers = 64;
    # A reproducible timestamp. `created = "now"` would make the image id change on
    # every build even when nothing else did, which defeats the point.
    created = "1970-01-01T00:00:01Z";
  };

  # The half of the OCI config that is the same everywhere.
  #
  # `WorkingDir` is deliberately **not** here, and was until 2026-09-20. It read
  # `WorkingDir = "/app"`, which was never the same everywhere: app.nix and migrate.nix
  # set their own `appRoot` and web.nix overrides it to /tmp, so the default applied to
  # exactly the three images built from a single binary on no base image -- label-worker,
  # label-renderer and mcp -- none of which contain an /app for it to name. Kubernetes
  # creates a missing working directory and the defect stayed invisible; podman validates
  # and refuses, with `starting container ...: workdir "/app" does not exist on
  # container`, which is how it was found. Every image now states its own.
  commonConfig = {
    User = "${toString uid}:${toString gid}";
    # No PATH: every Entrypoint below names absolute store paths, and an image with no
    # shell has nothing to look up. TMPDIR is set because PHP and nginx both want
    # somewhere to put a temporary file and the answer must be the one writable mount.
    Env = [ "TMPDIR=/tmp" ];
  };
}
