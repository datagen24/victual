# What `nix flake check` proves.
#
# ADR-0010's open question 2 leans towards "start with the cheap greps — CI fails a
# Dockerfile with no USER" and adopting a real linter only when the cheap version misses
# something. These are the cheap greps, except that Nix lets them be assertions about
# the *artifact* rather than about the file that describes it: "the image config
# declares a non-root user" is a stronger statement than "the Dockerfile contains the
# string USER", and it costs the same.
#
# None of these boot a container. The checks that need a running instance — does the
# read-only root filesystem hold, does every page render, which extensions actually got
# loaded — are in plan 20's verification section, because they need a Linux host with a
# container runtime and this evaluates on a laptop.
{
  lib,
  runCommand,
  closureInfo,
  jq,

  php,
  app,
  appRoot,
  runtimeNginxConf,
  webroot,
  nginx,
  webcheckBin,
  runtime,
  imageLib,
  version,

  # ADR-0019 packaging spike (gate 1), disposable.
  labelWorkerRust,
}:

let
  # streamLayeredImage's passthru does not expose the config, so the assertion is made
  # against the same value the image was built from. That is weaker than reading it back
  # out of the artifact and honest about being so: it catches an edit that drops the
  # setting, not a bug in dockerTools.
  nonRootUser = "${toString imageLib.uid}:${toString imageLib.gid}";

  # A shell in a production image is the difference between "an attacker who reaches
  # code execution has a foothold" and "an attacker who reaches code execution has a
  # PHP process". Nixpkgs' PHP moves phpize and php-config — the only shell scripts it
  # ships — into the `dev` output, so the runtime closure should contain no shell at
  # all. Should. This is the check that says whether it does.
  forbiddenInRuntimeClosure = [
    "bash"
    "dash"
    "busybox"
    "zsh"
    "ksh"
    "toybox"
    "perl"
    "python3"
  ];
in
{
  # 1. Every image runs as uid 65532.
  image-runs-unprivileged =
    assert lib.assertMsg (imageLib.commonConfig.User == nonRootUser)
      "images/lib.nix must set config.User to a non-root uid:gid (ADR-0010 property 3)";
    assert lib.assertMsg (imageLib.uid != 0) "the image uid must not be 0";
    runCommand "victual-check-unprivileged" { } ''
      echo "image User = ${nonRootUser}" > "$out"
    '';

  # 2. No shell, no scripting runtime other than PHP, in what the serving images ship.
  #    The web tier is in these root paths too, and that is new as of 2026-09-04: it
  #    gained a probe binary (nix/webcheck.nix) because podman runs httpGet probes inside
  #    the container, and a tier that had held only nginx and static files now holds a
  #    program. Static linking is what keeps that free, and this is what says so — an
  #    accidental dynamic build would pull a libc and its shell into the one image whose
  #    argument is that it has neither.
  image-has-no-shell =
    runCommand "victual-check-no-shell"
      {
        closure = closureInfo {
          rootPaths = [
            php
            app
            nginx
            webcheckBin
          ];
        };
      }
      ''
        found=""
        for forbidden in ${lib.escapeShellArgs forbiddenInRuntimeClosure}; do
          if grep -qE "^/nix/store/[a-z0-9]{32}-$forbidden(-[0-9]|\$)" "$closure/store-paths"; then
            found="$found $forbidden"
          fi
        done

        if [ -n "$found" ]; then
          echo "The serving images' runtime closure contains:$found" >&2
          echo >&2
          echo "That means an image ships an interpreter the application never calls," >&2
          echo "which is exactly what docs/adr/0013-nix-built-container-images.md claims" >&2
          echo "it does not. Find the reference with:" >&2
          echo "  nix why-depends .#app <the offending store path>" >&2
          echo "  nix why-depends .#webcheckBin <the offending store path>" >&2
          exit 1
        fi

        cp "$closure/store-paths" "$out"
      '';

  # 2b. The label worker's image holds no shell either — but python3 *is* its runtime.
  #
  #     This is a finding of the packaging spike, not a relaxation. The list above is
  #     named `forbiddenInRuntimeClosure` and reads "no shell, no scripting runtime other
  #     than PHP", which conflates two different rules: ADR-0013 forbids a shell and a
  #     package manager in a production image, and the PHP clause is a statement about
  #     *those three images* rather than about every artifact this flake will ever build.
  #     A Python worker cannot satisfy the PHP-shaped version of the rule and still run,
  #     so the rule is stated per image: every image forbids a shell, and each names its
  #     own interpreter.
  #
  #     What this check therefore asserts about the worker is the part that is actually
  #     load-bearing: no bash, dash, busybox, zsh, ksh, toybox or perl reachable from the
  #     image's roots. `kubectl exec … sh` fails here for the same reason it fails in the
  #     other three.
  label-worker-image-has-no-shell =
    runCommand "victual-check-worker-no-shell"
      {
        closure = closureInfo { rootPaths = [ labelWorkerRust ]; };
      }
      ''
        found=""
        for forbidden in ${lib.escapeShellArgs forbiddenInRuntimeClosure}; do
          if grep -qE "^/nix/store/[a-z0-9]{32}-$forbidden(-[0-9]|\$)" "$closure/store-paths"; then
            found="$found $forbidden"
          fi
        done

        if [ -n "$found" ]; then
          echo "The label worker's runtime closure contains:$found" >&2
          echo "Find the reference with: nix why-depends .#labelWorker <store path>" >&2
          exit 1
        fi

        cp "$closure/store-paths" "$out"
      '';

  # 2c. The worker actually renders a label from the installed layout.
  #
  #     This is the check the packaging gate is really about: a TrueType font loaded out
  #     of package data, Pillow compositing, a QR from `qrcode`, and `brother_ql` turning
  #     the result into printer instructions — all from the built artifact rather than
  #     from a checkout. A closure missing any of those fails here instead of at a
  #     deployment in front of a printer.
  label-worker-renders-a-label =
    runCommand "victual-check-worker-render" { } ''
      export HOME="$PWD"
      ${lib.getExe labelWorkerRust} \
        --dir ${../.spike-renderer/contract} \
        --case c1_wrap \
        --fonts ${../.spike-renderer/fonts} \
        --out "$PWD/label.png" | tee "$out"

      test -s "$PWD/label.png"
      grep -q '"ok":true' "$out"
    '';

  # 3. The document root the web tier serves contains no PHP. The web image has no
  #    interpreter, so a .php file there could only ever be served as source.
  webroot-has-no-php = runCommand "victual-check-webroot" { } ''
    if find ${webroot} -name '*.php' -print | grep -q .; then
      echo "PHP files in the web tier's document root:" >&2
      find ${webroot} -name '*.php' -print >&2
      exit 1
    fi
    echo "no .php under the document root" > "$out"
  '';

  # 4. Everything the request path opens by absolute or __DIR__-relative path is in the
  #    application root. This list is not decoration: every entry is a file some part of
  #    the tree reads at runtime, and three of them were missing from the first version
  #    of nix/source.nix.
  #
  #    victual.openapi.json — BaseApiController::GetOpenApispec() and UserfieldsService.
  #      Absent, every generic entity request answers 500.
  #    migrations/, db/ — DatabaseMigrationService::GetMigrationFiles() opens a
  #      FilesystemIterator that throws on a missing directory, and
  #      PostgresDialect::GetBaselineSchemaPath resolves to db/pgsql/baseline. Since plan
  #      10, SchemaVersionMiddleware enumerates the corpus on every request.
  #    viewcache/ — baked by nix/app.nix so the directory can be read-only.
  app-is-complete = runCommand "victual-check-app" { } ''
    for required in \
      public/index.php \
      app.php \
      config-dist.php \
      version.json \
      victual.openapi.json \
      packages/autoload.php \
      views/layout/default.blade.php \
      migrations \
      db/pgsql/baseline \
      bin/victual-migrate \
      viewcache
    do
      if [ ! -e "${appRoot}/$required" ]; then
        echo "missing from the application root: $required" >&2
        echo "see nix/source.nix's allowlist" >&2
        exit 1
      fi
    done

    echo ok > "$out"
  '';

  # 5. The view cache was actually warmed, and warmed for the path it will be served
  #    from. Blade names a compiled file after a hash of the absolute path of its source
  #    directory, so a cache warmed elsewhere is a 500 on every page against a read-only
  #    directory — the warmer's own comment calls this load-bearing. Baking it inside the
  #    application derivation is what makes the two paths the same by construction; this
  #    check is what notices if that ever stops being true.
  viewcache-is-warm = runCommand "victual-check-viewcache" { } ''
    # Counted rather than hardcoded. A partial is compiled separately from the page that
    # includes it, so the number is "every .blade.php under views/", and it moves whenever
    # anyone adds or removes one — plan 12 changed it from 96 to 97 while this branch was
    # open. A literal here would have been a check that silently stopped meaning anything.
    templates=$(find ${appRoot}/views -name '*.blade.php' | wc -l)
    compiled=$(find ${appRoot}/viewcache -name '*.php' -not -name 'route_cache*' | wc -l)
    echo "templates: $templates, compiled: $compiled"

    if [ "$compiled" -lt "$templates" ]; then
      echo "the baked view cache has $compiled compiled templates for $templates sources" >&2
      echo "a template the warmer missed is a 500 the first time somebody opens that page" >&2
      exit 1
    fi

    if ! find ${appRoot}/viewcache -name 'route_cache*.php' | grep -q .; then
      echo "no route cache in the baked directory" >&2
      exit 1
    fi

    echo "$compiled" > "$out"
  '';

  # 6. The application does not require config.php to exist.
  #
  #    This replaces a check that asserted the opposite arrangement was wired up
  #    correctly — that the entrypoint's seed file was really in the image, because the
  #    first version of this tree declared it and never installed it, and the migrate
  #    initContainer exited 1 on a fresh data directory, which kept the whole pod from
  #    starting.
  #
  #    Issue #49 was the same failure from the other end: podman does not honour fsGroup
  #    for emptyDir volumes, so the seed had nowhere writable to land and the pod did not
  #    start on a laptop even though the seed layer was present and correct. The fix was
  #    to stop needing the file at all, which deleted the entrypoint, the seed, the
  #    writable mount and the pcntl extension together.
  #
  #    So this asserts the property that replaced them: app.php reads config.php only if
  #    it is there. An unguarded `require_once` would reintroduce the need for a writable
  #    data directory, and the failure would appear as a pod that will not start rather
  #    than as anything naming this file.
  config-php-is-optional = runCommand "victual-check-config-optional" { } ''
    app=${appRoot}/app.php

    if ! grep -q "if (file_exists(VICTUAL_DATAPATH . '/config.php'))" "$app"; then
      echo "app.php no longer guards its config.php require with file_exists()." >&2
      echo >&2
      echo "The images ship no config.php and mount no writable data directory, so an" >&2
      echo "unconditional require means every container fails to start. See issue #49" >&2
      echo "and docs/adr/0013-nix-built-container-images.md." >&2
      exit 1
    fi

    # Column 0 only: the guarded require above is indented inside the if, and an
    # unanchored pattern matches it and fails a correct file. Found by this check
    # rejecting the very change it was written for.
    if grep -qE '^require_once VICTUAL_DATAPATH' "$app"; then
      echo "app.php requires config.php unconditionally at the top level." >&2
      exit 1
    fi

    echo ok > "$out"
  '';

  # 7. The web tier does not drag the application in behind it.
  #
  #    nginx has to name the file php-fpm should execute, and that name is a store path in
  #    the *other* container. Interpolating it normally would make the application a
  #    dependency of the nginx configuration and therefore of the web image — every PHP
  #    file, in the tier whose whole purpose is not to have any. nix/runtime/nginx-conf.nix
  #    discards the string context to keep the text and drop the edge, and that is exactly
  #    the sort of thing that regresses the next time somebody edits the file.
  web-tier-carries-no-application =
    runCommand "victual-check-web-closure"
      {
        closure = closureInfo { rootPaths = [ runtimeNginxConf webroot ]; };
      }
      ''
        if grep -qF "${app}" "$closure/store-paths"; then
          echo "the web tier's closure contains the application:" >&2
          echo "  ${app}" >&2
          echo >&2
          echo "SCRIPT_FILENAME in nix/runtime/nginx-conf.nix has to be wrapped in" >&2
          echo "builtins.unsafeDiscardStringContext, or the web image ships every PHP" >&2
          echo "file it exists in order not to have." >&2
          exit 1
        fi

        cp "$closure/store-paths" "$out"
      '';

  # 8. The image tag and the version the API reports are the same string. A deployment
  #    whose tag and /api/system/info disagree is one nobody can reason about.
  version-matches-the-application = runCommand "victual-check-version" { } ''
    reported=$(${jq}/bin/jq -r .Version ${appRoot}/version.json)
    if [ "$reported" != "${version}" ]; then
      echo "image tag ${version} does not match version.json ($reported)" >&2
      exit 1
    fi
    echo "${version}" > "$out"
  '';
}
