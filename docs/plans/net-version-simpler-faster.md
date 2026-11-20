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
