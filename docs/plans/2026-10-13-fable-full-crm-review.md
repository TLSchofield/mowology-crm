# Plan: Fable reviews every segment of the CRM and writes the fix plans

Status: **Tim asked for this plan on 2026-10-13. Nothing runs until he says go.** Reviewer: Fable (read-only). Fixer: Claude, one approved plan at a time.

## Why
Two Fable reviews on 2026-10-13 (ledger; invoicing and payments) found 6 High and 3 High problems that tests and daily use had missed. The worst were **silent**:
- journal posts that failed and were logged as "skipped";
- a fee account pointing at Meals;
- a customer pay page that could charge twice.

The same kind of problem is likely in every part of the CRM. A segment-by-segment review finds them before a client or the accountant does.

## What each review produces
One file per segment, `docs/reviews/2026-10/NN-<segment>.md`, in two parts:
1. **Findings**, most severe first. For each: severity, the defect in one line, file:line, evidence (query and output, or code), a concrete failure scenario (who is charged, emailed, misbooked or locked out), and a one-sentence fix. Unverified items are tagged. Then a "checked and clean" list and a live-data table.
2. **A draft fix plan** in the house format: Unknowns → how each is settled (cheapest first) → steps with proof. It's ready for Tim to approve, after which I build it.

I then merge all of them into **one ranked list** (`docs/reviews/2026-10/00-triage.md`):
- Critical/High first, grouped where one fix closes several findings;
- each row tagged with its owner head (Penny, Sam, Otto, Mia, Yui, Charlie, Ada).

Tim picks which plans to run.

## The segments
Done on 2026-10-13: **Ledger and accounting** (plan `2026-10-13-ledger-silent-failures.md`, Part A shipped) and **Invoicing and payments** (plan `2026-10-13-invoicing-payments-fixes.md`). Earlier reviews are inputs, not repeats: security audit 2026-10-12, Penny review rounds 2026-10-12, centralize-data review 2026-10-11.

| # | Segment | Main code | Why it matters |
|---|---|---|---|
| 3 | Receipts and expenses | `app/Modules/Expenses`, `app/Services/Receipts`, iOS Receipts, receipt crons | GST claims, card funding, duplicates, OCR learning |
| 4 | Payroll, time clock, timesheets | `app/Modules/Team`, timeclock pages, Wave import, iOS TimeClock | Wages, CRA remittances, crew trust |
| 5 | Jobs, schedule, visits, contracts in the field | `Jobs`, `Schedule`, `Contracts` (field side), rollover and missed-visit crons | Missed or double visits, contract work skipped |
| 6 | Crew field app: GPS, tracking, geofence, driver, offline | `Tracking`, `Geofence`, `Driver`, `Device`, crew pages, offline queue, Android shell | Silent tracking loss, lost completions, consent |
| 7 | Clients, contacts, companies, properties, privacy and consent | `Contacts`, `Clients`, `Privacy`, `Consent` | Wrong recipient, merged records, CASL/PIPEDA |
| 8 | Sales: quotes, pipeline, follow-ups, contract signing | `Quotes`, `Sales`, `Contracts` (sales side) | Wrong price or recipient, lost leads |
| 9 | Messaging: email, SMS, inbox routing, text bridge | `Comms`, `Messages`, MessagingService, `CampaignConnector` | Carrier rules, mail to the wrong person, unsubscribes |
| 10 | Marketing, social, CMS, public site, SEO | `Marketing`, `Social`, `CMS`, `Portfolio`, `Reviews`, `Referrals`, `public/` | Dead publishing, broken pages, claims on the site |
| 11 | Customer portal and public forms | `public/customer`, quote request, contact forms, `/api/` | Client data exposure (IDOR), spam, tokens |
| 12 | AI heads and automations | `ChiefOfStaff`, `Operations` (Otto), Sam, Yui, crons, LLM caps | Wrong automatic actions, runaway API cost |
| 13 | Products, pricing, cost factors, inventory | `Products`, cost factors, price lists | Quote prices, margins, stock |
| 14 | Platform: auth, roles, sessions, CSRF, API router, crons, deploy and backup scripts, admin tools | `loginAuth`, `app/Core`, `Database`, `Settings`, `Technology` (Ada), `scripts/` | Access control, drift, recovery |
| 15 | iOS native app | `ios/MowologyCRM` | Parity with web, offline data safety, JWT endpoints |
| 16 | Small modules | `Quiz`, `Gamification`, `Media`, `Photos`, `Knowledge` | Completeness |

## The reviewer's rules (from what went wrong or right on 2026-10-13)
- **Read-only.** No edits, commits, deploys or pushes. No email, SMS or Stripe calls (not even reads), and no requests to production URLs.
- **Production data:** `scripts/prod-run.sh` previews only (never `--commit`), SELECT only.
- **Review the live code, not just the repo.** The repo has been behind production (vendor commons, push-key fix). For each file reviewed, compare the server fingerprint (`prod-run` + `sha1_file`); if it differs, read the live copy. Never trust a downloaded copy without checking it was refreshed.
- **Evidence for every claim** (file:line, or the snippet and its output). Tag anything unverified. No invented numbers.
- **Read first:** the vault notes for the area (Known-Failure-Patterns, Decision-Log, Tech-Debt-Map), the head skill for the area, and earlier reviews, so known items aren't re-reported as new.
- **Hunt the families already found:**
  - a silent `catch` around a write;
  - constructor or argument mismatches;
  - non-atomic multi-step writes;
  - a check outside its transaction;
  - BS5 classes on Bootstrap 4;
  - class-name clashes in live-only CSS;
  - wrong account codes;
  - "nightly job" promises with no job;
  - portal wording that claims what the code doesn't do.
- **No more than 900 words** per findings section, plus the draft plan.

## How it runs
- **Three waves** of 4–5 reviewers in parallel, highest risk to clients first:
  - **Wave A** (client money, client data, crew pay): 3, 4, 6, 11.
  - **Wave B** (daily operations and contact): 5, 7, 8, 9, 12.
  - **Wave C** (everything else): 10, 13, 14, 15, 16.
- **Each wave ends with a check by me before the next starts:**
  - I spot-check every High finding against the code (as I did today with four of Fable's claims);
  - I drop anything that doesn't hold up;
  - I add the wave to the triage list.
- **Cost and time:** today's two reviews used about 230k and 320k tokens and took about 10 minutes each. Fourteen segments come to roughly 4–5M tokens and 30–40 minutes per wave.
- Runs as a workflow (one script fans out each wave and collects the files) or as background agents I launch wave by wave. Either way, nothing in production changes.

## What Tim decides
1. Go, and which wave first (Wave A recommended).
2. After each wave's triage: which fix plans I build, in what order. Each plan still follows the house rules: test first, local site, deploy, server fingerprint, and a production check.
