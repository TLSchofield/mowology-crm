# Ittai — first founding business (plan, 2026-10-21)

Owner: "Claudia, a gardener, is willing to try the app; she uses Android" → "her own gardening business" →
"She will be a founding business". So: Ittai's first outside customer. Her clients, jobs and books must never
mix with Mowology's, nothing she sees may say Mowology, and the setup must repeat for the next founders.

## Approach (recommended)

**One copy of the app per business** — same code, its own database, uploads folder, settings and secrets,
on its own address (e.g. `claudia.ittai.ca` or her business name). Strongest separation, smallest risk to
Mowology's live books, repeatable. Real shared multi-tenancy (a business id on every table and query) waits
until there are enough customers to pay for it — it touches nearly every query in the live system.

## What the code assumes today (audited 2026-10-21)

| Assumption | Where | Fix |
|---|---|---|
| Email sender `no-reply@mowology.ca` "Mowology", reply-to `office@mowology.ca` | `SmtpMailer.php:105`, `Notifications.php:36` | From/Reply-To from the install's settings |
| Session folder `/home/mowology/tmp` | `app/Core/session_config.php:25` | per-install path from config |
| Default business settings = Mowology (name, email, website, e-Transfer address) | `business-settings.php` defaults | blank defaults; onboarding fills them (company lookup already built) |
| AI prompts: "Penny… bookkeeper for Mowology, 3-person… Vancouver… owner Tim" (~15 prompts: Penny, Sam, Kaname, Yui…) | BankGuidanceService, ReceiptBookkeeperService, SamFollowupService, CharlieAskService… | one `TenantIdentity` (business name, trade, city, owner's name to use) filled into every prompt |
| SMS/email templates and copy with "Mowology" and (778) 846-9273 | SamFollowupService, FieldRecommendationService, DraftSafety, ~8 phone hits | from TenantIdentity / business settings |
| e-Transfer auto-deposit `info@mowology.ca` | `EtransferNudgeService` | setting |
| Crons with `/home/mowology/...` | ~12 cron files (mostly docs) | per-install cron list generated from one file |
| Mobile apps locked to `mowology.ca` (bundle `ca.mowology.*`) | capacitor.config, iOS VersionCheck | phase 1: web app in Chrome; phase 2: own Android build |
| Mowology-specific rules in Penny's knowledge (shareholders, accounts, vendor lessons) | ops_settings + tables | fresh database: none of it copies; the shared vendor commons is the only shared layer (already tenant-neutral) |

135 files under `app/` mention Mowology; most are comments. The sweep changes only what a user, client or AI
model sees, plus the sender, session path and defaults.

## Unknowns and how each is settled

| # | Unknown | Settled by |
|---|---|---|
| U1 | Hosting: a second site on the same cPanel account (subdomain + its own folder + its own database) | Tim creates the subdomain and database in cPanel (I can't over FTP); we check PHP version and cron access there |
| U2 | Her email sending: from her own domain (needs her DNS/SMTP) or from an Ittai address (e.g. `hello@ittai.ca` "on behalf of" her business, reply-to her email) | Tim decides; phase 1 = Ittai sender with her reply-to (no DNS work for her) |
| U3 | Texts: her clients' texts must show her number, not Mowology's | settings; SMS stays plain text, no URLs (rule 11) |
| U4 | Fresh database builds from schema + 345 migrations without Mowology data | build it locally first from `COMPLETE_DATABASE_SCHEMA_CLEAN.sql` + migrations; a test that a fresh install has zero Mowology rows |
| U5 | No Mowology identity anywhere she can see | after the sweep: crawl her local copy as owner and crew, grep every page, email, PDF and prompt for "Mowology", "mowology.ca", "778", "Tim" |
| U6 | Android: she uses the web app in Chrome first (phone-only flows must finish there — standing rule) | test every owner flow at 412 px in Chrome on Android; an Android build pointing at her address comes after |
| U7 | Keys: AI (Anthropic) usage billed to Ittai; Stripe/QuickBooks are HER accounts, connected by her | per-install secrets; AI usage capped per day per install |
| U8 | Legal: her data, privacy, trial terms | a short founding-business agreement (her data is hers, export any time, deletion on leaving, what Ittai stores and why) — Tim + lawyer, before her real clients go in |

## Steps (each proven before the next)

1. **TenantIdentity** — one service (like HeadIdentity): business name, trade, city, owner's name to use, phone,
   email, sender address; from business settings + `business_identity`, with safe blanks. Tests.
2. **The sweep** — sender, session path, defaults, prompts, templates, phone numbers, e-Transfer address all
   read TenantIdentity. For Mowology nothing changes (its settings already hold its details) — proven by
   tests and by rendering Mowology's pages before/after.
3. **Fresh install, local** — a second local site from schema + migrations; no Mowology rows; the leak crawl (U5).
4. **Her copy, hosted** — Tim creates subdomain + database; deploy; per-install secrets; crons; HTTPS.
5. **First session with Claudia** — on her Android in Chrome: the onboarding (About you, company name lookup,
   QuickBooks or our books, accountant), then her first client, quote and invoice. We watch, note what's hard.
6. **Then**: her Android build, her data import (from whatever she uses now — the "Switch from Jobber" list),
   pricing per the founding terms.

## Owner's decisions before step 4
- Her address (subdomain) and whether Ittai sends email for her (phase 1) or from her domain.
- Founding terms: price now / later, what "founding" earns her.
- The founding-business agreement (data ownership, privacy, leaving).
