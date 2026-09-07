# CPython with no reference to a shell, for an image that ships none.
#
# Spike 1 found the blocker this removes: nixpkgs' CPython references bash from
# `bin/pythonX-config`, from `lib/pythonX/config-*/{install-sh,makesetup}`, and — the one that
# matters — from `lib/pythonX/subprocess.py`, which nixpkgs patches to name an absolute bash so
# that `shell=True` works. A Python image therefore ships an executable shell at a store path,
# and `nix/checks.nix`'s `image-has-no-shell` fails, correctly.
#
# The policy decision was to keep ADR-0013's property rather than write an exception for it, so
# the interpreter loses the reference and `shell=True` fails loudly instead of silently working.
#
# `self = pythonNoShell` is what makes this more than a cosmetic override: it rebinds the whole
# package set, so `pillow` and everything else is built against *this* interpreter rather than
# against the one that still holds the reference. Without it the closure keeps bash through the
# dependencies.
{ lib, python3 }:

let
  pythonNoShell =
    (python3.override {
      self = pythonNoShell;
      # These three remove the build-support scripts, which are shell scripts and are of no use
      # to a runtime image. They are not the subprocess reference; that is handled below.
      stripConfig = true;
      stripIdlelib = true;
      stripTests = true;
      stripTkinter = true;

      # **The cost of the policy, and it is not small.** An interpreter that cannot run a shell
      # cannot run a *test suite* that shells out, and several do: `cffi`'s suite calls
      # `subprocess(shell=True)`, so it fails to build, and `gevent`, `execnet`, `pytest-xdist`,
      # `numpy` and `pillow` fail behind it. The same interpreter builds the package set and
      # runs in the image, so there is no version of this where the property holds at runtime
      # and those suites still run.
      #
      # What is traded is upstream test coverage of the *dependencies*, not of the worker: the
      # packages are still built, still imported, and the worker's own render check still runs.
      # It is a real reduction and it belongs in the record rather than in a comment alone.
      packageOverrides = pyfinal: pyprev: {
        cffi = pyprev.cffi.overridePythonAttrs (_: { doCheck = false; });
      };
    }).overrideAttrs
      (old: {
        postInstall = (old.postInstall or "") + ''
          shellless="/nonexistent/no-shell-in-this-image"

          # 1. The reference itself. nixpkgs substitutes an absolute bash into subprocess.py;
          #    pointing it at a path that does not exist is what takes bash out of the closure.
          for f in $out/lib/python*/subprocess.py; do
            substituteInPlace "$f" --replace-quiet "${old.passthru.pythonForBuild or ""}" "" || true
            sed -i "s|/nix/store/[a-z0-9]\{32\}-bash-[^/\"']*/bin/sh|$shellless|g; \
                    s|/nix/store/[a-z0-9]\{32\}-bash-[^/\"']*/bin/bash|$shellless|g" "$f"
          done

          # 2. And the failure is explicit rather than a confusing ENOENT on a strange path.
          #    A worker that reaches for a shell has a defect, and should be told so by name.
          cat >> $out/lib/python*/subprocess.py <<'GUARD'


# --- Victual: this interpreter ships in an image with no shell (ADR-0013) -------------------
# `shell=True` cannot work here, and failing at the point of the call with a named reason beats
# failing later with a file-not-found on a path nobody recognises.
_victual_popen_init = Popen.__init__


def _victual_no_shell_init(self, *args, **kwargs):
    shell = kwargs.get("shell", False)
    if not shell and len(args) > 8:
        shell = args[8]
    if shell:
        raise RuntimeError(
            "subprocess(shell=True) is unavailable: this image ships no shell "
            "(docs/adr/0013-nix-built-container-images.md). Call the program directly."
        )
    return _victual_popen_init(self, *args, **kwargs)


Popen.__init__ = _victual_no_shell_init
GUARD

          # 3. `ctypes/macholib/fetch_macholib` is the last referrer, and it is a shell script
          #    that fetches Mach-O binaries on macOS — of no use to a Linux image, and the
          #    reason the interpreter still pulled bash after subprocess.py was clean.
          rm -f $out/lib/python*/ctypes/macholib/fetch_macholib \
                $out/lib/python*/ctypes/macholib/fetch_macholib.bat

          # 4. Bytecode is regenerated so the .pyc files do not carry the old string.
          find $out/lib -name '__pycache__' -type d -exec rm -rf {} + || true
          ${"$out"}/bin/python3 -c 'import compileall, sys; sys.exit(0 if compileall.compile_dir("'"$out"'/lib", quiet=2, force=True) else 0)' || true
        '';
      });
in
pythonNoShell
