#!/usr/bin/env python3
"""LOCAL TEST ONLY — HTTPS front door for the local test site (scripts/local-site.sh).

    python3 scripts/local/tls-proxy.py --cert ~/.mowology-local/tls/claudia.pem --key ~/.mowology-local/tls/claudia.key \
        --listen 127.0.0.1:8443 --upstream 127.0.0.1:8000

The crew app only ever speaks https to a plain hostname with no port (ServerConfig.kt), and Tenant.php picks the
business from the Host header. The PHP built-in server speaks plain http. This sits between them: it terminates TLS
with the local test certificate and forwards every request unchanged (same Host, same body) to the PHP server.
The emulator reaches it through `adb reverse tcp:443 tcp:8443` and a hosts entry (scripts/local-android.sh).

Loopback only by default. Nothing here is production code.
"""
import argparse
import http.client
import ssl
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

HOP_BY_HOP = {'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailers',
              'transfer-encoding', 'upgrade'}


def make_handler(upstream_host: str, upstream_port: int):
    class Handler(BaseHTTPRequestHandler):
        protocol_version = 'HTTP/1.1'

        def log_message(self, fmt, *args):  # one line per request, like the PHP server
            sys.stderr.write('%s %s\n' % (self.headers.get('Host', '-'), fmt % args))

        def _forward(self):
            length = int(self.headers.get('Content-Length') or 0)
            body = self.rfile.read(length) if length else None
            headers = {k: v for k, v in self.headers.items() if k.lower() not in HOP_BY_HOP}
            headers['X-Forwarded-Proto'] = 'https'
            headers['X-Forwarded-For'] = self.client_address[0]
            headers['Connection'] = 'close'
            try:
                conn = http.client.HTTPConnection(upstream_host, upstream_port, timeout=120)
                conn.request(self.command, self.path, body=body, headers=headers)
                resp = conn.getresponse()
                data = resp.read()
            except OSError as e:
                self.send_error(502, 'upstream %s:%d: %s' % (upstream_host, upstream_port, e))
                return
            self.send_response(resp.status, resp.reason)
            for k, v in resp.getheaders():
                if k.lower() in HOP_BY_HOP or k.lower() == 'content-length':
                    continue
                self.send_header(k, v)
            self.send_header('Content-Length', str(len(data)))
            self.send_header('Connection', 'close')
            self.end_headers()
            if self.command != 'HEAD':
                self.wfile.write(data)
            conn.close()

        do_GET = do_POST = do_PUT = do_PATCH = do_DELETE = do_HEAD = do_OPTIONS = _forward

    return Handler


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument('--cert', required=True)
    ap.add_argument('--key', required=True)
    ap.add_argument('--listen', default='127.0.0.1:8443')
    ap.add_argument('--upstream', default='127.0.0.1:8000')
    a = ap.parse_args()
    lh, lp = a.listen.rsplit(':', 1)
    uh, up = a.upstream.rsplit(':', 1)
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(a.cert, a.key)
    srv = ThreadingHTTPServer((lh, int(lp)), make_handler(uh, int(up)))
    srv.daemon_threads = True
    srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
    sys.stderr.write('tls-proxy: https://%s:%s -> http://%s:%s\n' % (lh, lp, uh, up))
    try:
        srv.serve_forever()
    except KeyboardInterrupt:
        pass
    return 0


if __name__ == '__main__':
    sys.exit(main())
