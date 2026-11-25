#!/usr/bin/env python3
"""
csp-check.py — does a page satisfy its own nonce CSP? (Fable #22, 2026-10-12)

Fetches each URL, reads the Content-Security-Policy header(s), and reports:
  - inline <script> blocks (not JSON-LD / src) without the header's nonce
  - inline event-handler attributes (onclick=, onerror=, ...) — blocked by script-src-attr 'none'
  - javascript: URLs
  - external scripts/styles whose host the policy does not allow
Exit 1 if anything would be blocked.

Usage: scripts/csp-check.py http://localhost:8000 / /about /customer/portal.php?token=...
"""
import re, sys, urllib.request, urllib.parse, http.cookiejar

def fetch(url):
    req = urllib.request.Request(url, headers={'User-Agent': 'csp-check'})
    with urllib.request.urlopen(req) as r:
        return r.headers.get_all('Content-Security-Policy') or [], r.read().decode('utf-8', 'replace')

def directive(policy, name):
    for part in policy.split(';'):
        bits = part.strip().split()
        if bits and bits[0] == name:
            return bits[1:]
    return None

def host_ok(src, allowed, base):
    u = urllib.parse.urlparse(urllib.parse.urljoin(base, src))
    origin = f'{u.scheme}://{u.netloc}'
    b = urllib.parse.urlparse(base)
    if origin == f'{b.scheme}://{b.netloc}':
        return "'self'" in allowed
    for a in allowed:
        if a.startswith('https://*.') and u.scheme == 'https' and u.netloc.endswith(a[9:]):
            return True
        if a.rstrip('/') == origin:
            return True
    return False

def check(base, path):
    url = base.rstrip('/') + path
    pols, html = fetch(url)
    problems = []
    if not pols:
        return url, ['NO CSP header']
    strict = [p for p in pols if "'unsafe-inline'" not in (directive(p, 'script-src') or [])]
    if not strict:
        return url, ['only a lax CSP (unsafe-inline) — PHP nonce policy not sent']
    pol = strict[0]
    ss = directive(pol, 'script-src') or directive(pol, 'default-src') or []
    nonces = {s[7:-1] for s in ss if s.startswith("'nonce-")}
    for m in re.finditer(r'<script\b([^>]*)>', html, re.I):
        attrs = m.group(1)
        line = html.count('\n', 0, m.start()) + 1
        src = re.search(r'\bsrc\s*=\s*["\']([^"\']+)', attrs)
        if src:
            if not host_ok(src.group(1), ss, url):
                problems.append(f'L{line}: script host not allowed: {src.group(1)}')
            continue
        if re.search(r'type\s*=\s*["\']application/(ld\+)?json', attrs, re.I):
            continue
        n = re.search(r'nonce\s*=\s*["\']([^"\']+)', attrs)
        if not n or n.group(1) not in nonces:
            problems.append(f'L{line}: inline <script> without the nonce')
    for m in re.finditer(r'<[a-z][^>]*\s(on[a-z]+)\s*=', html, re.I):
        problems.append(f'L{html.count(chr(10), 0, m.start()) + 1}: inline handler {m.group(1)}=')
    for m in re.finditer(r'href\s*=\s*["\']\s*javascript:', html, re.I):
        problems.append(f'L{html.count(chr(10), 0, m.start()) + 1}: javascript: URL')
    st = directive(pol, 'style-src') or []
    for m in re.finditer(r'<link\b[^>]*rel=["\'](?:stylesheet|preload)["\'][^>]*href=["\']([^"\']+)', html, re.I):
        if m.group(1).startswith('http') and not host_ok(m.group(1), st, url):
            problems.append(f'stylesheet host not allowed: {m.group(1)}')
    return url, problems

if __name__ == '__main__':
    if len(sys.argv) < 3:
        print(__doc__); sys.exit(2)
    base, bad = sys.argv[1], 0
    for p in sys.argv[2:]:
        try:
            url, probs = check(base, p)
        except Exception as e:
            url, probs = base + p, [f'fetch failed: {e}']
        print(('OK   ' if not probs else 'FAIL ') + url)
        for x in probs:
            print('       ' + x)
        bad += bool(probs)
    sys.exit(1 if bad else 0)
