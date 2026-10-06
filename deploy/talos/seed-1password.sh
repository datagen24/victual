#!/usr/bin/env bash
#
# Create the three 1Password items deploy/talos's OnePasswordItems read, once. The cluster's
# 1Password Connect operator turns each item into a Secret of the same name, one key per
# field, so the field labels below are the environment variable names the pods read.
#
#   deploy/talos/seed-1password.sh
#
# An item that already exists is left alone: the database was initialised with the
# passwords in it, and replacing them would lock the pods out. A value comes from
# deploy/talos/.secrets/ when that file has it (how this cluster moved off the generated
# files without changing a password), and is generated otherwise. Values travel from Python
# to `op` on a pipe, as a JSON template, so none is on a command line or written to disk.
#
# Needs `op` signed in to the account whose vault Connect serves. OP_ACCOUNT and OP_VAULT
# name them; the defaults are this cluster's.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

ACCOUNT="${OP_ACCOUNT:-my.1password.com}"
VAULT="${OP_VAULT:-DevSecOps}"
SECRETS=deploy/talos/.secrets

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

# item  file-under-.secrets  field=default ...   (default: a literal, or @generate)
seed() {
	local item=$1 file=$2
	shift 2
	if op item get "$item" --vault "$VAULT" --account "$ACCOUNT" >/dev/null 2>&1; then
		log "$item: exists in $VAULT, left alone"
		return
	fi
	log "$item: creating in $VAULT"
	python3 - "$item" "$SECRETS/$file" "$@" <<'PY' | op item create --vault "$VAULT" --account "$ACCOUNT" >/dev/null
import json, os, secrets, string, sys

title, path, specs = sys.argv[1], sys.argv[2], sys.argv[3:]
existing = {}
if os.path.exists(path):
    for line in open(path):
        key, sep, value = line.rstrip("\n").partition("=")
        if sep:
            existing[key] = value

alphabet = string.ascii_letters + string.digits
fields = []
for spec in specs:
    label, _, default = spec.partition("=")
    value = existing.get(label)
    if value is None:
        value = "".join(secrets.choice(alphabet) for _ in range(32)) if default == "@generate" else default
    fields.append({
        "id": label,
        "label": label,
        "type": "STRING" if default != "@generate" else "CONCEALED",
        "value": value,
    })

json.dump({"title": title, "category": "SECURE_NOTE", "tags": ["victual", "k8s"], "fields": fields}, sys.stdout)
PY
}

seed victual-postgres-superuser superuser.env password=@generate
seed victual-db-migrate migrate.env \
	VICTUAL_DB_USER=victual_migrate \
	VICTUAL_DB_PASSWORD=@generate \
	VICTUAL_BOOTSTRAP_ADMIN_PASSWORD=@generate
seed victual-db-app app.env \
	VICTUAL_DB_USER=victual_app \
	VICTUAL_DB_PASSWORD=@generate
