FROM debian:12-slim
RUN apt-get update && apt-get install -y --no-install-recommends imapproxy \
 && rm -rf /var/lib/apt/lists/* && mkdir -p /var/run/imapproxy && chown nobody:nogroup /var/run/imapproxy
COPY imapproxy.conf /etc/imapproxy.conf
CMD ["/usr/sbin/imapproxyd", "-f", "/etc/imapproxy.conf"]
