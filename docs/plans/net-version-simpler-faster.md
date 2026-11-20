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
