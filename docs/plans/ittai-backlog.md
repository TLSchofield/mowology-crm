# Ittai backlog — features to carry from Mowology to every tenant

The running list of what Ittai tenants need. Add to it as Mowology proves things. Each item names
what is **shared** across all tenants (knowledge, formats, rules about the world) and what stays
**private** to a tenant (their people, prices, accounts and decisions). That split is the standing
rule.

Direction (council, 2026-10-12): keep the data discipline-neutral, and build and sell to field-service
trades first. The AI back office is the edge.

## Inbox tidy for every tenant (Yui) — added 2026-10-13
- **Mowology today:** Yui sorts the iCloud inbox into folders using the CRM's own clients, suppliers
  and payment senders. It is preview-first, moves only, never deletes, and every batch can be undone.
  "Keep it tidy" files new mail automatically once switched on.
- **Tenant model (Tim, 2026-10-13): the tenant teaches, Yui applies.**
  - Each message or sender gets simple buttons: **"Is work"**, **"Not work"** and "Always put in…".
    A click creates a rule for that tenant only, and Yui applies it from then on.
  - Yui may *suggest* a folder, but she only moves on rules the tenant confirmed (plus the
    tenant's CRM contacts).
  - Safer than guessing: nothing moves on a hunch, and every rule traces back to a click.
- **Shared:** sender rules for well-known services (Amazon/Home Depot → receipts, Interac → money,
  Twitter/X → promos, etc.). Fixes found by any tenant go into the shared list, e.g. the Twitter/"Bill"
  misfile on 2026-10-13.
- **Private:**
  - "Is work" rules, contacts and suppliers;
  - the folder plan, fitted to the trade;
  - mailbox credentials, encrypted per tenant.
- **Needs:**
  - per-tenant mailbox connection, with iCloud/IMAP app passwords plus Google and Microsoft OAuth;
  - site_id on the mail_tidy tables;
  - a settings page with the "Is work" buttons on Yui's inbox card and in the tidy preview.

## Receipts as the way in (Penny) — council 2026-10-12
- A free or cheap receipts-only entry tier *inside* Ittai, not a separate app. The upgrade moment is
  "put this receipt on a job" or "bill this cost".
- Penny's edge: knows the jobs, the province's taxes and the trade's rules; checks against supplier
  price lists.
- Canada first: Jobber's bookkeeping is US-only.
- Shared: receipt layouts, vendor names, bank-wording patterns. Private: amounts, payers, rules.

## Penny proves her own books + head learning (all heads) — plan 2026-10-13
- `docs/plans/2026-10-13-penny-upgrade-and-head-learning.md`: lessons record, rules in shadow mode,
  mistakes as tests, scorecards, and Charlie's weekly report card on every head.
- Shared: the learning machinery and scorecard format. Private: each tenant's lessons and rules.

## Supplier price lists and the shared catalogue (Otto/Penny) — built 2026-10-12
- Import formats are data (Inline Nurseries first).
- A shared category tree for every trade family (plants, plumbing, electrical, salon supplies…).
- Botanical and part-number matching; a review queue for fuzzy matches.
- Shared: formats, catalogue, aliases, size equivalents. Private: price lists, prices, which suppliers
  a tenant uses.
- Later: an anonymised materials price index, once there are enough tenants and with consent.

## Staff permissions page — Tim 2026-10-12
- A role × permission tick grid, per tenant. Admins and managers edit; only anything touching an
  admin needs users.manage.

## Switch from Jobber tool — `docs/plans/2026-10-12-ittai-switch-from-jobber.md`
- Lessons from Mowology's own migration: accountant's year-end first, bank first, payer interview,
  remittance reading, and Jobber's late "marked paid" dates.

## Security and health watch (Ada) — built 2026-10-13
- Nightly security-posture checks: blocked paths, stray data files, DB privileges, Stripe webhook
  events, payment-mail signatures, backups heartbeat, audit export, certificates, dependencies, drift,
  page render health, migrations, error spikes, mail DNS.
- Each tenant site gets the same checks.
- Before any outside tenant: real tenant isolation, a data-only DB user, tested backups, nonce CSP,
  and an independent penetration test (audit report 2026-10-12).

## "Personal" on receipts and bank lines (Penny) — plan 2026-10-13
- `docs/plans/2026-10-13-move-to-personal.md`: one button covers three cases (personal on own card →
  out of the books; business on own card → owed to the owner; personal on the business card →
  shareholder account). A personal list with T1 tags exports for the accountant. Not a second ledger.
- Shared: the seed list of usually-personal vendor types. Private: each owner's own personal vendors.

## Receipt catch-up, vendor by vendor, teaching Penny as you go (STANDARD) — built 2026-10-14
- Tim: "This should be a standard. Other tenants may join with 100's of receipts." Every tenant's
  receipt review works this way, including the onboarding backlog.
- Penny's card groups waiting receipts by vendor, biggest group first. Approving unchanged counts toward
  her track record. A fix is a lesson; a reject keeps its reason.
- When the owner corrects a vendor the same way twice, she redoes that vendor's other waiting reads
  with the lesson: 10 at once, the rest by the background preparer first in line, within the daily cap.
- Private per tenant: the lessons (head_lessons.site_id) and track record. Shared: nothing yet.
  Candidate for the shared layer: vendor category/tax hints once 2+ tenants agree (the vendor
  knowledge commons already does this for confirmed receipts).
- Before 100s-of-receipts tenants: the daily cap (bookkeeper_daily_cap, default 40) needs an
  onboarding allowance, and the cost per receipt should be shown up front.
- BLOCKER before a second tenant: there is no "current tenant" lookup yet. Lessons are written and
  read as site_id 1 (HeadLessons default; PennyLessons::forVendor's $siteId). Once tenancy exists,
  every caller passes the tenant's site_id (Codex review 2026-10-14).

## Android crew app as a founding business sees it — first local run 2026-10-26
Seen on the emulator against `claudia.ittai.ca` on the local test site (`scripts/local-android.sh up`;
docs/LOCAL_SITE.md "Android emulator"). The server side is right: Host → tenant database, Ittai sign-in page,
native sign-in, the tenant's own schedule. The APP still says Mowology. In the order Tim called them:
1. **Splash shows the Mowology logo** (launcher icon + splash in `android/app/src/main/res`, shared by both
   flavors). A founding business must never see it.
2. **Sign-in screen reads "Mowology / Field Manager" and "Access is restricted to Mowology staff."** — hardcoded
   in the app, not from the server (the server's own sign-in page already says the business's name).
3. **Sign-in (and every native screen) uses Mowology green** (button, "Show", tabs, pills). Tim: "the sign in
   page should only be ittai design brand colors" — Ittai Design System v1.2 tokens, nothing of Mowology's.
4. **Initiation should start from a sign-up screen** (Tim). Today the app only signs in to a business someone
   already provisioned (TenantProvisioner, owner-run). The first screen for a new business must be sign-up:
   create the business, then the owner's login — i.e. self-serve provisioning from the phone.
5. Bottom tab bar on the day screen has 8 tabs with labels wrapped letter by letter ("To da y", "Se arc h") —
   unreadable on a Pixel-6-width screen.
6. Crew gets 403 on `team-mobile?mode=brief/brains` at every open (expected for crew, but the app asks anyway).
7. **Android day screen must match the iOS Schedule screen** (Tim, with the iPhone screenshot 2026-10-26: "they are
   meant to be similar/same"). iOS: five icon tabs — Schedule · Time Clock · Search · Receipts · AI Office; week
   strip with MON/TUE day names; each job card shows est. arrival time + distance, the service tag pill, "First
   visit" / "Last done N days ago", the client and the crew names, "n visit(s)", a ··· menu. Android today: eight
   text tabs, no arrival estimate, plainer cards, a "Display" link and a "Web app" button in the header.
   Map mode too (iOS screenshot 2026-10-26): the list/map toggle in the header, numbered pins with the address
   label, the current-position dot, a collapsible stop card (time, distance, "1/3", name, address, last-visit
   line) with **Directions** (filled) and **Move** (tonal) buttons, locate-me button — Android's map icon must
   open the same thing.
   **Sign-up must flow into the EXISTING onboarding path** (Tim, 2026-10-26): `app/Modules/Onboarding/Services/
   OnboardingService.php` — Name → Company → QuickBooks → Your books → Accountant → Done (`/crm/onboarding/`,
   each step owned by a head; order/skips in ops_settings), plus the planned Penny-first unlock
   (`docs/plans/2026-10-24-claudia-onboarding-penny-first.md`). Sign-up = create the business + owner login, then
   the same steps, on the phone — not a second onboarding.
   **Built 2026-10-26 (both halves, local only).** App: `codex/native` 9776382…efcf6a9 (Codex builds 24/24b, behind
   `android.native_signup`). Server: `public/api/auth/signup.php` (claims a pre-provisioned, unclaimed business — owner
   email must match, claimed = `ops_settings tenant_claimed_at` or the owner has signed in; 2–60-char name),
   `public/api/onboarding/map.php` + `step.php` (JWT; `SignUpService`, `OnboardingStepService`,
   `OnboardingApiPresenter`; spec `docs/plans/2026-10-26-signup-onboarding-api-codex-spec.md`). Walked end to end
   against an unclaimed local tenant (`fern`). **Deployed 2026-10-27 (Tim's go):** the three endpoints, the three
   services, `JwtService::generateMobileJwt`, the transaction-joining `BusinessIdentityService::upsert`, and the feed
   keys `native_signup` / `native_crew_schedule` both OFF in `public/crm/downloads/app-version.json`. Checked live:
   mowology.ca → 404 not_available; testcompany.ittai.ca → 400 field messages, 409 taken, 405, 401 without a token.
   OPcache reset still owed (admin click) before the first real sign-up — the two edited helpers are only reached on a
   successful claim. **Owed:** `user.business_name` in `token.php`; run the real app through sign-up on the emulator
   (rebuild with `-PtenantTestHost=<fresh unclaimed key>.ittai.ca`); Tim's open calls — crew fifth tab, search scope,
   map tiles.
   **Superseded 2026-10-27 — sign-up v2 (Tim: "you would just need your full name, cell phone number, email address, and
   create a password, verification should be done then go to screen one").** Sign-up asks only for the person; a 6-digit
   code by email (SMS best effort); then every existing onboarding step on the phone (About you → Company lookup/confirm →
   QuickBooks connect from the app via a Custom Tab, or Your books → Invite accountant → You're set up). Server: spec
   `docs/plans/2026-10-27-phone-signup-v2-codex-spec.md`, Codex build signup-v2, commits 5d3af841b + 2222998af
   (`signup.php` request code, `signup-confirm.php`, map/step `data`, `quickbooks-start.php`, phone path in
   `quickbooks-callback.php`, QuickBooks redirect URI follows the business host). App: `codex/native` fde10c6 + 6154d9d
   (Android session spec `docs/plans/2026-10-27-android-phone-signup-v2-codex-spec.md`), walked end to end on the emulator
   against `sage.ittai.ca`. Tests 3613 web / 516 per Android flavor. **Deployed 2026-11-02 (Tim's go, switch still OFF):**
   the 12 server files via `scripts/deploy-safe.sh`; checked live on testcompany.ittai.ca (v2 four-field 400s, 409 taken,
   405, 401 on map/step/quickbooks-start, callback with a bad phone state falls through to the login redirect) and
   mowology.ca (404 not_available); OPcache revalidated on its own. **2026-11-03:** Ittai-flavour test APK with the two-people
   icon, "Ittai" label and Ittai splash at `/crm/downloads/ittai-testcompany-signup-v2-test.apk` (codex/native d28a824 + a3f1083,
   not pushed). Per-business feed override live: `/crm/api/app-version.php` merges `app-version.<key>.json` over the shared file on
   a business's own address (`AppVersionFeed`); `app-version.testcompany.json` turns on native_auth, native_signup,
   native_crew_schedule; mowology.ca feed unchanged (checked). Intuit redirect URI for testcompany added by Tim; the phone
   connect round trip still needs Tim's Intuit sign-in. **Owed:** add each further business's callback URL to the Intuit app
   (testcompany first) and prove the Intuit tab round trip on production; SMS leg unproven; Tim's call: after "Yes, I use
   QuickBooks" + Skip, the map marks "Your books" Not needed (existing web rule) — keep, or ask again?
   **Rehearsal walk 2026-11-03 (Tim's phone, rehearsal.ittai.ca, final APK):** step 1 proven — fresh install opens native
   "Create your login". Two first-impression findings for the Android session, Tim to decide after the walk: (a) no visible
   splash or welcome — the Ittai splash exists (`tenanttest/res/values/styles.xml`, hexagon icon) but MainActivity is a UI-less
   dispatcher that finishes at once and nothing calls `installSplashScreen()`, so the Android 12 splash is a sub-100 ms flash;
   no welcome composable exists; (b) the brown "TEST TENANT: <host>" banner is gated by the flavour (`BuildConfig.IS_TEST_TENANT`,
   `app/build.gradle:70-76`), so a build for Claudia would show "TEST TENANT: claudia.ittai.ca" — dropping it needs a non-test
   Ittai flavour (and the default-on native switches keyed off a separate `IS_ITTAI` field, not `isTestTenant`). Also: "Back"
   on the first screen. **Walk completed to the day screen 19:23** (two server fixes deployed mid-walk: sign-up
   duplicate-key 500, registry false warning). Blockers + full findings list in
   `docs/plans/2026-11-03-claudia-rehearsal-runbook.md` → "The walk": QuickBooks import off on production (Accept
   refuses, Continue loops), TEST TENANT banner, Claudia's Intuit URI; plus ~12 first-impression app fixes
   (incl. "Charlie" → Kaname on Android).
   **Migrations per business (found 2026-11-03):** a migration run on Mowology after a business is provisioned never
   reaches that business (walk-1 rehearsal lacked `snow_runs`). Needs a per-business migration runner before more
   founding businesses; until then provision Claudia's after the latest migrations.

**Claudia keeps QuickBooks (2026-11-04):** sending to QuickBooks is built but runs for Mowology only; per-business
run, imported-customer links, her own items, phone switch, payment bank-match test, Production keys; shadow
ledger (`books_mode`, monthly compare with her QuickBooks reports) when she considers switching.
Plan: `2026-11-04-claudia-quickbooks-sending-plan.md`.
