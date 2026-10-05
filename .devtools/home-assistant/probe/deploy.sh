#!/bin/sh
# Copy the probe add-ons into a Home Assistant host's local add-on directory.
# Usage: deploy.sh <ssh-host>. The host's SSH user needs passwordless sudo.
# Afterwards: Settings > Add-ons > Add-on store > menu > Check for updates, then install
# a probe from "Local add-ons". Reports land in /share/victual-probe/.
set -eu
host=$1
here=$(cd "$(dirname "$0")" && pwd)
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
for v in root user; do
	mkdir -p "$stage/victual_probe_$v"
	cp "$here/victual_probe_$v/config.yaml" "$here/victual_probe_$v/Dockerfile" "$stage/victual_probe_$v/"
	cp "$here/common/probe.sh" "$stage/victual_probe_$v/"
done
tar -C "$stage" -cf - victual_probe_root victual_probe_user |
	ssh "$host" 'sudo mkdir -p /addons /share/victual-probe && sudo chmod 1777 /share/victual-probe && sudo tar -C /addons --no-same-owner -xf - && ls -l /addons/victual_probe_root /addons/victual_probe_user'
