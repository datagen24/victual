# The static tier's nginx configuration.
#
# It serves `${webroot}` — css, js, images, and the yarn-installed frontend packages —
# and hands everything else to php-fpm over loopback. It never reads a PHP file: the
# FastCGI locations match before `try_files` gets a chance to stat anything, so the web
# image contains no application code at all. That is the whole reason the split exists.
#
# Every path nginx would otherwise want to write to is a leaf directly under /tmp, which
# is a tmpfs/emptyDir in the deployment. Directly under, not nested: nginx creates the
# temporary directories it was configured with, but it does not create their parents, so
# a `/tmp/nginx/client-body` under a freshly mounted empty /tmp fails at startup with
# `mkdir() "/tmp/nginx/client-body" failed (2: No such file or directory)`. Creating the
# parent in an image layer does not help either — the volume mounts over it.
{
  writeText,
  lib,
  nginx,
  webroot,
  appRoot,
  listenPort ? 8080,
  fpmPort ? 9000,
}:

let
  # The path php-fpm opens, as *text* rather than as a dependency.
  #
  # SCRIPT_FILENAME names a file in the app container's filesystem. nginx never opens it;
  # it puts the string on the wire and php-fpm resolves it. Interpolating the store path
  # normally would make the whole application closure a dependency of this configuration,
  # and therefore of the web image — which would put every PHP file into the tier whose
  # entire purpose is not to have any. Discarding the string context keeps the text and
  # drops the edge. nix/checks.nix asserts the web image's closure really does not contain
  # the application, because this is exactly the kind of thing that silently regresses.
  scriptFilename = builtins.unsafeDiscardStringContext "${appRoot}/public/index.php";
  documentRoot = builtins.unsafeDiscardStringContext "${appRoot}/public";

  # The front controller, in one place. Two locations need it — the exact-match `/` and
  # the `/index.php` that `try_files` falls back to — and a copy that drifts is a 502
  # nobody can explain.
  frontController = ''
    fastcgi_pass 127.0.0.1:${toString fpmPort};
    include ${nginx}/conf/fastcgi_params;
    fastcgi_param SCRIPT_FILENAME ${scriptFilename};
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param DOCUMENT_ROOT ${documentRoot};
    fastcgi_read_timeout 60s;
    fastcgi_buffering on;
  '';
in
writeText "victual-nginx.conf" ''
  # PID 1 is nginx itself; it must not fork away.
  daemon off;
  worker_processes auto;
  pid /tmp/nginx.pid;
  error_log /dev/stderr warn;

  events {
    worker_connections 1024;
  }

  http {
    include ${nginx}/conf/mime.types;
    default_type application/octet-stream;

    access_log /dev/stdout combined;

    # Leaves of /tmp, not of /tmp/nginx — see the header comment.
    client_body_temp_path /tmp/nginx-client-body;
    proxy_temp_path /tmp/nginx-proxy;
    fastcgi_temp_path /tmp/nginx-fastcgi;
    uwsgi_temp_path /tmp/nginx-uwsgi;
    scgi_temp_path /tmp/nginx-scgi;

    sendfile on;
    tcp_nopush on;
    keepalive_timeout 65;
    server_tokens off;

    # Matches php.ini's post_max_size and upload_max_filesize, which match
    # FILE_STORAGE_MAX_SIZE_MB's default. A mismatch here is the classic "the upload fails
    # with an HTML error page instead of a JSON one".
    client_max_body_size 64m;

    gzip on;
    gzip_types text/css text/javascript application/javascript application/json image/svg+xml;
    gzip_min_length 1024;

    server {
      listen ${toString listenPort};
      listen [::]:${toString listenPort};

      # The static tree. The application's own root lives in the *app* container; this is
      # only the assets half, and it is deliberately a different store path with no PHP
      # in it at all.
      root ${webroot};

      # No `index` directive, deliberately. nix/webroot.nix removes index.php from the
      # document root because the web image has no interpreter to run it with, so there
      # is no index file to find and nginx's index handling can only end in a 403.
      # Requests reach the front controller through the locations below instead.

      # TLS termination, HSTS and the rest belong to the ingress. What is set here is
      # what only the origin can know.
      add_header X-Content-Type-Options nosniff always;
      add_header X-Frame-Options SAMEORIGIN always;
      add_header Referrer-Policy no-referrer always;

      location = /robots.txt {
        access_log off;
      }

      # The root URL, routed explicitly. An exact-match location beats every prefix and
      # regex location, which is what this needs: `try_files $uri` on a request for `/`
      # resolves to the document root itself, matches as a directory, and hands the
      # request to nginx's index handling — which, with no index file present, answers
      # 403 rather than reaching PHP.
      location = / {
        ${frontController}
      }

      # nginx's own bundled mime.types is not the mime-db this box's /etc/mime.types
      # draws from, and has historically lagged it - `.mjs` is exactly the kind of entry
      # that goes missing, and an ES module served as application/octet-stream is a module
      # a browser refuses to run. Named ahead of the general packages/css/js/... location
      # below: nginx tries regex locations in the order they appear and takes the first
      # match, so this one is checked first regardless of the other's reach.
      location ~* \.mjs$ {
        default_type application/javascript;
        expires 30d;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
      }

      # Frontend libraries and application assets are content-addressed by the ?v=
      # query the Blade layout appends, so they can be cached hard.
      location ~* ^/(packages|css|js|viewjs|img|uisounds)/ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
      }

      # Everything else: serve it if it is a file in the document root, otherwise the
      # front controller. No `$uri/` candidate — a directory in the document root has
      # nothing to serve without an index file, and including it is what turns an
      # application route that happens to end in a slash into a 403.
      location / {
        try_files $uri /index.php$is_args$args;
      }

      # Where the try_files fallback lands. SCRIPT_FILENAME names a path inside the
      # *app* container: nginx does not open this file, php-fpm does.
      location ~ ^/index\.php(/|$) {
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        ${frontController}
        fastcgi_param PATH_INFO $fastcgi_path_info;
      }

      # Anything else ending in .php is not ours. Without this a future asset called
      # foo.php would be served as source.
      location ~ \.php$ {
        return 404;
      }

      # Dotfiles are not assets.
      location ~ /\. {
        deny all;
      }
    }
  }
''
