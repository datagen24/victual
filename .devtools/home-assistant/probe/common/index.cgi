#!/bin/sh
# CGI for the probe's listener: appends the request's source and headers to the report
# whose path probe.sh wrote to /tmp/report-path. Cookie and Authorization values are withheld.
REPORT=$(cat /tmp/report-path)
{
	echo ""
	echo "### request $(date -u +%Y%m%dT%H%M%SZ)"
	echo "REMOTE_ADDR=$REMOTE_ADDR"
	echo "REQUEST_METHOD=$REQUEST_METHOD REQUEST_URI=$REQUEST_URI"
	env | grep '^HTTP_' | grep -v -i -E '^HTTP_(COOKIE|AUTHORIZATION)=' | sort
	env | grep -i -E '^HTTP_(COOKIE|AUTHORIZATION)=' | cut -d= -f1 | sed 's/$/ (value withheld)/'
} >> "$REPORT"
printf 'Content-Type: text/plain\r\n\r\n'
echo "victual probe report $(basename "$REPORT")"
echo "REMOTE_ADDR=$REMOTE_ADDR"
echo "X-Ingress-Path=$HTTP_X_INGRESS_PATH"
