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

  # The renderer and the delivery worker live in their own repositories, pinned by
  # revision here. ADR-0019 decision item 1 puts the worker's source outside this tree and
  # ADR-0021 does the same for the renderer, and pinning by revision is what keeps
  # "reproducible" true across that seam: the flake owns the image, the pin and the
  # deployment, the other repository owns the driver matrix and the device transport, and
  # a revision bump is a flake.lock change reviewed like any other.
  #
  # `flake = false` because these are Cargo projects rather than flakes: what is wanted is
  # the source tree at a fixed revision, built by nixpkgs' rustPlatform here.
  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";
    label-renderer = {
      url = "github:datagen24/victual-label-renderer";
      flake = false;
    };
    label-worker = {
      url = "github:datagen24/victual-label-worker";
      flake = false;
    };
  };

  outputs =
    { self, nixpkgs, label-renderer, label-worker }:
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
            self.overlays.default
            # The two pinned source trees reach the overlay as ordinary attributes rather
            # than by path, so a consumer adding this overlay gets the same pins.
            (_: _: { victualLabelSources = { renderer = label-renderer; worker = label-worker; }; })
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
            labelRenderer
            labelWorker
            ;
        }
        // lib.optionalAttrs (builtins.elem system linuxSystems) {
          inherit (v)
            image-app
            image-web
            image-migrate
            image-label-renderer
            image-label-worker
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
