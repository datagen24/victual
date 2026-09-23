# The PHP the images run.
#
# `buildEnv`'s `extensions` argument *replaces* nixpkgs' default extension set rather
# than adding to it, which is the point: the stock build enables a couple of dozen
# extensions, and every one of them is code in the address space of a process that holds
# the database owner's credentials. The list below is derived from what the tree
# actually calls — helpers/PrerequisiteChecker.php names ten of them, and the rest carry
# their caller in the comment beside them.
#
# Trimming further is a measurement, not a guess, and it was measured on 2026-09-18 (plan 20
# piece 2, issue #133): the image was booted, `.devtools/nix/walk.py` walked every page and
# the API, and the extensions the walk did not need were dropped from a copy of the ini one
# group at a time. The list below is what survived, and each entry below the required ones
# carries its caller. Two came out — zip and xmlwriter — and both had been listed on a
# caller that did not exist; see the comments where they were.
{
  lib,
  php85,

  # `PrerequisiteChecker::checkDatabaseRequirements()` became driver-aware when plan 10
  # landed, so a PostgreSQL deployment no longer loads pdo_sqlite on every request just
  # to satisfy a check. Only the migrate image needs it, for bin/victual-db-import, which
  # ADR-0008 keeps SQLite around for.
  withSqlite ? false,
}:

# **The PEAR scripts are removed, and that is a conformance fix rather than a size one.**
# The first `nix flake check` failed check 2 — "the app image's runtime closure contains:
# bash" — and `nix why-depends` put the edge at php-with-extensions → php → bash. The
# referrers inside the PHP store path are `bin/pear`, `bin/peardev` and `bin/pecl`: shell
# scripts, so bash, and a package manager, which ADR-0013's decision says these images do
# not have. The header comment below used to assume nixpkgs moved PHP's only shell scripts
# into the `dev` output; that is true of phpize and php-config and not of these three.
#
# **`pearSupport = false` is the obvious lever and it is the wrong one.** nixpkgs'
# php/generic.nix adds `libxml2.dev` to PHP's build inputs *only when pearSupport is true*,
# so turning it off silently builds a PHP without libxml2 — and dom, simplexml and
# xmlwriter, which ezyang/htmlpurifier and gettext/gettext need, are then built against
# something else. It was tried here and simplexml failed its own test suite. Deleting the
# three scripts after the install keeps libxml2 and removes exactly what pulled bash in.
#
# The cost is that PHP and every extension are built from source rather than substituted
# from cache.nixos.org, because the derivation is no longer the one Hydra built. That is
# minutes on a laptop and a cached layer thereafter.
#
# **Two edges pulled bash in, not one.** With the PEAR scripts gone the check failed again,
# and `why-depends` named the second: php → system-sendmail → bash. nixpkgs' generic.nix
# appends `PROG_SENDMAIL=${system-sendmail}/bin/sendmail` to PHP's configure flags, and
# that store path is a shell script. Nothing in this tree sends mail — there is no `mail(`
# anywhere under controllers/, services/, helpers/ or middleware/, and no mailer in
# composer.json — so the flag is rewritten to a plain path, which is a string rather than a
# reference and therefore contributes nothing to the closure. If a mail feature ever
# arrives, this is where it has to be reconsidered rather than silently worked around.
let
  phpWithoutShell = php85.unwrapped.overrideAttrs (old: {
    configureFlags =
      (builtins.filter (
        flag: !(lib.hasPrefix "PROG_SENDMAIL=" (toString flag))
      ) (old.configureFlags or [ ]))
      ++ [ "PROG_SENDMAIL=/bin/sendmail" ];

    postInstall = (old.postInstall or "") + ''
      rm -f "$out"/bin/pear "$out"/bin/peardev "$out"/bin/pecl
    '';
  });
in
# `.passthru.buildEnv`, not `.buildEnv`, and the difference is not cosmetic. nixpkgs'
# php/generic.nix implements `overrideAttrs` as
# `phpOverridden // { passthru = phpOverridden.passthru // { buildEnv = <rebuilt>; }; }`,
# which replaces the *passthru* entry while the top-level `buildEnv` attribute is still
# the one stdenv merged from the original arguments. Calling `.buildEnv` on an overridden
# PHP therefore silently builds the un-overridden one: the first attempt at this fix
# produced a byte-identical derivation and a check that failed for the third time with the
# same store path, which is what led here.
phpWithoutShell.passthru.buildEnv {
  extensions =
    { all, ... }:
    with all;
    [
      # --- PrerequisiteChecker::REQUIRED_PHP_EXTENSIONS -------------------------------
      ctype
      fileinfo
      filter
      gd # also gumlet/php-image-resize, for userpicture handling
      iconv
      intl
      mbstring
      tokenizer
      zlib
      # json is compiled in unconditionally since PHP 8.0 and has no extension attribute.
      # session is *not* here: nothing in the fork's own tree calls session_start(), and
      # the point of this list is that an extension needs a caller. If something under
      # packages/ turns out to want it, that is a plan 20 Q4 finding and this is where
      # the line goes back.

      # --- What the deployment actually talks to --------------------------------------
      pdo
      pdo_pgsql

      # --- Traced to a caller ---------------------------------------------------------
      # Each was also dropped in turn on 2026-09-18 and the consequence measured, so "kept"
      # below means "removing it breaks something a request can reach", not "somebody once
      # thought it was needed".
      curl # guzzlehttp/guzzle picks its cURL handler when this is loaded: the barcode
      # lookup (plugins/OpenFoodFactsBarcodeLookupPlugin.php), StockService and the InfluxDB
      # writer (services/Influx/InfluxEventWriter.php). HTTPS for all of them is libcurl's
      # own TLS.
      dom # ezyang/htmlpurifier's Lexer::create() chooses DOMLex when DOMDocument exists and
      # falls back to DirectLex when it does not. Every API write that carries rich text
      # goes through it, and a sanitiser on that boundary should run the lexer it is
      # developed and tested against rather than its fallback.
      simplexml # slim/slim's BodyParsingMiddleware and slim/http's ServerRequest both call
      # simplexml_load_string() for an XML request body, and slim/http declares ext-simplexml.
      # Measured without it: an `application/xml` POST answers 500 "Call to undefined
      # function simplexml_load_string()" where it should be a 4xx the client can act on.
      openssl # php-mqtt/client wraps the broker connection in a tls:// stream when
      # MQTT_TLS is set (services/Mqtt/MqttPublisher.php), and PHP has no tls transport
      # without this — measured: "Unable to find the socket transport "tls"". This is not
      # what Guzzle's HTTPS or libpq's DB_SSLMODE use: libcurl and libpq bring their own
      # OpenSSL, so the comment that stood here said two things this does not do.
      # zip was here, on "mike42/escpos-php, gettext/gettext". Neither requires it — escpos
      # asks for intl, json and zlib; gettext for nothing of the kind — and nothing under
      # controllers/, services/, helpers/, middleware/ or plugins/ names ZipArchive. Dropped
      # 2026-09-18; the walk was unchanged.
      # xmlwriter was here on "ezyang/htmlpurifier, gettext/gettext". The only runtime-tree
      # user is HTMLPurifier_ConfigSchema_Builder_Xml, a maintenance script that builds the
      # purifier's schema and is not on any request path. Dropped 2026-09-18, likewise.
      # pcntl was here for nix/runtime/entrypoint.php, which pcntl_exec'd php-fpm after
      # seeding config.php. Both are gone (issue #49), and with them the only caller —
      # so the extension goes too, and disable_functions moves from the fpm pool to
      # php.ini, where it now covers the migrate image's CLI as well.
      # opcache is deliberately *not* here, and this is the first build's finding rather
      # than a preference: nixpkgs has no `opcache` extension attribute, because it
      # compiles Zend OPcache into php85 itself. Listing it failed evaluation with
      # "undefined variable 'opcache'". `php -m` on a buildEnv naming none of it reports
      # "Zend OPcache" in both the module and the Zend module list, so
      # runtime/php-ini.nix's opcache.* block applies exactly as written.
    ]
    ++ lib.optional withSqlite pdo_sqlite;

  # Appended to the generated extension-loading ini, so it applies to php-fpm and to the
  # CLI alike — no `-c` flag to remember, and no image whose PHP behaves differently
  # depending on how it was invoked.
  extraConfig = import ./runtime/php-ini.nix;
}
