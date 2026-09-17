# The development and CI image. One image, one job.
#
#   docker build .
#
# It exists so that `.devtools/pgsql/difftest.php`, `trigdifftest.php` and the regression
# suite built on them run from a clean checkout with no host setup beyond Docker: it
# carries a PHP CLI, a PostgreSQL client, pcov, and the working tree mounted over it. It is
# deliberately fat, and being fat is fine — nothing deploys it.
#
# **This file used to build a production image too, and no longer does.**
# [ADR-0013](docs/adr/0013-nix-built-container-images.md) was accepted on 2026-09-04:
# production images are built by Nix, from `flake.nix` and `nix/`, one image per workload
# on no base image. Its open question 5 said the accepting change should remove this stage
# rather than run two production images side by side, since two production images is the
# drift the record exists to avoid.
#
# What went with it: an Apache/mod_php stage, the `assets` stage that existed only to feed
# it (`nix/frontend.nix` does that job for the Nix images), and the `images` CI job's
# assertions about it — which moved rather than disappeared, to `nix/checks.nix` and to the
# `nix` workflow's boot test. `docs/plans/20-container-infrastructure.md` records what each
# one became.
#
# PHP 8.5 even though composer.json pins 8.4: the fork's floor is 8.4 (so an 8.4 box can
# still run it) while the shipped image stays current. See docs/plans/15-deliberate-cleanup.md,
# question 4.


# ---------------------------------------------------------------------------------------
# Development and CI
# ---------------------------------------------------------------------------------------
FROM php:8.5-cli-bookworm AS dev

# libpq-dev for building pdo_pgsql, the image libraries for gd, ICU for intl, and libzip
# for zip. postgresql-client is separate and not optional: libpq-dev ships headers and the
# shared library, while run-tests.sh calls dropdb and createdb, which live in the client
# package. sqlite3 is the CLI, useful for poking at a failing seed by hand, and libsqlite3-dev
# is what docker-php-ext-install needs to build pdo_sqlite: the base image ships the headers
# for neither stage, and the first CI build of this file failed on exactly that.
# libtap-parser-sourcehandler-pgtap-perl is ADR-0025 tier 2's pg_prove, the client that
# runs .devtools/pgtap/*.sql - this image never runs a PostgreSQL server itself (see
# docker-compose.yml's separate postgres service), so the pgtap *extension* is installed
# there instead, per decision 6.
RUN apt-get update && apt-get install -y --no-install-recommends \
		git \
		unzip \
		sqlite3 \
		libsqlite3-dev \
		libpq-dev \
		postgresql-client \
		libicu-dev \
		libzip-dev \
		libpng-dev \
		libjpeg62-turbo-dev \
		libfreetype6-dev \
		libtap-parser-sourcehandler-pgtap-perl \
	&& docker-php-ext-configure gd --with-freetype --with-jpeg \
	&& docker-php-ext-install -j"$(nproc)" \
		pdo_sqlite \
		pdo_pgsql \
		gd \
		intl \
		zip \
	&& rm -rf /var/lib/apt/lists/*

# pcov, so `SUITE_COVERAGE=1 .devtools/pgsql/run-tests.sh` works in the image without
# further setup. It is the cheap driver — line coverage only, no stepping or profiling —
# and it does nothing at all unless a process asks it to, so leaving it enabled costs the
# ordinary runs nothing measurable. Xdebug would also work and is roughly an order of
# magnitude slower.
RUN pecl install pcov \
	&& docker-php-ext-enable pcov

# fileinfo, ctype, zlib, mbstring, filter, iconv, tokenizer and json are already compiled
# into the base image; PrerequisiteChecker checks for all of them.

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencies first, so editing source does not invalidate the vendor layer.
# --ignore-platform-req=php keeps the image usable while composer.json still pins 8.5.*;
# once 15-C7 lowers that pin to 8.4 the flag becomes a no-op and can be dropped.
COPY composer.json composer.lock ./
RUN composer install \
		--no-interaction \
		--no-progress \
		--no-scripts \
		--ignore-platform-req=php

COPY . /app

# difftest.php and trigdifftest.php both default VICTUAL_ROOT to /app; being explicit means
# the scripts behave the same when invoked from somewhere else in the tree.
ENV VICTUAL_ROOT=/app

CMD ["php", "-v"]
