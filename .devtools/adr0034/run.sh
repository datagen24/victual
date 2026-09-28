#!/usr/bin/env bash
# Run only against a disposable, fully migrated PostgreSQL database.
set -euo pipefail
if [[ $# != 2 || ( "$2" != current && "$2" != candidate ) ]]; then
  echo "Usage: $0 DATABASE current|candidate" >&2
  exit 2
fi
candidate=false
[[ "$2" == candidate ]] && candidate=true
output=$(mktemp)
trap 'rm -f "$output"' EXIT
psql -X -A -t --set=ON_ERROR_STOP=on --set=candidate="$candidate" \
  --dbname="$1" --file="$(dirname "$0")/fixtures.sql" > "$output"
cat "$output"
if grep -Eq '^not ok|^# Looks like' "$output"; then
  exit 1
fi
