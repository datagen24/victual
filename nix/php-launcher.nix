# The image's PHP interpreter at a fixed path: /opt/victual/php.
#
# The app and migrate images have no PATH and no shell, and their Cmd names store paths, so
# a manifest that wants to run one of bin/'s commands from the same image has nothing it can
# write in `command:`. The Helm chart's hook Jobs (ADR-0038 decision 9) are the case:
#
#   command: ["/opt/victual/php", "bin/victual-timestamp-preflight"]
#
# relative to the image's WorkingDir, which is the application root. The scripts stay where
# they are, because each finds the autoloader through __DIR__; only the interpreter needs a
# name. As nix/healthcheck.nix says of its own shebang, a predictable path to a PHP
# interpreter adds nothing an attacker inside a container that already runs PHP lacked.
#
# A symlink, so the image carries no second copy of PHP. Called once per image with that
# image's PHP: the migrate image's carries pdo_sqlite and the app image's does not.
{
  runCommand,
  php,
  version,
}:

runCommand "victual-php-launcher-${version}"
  {
    meta.description = "Victual's PHP interpreter at /opt/victual/php";
  }
  ''
    mkdir -p "$out/opt/victual"
    ln -s ${php}/bin/php "$out/opt/victual/php"
  ''
