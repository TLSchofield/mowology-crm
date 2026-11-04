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

### Status 2026-10-26 (evening) — Codex builds 24 + 24b on `codex/native` (9776382, 9bfaa3c), checked on the emulator
Done in the tenanttest flavor: Ittai symbol launcher + splash; sign-in shows the business name, "Crew app", neutral note, brand-indigo button; per-flavor
generated tokens (`ittai` neutral tenant, no Mowology green); five icon tabs (Schedule · Time Clock · Search · Receipts · AI Office/Account); crew never
requests team-mobile; iPhone-style stop cards (est. arrival · distance, service pill, history line, status, client, crew names for admins, n visits, ⋮ menu);
week-strip chips; map with numbered pins, address chips, position dot, My location, collapsible carousel with Directions / Move (tonal); sign-up screen and
onboarding map behind `android.native_signup` (server contract named in docs/plans/2026-10-26-android-ittai-crew-app-codex-spec.md §4 — NOT built).
Left over (small, from the emulator run):
- The job-22 header week strip still shows ONE-letter day names (M T W); Tim asked for MON/TUE. One-line change in the native header strip when `native_crew_schedule` is on.
- Map: address chips overlap neighbouring pins on dense fake data; "My location" is a text button, not an icon; no map tiles (no SDK/key in the offline build — Tim: Google Maps key or osmdroid?).
- "⋮" / "⌄" are text glyphs (render fine in Inter on the emulator); could become drawables like the tab icons.
- Seeded `crew`/`manager`/`admin` passwords in `scripts/local/.credentials.local` no longer matched the DB (401); the crew one was re-hashed locally to the recorded value. Re-check after the next `seed.php` run.
- Server side still owed: `POST /api/auth/signup.php` (claim an unclaimed pre-provisioned tenant), `GET/POST /api/onboarding/map.php`, `POST /api/onboarding/step.php`, `user.business_name` in token.php — second spec.
- 2026-10-26 evening, after Tim's look: (a) the "Schedule / Mon 26 Oct" title bar is gone on the Schedule tab (ac3688f); (b) sign-in now shows the approved v1.3 gold-beam "ittai" wordmark drawn from the SVG masters (efcf6a9; masters copied to the Android repo `docs/design/ittai-v1.2/wordmark/`; rules + boards recorded in the Ittai design skill `.claude/skills/ittai-design-studio/references/wordmark-v1.3.md` on branch claude/ittai-design-studio-skill-889089); (c) fifth tab = AI Office for admin/manager, Account for crew (iPhone rule) — Tim: "account is meant to be AI Office" → decide whether crew get AI Office too (the server refuses office data to crew today, so it would need a crew-shaped office); (d) Search "practically anything" incl. vendors — scope decision pending (crew vs office, money); (e) the owner's AI Office overlay still says "Charlie" — the Kaname rename has not reached Android.
- 2026-10-27 — **the three missing New rows built** (Tim: "New client, New quote and Add a note. Build that"). Server (commit a3134ec4, deployed): `POST /api/schedule/field-job {action: create_client}` (client + one property, no job; shares the contact step with create_client_job) and new `POST /api/schedule/client-note {property_id, content}` (clients.view, idempotent); 5 tests, suite 3599 green. Android (codex/native, Codex 82828c1 + Claude fixes): the sheet is now Snap a receipt · New job · New client · New quote · Add a note · Clock in. New client = a client-only mode of the add-job flow (address prefilled from GPS, "Save client", then "Add a job for them" / Done). New quote = pick today's stop or a searched property → the recommendation flow from its visit; a property with no visit today gets "New job here" first (there is no blank-quote route, by design). Add a note = same picker → a visit note (today's stops, durable queue) or a client note (searched property, new endpoint). Codex review findings on the server: one real (JSON array cast to property 1) fixed; three pre-existing (idempotency keys not scoped per endpoint; check/store race; create_client_job can orphan a contact when the address is already attached elsewhere) — follow-ups, not new. OPcache reset still needs Tim's tap for create_client to be live. iOS still owed for all of navigation + these rows. Seen: production claudia.ittai.ca 404s every /api/* path (token.php, schedule/day) while /crm/api/app-version.php answers — the tenant host does not serve the JWT API.
- 2026-10-27 — **iOS showed last week's photos on today's visit (Tisdall)** → not a photo bug: the stop reschedule carried a photographed, never-completed `job_visits` row onto this week's stop. Built `VisitCarryGuard` (commit 1953cd4b, deployed): a visit with an After photo or completed_at is completed where it is (completed_at = photo time) and a fresh visit goes on the new date; wired into `StopRescheduleService`, `VisitLifecycleService::moveVisit`, `UnscheduledWorkService::moveVisitDate`. Codex: 2 real (single-visit paths now atomic; duplicate sequence retried), 1 narrowed (stop-level settling = scheduled only), 1 not real. Suite 3610. **Today's Tisdall data is NOT repaired** (last week's visit still carries this week's stop; Tim asked only for the guard) — repair snippet is a prod-run away if wanted. Also seen: `/crm/api/reschedule-visit.php` answers HTTP 500 to an anonymous request (siblings 302) — identical to repo, untouched by this change, cause unknown (error_log not at public/error_log).
- 2026-10-27 — **Ask first went to the caretaker, not the property manager** (Tim on site at Tisdall: "trying to send this with ask first to the property manager"). `FieldAskService::recipients()` never looked at `properties.property_manager_id` while invoices already route there. Now (commit 49905f88, deployed): the manager firm's billing/primary contact wins Ask first and is the quote recipient; an unsent draft follows the live rule when opened; the firm's contacts count as B2B for consent; iOS label "property manager" in the next build. Codex: 3 real fixed, 1 documented (email-only routing still needs a contact row), 1 **pre-existing follow-up: `companyManaged()` grants the B2B consent basis to ANY contact once the property has a `company_properties` row** — tighten to contacts related to that company. Unverified: whether 6015 Tisdall actually has a property manager set (production reads blocked in this session).
- 2026-10-27 — **Mowology Android flavor in the Ittai design** (Tim: "review the mowology app and brand it with ittai design") → review in `docs/plans/2026-10-27-android-mowology-ittai-branding.md`: the Ittai screens are already in the flavor behind the live `android.native_*` switches (OFF; 1.6.0 crews see the WebView); needs Tim's go on the switch flip (safe for 1.6.0, which ignores the new keys), the icon/name call, and a phone trial of a Mowology-flavor test APK with his login.
- 2026-10-26 night — **navigation adopted** (Tim: "Yes adopt" on the round-2 mock, canvas KCesZ3bUE654SMPDopFzzV): Ittai symbol 40 px centred in the top bar opens AI Office (admin/manager) or Account (crew) full-screen; the bottom bar is Schedule · Time · **+ New** · Search · Receipts, New opening a full-height sheet (Snap a receipt · New job · Clock in — New client / New quote / Add a note omitted until a native route exists); month "Oct 2026" beside Today. Android: Codex round 3 spec `docs/plans/2026-10-26-android-ittai-crew-app-codex-spec-round3.md`. **BUILT on codex/native** — Codex a4a9f88 + Claude review fixes (compact Today, aligned New slot, MON/TUE strip); emulator-checked on claudia.ittai.ca; tests 503/503 per flavor; nothing released. **Owed: iOS to match** (MainTabView: symbol entry, + New, "Time" label). Seen in passing, not fixed: the opening brief shows "as of M10 26 9:32 pm" (a date-format slip in the overlay, Kaname rename also still pending).

## 2026-10-27 — Android phone sign-up v2 (Task 28, codex/native fde10c6 + 6154d9d, NOT released)
- Built from `docs/plans/2026-10-27-android-phone-signup-v2-codex-spec.md`; walked end to end on the emulator against sage.ittai.ca (now claimed). Behind `android.native_signup` (forced on for the test-tenant flavor only).
- Owed: the Intuit round trip from the phone on production (testcompany); the SMS leg; the owner-facing wording of the server's 409 `not_set_up` (today it names secrets.php); a phone trial by Tim.

## 2026-11-04 — from Tim's tablet walk and two design references (not built)
- **Sign in with Google (Android)** — Tim: "Sign in with google is useful on android"; Google project must be **Ittai** ("Then it has to be ittai"), owned by ittai@mowology.ca for now ("we will change later"). Spec: side-session notes `2026-11-04-sign-in-with-google-spec.md`. Server half: parent Android session; app half: Codex task 33. Blocked on Tim signing ittai@mowology.ca into Chrome (or creating the project himself).
- **Wide-screen sign-in** — two panels (navy brand panel + form) sliding between sign in and sign up, for web at desktop width and tablet landscape only; phones keep today's flow. From a "interactive login page" reel Tim shared.
- **"Meniscus" bottom bar** — the active tab lifts out of the bar as a bead in a notch and slides between tabs. Fits the adopted bar (Schedule · Time · + New · Search · Receipts) if done in v1.2 terms: ONE accent (tenant colour, not a colour per tab), no glow, no drag-the-bead, still under reduce-motion, ≥48 dp targets for gloved hands. Needs a design-studio round before any build.
