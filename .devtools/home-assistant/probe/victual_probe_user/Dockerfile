FROM alpine:3.20
RUN apk add --no-cache jq su-exec libcap busybox-extras
COPY probe.sh /probe.sh
ENV PROBE_SLUG=victual_probe_user
# The uid the Victual images use (nix/images/lib.nix).
USER 65532:65532
CMD ["/bin/sh", "/probe.sh"]
