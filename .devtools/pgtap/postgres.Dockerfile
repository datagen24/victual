# ADR-0025 decision 6: pgTAP is installed where PostgreSQL runs for tests, and nowhere
# else. docker-compose.yml's postgres service builds from this file instead of pulling
# postgres:16 directly, so `docker compose run --rm dev .devtools/pgsql/run-tests.sh
# pgtap` works from a clean checkout with no host setup - the same reason the rest of
# this image exists. Production images (nix/, flake.nix) never run tests and are
# untouched; this file has no bearing on them.
FROM postgres:16

RUN apt-get update && apt-get install -y --no-install-recommends postgresql-16-pgtap \
	&& rm -rf /var/lib/apt/lists/*
