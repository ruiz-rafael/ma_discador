FROM alpine:3.24.1
RUN apk add --no-cache asterisk=22.9.0-r0 asterisk-srtp=22.9.0-r0 python3 \
 && addgroup -S -g 18080 ma-pbx && adduser -S -D -H -u 18080 -G ma-pbx ma-pbx \
 && mkdir -p /run/asterisk /var/log/asterisk /var/spool/asterisk /var/lib/asterisk /opt/voice \
 && chown -R 18080:18080 /run/asterisk /var/log/asterisk /var/spool/asterisk /var/lib/asterisk
COPY event.py /opt/voice/event.py
RUN chmod 755 /opt/voice/event.py
USER 18080:18080
CMD ["asterisk", "-f", "-C", "/etc/asterisk/asterisk.conf"]
