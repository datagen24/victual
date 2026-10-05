#!/bin/sh
# Usage: token-in.sh <pid>... Reports whether this process can read each pid's environment
# and whether SUPERVISOR_TOKEN appears in it. Never prints a value.
for pid in "$@"; do
	if tr '\0' '\n' < "/proc/$pid/environ" > /dev/null 2>&1; then
		if tr '\0' '\n' < "/proc/$pid/environ" | grep -q '^SUPERVISOR_TOKEN='; then
			echo "pid $pid environ readable by uid $(id -u); contains SUPERVISOR_TOKEN"
		else
			echo "pid $pid environ readable by uid $(id -u); no SUPERVISOR_TOKEN"
		fi
	else
		echo "pid $pid environ not readable by uid $(id -u)"
	fi
done
