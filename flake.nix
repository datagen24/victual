# Nix flake for Victual's container images.
#
# What this builds and why it is not the Dockerfile: the `Dockerfile` at the repository
# root is a *development and CI* image — a full Debian with a compiler toolchain, git,
# composer and a shell, so a contributor can run the differential suite from a clean
# checkout. It is deliberately fat. These images are the other thing: production
# artifacts with nothing in them that the running process does not need, built from a
# pinned dependency graph rather than from whatever `apt-get update` returns today.
#
# See docs/adr/0013-nix-built-container-images.md for the decision and
# nix/README.md for how to build them (including the part where a Mac cannot build a
# Linux image without a Linux builder).
{
  description = "Victual — minimal, reproducible container images built with Nix";

  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";

    # ADR-0019 packaging spike (gate 1), disposable. The worker's source lives in its
    # own repository and this flake builds its image from a *pinned revision* — decision
    # item 1. The spike pins a scratch branch of a local clone, which the record
    # explicitly permits; the real input is the published repository at the same shape.
    victual-label-worker = {
      url = "git+file:///worker?ref=main&rev=64381f45f1e3677478d70491c433693edd79307e";
      flake = false;
    };
  };

  outputs =
    { self, nixpkgs, victual-label-worker }:
    let
      inherit (nixpkgs) lib;

      # Container images are Linux artifacts. Everything else in this flake — the
      # devShell, the application derivation itself — evaluates on a Mac too, which is
      # the difference between "you can work on this from a laptop" and "you cannot".
      linuxSystems = [
        "x86_64-linux"
        "aarch64-linux"
      ];
      darwinSystems = [
        "x86_64-darwin"
        "aarch64-darwin"
      ];
      allSystems = linuxSystems ++ darwinSystems;

      forSystems = systems: f: lib.genAttrs systems (system: f system);

      pkgsFor =
        system:
        import nixpkgs {
          inherit system;
          overlays = [
            # The worker's pinned source, injected as a package attribute so the overlay
            # stays a plain `final: prev:` file that imports nothing from the flake.
            (_: _: { victualLabelWorkerSource = victual-label-worker; })
            self.overlays.default
          ];
        };
    in
    {
      overlays.default = import ./nix/overlay.nix;

      packages = forSystems allSystems (
        system:
        let
          pkgs = pkgsFor system;
          v = pkgs.victual;
        in
        {
          # `appRoot` is deliberately absent: it is the *string* naming where the
          # application lives inside the images, not a derivation, so it is not a
          # buildable output.
          inherit (v)
            php
            phpMigrate
            app
            frontend
            webroot
            healthcheckBin
            webcheckBin
            ;
        }
        // lib.optionalAttrs (builtins.elem system linuxSystems) {
          inherit (v)
            image-app
            image-web
            image-migrate
            image-label-worker
            brother-ql-inventree
            labelWorker
            pythonNoShell
            ;
          # `nix build` with no attribute gives the thing most people want first.
          default = v.image-app;
        }
      );

      apps = forSystems linuxSystems (
        system:
        let
          pkgs = pkgsFor system;
        in
        {
          # `nix run .#load` streams all three images straight into podman without
          # writing a tarball anywhere. streamLayeredImage produces a script that
          # writes the tar to stdout, which is exactly what `podman load` wants.
          load = {
            type = "app";
            program = lib.getExe pkgs.victual.loadImages;
          };
          default = self.apps.${system}.load;
        }
      );

      # `victual.checks` comes out of `callPackage`, which decorates the attribute set it
      # returns with `override` and `overrideDerivation`. `nix flake check` walks every
      # attribute under `checks` and insists each one is a derivation, so passing the set
      # through unfiltered fails with "flake attribute 'checks.<system>.override' is not a
      # derivation" — before it has run a single check. Found by the first `nix flake
      # check`, which is the whole argument for plan 20's piece 1 being a gate.
      checks = forSystems linuxSystems (
        system: lib.filterAttrs (_: lib.isDerivation) (pkgsFor system).victual.checks
      );

      devShells = forSystems allSystems (
        system:
        let
          pkgs = pkgsFor system;
        in
        {
          default = pkgs.mkShellNoCC {
            packages = [
              pkgs.victual.php
              pkgs.victual.php.packages.composer
              pkgs.yarn
              pkgs.nodejs
              pkgs.nix-prefetch-git
            ];
            shellHook = ''
              echo "Victual dev shell — PHP $(php -r 'echo PHP_VERSION;')"
            '';
          };
        }
      );

      formatter = forSystems allSystems (system: (pkgsFor system).nixfmt-tree);
    };
}
