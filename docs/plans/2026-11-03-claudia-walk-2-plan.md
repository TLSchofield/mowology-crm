# Claudia walk 2 — every fix from walk 1 (plan, 2026-11-03)

Tim: "Do all updates and fixes for the 2nd test walk." Walk 1 findings: `2026-11-03-claudia-rehearsal-runbook.md` → "The walk".
Goal: a second phone walk on a fresh business where every screen is one Claudia could see, and the QuickBooks part
imports for real.

## Unknowns and how each is settled

| # | Unknown | Settled by |
|---|---|---|
| U1 | Is lifting the "one business" import guard safe for Mowology? | Code read 2026-11-03: the guard is `QboOnboardingService::apply()` (LOCAL_TEST_SITE only). Keep Mowology (default business) preview-only; allow every other business (each has its own database, `Tenant::isDefault()`). Test both ways. |
| U2 | Does the import touch the ledger or money? | Code read: no. It writes business_settings, contacts/properties/companies (via ContactService), products, vendors, qbo_mappings. No journal lines, invoices or payments. Still money-adjacent → Codex review. |
| U3 | Re-run safety (Claudia taps Accept twice, or re-reads) | Code read: clients matched by import note / email / name+address; services and suppliers by name; mappings replaced; a re-read clears accepted parts. One write (`markPart`) uses the MySQL changed-row pattern that broke sign-up tonight → fix. |
| U4 | Does the import work against a real QuickBooks shape on the live server? | Only proven on the local test site with a sandbox snapshot. Settled by walk 2 itself on the throwaway business (sandbox company). Tim's own live QuickBooks later (needs Intuit Production keys — see U7). |
| U5 | Which business hosts log where | Known: business hosts log to `/home/mowology/logs/*_ittai_ca.php.error.log`. Used to check the missing 'Sign-up (mobile)' activity row. |
| U6 | Android package and identity for Claudia's build | Decision for Tim (below). Walk 2 can ship the non-test flavour under the test package so it installs over the current app. |
| U7 | Intuit Production keys for a live QuickBooks (Tim's) | One Intuit docs read before that test; Production keys usually need the app's production questionnaire. Not needed for walk 2 (sandbox). |
| U8 | Can the QuickBooks tab return to the app by itself? | Android session: does the app register a link it can be reopened by? If not, the callback page says "close this tab" and the app refreshes on return. |

## Server (Claude builds; Codex reviews; deploy on Tim's go)

- **S1 Import on for every business but Mowology.** `QboOnboardingService` decides writes with a pure rule:
  local test site OR not the default business. Mowology's live site keeps the preview message. Tests for both.
- **S2 Continue never loops.** The QuickBooks step counts as done once connected and read; accepting parts is
  optional (each stays doable later on the web QuickBooks page). **Tim's call:** or keep "all five accepted" and add a "Done" action (needs an app change).
- **S3 Rows say what they will do before Accept:** "12 clients to add, 2 already here", "8 services", etc. The app
  already shows each row's summary line, so this needs no app build.
- **S4** `markPart` safe upsert (same MySQL trap as tonight).
- **S5** Missing 'Sign-up (mobile)' activity row: read the business log, fix if it is a real fault.
- **S6** QuickBooks callback page in the Ittai design, one line ("QuickBooks is connected. Go back to Ittai."), plus the return link if U8 allows.
- **S7** `user.business_name` in the sign-in token (owed since sign-up v2), so the app can greet the business by name.

## Android (Android session writes via Codex; Claude reviews)

- **A1 Claudia's build without the TEST TENANT banner:** a non-test Ittai flavour; the all-native defaults key off a new Ittai flag, not the test flag. Host baked in at build.
- **A2 Welcome:** hold the Ittai splash briefly, then one welcome screen ("Welcome to Ittai") with one button. No "Back" on the first screen.
- **A3 Onboarding in the axis style** (Tim liked the website's): step number + kanji above each title, a thin progress spine replacing "All steps", the heading turns into the answer once done. Added to Design System v1.2 as a documented pattern, not ad hoc.
- **A4 Company:** match rows look tappable; registry names shown in normal case.
- **A5 QuickBooks:** "Accept all"; heading changes after connecting; the rows' server summary shown (S3).
- **A6 Accountant:** "Invitation ready for <name>" with Share and Copy; the raw link not shown on screen.
- **A7 Finish:** a short summary (business, QuickBooks, accountant) and the AI team introduced in one line each.
- **A8 Day screen:** chief of staff shown as **Kaname** (name from the server, as the web does); "this morning" follows the clock.
- **A9 Owner view of the day:** owner wording (no "the office reviews it"), no "Mine / All crews" for a one-person business, an empty-day nudge (add a first client or job).

## Walk 2 setup

- New throwaway business `rehearsal2` (provisioner on its own connection, as tonight), its feed override file, the new APK.
- Tim adds its Intuit redirect line before starting.

## Verification

Server: tests per change, Codex review of the diff, local render, deploy, prod-run preview checks. Android: Android
session tests per flavour, emulator walk against `rehearsal2`, Claude reviews the diff and screenshots before the APK
link goes to Tim. Walk 2 itself is the final proof.

## Tim's calls

1. S2: QuickBooks step done once connected and read (recommended), or keep all five accepted plus a Done button.
2. A1: package for Claudia's real build — keep `ca.mowology.crew.test` for walk 2 (recommended), decide the store identity later.
3. A3: axis style in the app and the design system — yes (you suggested it).
