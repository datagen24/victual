# The worker application, built from the pinned revision of its own repository.
#
# ADR-0019 decision item 1: the worker's source lives in another repository and this
# flake builds its image from a pinned revision. `src` is the flake input, so a revision
# bump is a flake.lock change reviewed like any other.
{
  lib,
  python3Packages,
  makeBinaryWrapper,
  brother-ql-inventree,
  workerSource,
}:

python3Packages.buildPythonApplication {
  pname = "victual-label-worker";
  version = "0.0.0";
  pyproject = true;

  src = workerSource;

  build-system = [ python3Packages.setuptools ];

  # **Without this the image ships bash.** `wrapPythonPrograms` wraps every console
  # script with a shell script whose shebang is bash, so the interpreter lands in the
  # runtime closure and `image-has-no-shell` fails — the ordinary way to package a Python
  # application in nixpkgs is incompatible with ADR-0013's property, and nothing says so
  # until an image is actually built. Putting makeBinaryWrapper in nativeBuildInputs
  # shadows makeWrapper's shell function with the compiled implementation, so the wrapper
  # becomes a small C program and bash leaves the closure.
  nativeBuildInputs = [ makeBinaryWrapper ];

  dependencies = [
    brother-ql-inventree
    python3Packages.pillow
    python3Packages.qrcode
  ];

  # The fonts are package data, not a sibling directory. The prototype resolved them as
  # ../fonts relative to the module, which exists in a checkout and does not exist once
  # anything installs the package — the failure this spike was scoped to catch rather
  # than meet at deployment. pythonImportsCheck plus the render check in
  # nix/checks.nix is what proves the installed layout works.
  pythonImportsCheck = [
    "victual_label_worker"
    "victual_label_worker.imaging"
    "victual_label_worker.render"
  ];

  # `doCheck` is the python builder's own test phase and this spike has no test suite.
  #
  # The render that proves the closure is complete is *not* here, and that is a finding
  # rather than a preference: `mkPythonDerivation` forces `doInstallCheck = false`, so a
  # `postInstallCheck` attribute on a python package evaluates fine, never runs, and the
  # build passes looking exactly like one that checked something. It lives in
  # nix/checks.nix instead, where `nix flake check` runs it — which is the command
  # ADR-0019's gate 1 names anyway.
  doCheck = false;

  meta = {
    description = "Victual label worker (ADR-0019 packaging spike)";
    mainProgram = "victual-label-render";
    license = lib.licenses.mit;
  };
}
