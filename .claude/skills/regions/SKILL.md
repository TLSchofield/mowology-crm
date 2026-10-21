---
name: regions
description: Use whenever a rule depends on WHERE the business or the job is — country, province or city. Taxes (GST/PST/HST), payroll and employment standards, WorkSafe, marketing-consent and SMS rules, business licences, noise/leaf-blower bylaws, watering restrictions, snow bylaws. Every head's skill (Penny, Otto, Sam, Mia, Yui, Kaname, Ada) points here instead of keeping its own copy. For Ittai, a business in another place gets its own region files and data rows.
covers:
  - app/Modules/Operations/Services/MunicipalRuleService.php
  - app/Modules/Accounting/Services/TaxEngine.php
  - app/Modules/Accounting/Services/GstReportService.php
  - app/Core/OnboardingQuestions.php
  - database/migrations/*
metadata:
  version: 1.0.0
  created: 2026-10-11
---

# Regions — rules that depend on where the business works

One place per jurisdiction, shared by every head. A head's skill says *what it does with* a rule;
this says *what the rule is*, its source, and where its live value is stored.

| Level | File | Holds |
|---|---|---|
| Country | `regions/CA.md` (this folder: `CA.md`) | GST, CRA payroll, ROE, T4, CASL, carrier SMS limits |
| Province | `CA-BC.md` | PST, WorkSafeBC, BC employment standards |
| City / metro | `CA-BC-MetroVancouver.md` | business licences, power-equipment and leaf-blower bylaws, West End, watering restrictions, cities served |

**Ittai:** a business in Calgary would add `CA-AB.md` and `CA-AB-Calgary.md`; the same skills apply.

## Where the live values live — the database, not this text

| Rule kind | Table / setting (live value the code acts on) | Read by |
|---|---|---|
| Sales-tax rates | `tax_rates` (code, rate, province, applies_to) | `TaxEngine` |
| City hours, bans, areas | `municipal_rules`, `municipal_areas` (status: unverified / confirmed) | `MunicipalRuleService` |
| Business's own place | `ops_settings.company_province` (BC), `business_settings.company_address` | `CompanyProfileService` |
| Business rules asked at onboarding | `app/Core/OnboardingQuestions.php` (e.g. breaks paid) | heads |

The text here explains, sources and dates each rule; the table holds the value. When a rule changes:
change the row (and its status / source_url), then the row in the file here.

## Before and after every change (Obsidian + Graphify)

**Before:** read the Obsidian notes for the area (`~/Library/Mobile Documents/iCloud~md~obsidian/Documents/30-PROJECTS/Active/Mowology-CRM/Architecture/` — `grep -n -i "bylaw\|gst\|pst\|payroll"` in Known-Failure-Patterns.md / Decision-Log.md), and graph who uses the rule: `python3 ~/.claude/tools/graphify.py touches TaxEngine` (or `MunicipalRuleService`). A rate hard-coded outside these (the 5% GST is, in about eight places — see the Penny skill) is a bug waiting.
**After:** update the row in the file here with source + new review-by date; record a mistake in the head's `references/traps.md`; `/obsidian` at session end.

## Status words
**Verified** — read in the primary source on the date shown. **Research** — from a research pass, not yet read in the primary source. **Unverified** — stored but not confirmed (e.g. every `municipal_rules` row as of 2026-10-11). **Confirm with accountant** — Steve Lim CPA decides.

## Keeping this true
`tests/Unit/Docs/HeadSkillsTest.php` fails when a review-by date in these files passes, and when a file or code name in any head's skill disappears. Review every January, and whenever CRA, BC or a city announces a change.

## Known data issues (2026-10-11)
- `tax_rates` holds the GST and PST rows three times (seeded 2026-03-22, 03-24, 05-18). Same values; de-duplicate before anything sums them.
- `properties.city` has misspellings ("van", "Vanvouver") — they miss Vancouver's bylaw rows.
- No `municipal_rules` rows yet for North Vancouver (25 properties), West Vancouver (12), Surrey (9), New Westminster (7), Port Moody, Delta, Coquitlam.
