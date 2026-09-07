# The one overlay this flake exports. Everything the flake builds hangs off
# `pkgs.victual`, so a consumer who wants one piece — the PHP build, say, or the
# webroot — gets it by adding this overlay rather than by importing files by path.
final: prev:

let
  inherit (final) lib;

  # The image tag and the derivation versions come from the same place the application
  # reports at /api/system/info. One version, one source.
  version = (builtins.fromJSON (builtins.readFile ../version.json)).Version;

  hashes = import ./hashes.nix;
  sources = import ./source.nix { inherit lib; };
in
{
  # **The third edge that put a shell in the app image's closure**, and the only one that
  # is not this fork's doing. `nix flake check`'s no-shell assertion traced it to
  # php-gd → gd → libavif → bash, where the referrer inside libavif is
  # `libexec/gdk-pixbuf-thumbnailer-avif`: a wrapper script for a desktop file manager's
  # thumbnailer, in a library that PHP's gd links for its `.so` alone. Nothing in a
  # container renders a thumbnail for a file browser.
  #
  # It is removed rather than allowed for, because allowing for it means editing
  # `nix/checks.nix` to permit a shell — and that check exists to make ADR-0013's "no
  # shell and no package manager" a property of the artifact rather than a sentence in a
  # record. `gdk-pixbuf = null` is not an option (the derivation dereferences it), so the
  # files go after the install. gd and php-gd rebuild from source as a result; libavif's
  # shared library, which is the only part gd uses, is untouched.
  libavif = prev.libavif.overrideAttrs (old: {
    postInstall = (old.postInstall or "") + ''
      rm -rf "$out/libexec" "$out/share/thumbnailers"
    '';
  });

  # …and the fourth, which the libavif fix uncovered: gd → libxpm → gzip → bash. X11
  # pixmap support drags in an X library, which drags in gzip, which is a shell script.
  #
  # `withXorg = false` is not a trim for its own sake — it is what the `Dockerfile`'s
  # production stage has always done, one line up from here in the same repository:
  # `docker-php-ext-configure gd --with-freetype --with-jpeg`, and nothing else. The two
  # images disagreed about what gd needs and only one of them said so out loud. This makes
  # the Nix build match the Dockerfile rather than the other way round, because the
  # Dockerfile's narrower list is the one that has been serving the application.
  gd = prev.gd.override { withXorg = false; };

  victual = lib.makeScope final.newScope (self: {
    inherit version hashes sources;

    # PHP 8.5 with exactly the extensions the application asks for and nothing else.
    # The serving images get no pdo_sqlite: plan 10 made the prerequisite check
    # driver-aware, so a PostgreSQL deployment no longer loads it to satisfy a check.
    php = self.callPackage ./php.nix { };

    # The migrate image's interpreter. bin/victual-db-import reads SQLite as an import
    # format, which is the one thing ADR-0008 keeps it for.
    phpMigrate = self.php.override { withSqlite = true; };

    # The application tree with its Composer dependencies resolved.
    app = self.callPackage ./app.nix { };

    # public/packages — the yarn-installed frontend libraries the Blade layout links.
    frontend = self.callPackage ./frontend.nix { };

    # What the web tier serves: the static half of public/, plus the frontend packages.
    webroot = self.callPackage ./webroot.nix { };

    # Where the application root is inside every image. It is a store path rather than a
    # chosen name like /app, because the baked view cache's compiled file names hash the
    # absolute path of the views directory — so the path the warmer saw and the path
    # php-fpm serves from have to be the same one, and a store path is the only way to
    # get that by construction. See nix/app.nix.
    appRoot = self.app.root;

    # Runtime configuration files, generated rather than hand-maintained so that a
    # change to a path is a change in one place.
    runtime = {
      # php-ini.nix is a bare string rather than a derivation: it is baked into the PHP
      # build (nix/php.nix) so that every SAPI in every image reads the same settings.
      fpmConf = self.callPackage ./runtime/fpm-conf.nix { };
      nginxConf = self.callPackage ./runtime/nginx-conf.nix { };
      healthcheck = ./runtime/healthcheck.php;
    };

    # /opt/victual/healthcheck — what the pod manifest's exec probe runs. See the file.
    healthcheckBin = self.callPackage ./healthcheck.nix { };

    # /opt/victual/webcheck — the same thing for the nginx tier, statically linked so it
    # brings no libc into an image that holds no interpreter.
    webcheckBin = self.callPackage ./webcheck.nix { };

    imageLib = self.callPackage ./images/lib.nix { };

    # --- ADR-0019 packaging spike (gate 1), disposable ---------------------------
    # The driver nixpkgs does not carry, the worker built from its pinned revision, and
    # the image built through the same imageLib as the other three.
    # CPython with no shell in its closure — spike 1's blocker, removed rather than waived.
    pythonNoShell = self.callPackage ./worker/python-no-shell.nix { };

    brother-ql-inventree = self.callPackage ./worker/brother-ql-inventree.nix {
      inherit (final) makeBinaryWrapper;
      inherit (self.pythonNoShell.pkgs)
        buildPythonPackage
        fetchPypi
        setuptools
        click
        packbits
        pillow
        pyusb
        attrs
        ;
    };

    labelWorker = self.callPackage ./worker/package.nix {
      workerSource = final.victualLabelWorkerSource;
      python3Packages = self.pythonNoShell.pkgs;
      inherit (final) makeBinaryWrapper;
    };

    image-label-worker = self.callPackage ./images/worker.nix { };

    image-app = self.callPackage ./images/app.nix { };
    image-web = self.callPackage ./images/web.nix { };
    image-migrate = self.callPackage ./images/migrate.nix { };

    loadImages = self.callPackage ./images/load.nix { };

    checks = self.callPackage ./checks.nix {
      # Passed explicitly: `runtime` is a plain attrset rather than a scope, so
      # callPackage cannot resolve `runtime.nginxConf` on its own.
      runtimeNginxConf = self.runtime.nginxConf;
    };
  });
}
