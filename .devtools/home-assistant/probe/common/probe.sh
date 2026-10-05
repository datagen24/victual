#!/bin/sh
# Gate G1 probe for plan 35 (docs/plans/35-home-assistant-target.md).
#
# Records what the Supervisor actually gives an add-on container: its user, capabilities,
# mounts, options-file permissions, cgroup limits, and network; then serves a CGI page on
# the Ingress port that records each request's source address and headers. It prints no
# option values. With fail_after_seconds > 0 it stops the listener while PID 1 stays up,
# so the report shows whether the watchdog restarts the add-on.
set -u

SLUG="${PROBE_SLUG:-unknown}"
OUT_DIR=/share/victual-probe
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p "$OUT_DIR" 2>/dev/null
if touch "$OUT_DIR/.w" 2>/dev/null; then
	rm -f "$OUT_DIR/.w"
	REPORT="$OUT_DIR/$SLUG-$STAMP.txt"
else
	REPORT=/tmp/report.txt
fi

say() { printf '%s\n' "$*" | tee -a "$REPORT"; }
section() { say ""; say "## $1"; }
run() { say "\$ $*"; sh -c "$*" 2>&1 | tee -a "$REPORT"; }

say "# victual probe: $SLUG at $STAMP"
say "report: $REPORT"

section "identity"
run "id"
run "grep -E '^(Uid|Gid|Groups|CapInh|CapPrm|CapEff|CapBnd|CapAmb|NoNewPrivs|Seccomp):' /proc/self/status"
run "capsh --decode=\$(awk '/^CapBnd/ {print \$2}' /proc/self/status)"
run "cat /proc/self/attr/current; echo"
run "tr '\\0' ' ' < /proc/1/cmdline; echo"
run "ls -l /proc/1/exe"

section "environment names (values withheld)"
run "env | cut -d= -f1 | sort"

section "mounts"
run "awk '{print \$5, \$6, \$9, \$10, \$11}' /proc/self/mountinfo"

section "root filesystem and scratch"
for d in / /usr /etc /data /share /tmp; do
	if touch "$d/.probe-rw" 2>/dev/null; then
		rm -f "$d/.probe-rw"
		say "$d writable"
	else
		say "$d not writable"
	fi
done
run "stat -c '%n %a %U:%G' / /data /share /tmp"

section "options file"
run "stat -c '%n %a %U:%G %s bytes' /data/options.json"
if [ -r /data/options.json ]; then
	say "options.json readable by $(id -u)"
	run "jq -r 'keys[]' /data/options.json"
else
	say "options.json NOT readable by $(id -u)"
fi
run "ls -la /data"

if [ "$(id -u)" = 0 ]; then
	section "as uid 65532 from a root container"
	run "su-exec 65532:65532 sh -c 'test -r /data/options.json && echo options.json readable || echo options.json not readable'"
	PROBE_FAKE_ELEVATED=probe-env-marker sleep 300 &
	SLEEPER=$!
	sleep 1
	run "su-exec 65532:65532 sh -c 'grep -c PROBE_FAKE_ELEVATED /proc/$SLEEPER/environ 2>&1 || echo environ of root process not readable'"
	run "su-exec 65532:65532 sh -c 'ls -l /proc/$SLEEPER/fd 2>&1 | head -3'"
	run "su-exec 65532:65532 sh -c 'tr \"\\0\" \" \" < /proc/$SLEEPER/cmdline; echo'"
	kill "$SLEEPER" 2>/dev/null
	run "grep -E '^(hidepid|proc)' /proc/mounts; grep ' /proc ' /proc/mounts"
fi

section "options through the Supervisor API"
# Records the HTTP status and the option keys only, never values.
say "as uid $(id -u) with the inherited environment:"
/opt/probe/api-options.sh 2>&1 | tee -a "$REPORT"
say "as uid $(id -u) with both tokens removed:"
env -u SUPERVISOR_TOKEN -u HASSIO_TOKEN /opt/probe/api-options.sh 2>&1 | tee -a "$REPORT"
if [ "$(id -u)" = 0 ]; then
	say "as a uid 65532 child with the inherited environment:"
	su-exec 65532:65532 /opt/probe/api-options.sh 2>&1 | tee -a "$REPORT"
fi

section "resource limits"
run "cat /proc/self/cgroup"
for f in memory.max memory.high cpu.max cpu.weight pids.max; do
	[ -r "/sys/fs/cgroup/$f" ] && say "$f: $(cat /sys/fs/cgroup/$f)"
done
run "ulimit -a"
run "nproc; grep MemTotal /proc/meminfo"

section "network"
run "hostname"
run "ip -4 addr"
run "ip route"
run "cat /etc/resolv.conf"
run "getent hosts supervisor homeassistant core-mosquitto a0d7b954-influxdb 2>&1"

section "start counter"
COUNTER=/data/probe-starts
if [ -w /data ]; then
	n=$(( $(cat "$COUNTER" 2>/dev/null || echo 0) + 1 ))
	echo "$n" > "$COUNTER"
	say "start number $n"
else
	say "/data not writable; start count not kept"
fi

section "ingress listener"
# The listener's files are in the image: with tmpfs enabled, /tmp is mounted noexec.
echo "$REPORT" > /tmp/report-path
httpd -f -p 8099 -h /opt/probe/www &
HTTPD=$!
sleep 1
if kill -0 "$HTTPD" 2>/dev/null; then say "httpd pid $HTTPD on 8099"; else say "httpd failed to start"; fi
trap 'say "SIGTERM at $(date -u +%Y%m%dT%H%M%SZ)"; kill $HTTPD 2>/dev/null; exit 0' TERM INT

FAIL_AFTER=$(jq -r '.fail_after_seconds // 0' /data/options.json 2>/dev/null || echo 0)
if [ "$FAIL_AFTER" -gt 0 ] 2>/dev/null; then
	say "listener stops after $FAIL_AFTER s; PID 1 stays up"
	sleep "$FAIL_AFTER"
	kill "$HTTPD"
	say "listener stopped at $(date -u +%Y%m%dT%H%M%SZ)"
fi

while :; do sleep 3600 & wait $!; done
