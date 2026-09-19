# What goes into an image, decided once.
#
# The Dockerfile does `COPY . /app` with no .dockerignore, so it ships .git, docs/, the
# differential test harness and whatever is lying around the working tree (plan 10 and
# sweep S25 both call this out). Nix inverts the default: nothing is in the source
# unless it is named here, so a new directory in the repository does not silently become
# part of the production image.
{ lib }:

let
  root = ../.;
  fs = lib.fileset;

  # Everything the running application reads. Note what is absent: docs/, .devtools/,
  # .github/, .agents/, changelog/, branding/, the Dockerfile and the compose file, and
  # the whole frontend toolchain — public/packages is built by nix/frontend.nix rather
  # than copied from a working tree that may not have run yarn.
  application = fs.unions [
    (root + "/app.php")
    (root + "/config-dist.php")
    (root + "/routes.php")
    (root + "/version.json")
    (root + "/composer.json")
    (root + "/composer.lock")
    # A runtime dependency, not a build artefact: BaseApiController::GetOpenApispec()
    # and UserfieldsService both read it on the request path to validate exposed
    # entities, permitted operations and file groups. Without it, every generic entity
    # request answers 500 rather than JSON.
    (root + "/victual.openapi.json")
    (fs.fileFilter (f: f.hasExt "php") (root + "/controllers"))
    (fs.fileFilter (f: f.hasExt "php") (root + "/helpers"))
    (fs.fileFilter (f: f.hasExt "php") (root + "/middleware"))
    (fs.fileFilter (f: f.hasExt "php") (root + "/services"))
    (root + "/views")
    (root + "/localization")
    (root + "/plugins")
    (root + "/bin")
    (root + "/migrations")
    (root + "/db")
    publicTree
  ];

  # public/ minus the things that are not assets: the .htaccess is Apache's rewrite
  # rule and this deployment is nginx, and public/packages is generated.
  publicTree = fs.difference (root + "/public") (fs.unions [
    (root + "/public/.htaccess")
  ]);

in
{
  inherit application;

  # Composer resolves from these two files alone; keeping the vendor derivation's src
  # this narrow means editing a controller does not invalidate the dependency fetch.
  composerFiles = fs.unions [
    (root + "/composer.json")
    (root + "/composer.lock")
  ];

  yarnFiles = fs.unions [
    (root + "/package.json")
    (root + "/yarn.lock")
  ];

  # The MCP sidecar's whole source tree, minus what npm/tsc produce locally and would
  # otherwise get swept in if someone had run them in the working copy before building.
  mcpFiles = fs.difference (root + "/mcp") (
    fs.unions [
      (fs.maybeMissing (root + "/mcp/node_modules"))
      (fs.maybeMissing (root + "/mcp/dist"))
    ]
  );

  toSource = fileset: fs.toSource { inherit root fileset; };
}
