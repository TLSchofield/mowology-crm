#!/usr/bin/env python3
"""Writes the five solutions into folders a- to e-. Run: python3 build.py"""
import os, textwrap, json
ROOT = os.path.dirname(os.path.abspath(__file__))
PAGES = [('today','Today'),('clients','Clients'),('quotes','Quotes'),('jobs','Jobs'),('schedule','Schedule'),('invoices','Invoices')]
HEADS = {'today': ('K', 'Kaname', 'Chief of staff', '#233a5e', 'Two things need you today'),
 'clients': ('Y', 'Yui', 'Client relations', '#8a4fb0', '3 replies are waiting for you'),
 'quotes': ('S', 'Sam', 'Sales', '#2d8659', 'Send the Alder Test quote?'),
 'jobs': ('O', 'Otto', 'Operations', '#d9822b', 'Move the Friday visit for rain?'),
 'schedule': ('O', 'Otto', 'Operations', '#d9822b', 'Two crews are double-booked'),
 'invoices': ('P', 'Penny', 'Bookkeeping', '#c0392b', 'Match this $1,102.50 deposit?')}
ROWS = {'today':14,'clients':30,'quotes':9,'jobs':22,'schedule':12,'invoices':26}

def rows(key):
    return ''.join('<div class="row"><span>%s item %d</span><span>%dd</span></div>' % (key.title(), i+1, (i*3)%17+1) for i in range(ROWS[key]))

def content(key, title):
    h = HEADS[key]
    strip = '<div class="decision" style="--hc:%s"><div><b>%s</b><span>%s asks</span></div><button>Yes, do it</button></div>' % (h[3], h[4], h[1])
    return ('<h1>%s</h1><p class="sub">Test page: %d rows. Content height differs per page on purpose.</p>' + strip +
            '<div class="stats"><div class="stat"><b>%d</b>open</div><div class="stat"><b>$%d</b>value</div><div class="stat"><b>%d%%</b>rate</div><div class="stat"><b>%d</b>days</div></div>'
            '<div class="rows">%s</div>') % (title, ROWS[key], ROWS[key], ROWS[key]*410, 40+ROWS[key], ROWS[key]%9+2, rows(key))

def menu(active, dirprefix=''):
    idx = [k for k,_ in PAGES].index(active)
    links = ''.join('<a href="%s%s.html"%s>%s</a>' % (dirprefix, k, ' aria-current="page"' if k==active else '', t) for k,t in PAGES)
    return '<nav class="menu" style="--i:%d">%s<span class="hl"></span></nav>' % (idx, links)

def card(key):
    h = HEADS[key]
    return '<div class="card" style="--hc:%s"><div class="face">%s</div><b>%s</b><small>%s</small></div>' % (h[3], h[0], h[1], h[2])

def sidebar(active, dirprefix='', link_prefix=None):
    return ('<aside class="sidebar" id="sidebar"><div class="logo">ittai</div>'
            '%s%s'
            '<div class="foot">Sidebar built <b id="age">0</b> s ago</div></aside>') % (card(active), menu(active, link_prefix if link_prefix is not None else dirprefix))

def page(active, title, head_extra, body_class, js, depth='..', with_sidebar=True, sidebar_html=None):
    sb = (sidebar_html if sidebar_html is not None else sidebar(active)) if with_sidebar else ''
    return textwrap.dedent('''\
        <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>%s</title><link rel="stylesheet" href="%s/shared/app.css">%s</head>
        <body class="%s"><div class="shell">%s<main id="main">%s</main></div>
        <script src="%s/shared/probe.js"></script>%s</body></html>''') % (title, depth, head_extra, body_class, sb, content(active, title), depth, js)

VT = '''<style>
@view-transition { navigation: auto; }
.sidebar { view-transition-name: sidebar; }
.hl { view-transition-name: hl; }
main { view-transition-name: main; }
::view-transition-group(sidebar) { animation: none; }
::view-transition-group(hl) { animation-duration: 260ms; animation-timing-function: cubic-bezier(.2,.7,.2,1); }
::view-transition-old(main) { animation: 90ms ease-out both vt-out; }
::view-transition-new(main) { animation: 160ms ease-out 40ms both vt-in; }
@keyframes vt-out { to { opacity: 0; } } @keyframes vt-in { from { opacity: 0; } }
@media (prefers-reduced-motion: reduce) { ::view-transition-group(*), ::view-transition-old(*), ::view-transition-new(*) { animation: none !important; } }
</style>'''
EXPECT = '<link rel="expect" href="#sidebar" blocking="render">'
SPEC = '<script type="speculationrules">{"prerender":[{"where":{"href_matches":"*.html"},"eagerness":"immediate"}]}</script>'

# shared in-page navigation helpers for B and E
SWAP = r'''
function parse(html) { return new DOMParser().parseFromString(html, 'text/html'); }
function setActive(url) {
  var file = new URL(url, location.href).pathname.split('/').pop();
  var links = [].slice.call(document.querySelectorAll('.menu a')), i = 0;
  links.forEach(function (a, n) { var on = a.getAttribute('href') === file; if (on) i = n; if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); });
  document.querySelector('.menu').style.setProperty('--i', i);
}
function swapIn(doc, url) {
  var next = doc.getElementById('main'); document.title = doc.title;
  document.getElementById('main').innerHTML = next.innerHTML;
  var oc = document.querySelector('.card'), nc = doc.querySelector('.card');
  if (oc && nc) { oc.setAttribute('style', nc.getAttribute('style')); oc.innerHTML = nc.innerHTML; oc.classList.remove('pop'); void oc.offsetWidth; oc.classList.add('pop'); }
  window.scrollTo(0, 0);
}
function withTransition(fn) {
  if (!document.startViewTransition) { fn(); return Promise.resolve(); }
  var t = document.startViewTransition(fn); return t.finished.catch(function () {});
}
'''
B_JS = '<script>' + SWAP + r'''
// B: classic soft navigation (pjax). A click is caught, the highlight moves at once, the page is fetched, only <main> is swapped.
var cache = {};
function load(url) { return cache[url] || (cache[url] = fetch(url).then(function (r) { return r.text(); })); }
function go(url, push) {
  setActive(url); document.body.classList.add('loading');
  load(url).then(function (html) {
    return withTransition(function () { swapIn(parse(html), url); if (push) history.pushState({}, '', url); });
  }).then(function () { document.body.classList.remove('loading'); delete cache[url]; });
}
document.addEventListener('click', function (e) {
  var a = e.target.closest && e.target.closest('.menu a'); if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;
  e.preventDefault(); if (a.getAttribute('aria-current') === 'page') return; go(a.href, true);
});
addEventListener('popstate', function () { go(location.href, false); });
</script>'''
E_JS = '<script>' + SWAP + r'''
// E: Navigation API. Every same-origin navigation is intercepted (links, back, forward, address-bar edits to this site, code),
// the page is fetched ahead on hover/press, the highlight is optimistic, and the swap runs inside one view transition.
var cache = {};
function load(url) { return cache[url] || (cache[url] = fetch(url).then(function (r) { return r.text(); })); }
['pointerenter', 'pointerdown', 'focusin'].forEach(function (ev) {
  document.addEventListener(ev, function (e) { var a = e.target.closest && e.target.closest('.menu a'); if (a) load(a.href); }, true);
});
if (window.navigation) {
  navigation.addEventListener('navigate', function (e) {
    if (!e.canIntercept || e.hashChange || e.downloadRequest) return;
    var url = e.destination.url; if (new URL(url).pathname.indexOf('.html') < 0) return;
    e.intercept({ scroll: 'manual', focusReset: 'manual', handler: function () {
      setActive(url); document.body.classList.add('loading');
      return load(url).then(function (html) {
        return withTransition(function () { swapIn(parse(html), url); });
      }).then(function () { document.body.classList.remove('loading'); delete cache[url]; });
    }});
  });
} else { document.body.insertAdjacentHTML('afterbegin', '<p style="padding:8px;background:#fde">This browser has no Navigation API; links load normally.</p>'); }
</script>'''
C_SHELL_JS = r'''<script>
// C: the sidebar lives in this shell and is never reloaded; the pages load inside the frame. The highlight moves on press.
var HEADS = __HEADS__;
var frame = document.getElementById('frame'), menu = document.querySelector('.menu');
function mark(file) {
  var links = [].slice.call(menu.querySelectorAll('a')), i = 0;
  links.forEach(function (a, n) { var on = a.getAttribute('data-file') === file; if (on) i = n; if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); });
  menu.style.setProperty('--i', i);
  var h = HEADS[file.replace('.html', '')], c = document.querySelector('.card');
  if (h && c) { c.style.setProperty('--hc', h[3]); c.innerHTML = '<div class="face">' + h[0] + '</div><b>' + h[1] + '</b><small>' + h[2] + '</small>'; c.classList.remove('pop'); void c.offsetWidth; c.classList.add('pop'); }
}
menu.addEventListener('click', function (e) {
  var a = e.target.closest('a'); if (!a) return; e.preventDefault();
  mark(a.getAttribute('data-file')); frame.classList.add('wait'); frame.src = 'pages/' + a.getAttribute('data-file');
  history.replaceState(null, '', '#' + a.getAttribute('data-file').replace('.html', ''));
});
frame.addEventListener('load', function () { frame.classList.remove('wait'); try { mark(frame.contentWindow.location.pathname.split('/').pop()); } catch (e) {} });
var h = location.hash.slice(1); if (h) { frame.src = 'pages/' + h + '.html'; mark(h + '.html'); }
</script>'''

def write(path, text):
    full = os.path.join(ROOT, path); os.makedirs(os.path.dirname(full), exist_ok=True)
    open(full, 'w').write(text)

for k, t in PAGES:
    write('a-crossdoc/%s.html' % k, page(k, t, VT + EXPECT, '', ''))
    write('d-prerender/%s.html' % k, page(k, t, VT + EXPECT + SPEC, '', ''))
    write('b-pjax/%s.html' % k, page(k, t, VT, 'soft', B_JS))
    write('e-navapi/%s.html' % k, page(k, t, VT, 'soft', E_JS))
    # C: content-only pages inside the frame (no sidebar of their own)
    write('c-iframe/pages/%s.html' % k, textwrap.dedent('''\
        <!doctype html><html lang="en"><head><meta charset="utf-8"><title>%s</title><link rel="stylesheet" href="../../shared/app.css"></head>
        <body style="background:var(--page)"><main id="main" style="padding:28px 32px 80px">%s</main></body></html>''') % (t, content(k, t)))

# C shell
links = ''.join('<a href="pages/%s.html" data-file="%s.html"%s>%s</a>' % (k, k, ' aria-current="page"' if k=='today' else '', t) for k,t in PAGES)
shell_sidebar = ('<aside class="sidebar" id="sidebar"><div class="logo">ittai</div>' + card('today') +
                 '<nav class="menu" style="--i:0">%s<span class="hl"></span></nav><div class="foot">Sidebar built <b id="age">0</b> s ago</div></aside>') % links
write('c-iframe/index.html', textwrap.dedent('''\
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>C: frame</title>
    <link rel="stylesheet" href="../shared/app.css"><style>#frame{flex:1;border:0;width:100%%;height:100vh;transition:opacity 140ms;display:block}#frame.wait{opacity:.55}</style></head>
    <body class="soft"><div class="shell">%s<iframe id="frame" src="pages/today.html" title="page"></iframe></div>
    <script src="../shared/probe.js"></script>%s</body></html>''') % (shell_sidebar, C_SHELL_JS.replace('__HEADS__', json.dumps(HEADS))))
print('built')
