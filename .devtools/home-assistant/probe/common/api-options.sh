#!/bin/sh
# Usage: api-options.sh [path]. Calls the Supervisor API with the SUPERVISOR_TOKEN in this
# process's environment and prints the HTTP status and key names only, never values:
# the keys of .data, and the keys of .data.options when the response carries an add-on's
# options. The path defaults to this add-on's own options.
path=${1:-/addons/self/options/config}
if [ -z "${SUPERVISOR_TOKEN:-}" ]; then
	echo "no SUPERVISOR_TOKEN in this environment"
	exit 0
fi
hdr=$(mktemp)
body=$(wget -S -q -O - --header "Authorization: Bearer $SUPERVISOR_TOKEN" \
	"http://supervisor$path" 2>"$hdr")
status=$(grep -m1 'HTTP/' "$hdr" | sed 's/^ *//')
echo "GET $path status: ${status:-none; $(head -n 1 "$hdr")}"
if [ "$path" = /addons/self/options/config ]; then
	printf '%s' "$body" | jq -r '.data | keys[]?' 2>/dev/null | sed 's/^/key: /'
else
	printf '%s' "$body" | jq -r '.data.options // empty | keys[]?' 2>/dev/null | sed 's/^/options key: /'
	printf '%s' "$body" | jq -r '.message // empty' 2>/dev/null | sed 's/^/message: /'
fi
rm -f "$hdr"
