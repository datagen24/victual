FROM alpine:3.20
RUN apk add --no-cache jq su-exec libcap busybox-extras
COPY probe.sh api-options.sh token-in.sh /opt/probe/
COPY index.cgi /opt/probe/www/cgi-bin/index.cgi
RUN chmod 755 /opt/probe/*.sh /opt/probe/www/cgi-bin/index.cgi \
 && printf '<meta http-equiv="refresh" content="0; url=cgi-bin/index.cgi">\n' > /opt/probe/www/index.html
ENV PROBE_SLUG=victual_probe_root PROBE_OTHER_SLUG=local_victual_probe_user
CMD ["/bin/sh", "/opt/probe/probe.sh"]
