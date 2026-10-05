FROM alpine:3.20
RUN apk add --no-cache jq su-exec libcap busybox-extras
COPY probe.sh /probe.sh
ENV PROBE_SLUG=victual_probe_root
CMD ["/bin/sh", "/probe.sh"]
