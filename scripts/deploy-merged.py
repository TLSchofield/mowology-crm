#!/usr/bin/env python3
"""
scripts/deploy-merged.py — deploy a feature branch's files on top of whatever is LIVE.

For files that more than one session deploys (mowology-brand.css, dept-heads-deck.php,
database_appstack.php, …) the repo copy is never the whole truth: production holds
other branches' work too. This does what was done by hand for Sam, Otto, Charlie and
Mia (2026-10-05):

  1. download the live copy of every file (one lftp session);
  2. per file:
       not live            → new: upload the branch version
       live == branch      → nothing to do
       live == base        → upload the branch version as is
       otherwise           → 3-way merge (branch ← base → live). A single conflict where
                             both sides appended at the end of the file is resolved as
                             "live first, then the branch's addition"; the conflict must be at
                             the end of the file, every live line must survive (≤5 changed by
                             clean edits), and a .css file must balance its braces. Anything
                             else stops: merge by hand.
  3. build a throwaway "drift base" commit in a temp worktree (live copies, new files
     absent) and run scripts/deploy-checked.sh against it — so the upload still stops
     if production changes between the download and the upload.

Usage:
  scripts/deploy-merged.py [--dry-run] <branch-ref> <base-ref> <file>...
    branch-ref  what to deploy, e.g. origin/feature/otto-dispatcher-phase2
    base-ref    the ref the branch's changes are relative to (usually the branch it was
                cut from, whose version of each shared file is already live)
Then: run migrations, reset OPcache (/crm/api/opcache-reset.php), verify to </html>.
"""
import os, re, subprocess, sys, tempfile, shutil

def run(cmd, **kw):
    return subprocess.run(cmd, check=True, text=True, capture_output=True, **kw).stdout

def show(ref, path):
    r = subprocess.run(['git', 'show', f'{ref}:{path}'], capture_output=True)
    return r.stdout if r.returncode == 0 else None

def remote_path(f):
    if f.startswith('public/'): return '/' + f[len('public/'):]
    if f.startswith('app/'): return '/' + f
    if f.startswith('database/migrations/'): return '/database/migrations/' + os.path.basename(f)
    sys.exit(f'Refused (unknown destination): {f}')

def _subseq(needle, hay):
    """Yield each needle line found in hay, in order (a longest-prefix walk)."""
    j = 0
    for line in needle:
        k = j
        while k < len(hay) and hay[k] != line:
            k += 1
        if k < len(hay):
            j = k + 1
            yield line

def resolve_appended(merged: bytes, live: bytes, is_css: bool):
    s = merged.decode('utf-8')
    blocks = list(re.finditer(r'^<<<<<<< [^\n]*\n(.*?)^=======\n(.*?)^>>>>>>> [^\n]*\n', s, re.S | re.M))
    if len(blocks) != 1:
        return None, f'{len(blocks)} conflicts'
    m = blocks[0]
    ours, theirs = m.group(1), m.group(2)          # ours = branch, theirs = live
    if s[m.end():].strip() != '':
        return None, 'conflict is not at the end of the file'
    live_lines = live.decode('utf-8').splitlines()
    for glue in ['', '\n', '}\n\n']:
        out = s[:m.start()] + theirs + glue + ours
        if is_css and out.count('{') != out.count('}'):
            continue
        # every live line must survive, in order (clean edits elsewhere may change a few)
        missing = len(live_lines) - sum(1 for _ in _subseq(live_lines, out.splitlines()))
        if missing > 5:
            continue
        return out.encode('utf-8'), None
    return None, 'not a both-appended-at-the-end conflict (or braces unbalanced)'

def main():
    args = sys.argv[1:]
    dry = False
    if args and args[0] == '--dry-run':
        dry, args = True, args[1:]
    if len(args) < 3:
        print(__doc__); sys.exit(2)
    branch, base, files = args[0], args[1], args[2:]
    repo = run(['git', 'rev-parse', '--show-toplevel']).strip()
    os.chdir(repo)
    user = run(['git', 'config', 'git-ftp.user']).strip()
    pw = run(['git', 'config', 'git-ftp.password']).strip()

    tmp = tempfile.mkdtemp(prefix='deploy-merged-')
    live_dir = os.path.join(tmp, 'live'); os.makedirs(live_dir)
    gets = '; '.join(f'get {remote_path(f)} -o {live_dir}/{i}' for i, f in enumerate(files))
    subprocess.run(['lftp', '-u', f'{user},{pw}', '-e',
                    f'set ssl:verify-certificate no; set ftp:ssl-force true; set net:max-retries 2; set cmd:fail-exit no; {gets}; bye',
                    'ftp.mowology.ca'], capture_output=True, text=True)

    final, live_copies = {}, {}
    for i, f in enumerate(files):
        lp = os.path.join(live_dir, str(i))
        live = open(lp, 'rb').read() if os.path.exists(lp) and os.path.getsize(lp) > 0 else None
        mine = show(branch, f)
        if mine is None:
            sys.exit(f'{f}: not in {branch}')
        old = show(base, f)
        if live is None:
            print(f'new      {f}'); final[f] = mine; continue
        live_copies[f] = live
        if live == mine:
            print(f'same     {f}'); final[f] = mine; continue
        if old is not None and live == old:
            print(f'replace  {f}'); final[f] = mine; continue
        if old is None:
            sys.exit(f'STOP     {f}: live exists but not in {base} — merge by hand')
        paths = {}
        for k, v in (('mine', mine), ('old', old), ('live', live)):
            paths[k] = os.path.join(tmp, f'{i}.{k}')
            open(paths[k], 'wb').write(v)
        r = subprocess.run(['git', 'merge-file', '-p', paths['mine'], paths['old'], paths['live']], capture_output=True)
        if r.returncode == 0:
            print(f'merged   {f}'); final[f] = r.stdout; continue
        out, why = resolve_appended(r.stdout, live, f.endswith('.css'))
        if out is None:
            sys.exit(f'STOP     {f}: {why} — merge by hand (nothing uploaded)')
        added = out.count(b'\n') - live.count(b'\n')
        print(f'appended {f} (+{added} lines after the live content)')
        final[f] = out

    for f, data in final.items():
        if f.endswith('.php'):
            p = os.path.join(tmp, 'lint.php'); open(p, 'wb').write(data)
            r = subprocess.run(['php', '-l', p], capture_output=True, text=True)
            if r.returncode != 0:
                sys.exit(f'STOP     {f}: php -l failed: {r.stdout.strip()}')

    wt = os.path.join(tmp, 'wt')
    run(['git', 'worktree', 'add', '--detach', '-q', wt, branch])
    try:
        for f in files:
            p = os.path.join(wt, f)
            if f in live_copies:
                os.makedirs(os.path.dirname(p), exist_ok=True); open(p, 'wb').write(live_copies[f])
                run(['git', 'add', '--', f], cwd=wt)
            else:
                run(['git', 'rm', '-q', '--cached', '--ignore-unmatch', '--', f], cwd=wt)
        run(['git', '-c', 'user.name=deploy', '-c', 'user.email=deploy@local', 'commit', '-q', '--no-verify',
             '--allow-empty', '-m', 'tmp: drift base = live copies, new files absent'], cwd=wt)
        drift_base = run(['git', 'rev-parse', 'HEAD'], cwd=wt).strip()
        for f in files:
            p = os.path.join(wt, f); os.makedirs(os.path.dirname(p), exist_ok=True)
            open(p, 'wb').write(final[f])
        cmd = ['scripts/deploy-checked.sh'] + (['--dry-run'] if dry else []) + [drift_base] + files
        r = subprocess.run(cmd, cwd=wt, text=True, capture_output=True)
        print('\n'.join(l for l in r.stdout.splitlines() if not l.startswith('would upload')))
        if r.returncode != 0:
            print(r.stderr); sys.exit(r.returncode)
    finally:
        subprocess.run(['git', 'worktree', 'remove', '--force', wt], capture_output=True)
        shutil.rmtree(tmp, ignore_errors=True)

if __name__ == '__main__':
    main()
