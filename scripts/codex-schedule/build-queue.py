#!/usr/bin/env python3
"""
Build the Codex review queue: every code file of the website/CRM, the iPhone app and the Android app,
grouped into review units of at most ~LINE_BUDGET lines, riskiest first (Tim, 2026-10-15:
"a schedule for codex reviews so that the ios app, android app and website are reviewed entirely",
3 a night, "start with all things salt/snow related").

    python3 scripts/codex-schedule/build-queue.py            # writes scripts/codex-schedule/queue.json
    python3 scripts/codex-schedule/build-queue.py --check    # prints the plan, writes nothing

Order: salt & snow (hand-picked, below) → money → logins & permissions → client portal & public site →
crew apps (web schedule, iPhone, Android) → messaging & consent → everything else.
The queue holds paths only — no code, no client data. Progress lives outside the repo
(~/.mowology-codex/state.json, written by nightly.sh). Rebuilding keeps unit ids stable for unchanged
groups, so state survives; new files land in new units at the end of their area.
"""
import json, os, re, subprocess, sys

ROOT = subprocess.check_output(['git', 'rev-parse', '--show-toplevel'], text=True).strip()
ANDROID = os.path.expanduser('~/Projects/mowology-android')
LINE_BUDGET = 6000
CODE = re.compile(r'\.(php|js|swift|kt|java|html)$')
SKIP = re.compile(r'(^|/)(tests?|Tests?|docs?|vendor|node_modules|build|uploads|downloads|app_config|crinum|renders)/'
                  r'|\.min\.(js|css)$|secrets\.php$|\.test\.|/run-migration[^/]*\.php$|/LogicTests/'
                  r'|^public/crm/(css|js)/(classic|corporate|app)\.(css|js)$|/Marketing/Drafts/')

# ── Salt & snow first (Tim) ─────────────────────────────────────────────────
FIRST = [
    ('snow-contracts', 'Snow & salt contracts: setup, routes, weekly statements, billing',
     'Money (contract billing, one invoice per run, statements), crew route choice, contract state changes, permissions.',
     ['app/Modules/Contracts', 'app/Modules/Schedule/Api/snow-route-choice.php', 'public/crm/api/snow-route-choice.php',
      'public/crm/api/snow-routes.php', 'public/crm/contracts']),
    ('salt-reports-pow', 'Salt reports and proof of work (client-facing PDFs and pages)',
     'Client-facing: token links, what one client can see of another, PDF content, GPS proof accuracy, anonymised routes.',
     ['app/Services/Salt', 'app/Services/Pow', 'app/Services/Media/PowStampGenerator.php', 'app/Modules/Geofence/Services/GeofencePowRenderer.php',
      'public/crm/api/salt-report.php', 'public/crm/salt', 'public/customer/salt-report.php', 'public/customer/pow.php',
      'public/crm/api/pow-actions.php', 'public/crm/api/pow-email.php', 'public/crm/api/pow-gps-sync.php', 'public/crm/api/visit-share-token.php']),
    ('weather-moves', 'Weather: forecasts, the schedule guard, weather moves, seasonal outlook',
     'Visits moved or skipped by weather (wrong day, lost visits, double moves), cron safety, outside API failures.',
     ['app/Services/Weather', 'app/Modules/Jobs/Cron/weather_schedule_guard.php', 'app/Modules/Jobs/Services/WeatherActionGuard.php',
      'app/Modules/Jobs/Services/WeatherGuardSummary.php', 'app/Modules/Jobs/Api/weather-actions.php', 'public/crm/api/weather-actions.php',
      'public/crm/cron/weather_schedule_guard.php', 'public/crm/includes/weather-service.php', 'public/crm/modules/weather',
      'public/crm/ops/weather_actions.php', 'public/crm/admin/ops_weather.php', 'app/Modules/Jobs/Services/SeasonalOutlookService.php',
      'app/Modules/Jobs/Services/SeasonalOutlookRefreshService.php', 'app/Modules/Jobs/Cron/seasonal_outlook_refresh.php']),
    ('snow-visits-billing', 'Snow and salt on visits and invoices',
     'Per-run invoicing, contract-billed visits never invoiced per visit, visit generation for snow days, materials (salt) amounts.',
     ['app/Modules/Invoices/Services/InvoiceFromVisitService.php', 'app/Modules/Jobs/Cron/generate_visits.php',
      'public/crm/jobs/visit-work.php', 'public/crm/jobs/visit-detail.php']),
    ('snow-crew-devices', 'Snow and salt on the crew devices (iPhone completion, web/Android schedule)',
     'Salt amounts and snow completion on the phone, offline replay, crew seeing only their own work.',
     ['ios/MowologyCRM/MowologyCRM/Features/Schedule/VisitCompletionViewModel.swift',
      'ios/MowologyCRM/MowologyCRM/Features/Schedule/VisitCompletionSheet.swift']),
]

# ── Then by risk: (area id, title, focus, path prefixes) ────────────────────
AREAS = [
    ('money', 'Money', 'Wrong amounts, double charges or double posting, tax (GST/PST), ledger balance, payments matched twice, '
     'locked periods edited, permission gaps on money actions.',
     ['app/Modules/Accounting', 'app/Modules/Ledger', 'app/Modules/Invoices', 'app/Modules/Expenses', 'app/Services/Receipts',
      'app/Services/Stripe', 'app/Services/Payments', 'public/crm/accounting', 'public/crm/invoices', 'public/crm/expenses',
      'public/crm/payroll', 'public/crm/timeclock/payroll', 'public/crm/api/stripe', 'public/crm/api/invoice', 'public/crm/api/payment',
      'public/crm/api/expense', 'public/crm/api/receipt', 'public/crm/api/bank', 'public/crm/expenses_appstack.php']),
    ('auth', 'Logins and permissions', 'Login, sessions, CSRF, JWT for the apps, permission checks (keys, never isAdmin), password reset, '
     'the /api router, file access.',
     ['public/loginAuth', 'app/Core', 'public/api', 'public/crm/includes', 'app/Modules/Settings', 'app/Modules/Team/Api']),
    ('public', 'Client portal and public site', 'Anything a client or the public can reach: token links, forms, uploads, '
     'what one client can see of another, injection, spam.',
     ['public/customer', 'public/jobFlow', 'public/includes', 'public/cms', 'public/services', 'public/crm/products/process-quote-request.php',
      'public/index.php', 'public/contact.php', 'public/about.php', 'public/services.php', 'public/portfolio.php']),
    ('crew', 'Crew apps: schedule, visits, GPS, photos', 'Crew data scope, offline queues and replays, GPS accuracy and consent, '
     'photos, timers and clock, iPhone/Android/web behaving the same.',
     ['app/Modules/Schedule', 'app/Modules/Jobs', 'app/Modules/Geofence', 'app/Modules/Tracking', 'app/Modules/Team',
      'app/Modules/Photos', 'app/Modules/Media', 'app/Modules/Driver', 'app/Modules/Device', 'app/Modules/Plants', 'app/Modules/Quiz',
      'public/crm/timeclock', 'public/crm/jobs', 'public/crm/driver', 'public/crm/js', 'public/crm/team', 'ios', 'android:']),
    ('messages', 'Messaging and consent', 'Messages sent without consent or a click, SMS rules (plain, 160, no links), unsubscribes, '
     'email injection, wrong recipient.',
     ['app/Services/Messaging', 'app/Modules/Comms', 'app/Modules/Consent', 'app/Modules/Messages', 'app/Modules/Privacy',
      'app/Modules/Marketing', 'app/Modules/Social', 'app/Modules/CampaignConnector', 'app/Modules/Referrals', 'app/Modules/Reviews',
      'public/crm/marketing', 'public/crm/social', 'public/crm/email']),
    ('rest', 'Everything else', 'Correctness, permission checks, injection, data loss.', ['app', 'public']),
]


def files_under(prefix):
    if prefix == 'android:':
        if not os.path.isdir(ANDROID):
            return []
        out = subprocess.check_output(['git', '-C', ANDROID, 'ls-files'], text=True).split()
        return [os.path.join(ANDROID, f) for f in out if CODE.search(f) and not SKIP.search(f) and not f.startswith('android/app/src/main/assets/public/')]
    out = subprocess.check_output(['git', '-C', ROOT, 'ls-files', '--', prefix], text=True).split()
    return [f for f in out if CODE.search(f) and not SKIP.search(f) and (f == prefix or f.startswith(prefix.rstrip('/') + '/') or f.startswith(prefix))]


def lines(f):
    try:
        with open(f if os.path.isabs(f) else os.path.join(ROOT, f), 'rb') as h:
            return h.read().count(b'\n') + 1
    except OSError:
        return 0


def chunk(area, title, focus, files):
    """Group files (sorted, so a directory stays together) into units under the line budget."""
    units, cur, n = [], [], 0
    for f in sorted(files):
        k = lines(f)
        if cur and n + k > LINE_BUDGET:
            units.append(cur); cur, n = [], 0
        cur.append(f); n += k
    if cur:
        units.append(cur)
    out = []
    for i, u in enumerate(units, 1):
        dirs = sorted({os.path.dirname(f).replace(ANDROID, 'android') for f in u})
        out.append({'id': f'{area}-{i:02d}', 'area': area, 'title': f'{title} ({i}/{len(units)})',
                    'focus': focus, 'lines': sum(lines(f) for f in u), 'where': dirs[:4], 'paths': u})
    return out


def main():
    taken, queue = set(), []
    for uid, title, focus, prefixes in FIRST:
        fs = [f for p in prefixes for f in files_under(p) if f not in taken]
        taken.update(fs)
        for u in chunk(uid, title, focus, fs):
            u['area'] = 'salt-snow'
            queue.append(u)
    for area, title, focus, prefixes in AREAS:
        fs = []
        for p in prefixes:
            for f in files_under(p):
                if f not in taken:
                    taken.add(f); fs.append(f)
        queue.extend(chunk(area, title, focus, fs))
    total = sum(u['lines'] for u in queue)
    summary = {}
    for u in queue:
        s = summary.setdefault(u['area'], [0, 0]); s[0] += 1; s[1] += u['lines']
    print(f"{len(queue)} units, {total:,} lines, {len(taken):,} files — at 3 a night: {-(-len(queue) // 3)} nights")
    for a, (n, l) in summary.items():
        print(f"  {a:10s} {n:3d} units  {l:8,d} lines")
    if '--check' in sys.argv:
        return
    out = sys.argv[sys.argv.index('--out') + 1] if '--out' in sys.argv else os.path.join(ROOT, 'scripts/codex-schedule/queue.json')
    with open(out, 'w') as h:
        json.dump({'built': subprocess.check_output(['git', '-C', ROOT, 'rev-parse', '--short', 'HEAD'], text=True).strip(),
                   'line_budget': LINE_BUDGET, 'units': queue}, h, indent=1)
    print('wrote', out)


if __name__ == '__main__':
    main()
