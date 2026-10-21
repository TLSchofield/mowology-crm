# How Ada operates — PLANNED, NOT BUILT

Agreed with Tim 2026-10-11. None of this is implemented yet.

## She watches; others fix
- Ada reads. She never writes to business tables, never deploys, never resets OPcache, never
  runs a migration, never reconnects an account, never edits the crontab.
- Every problem ends in a **hand-off**, addressed to one of:
  - **a developer session** — code is wrong or missing (e.g. a cron that never calls
    `recordCronRun()`, a check reading the wrong table);
  - **Tim** — it needs his login or his decision (cPanel crontab, Meta / Google reconnect,
    migrations runner, OPcache reset, rotating a secret, Apple/Google consoles).
- A hand-off says: what is wrong, the evidence (row / run / file and its date), the likely cause
  if known, the single next step, and how to confirm it is fixed. Short enough to paste into a
  new Claude session.

## Evidence, not alarms
- One line per problem in Kaname's brief: system, what is happening, the contrast that makes
  it suspicious, a verdict word ("probably broken", "stale", "cannot tell").
  Example: "Yardi reader: 0 emails since it started on Aug 5, while 5 remittances (May–Jul) sit in office@ before its start date and Dorset's go to info@/iCloud, which it never opens — coverage gap, not necessarily broken."
- No line for green systems. No repeat of the same line every day unless it got worse; age is
  shown instead ("for 12 days").
- "Cannot tell" is a real state (amber): the source does not record enough to judge. That is a
  developer hand-off, not a guess.
- Lines are ordered by cost of waiting: money in or out first (Stripe, autopay, Yardi,
  e-Transfer, invoices), then crew in the field (tracking, push, app version), then marketing
  and reports.

## Rules, not AI
- Every check is a query or a file comparison with a written threshold. No model calls.
- Thresholds live in one place (planned: ops_settings keys, same pattern as other heads) so Tim
  can tune them without a deploy. First drafts are in `checks.md`.
- Server-side checks only use what the server can see. Mac-side evidence (prod drift, TestFlight,
  crash logs) is gathered by a developer session and is never claimed by Ada as hers.

## Health page states
| State | Meaning |
|---|---|
| green | last worked inside its expected interval, and a second source agrees |
| amber | stale, quiet with no contrast, or "cannot tell" |
| red | failing, refused, expired, or quiet while a second source shows work waiting |
Each row shows "last worked" (the last time it demonstrably did its job — not the last time it ran).

## Never
- Print or store a secret value anywhere (brief, page, log, vault, skill).
- Treat a stored badge ("Verified", "Never run", "configured") as proof of health.
- Mark a system green because nothing complained.
