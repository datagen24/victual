#!/bin/sh
# Issue 648: generate a Swift client from victual.openapi.json with swift-openapi-generator
# (version pinned by Package.resolved) and decode one row per listable entity under the
# GET /objects unions. Needs a Swift 5.10+ toolchain and network access for the first
# package resolve. Usage: .devtools/issue648/run.sh [document] (default: victual.openapi.json)
set -eu
here=$(cd "$(dirname "$0")" && pwd)
root=$(cd "$here/../.." && pwd)
doc=${1:-$root/victual.openapi.json}
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
cp "$here/Package.swift" "$here/Package.resolved" "$work/"
mkdir -p "$work/Sources/UnionCheck"
cp "$here/Sources/UnionCheck/main.swift" "$here/Sources/UnionCheck/openapi-generator-config.yaml" "$work/Sources/UnionCheck/"
python3 -I "$here/prepare.py" "$doc" "$root/tests/Pgsql/snapshots/contract-admin.json" "$work"
(cd "$work" && swift build 2>&1 | grep -E "warning: Schema .*(not supported).*(objects|Union)" || true)
(cd "$work" && swift build -q && swift run -q UnionCheck rows.json)
