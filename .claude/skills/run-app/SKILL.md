---
name: run-app
description: Boot this app locally (PHP built-in server, PostgreSQL demo mode) and drive it with Playwright for screenshots. Use when asked to run, start, boot, or screenshot the app, or to verify a change on a running instance.
---

# Run the app locally

Verified cold-start from a fresh Linux container (Claude Code web session,
2026-08; re-verified on PostgreSQL 2026-09-05, when ADR-0008's retirement
removed the SQLite boot). Total time ~3 minutes, most of it the demo
generation. All commands from the repo root.

## 1. PHP dependencies

```bash
composer install --no-interaction
```

## 2. Frontend packages

```bash
yarn install --frozen-lockfile
```

The views load CSS/JS from `/packages/...`, and `.yarnrc` already sets
`--modules-folder public/packages`, so yarn installs straight there — there
is no `node_modules` and no symlink to make. If a stale `public/packages`
symlink exists from an earlier session, yarn fails with
`EEXIST: file already exists, mkdir '.../public/packages'`; `rm -f
public/packages` and re-run. Without the packages the app boots unstyled.

## 3. PostgreSQL

Since [ADR-0008](../../../docs/adr/0008-postgresql-only-runtime-engine.md)'s
retirement landed there is no SQLite boot: `DB_DRIVER` accepts `pgsql` and
nothing else, and demo mode runs on it like everything else. A container
that has the `postgresql` packages but no running cluster - which is the
usual state - starts one and gets a role in two commands:

```bash
pg_ctlcluster 16 main start          # or: service postgresql start
su postgres -c "psql -c \"CREATE ROLE victual LOGIN SUPERUSER PASSWORD 'victual'\""
export PGHOST=127.0.0.1 PGPORT=5432 PGUSER=victual PGPASSWORD=victual
createdb victual_demo
```

`pg_isready` says whether the cluster came up. If PostgreSQL is not
installed at all, `docker-compose.yml` has a service for it.

## 4. Data directory and boot

Use a throwaway data directory - never `./data`, which may hold a real
local `config.php` and database that an unconditional copy would destroy.
The config is written rather than copied from `config-dist.php`: the
distributed defaults name a server on localhost with an empty password,
which is not the one just created.

```bash
export VDATA=$(mktemp -d)
cat > "$VDATA/config.php" <<'PHPCONFIG'
<?php
Setting('DB_DRIVER', 'pgsql');
Setting('DB_HOST', '127.0.0.1');
Setting('DB_PORT', 5432);
Setting('DB_NAME', 'victual_demo');
Setting('DB_USER', 'victual');
Setting('DB_PASSWORD', 'victual');
PHPCONFIG
VICTUAL_MODE=demo VICTUAL_DATAPATH="$VDATA" php bin/victual-migrate
VICTUAL_MODE=demo VICTUAL_DATAPATH="$VDATA" php -S 127.0.0.1:8085 -t public > /tmp/php-server.log 2>&1 &
sleep 2 && curl -s -o /dev/null -w "%{http_code}\n" --max-time 300 http://127.0.0.1:8085/
```

Migrate first: a request no longer migrates the database unless
`MIGRATE_ON_ROOT_REQUEST` is on, and an application whose schema is behind
its code answers **503** with a message saying exactly this (plan 10). The
alternative is `VICTUAL_MIGRATE_ON_ROOT_REQUEST=true` in the environment of
both commands, which restores the old "just hit the page" behaviour.

Demo mode seeds the database with sample data and auto-logs-in as "Demo
User" - no credentials needed. The first `GET /` generates the demo data and
then 302s to the entry page. It takes a minute or so, mostly waiting on the
picture downloads below, so give that request a generous timeout; a 302 back
means it finished.

Smoke check - expect 200 with a large HTML body:

```bash
curl -s -o /dev/null -w "%{http_code} %{size_download}\n" http://127.0.0.1:8085/stockoverview
```

If the demo tables are empty afterwards, read `/tmp/php-server.log`: a boot
that fails a prerequisite answers 200 with an error page, which looks like
success to `curl -o /dev/null`.

## 5. Screenshots (Playwright)

Chromium is pre-installed at `/opt/pw-browsers/chromium`; do NOT run
`playwright install`. `playwright-core` is not in this repo's
`package.json` — install it in the scratchpad, not here:

```bash
cd "$SCRATCHPAD" && npm init -y >/dev/null && npm i playwright-core >/dev/null
```

```js
const { chromium } = require('playwright-core');
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
  await page.goto('http://127.0.0.1:8085/stockoverview', { waitUntil: 'networkidle' });
  await page.screenshot({ path: 'fullpage.png' });
  await page.locator('#mainNav').screenshot({ path: 'navbar.png' });  // navbar only
  await browser.close();
})();
```

Look at the screenshot after taking it — a blank frame means the boot or
step 2 failed.

**Demo pictures do not load in this environment.** Demo generation fetches
product and recipe photos from `releases.grocy.info`, which the agent proxy
denies (403 on CONNECT), and `DownloadFileIfNotAlreadyExists` writes a
0-byte file instead of failing — so recipe thumbnails render as broken-image
icons on `/mealplan`. For presentable screenshots, clear the references
first:

```bash
psql -d victual_demo -c "UPDATE recipes SET picture_file_name = NULL" \
  -c "UPDATE products SET picture_file_name = NULL"
find "$VDATA/storage" -type f -size 0 -delete
```

Check for the problem rather than assuming: after loading a page,
`document.images` filtered on `complete && naturalWidth === 0` lists what
failed. One hit is expected and harmless — `#productcard-product-picture`
is a `d-none` template element.

## Variants

- **Dev mode instead of demo data**: `VICTUAL_MODE=dev` — empty database,
  auth also bypassed (user id 1).
- **A database that is not local**: point the `DB_*` settings at it. There
  is no engine to switch to any more - see
  [ADR-0008](../../../docs/adr/0008-postgresql-only-runtime-engine.md), and
  `bin/victual-db-import` for moving an old SQLite installation across.
