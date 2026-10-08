#!/usr/bin/env bash
#
# Create the four 1Password items deploy/talos's OnePasswordItems read, once. The cluster's
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
# Each database item holds VICTUAL_DB_USER and VICTUAL_DB_PASSWORD and nothing else, so the
# credential rotation handler can rewrite it whole (docs/plans/36-database-credential-rotation.md,
# "Item layout"). The first administrator's password has an item of its own. Items seeded
# before that split kept it in victual-db-migrate: `--from` reads the value from there
# first, so the new item carries the password the database was seeded with. This script
# never removes a field from an existing item; deploy/README.md, "One Secret per
# credential", says when that is safe.
#
# Needs `op` signed in to the account whose vault Connect serves. OP_ACCOUNT and OP_VAULT
# name them; the defaults are this cluster's.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

ACCOUNT="${OP_ACCOUNT:-my.1password.com}"
VAULT="${OP_VAULT:-DevSecOps}"
SECRETS=deploy/talos/.secrets

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

# item  file-under-.secrets  [--from older-item]  field=default ...
#   (default: a literal, or @generate; a value in older-item wins over one in the file)
seed() {
	local item=$1 file=$2 from=
	shift 2
	if [ "${1:-}" = --from ]; then
		from=$2
		shift 2
	fi
	if op item get "$item" --vault "$VAULT" --account "$ACCOUNT" >/dev/null 2>&1; then
		log "$item: exists in $VAULT, left alone"
		return
	fi
	log "$item: creating in $VAULT"
	# The older item's JSON reaches Python on file descriptor 3, never on a command line.
	python3 - "$item" "$SECRETS/$file" "$@" 3< <(
		[ -z "$from" ] || op item get "$from" --vault "$VAULT" --account "$ACCOUNT" --format json --reveal 2>/dev/null || true
	) <<'PY' | op item create --vault "$VAULT" --account "$ACCOUNT" >/dev/null
import json, os, secrets, string, sys

title, path, specs = sys.argv[1], sys.argv[2], sys.argv[3:]
existing = {}
if os.path.exists(path):
    for line in open(path):
        key, sep, value = line.rstrip("\n").partition("=")
        if sep:
            existing[key] = value
older = os.fdopen(3).read().strip()
if older:
    for field in json.loads(older).get("fields", []):
        if field.get("label") and field.get("value"):
            existing[field["label"]] = field["value"]

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
	VICTUAL_DB_PASSWORD=@generate
seed victual-bootstrap-admin migrate.env --from victual-db-migrate \
	VICTUAL_BOOTSTRAP_ADMIN_PASSWORD=@generate
seed victual-db-app app.env \
	VICTUAL_DB_USER=victual_app \
	VICTUAL_DB_PASSWORD=@generate
