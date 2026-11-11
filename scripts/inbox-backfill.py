#!/usr/bin/env python3
"""Look back through a mailbox, read-only, a window at a time (2026-11-10). Run from the main checkout.
usage: scripts/inbox-backfill.py <sales|icloud> <days> <out.jsonl> [folder] [max_runs]
Appends one JSON line per account match to out.jsonl and prints the counts. Uses scripts/inbox-backfill-scan.php."""
import json, os, subprocess, sys, tempfile
mb, days, path = sys.argv[1], int(sys.argv[2]), sys.argv[3]
folder = sys.argv[4] if len(sys.argv) > 4 else 'INBOX'
max_runs = int(sys.argv[5]) if len(sys.argv) > 5 else 200
here = os.path.dirname(os.path.abspath(__file__))
scan = open(sys.argv[6] if len(sys.argv) > 6 else os.path.join(here, 'inbox-backfill-scan.php'), encoding='utf8').read()
label = 'office@' if mb == 'sales' else 'icloud'
cursor, totals, fails = 0, {}, 0
for run in range(max_runs):
    head = f"$MB = {json.dumps(mb)}; $FOLDER = {json.dumps(folder)}; $MBLABEL = {json.dumps(label)}; $DAYS = {days}; $CURSOR = {cursor};\n"
    with tempfile.NamedTemporaryFile('w', suffix='.php', delete=False, encoding='utf8') as f:
        f.write(head + scan)
    r = subprocess.run([os.path.join(here, 'prod-run.sh'), f.name], capture_output=True, text=True)
    os.unlink(f.name)
    raw = r.stdout
    try:
        d = json.loads(raw[raw.index('{'):])
    except Exception:
        d = {'_error': 'unreadable: ' + raw[:200] + r.stderr[:200]}
    if d.get('_error'):
        fails += 1
        print('run', run, 'ERROR', d['_error'][:300], flush=True)
        if fails >= 5: sys.exit(1)
        continue
    fails = 0
    with open(path, 'a', encoding='utf8') as f:
        for it in d['items']: f.write(json.dumps(it, ensure_ascii=False) + '\n')
    for k, v in d['counts'].items(): totals[k] = totals.get(k, 0) + v
    cursor = d['cursor']
    print('run', run, 'total', d['total'], 'left', d['left_at_start'], 'cursor', cursor, 'sec', d['seconds'], d['counts'], flush=True)
    if d['done']: break
print('DONE', mb, folder, totals, flush=True)
