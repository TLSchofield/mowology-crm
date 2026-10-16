#!/usr/bin/env python3
"""
Build the Codex review progress page (Tim, 2026-10-16: "show graphically what has been repaired").

    python3 scripts/codex-schedule/progress-page.py [out.html]

Reads ~/.mowology-codex/queue.json (every review part), state.json (what's been reviewed) and
findings.json (every finding Claude checked: unit, severity, title, status fixed|not_real|left, note).
Writes one self-contained HTML page — no client data, only part names and one-line findings — which a
session republishes as the "Codex Repair Log" artifact after checking new reports.
"""
import datetime, html, json, os, sys

HOME = os.path.expanduser('~/.mowology-codex')
AREAS = [('salt-snow', 'Salt & snow'), ('money', 'Money'), ('auth', 'Logins & permissions'), ('public', 'Client portal & public site'),
         ('crew', 'Crew apps'), ('messages', 'Messaging & consent'), ('rest', 'Everything else')]
STATUS = {'fixed': ('Fixed', 'ok'), 'not_real': ('Not a real problem', 'muted'), 'left': ('Left as design', 'warn')}
SEV_ORDER = {'High': 0, 'Medium': 1, 'Low': 2}


def load(name, default):
    try:
        return json.load(open(os.path.join(HOME, name)))
    except Exception:
        return default


def main():
    out = sys.argv[1] if len(sys.argv) > 1 else os.path.join(HOME, 'progress.html')
    units = load('queue.json', {}).get('units', [])
    state = load('state.json', {'pass': 1, 'units': {}, 'files': {}})
    findings = load('findings.json', [])
    files = state.get('files', {})
    p = state.get('pass', 1)
    done = [u for u in units if u['paths'] and all(files.get(f) == p for f in u['paths'])]
    done_ids = {u['id'] for u in done}
    lines_total = sum(u['lines'] for u in units) or 1
    lines_done = sum(u['lines'] for u in done)
    nxt = [u for u in units if u['id'] not in done_ids][:3]
    waiting = [k for k, v in state.get('units', {}).items() if v.get('result') == 'reviewed' and not v.get('checked_by_claude')]

    n = len(findings)
    by = {k: sum(1 for f in findings if f['status'] == k) for k in STATUS}
    high_fixed = sum(1 for f in findings if f['severity'] == 'High' and f['status'] == 'fixed')
    e = html.escape

    # Coverage by area
    rows = []
    for key, label in AREAS:
        au = [u for u in units if u['area'] == key]
        if not au:
            continue
        ad = [u for u in au if u['id'] in done_ids]
        pct = round(100 * sum(u['lines'] for u in ad) / max(1, sum(u['lines'] for u in au)))
        rows.append(f'''<div class="area"><div class="area-name">{e(label)}</div>
<div class="bar" role="img" aria-label="{pct}% reviewed"><span style="width:{pct}%"></span></div>
<div class="area-num">{len(ad)}<span>/{len(au)}</span></div></div>''')

    # Findings grouped by part, newest part first
    title_of = {u['id']: u['title'] for u in units}
    title_of['quote-visits'] = 'Quote visits (pre-release review)'
    groups = []
    order = []
    for f in findings:
        if f['unit'] not in order:
            order.append(f['unit'])
    for uid in reversed(order):
        fs = sorted([f for f in findings if f['unit'] == uid], key=lambda f: (SEV_ORDER.get(f['severity'], 3), f['status'] != 'fixed'))
        items = ''.join(f'''<li class="f">
  <span class="sev sev-{e(f['severity'].lower())}">{e(f['severity'])}</span>
  <div class="f-body"><div class="f-title">{e(f['title'])}</div><div class="f-note">{e(f['note'])}</div></div>
  <span class="pill pill-{STATUS[f['status']][1]}">{STATUS[f['status']][0]}</span>
</li>''' for f in fs)
        fixed = sum(1 for f in fs if f['status'] == 'fixed')
        groups.append(f'''<section class="part"><header><h3>{e(title_of.get(uid, uid))}</h3>
<span class="part-meta">{fixed} of {len(fs)} fixed · {e(fs[0].get('date', ''))}</span></header><ul>{items}</ul></section>''')

    nxt_html = ''.join(f'<li><span class="mono">{e(u["id"])}</span> {e(u["title"])}</li>' for u in nxt)
    pct_all = round(100 * lines_done / lines_total, 1)
    stamp = datetime.datetime.now().strftime('%b %-d, %Y %-I:%M %p')

    page = f'''<title>Codex Repair Log</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;700;800&family=Source+Sans+3:wght@400;600&family=JetBrains+Mono:wght@500&display=swap">
<style>
/* Layout: one column ledger — summary, coverage bars, then findings grouped by reviewed part. Mowology greens. */
:root {{
  --bg:#F3F6F4; --surface:#FFFFFF; --ink:#13241D; --ink-soft:#4E6159; --rule:#DCE5E0;
  --accent:#2D8659; --accent-soft:#E2F0E8; --ok:#2D8659; --ok-bg:#E2F0E8; --warn:#9A5B0E; --warn-bg:#FBF0DD;
  --muted:#5F6B66; --muted-bg:#ECEFED; --high:#B42318; --high-bg:#FCE9E7; --med:#9A5B0E; --med-bg:#FBF0DD; --low:#4E6159; --low-bg:#ECEFED;
  --display:'Archivo', 'Helvetica Neue', Arial, sans-serif; --body:'Source Sans 3', 'Helvetica Neue', Arial, sans-serif; --mono:'JetBrains Mono', ui-monospace, Menlo, monospace;
}}
@media (prefers-color-scheme: dark) {{ :root:not([data-theme="light"]) {{
  --bg:#0F1814; --surface:#16231D; --ink:#E4EEE8; --ink-soft:#9DB0A7; --rule:#26362E;
  --accent:#6CCB95; --accent-soft:#1D3A2B; --ok:#6CCB95; --ok-bg:#1D3A2B; --warn:#E8B566; --warn-bg:#3A2C14;
  --muted:#A4B1AB; --muted-bg:#24302A; --high:#F2877C; --high-bg:#3D1C19; --med:#E8B566; --med-bg:#3A2C14; --low:#A4B1AB; --low-bg:#24302A; color-scheme:dark; }} }}
:root[data-theme="dark"] {{
  --bg:#0F1814; --surface:#16231D; --ink:#E4EEE8; --ink-soft:#9DB0A7; --rule:#26362E;
  --accent:#6CCB95; --accent-soft:#1D3A2B; --ok:#6CCB95; --ok-bg:#1D3A2B; --warn:#E8B566; --warn-bg:#3A2C14;
  --muted:#A4B1AB; --muted-bg:#24302A; --high:#F2877C; --high-bg:#3D1C19; --med:#E8B566; --med-bg:#3A2C14; --low:#A4B1AB; --low-bg:#24302A; color-scheme:dark; }}
body {{ background:var(--bg); color:var(--ink); font:16px/1.5 var(--body); }}
.wrap {{ max-width:880px; margin:0 auto; padding-inline:16px; padding-block:28px 48px; display:grid; gap:28px; }}
h1 {{ font:800 clamp(28px,5vw,40px)/1.1 var(--display); letter-spacing:-0.01em; margin:0; text-wrap:balance; }}
.lede {{ color:var(--ink-soft); margin:6px 0 0; max-width:62ch; }}
.eyebrow {{ font:600 12px/1 var(--body); letter-spacing:.08em; text-transform:uppercase; color:var(--accent); }}
.summary {{ display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; }}
.stat {{ background:var(--surface); border:1px solid var(--rule); border-radius:10px; padding:14px 16px; min-width:0; }}
.stat b {{ display:block; font:800 30px/1 var(--display); font-variant-numeric:tabular-nums; }}
.stat span {{ color:var(--ink-soft); font-size:14px; }}
.stat.lead b {{ color:var(--accent); }}
.overall {{ display:grid; gap:8px; }}
.overall .bar {{ height:12px; }}
.overall-cap {{ display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; color:var(--ink-soft); font-size:14px; }}
h2 {{ font:700 20px/1.2 var(--display); margin:0 0 12px; }}
.areas {{ display:grid; gap:10px; }}
.area {{ display:grid; grid-template-columns:minmax(0,190px) minmax(0,1fr) 56px; gap:12px; align-items:center; }}
.area-name {{ font-weight:600; font-size:15px; }}
.area-num {{ font:500 14px var(--mono); text-align:right; font-variant-numeric:tabular-nums; }}
.area-num span {{ color:var(--ink-soft); }}
.bar {{ height:8px; background:var(--muted-bg); border-radius:99px; overflow:hidden; }}
.bar span {{ display:block; height:100%; background:var(--accent); border-radius:99px; min-width:2px; }}
.part {{ background:var(--surface); border:1px solid var(--rule); border-radius:10px; overflow:hidden; }}
.part header {{ display:flex; justify-content:space-between; align-items:baseline; gap:12px; flex-wrap:wrap; padding:14px 16px; border-bottom:1px solid var(--rule); }}
.part h3 {{ font:700 16px/1.3 var(--display); margin:0; min-width:0; }}
.part-meta {{ color:var(--ink-soft); font-size:13px; }}
.part ul {{ list-style:none; margin:0; padding:0; }}
.f {{ display:grid; grid-template-columns:64px minmax(0,1fr) auto; gap:12px; align-items:start; padding:12px 16px; border-top:1px solid var(--rule); }}
.f:first-child {{ border-top:0; }}
.f-title {{ font-weight:600; }}
.f-note {{ color:var(--ink-soft); font-size:14px; }}
.sev, .pill {{ font:600 12px/1 var(--body); padding:5px 8px; border-radius:6px; text-align:center; white-space:nowrap; }}
.sev-high {{ color:var(--high); background:var(--high-bg); }} .sev-medium {{ color:var(--med); background:var(--med-bg); }} .sev-low {{ color:var(--low); background:var(--low-bg); }}
.pill-ok {{ color:var(--ok); background:var(--ok-bg); }} .pill-warn {{ color:var(--warn); background:var(--warn-bg); }} .pill-muted {{ color:var(--muted); background:var(--muted-bg); }}
.next ul {{ margin:0; padding-left:18px; display:grid; gap:4px; }}
.mono {{ font:500 13px var(--mono); color:var(--ink-soft); }}
footer {{ color:var(--ink-soft); font-size:13px; }}
@media (max-width:640px) {{
  .summary {{ grid-template-columns:repeat(2,minmax(0,1fr)); }}
  .area {{ grid-template-columns:minmax(0,1fr) 52px; }} .area .bar {{ grid-column:1 / -1; grid-row:2; }}
  .f {{ grid-template-columns:minmax(0,1fr) auto; }} .f .sev {{ grid-column:1; justify-self:start; }} .f .f-body {{ grid-column:1 / -1; grid-row:2; }} .f .pill {{ grid-column:2; grid-row:1; }}
}}
</style>
<div class="wrap">
  <div><div class="eyebrow">Mowology · nightly Codex review</div>
  <h1>Codex Repair Log</h1>
  <p class="lede">Every part of the website, iPhone app and Android app gets an independent Codex review, three parts a night, salt and snow first. Claude checks each finding against the real code, fixes the real ones and deploys them.</p></div>

  <div class="summary">
    <div class="stat lead"><b>{by['fixed']}</b><span>fixed and live</span></div>
    <div class="stat"><b>{n}</b><span>findings checked</span></div>
    <div class="stat"><b>{high_fixed}</b><span>serious ones fixed</span></div>
    <div class="stat"><b>{by['not_real'] + by['left']}</b><span>not real or left as design</span></div>
  </div>

  <div class="overall">
    <div class="bar" role="img" aria-label="{pct_all}% of the code reviewed"><span style="width:{pct_all}%"></span></div>
    <div class="overall-cap"><span>{len(done)} of {len(units)} parts reviewed · {lines_done:,} of {lines_total:,} lines ({pct_all}%)</span><span>{len(waiting)} report{'' if len(waiting) == 1 else 's'} waiting for Claude's check · Pass {p} · about {max(0, -(-(len(units) - len(done)) // 3))} nights to go</span></div>
  </div>

  <div><h2>Coverage by area</h2><div class="areas">{''.join(rows)}</div></div>

  <div class="next"><h2>Next up</h2><ul>{nxt_html}</ul></div>

  <div><h2>Findings, by part reviewed</h2><div style="display:grid;gap:14px">{''.join(groups)}</div></div>

  <footer>Updated {e(stamp)}. Findings are listed after Claude has checked them; reports waiting for a check are not shown.</footer>
</div>
'''
    open(out, 'w').write(page)
    print('wrote', out)


if __name__ == '__main__':
    main()
