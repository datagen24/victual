# Getting started

Victual is a self-hosted groceries and household management application, forked from
[grocy](https://github.com/grocy/grocy). If you have run grocy before, three things are
different enough that community grocy installation guides do not apply here:

- **PostgreSQL is the only database engine.** There is no SQLite runtime mode; `DB_DRIVER`
  accepts `pgsql` alone. An existing SQLite database — grocy's or an older Victual's — is
  moved across with `bin/victual-db-import`, not run in place.
- **Production images are built by Nix, from `scratch`.** There is no base image, no shell
  and no package manager inside them, and no Victual-specific Docker Hub image to `docker
  pull`.
- **Nothing is written to the application directory.** Uploaded files live in PostgreSQL or
  on a separate storage volume you configure, not next to the code, so the running
  application needs no persistent volume of its own.

There are two ways to run Victual: from a checkout on your own web server, or from the
Nix-built container images. Both need a PostgreSQL 15 or newer server; nothing here manages
that server for you.

## From a checkout

From the repository root:

1. Clone the repository, then install its Composer (PHP) and Yarn (frontend) dependencies.
2. Copy `config-dist.php` to `data/config.php` and edit it — at minimum the `DB_*` settings
   under [Configuration](configuration.md#database).
3. Make sure the `data` directory is writable by the web server user.
4. Run `php bin/victual-migrate` to create the schema in your PostgreSQL database.
5. Point your web server's document root at the `public` directory.
   - nginx: add `try_files $uri /index.php$is_args$query_string;` in the location block.
   - Any server that cannot rewrite URLs: set `DISABLE_URL_REWRITING` to `true` instead
     (see [Configuration](configuration.md)).
6. Open the site — you land on `/login` unless `DISABLE_AUTH` is set — and log in as
   `admin` / `admin`. **Change that password immediately**: user menu, top right, "Change
   password". Signing out again is "Log out" on the same menu.

## From the Nix-built images

Production images are built by [Nix](../../nix/README.md) from `flake.nix`, one image
per workload, from `scratch` — no base image, no shell, no package manager, all three
running as uid 65532:

| Image | Role |
|---|---|
| `.#image-app` | php-fpm, listening on loopback:9000 |
| `.#image-web` | nginx on :8080, serving `public/` and the built frontend assets; no PHP |
| `.#image-migrate` | `bin/victual-migrate`, run as a Job or an initContainer before the other two start |

`nix run .#load` builds and loads all three into a local container runtime; on macOS that
needs a Linux builder, which [`nix/build-in-podman.sh`](https://github.com/datagen24/victual/blob/master/nix/build-in-podman.sh)
provides. [`deploy/podman/victual.yaml`](https://github.com/datagen24/victual/blob/master/deploy/podman/victual.yaml)
is a working pod definition (migrate initContainer, php-fpm, nginx), and
[Deployment](../../deploy/README.md) covers the bootstrap it expects — the ConfigMap
and Secret shapes, and which settings go in each.

Configuration for these images comes entirely from environment variables and mounted
secrets rather than a `config.php` file; see
[Configuration outside config.php](configuration.md#configuration-outside-configphp).

Log in the same way as a checkout install — `admin` / `admin` — and change the password
immediately.

## PostgreSQL

`DB_DRIVER` is `pgsql`; there is no other supported value. Set `DB_HOST`, `DB_PORT`,
`DB_NAME`, `DB_USER`, `DB_PASSWORD` and, if your server requires it, `DB_SSLMODE` — see
[Configuration](configuration.md#database). A fresh, empty database is a valid target: the
schema is created from a squashed baseline on first migration, not by replaying grocy's
SQLite migration history.

PostgreSQL 15 is the floor, because a nested-locations migration needs
`UNIQUE ... NULLS NOT DISTINCT`, which 15 introduced. An installation whose `config.php`
still names `sqlite` is refused at startup, with the exact command that moves it.

### Moving an existing SQLite installation across

```
php bin/victual-migrate                                    # create the schema in the empty PostgreSQL database
php bin/victual-db-import /path/to/victual.db --force
```

`bin/victual-db-import` preserves row ids exactly. It accepts a grocy or Victual SQLite
database whose schema is between migrations 0255 and 0265 inclusive, and refuses anything
outside that span by naming both numbers — 0255 is where upstream grocy 4.x stops, and 0265
is the last migration the SQLite line will ever have. During the import (not during a
migration, because the target is already migrated when the rows arrive) it also runs the
HTML sanitizer over the five rich-text columns and replaces any plaintext API key with its
hash; calendar sharing keys stay readable, as they do in an in-place grocy upgrade.

See [db/pgsql/README.md](https://github.com/datagen24/victual/blob/master/db/pgsql/README.md)
for the porting rules and the accepted behavioural differences between the two engines.

## Platform support

- PHP: `composer.json` declares 8.5; the language floor the code actually needs is 8.4.
  Required extensions: `fileinfo`, `gd`, `ctype`, `intl`, `zlib`, `mbstring`, plus
  `pdo_pgsql`. `pdo_sqlite` is needed only for `bin/victual-db-import` and the differential
  test suite — the Nix migrate image carries it and the serving images do not.
- A recent Firefox, Chrome or Edge.

## Updating

This fork tracks no release schedule. Pull, check `config-dist.php` for settings you have
not set yet (an unset setting falls back to the default there), then run
`php bin/victual-migrate` — a deployment's init step does that for you. Migrations are meant
to work between releases, not between every commit, so pulling a specific tag rather than an
arbitrary commit is the safer habit. Upstream's `update.sh` is not the path used here.

## Localization

The default language is English, integrated into the code. Translations for everything
else come from upstream's [Transifex project](https://explore.transifex.com/grocy/grocy/);
strings this fork has added since forking are not translated there. Set the default with
`DEFAULT_LOCALE` (see [Configuration](configuration.md)); a signed-in user can also pick
their own language on the [user settings](using-victual/administration.md#user-settings)
page. Right-to-left languages are not yet supported.
