# The application tree, its Composer dependencies, and its baked view cache.
#
# `buildComposerProject2` splits the dependency half in two: a fixed-output derivation
# that fetches the graph named by composer.lock (the only step allowed to touch the
# network, and the only one with a hash in nix/hashes.nix), and an ordinary derivation
# that assembles the tree. Two of the sixty-one locked packages are VCS forks —
# berrnd/lessql and berrnd/php-gettext — and both are pinned to a commit with a GitHub
# zipball dist in composer.lock, so the fetch is content-addressed like every other one.
#
# The view cache is warmed here, in the same derivation, and that placement is not a
# convenience. `bin/victual-warm-cache` compiles Blade templates whose **compiled file
# names are a hash of the absolute path of the views directory** — the warmer's own
# comment says so, and calls it load-bearing. Warm at one path and serve from another and
# every compiled name is wrong, so on a read-only cache directory every page is a 500.
# Warming inside the derivation that owns the tree means the path the warmer sees and the
# path php-fpm serves from are the same store path by construction, rather than by
# somebody remembering to keep two directories in step.
#
# That constraint is also why there is no `/app` any more: the application is served from
# its store path. See nix/README.md.
{
  lib,
  php,
  hashes,
  sources,
  version,

  # Baked into the route cache's file name, exactly as it is in the Dockerfile's build
  # arg. `CachePaths::RouteCacheFile()` fingerprints routes.php plus the base path, so an
  # image built with one base path and deployed under another does not misroute — Slim
  # refuses to start, naming the cache directory. Serving under a sub-path is therefore a
  # rebuild, not an environment variable.
  basePath ? "",
}:

php.buildComposerProject2 (finalAttrs: {
  pname = "victual";
  inherit version;

  src = sources.toSource sources.application;

  vendorHash = hashes.composerVendor;

  # composer.json describes an application, not a library: it has no name, description
  # or license field, and `composer validate --strict` fails on all three. Validating it
  # would be checking a property this repository has never claimed to have.
  composerStrictValidation = false;

  # require-dev is phpunit/php-code-coverage, which exists for the differential suite's
  # coverage mode and has no business in a production image.
  composerNoDev = true;
  composerNoScripts = true;
  composerNoPlugins = true;

  # The vendor tree is a fixed-output derivation, so its hash covers everything Composer
  # writes into it - including composer/installed.php's record of the root package's
  # version, which nixpkgs' vendor hook sets from this derivation's `version`. Two reasons
  # not to let it. Composer's parser refuses a string like `0.1.0-MVP` (`MVP` is not a
  # stability suffix it knows), and the image tag needs the string exactly as version.json
  # spells it. And a root version that moves on every release would move the vendor hash
  # with it, making hashes.nix's "changes only when composer.lock changes" untrue. So the
  # root version Composer records is pinned to Composer's own sentinel for "not set" -
  # nothing in Victual reads it - and the hash depends on composer.lock alone. postConfigure
  # runs after the hook that exported the derivation's version, and before the install.
  # The project derivation's own install hook does the same export, so both get it.
  postConfigure = ''
    export COMPOSER_ROOT_VERSION="1.0.0+no-version-set"
  '';
  composerVendor = php.mkComposerVendor {
    inherit (finalAttrs)
      pname
      src
      vendorHash
      version
      composerNoDev
      composerNoScripts
      composerNoPlugins
      composerStrictValidation
      ;
    inherit php;
    postConfigure = ''
      export COMPOSER_ROOT_VERSION="1.0.0+no-version-set"
    '';
  };

  # Runs after the install hook has copied the tree into $out, and before fixup, so the
  # directory is still writable and already at its final path.
  postInstall = ''
    root="$out/share/php/victual"

    # The warmer takes its configuration from the environment the same way the
    # application does. config.php is optional here by design — at build time the
    # configuration is not in the image at all, and only these two settings matter.
    export VICTUAL_VIEWCACHE_PATH="$root/viewcache"
    export VICTUAL_BASE_PATH="${basePath}"

    # It defaults VICTUAL_DATAPATH to ../data and only reads it to look for an optional
    # config.php; pointing it at a scratch directory keeps it from touching the source.
    export VICTUAL_DATAPATH="$TMPDIR/warm-datapath"
    mkdir -p "$VICTUAL_DATAPATH"

    # It exits non-zero when a template fails to compile, which is the point: on a
    # read-only cache directory a template the warmer missed is a 500 at the moment
    # somebody opens that page. Failing the build is the same information, delivered when
    # it can still be acted on.
    php "$root/bin/victual-warm-cache" --quiet

    # Nothing writes here at runtime.
    chmod -R a-w "$VICTUAL_VIEWCACHE_PATH"
  '';

  passthru = {
    # Where the application root actually is, so that nothing else has to spell out the
    # buildComposerProject2 layout.
    root = "${finalAttrs.finalPackage}/share/php/victual";
  };

  meta = {
    description = "Victual application tree, Composer dependencies and baked view cache";
    homepage = "https://github.com/datagen24/victual";
    license = lib.licenses.mit;
    platforms = lib.platforms.all;
  };
})
