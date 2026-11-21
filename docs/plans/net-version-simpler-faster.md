# Net (desktop web) version: simpler, faster, more Ittai

Branch: `claude/net-version-visual-language-d53726` (synced to `feature/language` 2026-11-20)
Goal (Tim, 2026-11-20): a new tenant who is not tech savvy must not be overwhelmed. It must also be faster.
Rule for all of it: built behind the `it_design` cookie and a first-week switch, so Tim's own view does not change until he chooses.

## What the audit found (dashboard, local site, admin, 1440 px)

| Finding | Evidence |
|---|---|
| First load is heavy | 78 requests, 6.3 MB. Repeat visit about 30 KB. |
| Minified files exist but are not loaded | `mowology-brand.css` 1.25 MB vs `.min.css` 972 KB. `app.js` 3.6 MB vs `app.min.js` 2.4 MB. `scripts/minify-assets.php` already builds them. |
| Every head's script loads on the dashboard | About 30 JS files, around 600 KB. `head-brain.js` (50 KB) is referenced 7 times: charlie-card, dept-heads-deck, mia-card, otto-card, sam-card, and others each add their own tag. |
| Classic dashboard is busy | 55 sidebar links, about 80 buttons, 11 cards, 9,000 px tall. Six heads each show a list with "Not now / Open" on the first screen. |
| Ittai dashboard is calmer but long | One head at a time and 3 priorities, but 10,000 px tall, and the diagram, brief strip and card all compete on the first screen. |
| Menu mixes owner and daily items | Payroll, Timesheets, Time Clock Settings and more sit beside Contacts and Quotes. |
| Small bugs | `/assets/favicon-ittai/` favicon and manifest return 404 (the folder is not in git: Tech-Debt-Map 2026-11-03). |

Not measured: production timings, other pages, a truly empty tenant, phone width. Whether production already compresses the CSS and JS is unknown.

## Unknowns and how each is settled

1. Does production already gzip/brotli and cache the CSS/JS? Check the response headers of `mowology-brand.css` and `app.js` on mowology.ca (one HEAD request, no login).
2. Does `app.min.js` behave the same as `app.js`? Settled by loading the dashboard, quotes and schedule with the min file locally and checking the console and a smoke run (`scripts/local-site.sh smoke`).
3. Which dashboard parts does a new tenant actually need? Settled with a new-tenant account on the local site (migration or `provision-tenant.php`), not Tim's data. Tim's answer needed for the final cuts (step 3).
4. Do the head scripts depend on each other's load order? Settled by reading `head-brain.js` and each card's include, then testing with one tag.

## Steps (each proven before the next)

**Step 1: speed, no look change.** Smallest risk, biggest win.
- Load `.min.css` / `.min.js` for `mowology-brand`, `classic` and `app` (swap in `appstack_head.php` / `appstack_footer.php`, as the minifier note says "per-page rollout"). Run the minifier in the deploy step so the min files cannot go stale.
- Load `head-brain.js` once, not per card.
- Load a head's card script only on pages that show that head's card.
- Add the missing favicon folder to git and fix the 404s.
- Proof: before/after request count, KB and load time on the same pages; console clean; phpunit; smoke.
- Rule 4 in CLAUDE.md (no vendor edits) holds: only the includes change.

**Step 2: first-week mode (the switch).** A per-business setting (default off for existing tenants, on for new ones) that Tim can flip. It drives steps 3 and 4. Uses the existing `it_design` shell; no new engine.

**Step 3: the simple dashboard for a new tenant.**
- First screen: one line of greeting, the single most important thing, and one big action. Nothing else.
- Everything else (head diagram, other heads' lists, stats) sits under a "More" fold, closed by default.
- Plain labels. Empty states that say what to do next ("Add your first client") with one button.
- Tim decides each cut; I list them page by page and he approves before building.

**Step 4: a short menu for a new tenant.**
- About 8 daily items: Dashboard, Contacts, Quotes, Jobs, Schedule, Invoices, Receipts, Settings.
- Owner items (Payroll, Timesheets, Time Clock Settings, Tax, Database and the rest) behind a single "More" or shown only when the business turns that feature on.
- Permissions still decide what each login can open; this only changes what is shown.

**Step 5: Ittai v1.2 on the pages a newcomer meets first.**
- Order: dashboard, contacts, quotes list, new quote, jobs, invoices, schedule.
- Use `ittai-tokens.css` and `docs/design/ittai-v1.2` / `COMPONENTS.md` (status scale: green in, rust look, red missing, grey not expected). No new hex values, no inline styles.
- One primary action per page. Dense columns and filters collapse for first-week mode.
- Each page checked at 1440, 1024 and phone width, light and dark, with `scripts/ittai-design/review-shots.mjs` where it applies.

**Step 6: check as a newcomer.**
- Walk the first-run path on a clean tenant (login, add a client, make a quote, send it) with timings and screenshots.
- Re-measure speed against step 1's baseline.
- Independent review (`scripts/codex-review.sh`; if Codex is out, `code-reviewer`).

## Order of delivery

Step 1 first, shipped alone (measurable, safe). Then steps 2 and 3 together on the dashboard. Then 4. Then step 5 page by page. Each ships separately through the `feature/language` deploy gate, and each is checked live after.

## Parity and risks

- iPhone and Android: this is the web CRM only. The same simplification of the iPhone app and native Android is a separate decision (design decisions reach native Android too, so it goes on the backlog, not silently skipped).
- The crew app has its own slim CSS set (`crew-base.css`); step 1 must not touch it.
- Existing tenants (Mowology): defaults unchanged. The switch is the only way new behaviour appears.
- Rule: no new business logic in page files. The first-week setting and the "what to show" choice go in a small service with a test.

## Asked of Tim

1. Approve this order (speed first).
2. Approve the 8-item daily menu or change it (step 4).
3. Approve the dashboard's first-screen content (step 3).

## Step 1 results (2026-11-20, deployed 902cf302a) and what they changed

Done: `mowology-brand.min.css` regenerated (the committed one was 9 days stale) and `app.min.js` now load through `_amin()`; `MinifiedAssetsFreshTest` fails if either goes stale; Ittai favicons restored into git. Checked: identical computed styles on 6 pages locally, same console errors as before (a pre-existing `SyntaxError: Invalid or unexpected token` and one 500 are on the page with the original files too), dashboard renders on production.

Corrections to the audit:
- Production already gzips. Real saving from the min files is about 46 KB (brand CSS) and 259 KB (app.js) over the wire, plus about 1.2 MB less JS to parse. Useful, not large.
- The `favicon-ittai` 404s were local-only (production serves them); the files were just missing from git.
- Loading each head's script only where needed was dropped: `head-brain.js` guards against double init, so repeats cost parse time only.

**The real speed problem is the server, not the assets.** On production (signed in as Tim, one session, 2026-11-20): dashboard time to first byte 13.8 s and 17.2 s; clients page 11.5 s; quotes 0.3 s; tasks 0.3 s. The dashboard then fires slower API calls (`charlie.php` 9.5 s, `bookkeeper.php` 2.7 s, `otto.php` 2.1 s). A 95-request, 8 MB first load is secondary to that.

### New step 1b: profile the slow pages (before any look change)
1. Time the PHP work in `dashboard_appstack.php` and `clients_appstack.php` by section (queries and head cards) on a production-sized copy, or with read-only timing through `scripts/prod-run.sh`.
2. Fix the biggest items first (likely a few heavy queries or per-head work done on page load). Move what is slow to the existing precomputed-brief pattern (`head_brief_ready`) where the owner does not need it live.
3. Re-time on production. Target: first byte under 1 s for dashboard and clients.
4. Open question: how much of this is Mowology's data volume vs. what a brand-new tenant would see. Measure a new, empty tenant too, so the "new tenant" experience is judged on its own numbers.

## Rule added 2026-11-20 (Tim): refactor each page once it is done
A page is finished only after its refactor. Per page the order is: audit, simplify, speed up, **refactor**, check on production.
- Refactor = business logic and SQL out of the page into `app/Modules/*/Services` with a test (CLAUDE.md rule 10); very large pages (`clients_appstack.php` 7,907 lines, `dashboard_appstack.php` 1,323) split into includes; dead and duplicated code removed. No change to layout, labels or behaviour.
- Its own commit, after the page's simplify and speed work is proven, so it can be reverted alone. Proof: the same screenshots and timings before and after, phpunit, independent review.
- The slow-query fixes in step 1b are the first slice of this for the dashboard and clients pages.

## Drop the "appstack" naming from page files (added 2026-11-20, Tim asked)

Size: 50 page files `public/crm/*_appstack.php`; 922 references to those names in about 290 files (154 in `public/crm`, 71 in `app/Modules`, 26 tests, about 27 docs/plans/skills, 4 migrations with stored links, 3 iPhone files, about 7 scripts/tools). The shared layout includes (`appstack_head.php`, `appstack_footer.php`, `appstack_sidebar.php`, `appstack_topbar.php`) are referenced from 245 files.

Risks: bookmarks, sent emails and texts, stored links in the database, the iPhone and Android apps, and file-by-file deploys where a link and its target go live at different moments.

How:
1. One page at a time, inside that page's refactor (see the rule above); one small commit per rename. No big-bang rename.
2. The old `*_appstack.php` stays as a three-line redirect to the new name (permanent redirect, query string kept), so nothing already sent or saved breaks. Redirect stubs are removed only after production logs show no hits for 60 days.
3. Per rename: update every reference (links, nav keys' hrefs, services, tests, iPhone, docs), add a test that fails if the old name is still linked from code, deploy the new file and the references before the stub, check live.
4. Leave the four layout includes alone. Nobody sees those names; renaming them touches 245 files for no visible gain, and CLAUDE.md names them. Revisit only if Tim wants them renamed too.

Proposed names (drop the suffix), for Tim to approve or change:
`dashboard`, `clients`, `tasks`, `expenses`, `messages`, `photos`, `photos_refile`, `users`, `sites`, `leaderboard`, `kaname`, `ada`, `agents`, `freedom`, `import`, `privacy`, `profitability`, `tax-report`, `trip-reports`, `zone-report`, `system-log`, `audit-trail`, `before-after`, `card-designer`, `certification`, `design-system`, `foreman_calendar`, `gps-lookup`, `icloud_tidy`, `mobile-preview`, `penny-onboarding`, `receipts-review`, the `cms-*` set (`cms-activity`, `cms-dashboard`, `cms-form-submissions`, `cms-media`, `cms-pages`, `cms-redirects`) and the `quiz*` set (`quiz`, `quiz-admin`, `quiz-learn`, `quiz-play`, `quiz-progress`).

Names that need a decision:
- `map`: `public/crm/map.php` already exists, so the AppStack map page needs a different name (for example `job-map`).
- `accounting`, `contracts`, `database`, `mockups`, `quotes`, `reports`: a folder of the same name already exists (`/crm/quotes/` and so on). `quotes.php` next to `quotes/` works because URLs name the file, but it is easy to confuse. Either accept it or use `quotes-list`, `reports-home` and so on.
- Names that mix styles (`foreman_calendar`, `icloud_tidy`, `photos_refile` use underscores; the rest use hyphens): CLAUDE.md allows both. Keep as is unless Tim wants one style.

## Retire old styling as we go (Tim, 2026-11-20)

Each page that moves to the Ittai look gives up its old styling in the same piece of work, so the CSS shrinks instead of growing a second layer.

Per page, after it is on Ittai v1.2 and checked:
1. List the page's old `.mw-*` classes and any page-specific blocks in `mowology-brand.css` (and its inline or page CSS files).
2. For each, search the whole repo (PHP, JS, other CSS, emails, the iPhone WebView pages) for other users of that class. Remove only classes nothing else uses; a class still used elsewhere stays and is noted as "retire when X is migrated".
3. Remove the matching rules, regenerate `mowology-brand.min.css` (the freshness test enforces this), and compare before/after screenshots and computed styles on that page and on two pages that share the shell.
4. Record the bytes removed per page in this plan, so the saving is visible.

Fences:
- `classic.css`, `corporate.css`, `app.js` and `/crinum/` are vendor files and are never edited (CLAUDE.md rule 1). They stop being loaded only when the last classic page is gone; until then the classic look stays for pages not yet migrated.
- The `it_design` choice stays for Tim's own account while any page is still classic. A page is retired from classic only when Tim has seen the Ittai version of it.
- The crew app has its own slim CSS (`crew-base.css`, `crew-brand.css`); retiring rules there is a separate check, never a side effect.
- Colours stay on tokens (`--mw-*`, `--it-*`); old hex literals are removed when found, not re-added.

End state: when every page is on Ittai, `classic.css` and the `.mw-*` layer for retired pages are dropped from the head, and `mowology-brand.css` holds only what is still used.

## Step 1b result 1: the duplicate-contact scan (2026-11-20, deployed 2e1941853)

Cause: `findDuplicateContacts()` compared every contact with every other (about 1.2 million pairs): 6.2 s on production, run on every clients page load and again by `getWorkQueueItems()` for the dashboard. Fixed with `ContactDuplicateFinder` (groups by email, phone digits, address, name). Proven: the new result is identical to the old on all 1,520 production contacts (same map, same order), 6.2 s becomes 9 ms; a test keeps the old loop as the reference. One deliberate difference: numeric-only addresses ("0" vs "00") no longer count as the same place.

Production time to first byte, same session, before then after: clients 11.5 s then 0.3 s (1.0 s on the cold request); dashboard 13.8–17.2 s then 2.8–4.7 s; quotes 0.3 s unchanged.

Still slow, measured next (from the helper's static read, none yet timed):
- Dashboard weather on a cold or failing cache (Environment Canada 15 s and Open-Meteo 10 s timeouts, nothing cached on failure).
- Payment-match refresh in `dept-heads-deck.php` (first admin load every 4 hours).
- `TripCostService` and `CharlieDeskService` each built twice per dashboard load.
- Otto GPS subqueries on `crew_location_history.visit_id` (no index).
- The head API calls after load (`charlie.php` 9.5 s, `bookkeeper.php` 2.7 s, `otto.php` 2.1 s).
- The clients page sends about 3.9 MB of HTML (the whole list is in the page); a paged or lazy list belongs to its simplify step.

## Step 1b result 2: dashboard time found with `?prof=1` (2026-11-20)

Admins can add `?prof=1` to the dashboard: the page ends with an HTML comment of milliseconds per stage (marks in
`dashboard_appstack.php` and `includes/dept-heads-deck.php`). Findings, production:

- Weather, profit, work queue, Penny's services: all under 80 ms. The static read that blamed weather was wrong (it serves cached data).
- The heads deck was 2.4 s of 2.9 s. Inside it: Otto's card 1.44 s, Ada's 0.64 s.
- Otto: the clock-out lookup's location subquery made MySQL pick `idx_clh_point_uuid` and scan ~70,000 rows per shift
  (0.9 s for 7 rows). `FORCE INDEX (crew_id)` gives the same result in ~0.15 s (falls back to no hint if the index is missing).
- Ada: "slowest recent run" grouped all 300,000 `cron_runs` rows (0.6 s). One short keyed query per job takes 49 ms.
- After: card:otto 1440 to ~740 ms, card:ada 640 to ~190 ms; dashboard first byte 2.9 s to ~1.9 s.

Still open on the dashboard: Otto's card (~740 ms: unscheduled items 230 ms, clock-outs 175 ms, dispatch 73 ms), Ada (~190 ms),
Penny's payment-match refresh (a one-off ~1.7 s on the first admin load every 4 h), and the head API calls after load
(`charlie.php`, `bookkeeper.php`, `otto.php`). `crew_location_history` carries 13 index entries for 7 distinct needs:
duplicate indexes to drop in a migration.

## Step 2 result: the first-week switch (2026-11-20, deployed)

- `FirstWeekService` (`app/Modules/Settings/Services/`) reads `ops_settings.first_week_mode` ('1' = on, no row = off). Per business database, so per tenant. Tests: `tests/Unit/Settings/FirstWeekServiceTest.php`.
- Admin toggle in the account menu (top right): "First-week view: on/off". It saves through the existing `ops-settings.php?action=save` (admin only, CSRF).
- New businesses start ON (`TenantProvisioner`, first run only, so re-provisioning never flips an existing business). Mowology stays OFF.
- Nothing visible changes yet: steps 3 (simple dashboard) and 4 (short menu) read `FirstWeekService::isOn()`.
- Checked live: dashboard renders in full, item shows "off (turn on)".

## Steps 3 and 4 result: short menu and one-thing dashboard (2026-11-20, approved by Tim)

- With the first-week view on, the menu is Today, Clients, Quotes, Jobs, Schedule, Invoices, Receipts, Settings and a closed More (everything else the login may open). Both shells. `FirstWeekMenu` (tested). Phone menu not changed yet.
- Dashboard first screen: greeting, one thing (add client, first quote, follow up, today's visits, all caught up; steps the login can't open are skipped), one button, the rest under a closed More. `FirstWeekFocus` (tested). A failed count falls back to the full dashboard.
- Checked locally in both shells; live with the view off renders as before. Mowology stays off; turn it on from the account menu to try it.
