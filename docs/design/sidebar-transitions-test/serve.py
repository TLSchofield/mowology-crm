#!/usr/bin/env python3
"""Tiny static server for the sidebar-transition test. http://localhost:8765/  (normal)  and  /slow/  (HTML answers after 400 ms,
like a busy PHP page). Run:  python3 serve.py"""
import http.server, os, time, functools

ROOT = os.path.dirname(os.path.abspath(__file__))

class H(http.server.SimpleHTTPRequestHandler):
    def translate_path(self, path):
        self.slow = path.startswith('/slow/')
        if self.slow:
            path = path[len('/slow'):]
        return super().translate_path(path)
    def do_GET(self):
        super_path = self.path
        if super_path.startswith('/slow/') and (super_path.split('?')[0].endswith('.html') or super_path.endswith('/')):
            time.sleep(0.4)
        return super().do_GET()
    def end_headers(self):
        self.send_header('Cache-Control', 'no-store')
        super().end_headers()
    def log_message(self, *a): pass

if __name__ == '__main__':
    os.chdir(ROOT)
    srv = http.server.ThreadingHTTPServer(('127.0.0.1', 8765), H)
    print('Sidebar transition test: http://localhost:8765/   (slow server: http://localhost:8765/slow/)')
    srv.serve_forever()
