# Ada phase 2 — her Team page, crew guides, systems + security review (plan, 2026-10-11)

Tim 2026-10-11: "She needs a team page. She will be the tech onboarding guide for all current
and new functionality; for me she will be the systems analyzer and security reviewer."
Answers: guides are for **crew** and **future Ittai customers**; **her page first, per-screen
tips after**.

## Ada's two faces

| For | Ada is | Where |
|---|---|---|
| Crew (and later each Ittai company's people) | the guide: how each thing works, what's new, a first-week path by role | crew app: Account tab → "Learn with Ada" (Android web + iPhone) |
| Tim | systems analyzer + security reviewer | iPhone Team tab (new Ada card) + web System Health page (built) |

## Unknowns and how each is settled (cheapest first)

| # | Unknown | Settled by |
|---|---|---|
| 1 | Exactly what each crew screen does today, per platform (Android web vs iPhone native differ) | read the code per feature (subagent per surface, word-capped); every guide step cites the file it was read from |
| 2 | Which crew pages are safe to edit (live copies drifted: schedule, homebase, appstack head/footer, brand.css) | `scripts/prod-file.sh -b` before each; Learn page is a NEW file, linked from `profile.php` only |
| 3 | iOS Team tab: can Ada reuse the shared head card (`HeadCardView`) | read `TeamView.swift` / `HeadCardView.swift`; server `TeamCardService::HEADS` + `team-mobile.php` |
| 4 | Is there 2-step sign-in, a failed-login record, a session list? (security checks depend on it) | grep auth code + prod `SHOW TABLES`; a check is only built on data that exists |
| 5 | Tenant-neutral wording for Ittai (no "Mowology", no Tim) | guides read the company name from `business_settings` (standing rule) |

## The guide catalog (one place, versioned with the code)

- Guides are files: `app/Modules/Technology/Guides/<id>.md` — front matter: id, title, roles
  (crew / driver / office / owner), platforms (android / ios / web), feature, added (date),
  changed (date), screens it describes (file paths). Body: short steps, plain words.
- `AdaGuideService` reads them (cached), filters by the viewer's role + platform, marks
  **New** (added/changed since the viewer last opened Learn).
- Progress per person (seen / done) in one small table — migration (Tim runs it once).
  Before the migration runs, Learn still works; only the ticks wait.
- **Kept up to date automatically, the same way as the skills:** a test fails when a guide names
  a screen file that no longer exists; the end-of-session hook names guides whose screens changed;
  Ada's skill gains the rule "a feature that changes what crew see ships with its guide edit".
  Ada's System Health page lists guides whose screens changed after the guide did ("stale guides").

## First guides (crew, current functionality — each verified against code first)
Clock in / out and breaks · Today's schedule and route · Start and finish a visit (notes,
photos, skip) · Snap a receipt · My timesheet · Location permission and why it matters ·
Special requests (only if switched on) · Getting the app updated.

## Tim's side
- **iPhone Team tab:** Ada joins the faces row; her card = her column (red/amber lines, same as
  the Action Board), headline, and a "System Health" link. Needs an app build (bump version).
- **Charlie passes on Ada's red items** in the opening brief — small server change, so a broken
  system reaches the splash without opening the Team tab.
- **Security review (adds to logins):** admin and manager accounts list (who has what),
  signed-in phones belonging to switched-off users (`device_tokens` / `device_tracking_health`),
  leftover one-time scripts and backups reachable on the web (`crm/api/once-*.php`, `*.sql`,
  `*.zip` under public_html), cron web shims still guarded. Each only after unknown 4 says the
  data exists. She reports; Tim acts.
- **Systems (adds):** app version each crew phone runs vs offered (`device_tracking_health`
  vs `app-version.json`), migrations shipped but never run, stale guides.

## Steps and verification
1. Guide catalog + service + test (unit) → 8 crew guides, each step checked against code.
2. Learn page (web, crew shell) linked from Account → render at 412px → deploy merged → verify
   live page renders to `</html>`.
3. JWT endpoint for guides + Ada's Team-tab card on iPhone; Learn on iPhone Account screen →
   `ios/compile-check.sh` → version bump → TestFlight.
4. Charlie passes on Ada's red items (server) → check the ready brief.
5. Security + systems additions, one check at a time, each previewed on prod data first.
6. Migration for progress; Tim runs it. Per-screen "How this works" tips: next phase.
