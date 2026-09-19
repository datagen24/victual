# The php.ini body, as a string.
#
# It is appended to the extension-loading ini that `php.buildEnv` generates, so it
# applies to every SAPI in every image — php-fpm in the app image, the CLI in the
# migrate image — without anyone having to remember a `-c` flag. An image whose PHP
# behaves differently depending on how it was invoked is a debugging problem waiting to
# happen.
#
# Two settings here are specific to an immutable image and worth naming. First,
# `opcache.validate_timestamps=0`: the source tree is a read-only store path that cannot
# change under a running process, so stat-ing every file on every request buys nothing.
# Second, `error_log=/proc/self/fd/2` — plan 11's recorded answer to "where do logs go"
# is stderr, and this is that answer written down.
#
# What is deliberately *not* here: `disable_functions`. It belongs to the fpm pool
# (nix/runtime/fpm-conf.nix) rather than to the interpreter.
#
# The reason recorded here until 2026-09-04 was that the entrypoint ran under this same
# ini and needed pcntl_exec. That reason is gone with the entrypoint (issue #49) — and
# moving the ban here anyway immediately broke the build, which is how the *real* reason
# surfaced. This ini is baked into `php.buildEnv`, so it applies to every consumer of this
# PHP in the overlay, including the one that runs at build time: nixpkgs' composer, whose
# XdebugHandler calls `putenv()` and which dies with "Call to undefined function
# Composer\XdebugHandler\putenv()" before it can install a single package.
#
# So the ban is a property of the *serving pool*, not of the interpreter, and the migrate
# image's CLI is not covered by it. That is a real gap and it is stated rather than
# papered over: closing it needs a second ini applied to the migrate image alone, not a
# line moved into this file.
''
  ; --- Disclosure -------------------------------------------------------------------
  expose_php = Off
  display_errors = Off
  display_startup_errors = Off
  html_errors = Off
  zend.exception_ignore_args = On
  zend.assertions = -1

  ; --- Logging: stderr, and nowhere else --------------------------------------------
  log_errors = On
  error_log = /proc/self/fd/2

  ; --- Limits -----------------------------------------------------------------------
  memory_limit = 256M
  max_execution_time = 60
  max_input_time = 60
  ; Both at FILE_STORAGE_MAX_SIZE_MB's default (config-dist.php), which FileSizeLimit clamps
  ; to the smaller of these two. At 32M every boot logged that the configured 64 MB was being
  ; cut to 32, and every upload between the two sizes was refused. nginx's
  ; client_max_body_size (nginx-conf.nix) has to move with them.
  post_max_size = 64M
  upload_max_filesize = 64M
  upload_tmp_dir = /tmp
  sys_temp_dir = /tmp

  ; --- Time -------------------------------------------------------------------------
  ; The image has no /etc/localtime worth reading. UTC in; the application's own
  ; CULTURE and timezone settings decide what a user sees.
  date.timezone = UTC

  ; --- opcache ----------------------------------------------------------------------
  opcache.enable = 1
  opcache.enable_cli = 0
  ; The tree is a read-only Nix store path. It cannot change; do not check whether it did.
  opcache.validate_timestamps = 0
  opcache.memory_consumption = 128
  opcache.interned_strings_buffer = 16
  opcache.max_accelerated_files = 20000

  ; --- Streams ----------------------------------------------------------------------
  ; Guzzle uses the curl handler when ext/curl is present, which it is, and nothing in
  ; the tree opens an http:// stream directly.
  allow_url_fopen = Off
  allow_url_include = Off
''
