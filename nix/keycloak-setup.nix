# The Keycloak realm reconciler (deploy/keycloak/reconcile.mjs, plan 43) as a package: the
# reconciler, its realm configuration and the adapter recipe it pins, under
# share/victual-keycloak-setup with the same relative layout as deploy/keycloak/, because
# reconcile.mjs finds realm.json and the recipe beside itself.
#
# It has no npm dependencies, so there is nothing to build: the files are copied. It runs
# on the MCP sidecar's Node (`mcp.node`, nix/mcp.nix), the binary already stripped of the
# -dev and -bin references that would bring a shell into the closure; one Node for both
# images keeps one set of those reasons in one place.
#
# Shebangs are left alone. reconcile.mjs starts with `#!/usr/bin/env node`, and the fixup
# phase would rewrite it to the full nodejs, whose npm and corepack pull bash in behind
# them (nix/mcp.nix's header). Nothing executes the file directly: the image's
# entrypoint runs Node on it.
{
  lib,
  stdenvNoCC,
  mcp,
  sources,
  version,
}:

stdenvNoCC.mkDerivation (finalAttrs: {
  pname = "victual-keycloak-setup";
  inherit version;

  src = sources.toSource sources.keycloakSetupFiles;

  dontConfigure = true;
  dontBuild = true;
  dontFixup = true;

  installPhase = ''
    runHook preInstall
    mkdir -p "$out/share/victual-keycloak-setup"
    cp -r deploy/keycloak/. "$out/share/victual-keycloak-setup/"
    runHook postInstall
  '';

  passthru = {
    node = mcp.node;
    entrypoint = "${finalAttrs.finalPackage}/share/victual-keycloak-setup/reconcile.mjs";
  };

  meta = {
    description = "Applies Victual's Keycloak realm configuration through the admin REST API";
    license = lib.licenses.mit;
  };
})
