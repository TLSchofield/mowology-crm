# Claudia dress rehearsal — the phone sign-up, end to end on production (2026-11-03)

Tim: "I only get one shot at impressing someone and delivering a confident use." This is the full run of the
founding-business path on a real phone against production, using a throwaway business so nothing of Claudia's
is touched. Each part below says who does it and how it is proven before the day.

## The pieces

| Piece | What | State |
|---|---|---|
| Business | `rehearsal` → rehearsal.ittai.ca, DB `mowology_rehearsal`, owner email mowology@icloud.com (so the code email lands on Tim's phone). Provisioned by `TenantProvisioner` through `scripts/prod-run.sh --commit` (snippet: provision key/name/owner_email, password stripped — sign-up v2 sets its own). **Trap:** the provisioner's DDL ends prod-run's wrapper transaction, so the first run died after the tables with "There is no active transaction" and the wrapper's "nothing changed" was wrong (DB + 496 tables + reference rows existed). Re-ran with the provisioner on a plain `new PDO(...)` of its own (the documented way to stay outside the wrapper): registered, owner created. | **LIVE 2026-11-03** — 496 tables, 297 migrations logged, 68-account chart, 1 owner, unclaimed |
| Feed | `public/crm/downloads/app-version.rehearsal.json` — native_auth, native_signup, native_crew_schedule on; `/crm/api/app-version.php` merges it on that host only (`AppVersionFeed`). | **LIVE** — checked on rehearsal.ittai.ca; mowology.ca unchanged |
| App | Ittai-flavour APK built with `-PtenantTestHost=rehearsal.ittai.ca` (two-people icon, "Ittai" label, Ittai splash) at `/crm/downloads/ittai-rehearsal-signup-v2-test.apk` (package ca.mowology.crew.test 2.2.8 code 40; replaces the testcompany test build on the same phone). | **LIVE** (codex/native a3f1083, not pushed) |
| QuickBooks | Intuit app "ittai" (ittai@mowology.ca) → Keys & credentials → Development → Redirect URIs: add `https://rehearsal.ittai.ca/crm/accounting/quickbooks-callback.php`. Sandbox company is the one on that developer account. | Tim |

## The walk (what Claudia will do, in order)

1. Install the APK; first launch fetches the feed and opens native **sign-up** (not the web sign-in).
2. Sign-up: full name, cell, email (must be the provisioned owner email), password (10+ characters). → "We emailed you a code."
3. Enter the 6-digit code from the email (15 minutes; 5 tries). → signed in, straight to onboarding.
4. **About you** — how you like to be called, how to reach you, quiet hours.
5. **Company** — type the business name, pick the registry match (or "none of these" → manual), confirm.
6. **QuickBooks** — "Yes, I use QuickBooks" → Connect opens the Intuit tab → sign in → pick the sandbox company → Connect → "QuickBooks connected — go back to the Ittai app" → back in the app the step shows connected. (Or "No" → **Your books** → Penny's books acknowledged.)
7. **Accountant** — invite by email, or skip.
8. **You're set up** → the day screen.

## Proof before the day

- ✓ 2026-11-03 Server shapes on rehearsal.ittai.ca: root → sign-in "Sign in - Rehearsal"; signup 400 four-field set; a non-owner email → "Use the email address this business was set up with."; 401 on map/quickbooks-start without a token.
- ✓ 2026-11-03 Email leg: one real `signup.php` request from the Mac with the owner email → 200 `sent:["email","sms"]`, expires_in 900. Tim to confirm the code arrived in mowology@icloud.com (the phone's own request re-issues it; 5 requests/hour).
- ✓ 2026-11-03 Feed: rehearsal.ittai.ca shows the three switches true; mowology.ca unchanged.
- ✓ 2026-11-03 QuickBooks start URL built under the rehearsal tenant (prod-run preview, `Tenant::forTests`): keys present (`missing` empty), authorize host appcenter.intuit.com/connect/oauth2, redirect_uri `https://rehearsal.ittai.ca/crm/accounting/quickbooks-callback.php`, scope accounting. Intuit will accept it once Tim adds that URI to the app.
- The phone walk itself is the final proof; Tim does it once before Claudia's day. If anything is off, the fix is server-side or app-side and the business is re-provisionable under a new key (keys are 3–20 lowercase letters/digits; each new host needs its own Intuit redirect line).

## Known rough edges to decide before Claudia

- **Intuit redirect URI per business** — every business host must be added to the Intuit app by hand. Fine for the first few; a single fixed callback host that forwards by state is the product fix.
- **"Your books" after QuickBooks yes + Skip** — the web rule marks it Not needed; Tim to say keep or ask again.
- **SMS leg** of the code is best effort and unproven on production; email is the one that counts.
- **Business name in the token** (`user.business_name`) still owed in token.php.
