# The MCP sidecar — a TypeScript/Node package, built the same way every future
# non-PHP workload in this family is supposed to be: `buildNpmPackage` away from an
# image with the shared uid, labels, empty `/bin` and checks. See
# docs/adr/0013-nix-built-container-images.md, "New workloads are born into this."
#
# Unlike the label renderer and worker, this workload's source lives in this
# repository (mcp/) rather than a pinned external one — see mcp/README.md and
# docs/mcp-interface-spec.md's Open Question 1 amendment (2026-09-19) for why.
#
# **No bin wrapper, and no build-time Node at runtime.** buildNpmPackage's default
# output is a `bin/victual-mcp` makeWrapper script — a bash script — exec'ing the full
# `nodejs` it was built with, whose npm and corepack pull bash and coreutils in behind
# it. The first build (2026-09-19) had all three in its closure, which is exactly what
# `mcp-image-has-no-shell` in nix/checks.nix exists to refuse. So the wrapper is
# deleted, shebangs are left unpatched (nothing executes dist/main.js directly), and the
# image's entrypoint runs `passthru.node` on `passthru.entrypoint`.
#
# `passthru.node` is not nixpkgs' nodejs-slim as-is, either. Node embeds its build
# configuration in the binary (`process.config`), and that configuration names the
# include directory of every library it was compiled against — so nodejs-slim's closure
# holds a dozen `-dev` outputs and a few `-bin` ones, and through them icu4c-dev's
# `icu-config` (bash), zstd's `zstdgrep` (grep, coreutils) and gtest. None of it is
# reachable at runtime: the linker follows RPATH, which names library outputs, not
# headers. So the runtime copies `bin/node`, strips exactly those `-dev`/`-bin`
# references and nodejs-slim's own prefix, and proves the result still runs before it is
# accepted.
{
  lib,
  buildNpmPackage,
  runCommand,
  removeReferencesTo,
  nodejs_24,
  nodejs-slim_24,
  hashes,
  sources,
  version,
}:

let
  node =
    runCommand "node-runtime-${nodejs-slim_24.version}"
      {
        nativeBuildInputs = [ removeReferencesTo ];
        meta.mainProgram = "node";
      }
      ''
        mkdir -p "$out/bin"
        cp ${nodejs-slim_24}/bin/node "$out/bin/node"
        chmod u+w "$out/bin/node"

        # Every store path the binary names whose output is a headers or tools output.
        grep -aoE '/nix/store/[a-z0-9]{32}-[^/"[:space:]]+' "$out/bin/node" \
          | sort -u \
          | grep -aE -- '-(dev|bin)$' \
          | while read -r ref; do
              remove-references-to -t "$ref" "$out/bin/node"
            done
        # And nodejs-slim's own prefix (global module paths, man pages) — reaching it would
        # bring every reference above straight back. Node finds itself via
        # /proc/self/exe, not this string.
        remove-references-to -t ${nodejs-slim_24} "$out/bin/node"

        chmod u-w "$out/bin/node"
        "$out/bin/node" -e 'if (typeof fetch !== "function") process.exit(1)'
      '';
in
buildNpmPackage (finalAttrs: {
  pname = "victual-mcp";
  inherit version;

  nodejs = nodejs_24;

  src = sources.toSource sources.mcpFiles;

  # sources.mcpFiles keeps paths relative to the repository root (nix/source.nix's
  # `root`), so the unpacked source has package.json at source/mcp/package.json, not at
  # its own root. buildNpmPackage looks for the manifest and lockfile at the source
  # root, so this repositions it — the same reason composer.json and yarn.lock need no
  # equivalent in nix/app.nix and nix/frontend.nix: those already sit at the repository
  # root sources.nix uses.
  sourceRoot = "source/mcp";

  npmDepsHash = hashes.mcpNpmDeps;

  npmBuildScript = "build";

  dontPatchShebangs = true;
  postInstall = ''
    rm -rf "$out/bin"
  '';

  passthru = {
    inherit node;
    entrypoint = "${finalAttrs.finalPackage}/lib/node_modules/victual-mcp/dist/main.js";
  };

  # The contract tests (tests/contract, once plan 14's fixtures are copied in) run in CI
  # against a live compose stack per spec §11.1, not as part of this derivation — a
  # fixture mismatch should fail a workflow attributable to the change that caused it,
  # not an unrelated flake.lock bump. Left as the default (npm's own `test` script is not
  # invoked by buildNpmPackage unless doCheck is set) rather than an explicit doCheck =
  # false, since there is no test script wired to a real fixture yet to skip.

  meta = {
    description = "Victual MCP sidecar — read-only tools over Victual's REST API";
    homepage = "https://github.com/datagen24/victual";
    license = lib.licenses.mit;
    platforms = lib.platforms.all;
  };
})
