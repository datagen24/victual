#!/bin/sh
if [ -z "${SUPERVISOR_TOKEN:-}" ]; then
	echo "no SUPERVISOR_TOKEN in this environment"
	exit 0
fi
hdr=$(mktemp)
body=$(wget -S -q -O - --header "Authorization: Bearer $SUPERVISOR_TOKEN" \
	http://supervisor/addons/self/options/config 2>"$hdr")
status=$(grep -m1 'HTTP/' "$hdr" | sed 's/^ *//')
echo "status: ${status:-none; $(head -n 1 "$hdr")}"
printf '%s' "$body" | jq -r '.data | keys[]?' 2>/dev/null | sed 's/^/key: /'
rm -f "$hdr"
