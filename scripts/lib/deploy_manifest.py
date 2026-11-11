#!/usr/bin/env python3
"""
scripts/lib/deploy_manifest.py — record what was deployed, for Ada's drift check (2026-10-13).

Called by scripts/deploy-checked.sh after a successful upload (so scripts/deploy-merged.py,
which deploys through it, records too). It merges into app/Storage/deploy-manifest.json on the
server (denied to the web by app/.htaccess):

  {"updated_at": "...", "files": {"<repo path>": "<sha1 of the bytes uploaded>", ...},
   "migrations": ["1347_security_posture.sql", ...]}

Nightly, SecurityPostureService::checkDrift() re-hashes every listed file on the server: a file
changed since its deploy is amber (red under app/Core, app/Modules/Accounting, public/api), and
checkMigrations() compares "migrations" (every numbered .sql in the repo at deploy time) with
migrations_log. Usage: deploy_manifest.py <file>...  (paths relative to the current directory,
which is the checkout that was uploaded). Never fails a deploy: the caller only warns.
"""
import hashlib, json, os, subprocess, sys, tempfile, datetime, glob

REMOTE = '/app/Storage/deploy-manifest.json'

def lftp(cmds):
    lib = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'ftp.sh')
    return subprocess.run(['bash', '-c', '. "$0" && mw_lftp "$1"', lib, f'set net:max-retries 2; set cmd:fail-exit no; {cmds}; bye'],
                          capture_output=True, text=True)

def main(files):
    tmp = tempfile.mkdtemp(prefix='deploy-manifest-')
    cur = os.path.join(tmp, 'cur.json')
    lftp(f'get {REMOTE} -o {cur}')
    data = {}
    if os.path.exists(cur) and os.path.getsize(cur) > 0:
        try:
            data = json.load(open(cur))
        except ValueError:
            print('deploy manifest: live copy unreadable — starting a new one')
    data.setdefault('files', {})
    for f in files:
        data['files'][f] = hashlib.sha1(open(f, 'rb').read()).hexdigest()
    try:
        tracked = subprocess.run(['git', 'ls-files', 'database/migrations', 'public/database/migrations'],
                                 capture_output=True, text=True, check=True).stdout.split()
    except Exception:
        tracked = []
    names = {os.path.basename(p) for p in tracked + glob.glob('database/migrations/*.sql') if p.endswith('.sql')}
    data['migrations'] = sorted(names | set(data.get('migrations', [])))
    data['updated_at'] = datetime.datetime.now().astimezone().strftime('%Y-%m-%d %H:%M:%S %z')
    try:
        data['commit'] = subprocess.run(['git', 'rev-parse', '--short', 'HEAD'], capture_output=True, text=True).stdout.strip()
    except Exception:
        pass
    new = os.path.join(tmp, 'new.json')
    json.dump(data, open(new, 'w'), indent=1, sort_keys=True)
    r = lftp(f'mkdir -pf /app/Storage; put {new} -o /app/Storage/.deploy-manifest.json.uploading; '
             f'mv /app/Storage/.deploy-manifest.json.uploading {REMOTE}; get {REMOTE} -o {cur}.back')
    ok = os.path.exists(cur + '.back') and open(cur + '.back', 'rb').read() == open(new, 'rb').read()
    print(f"deploy manifest: {'updated' if ok else 'NOT updated'} ({len(files)} file(s), {len(data['files'])} tracked)")
    return 0 if ok else 1

if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]) if len(sys.argv) > 1 else 2)
