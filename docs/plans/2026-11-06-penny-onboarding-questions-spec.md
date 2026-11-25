# Build spec — Penny's onboarding questions, server part (2026-11-06)

Plan: `docs/plans/2026-11-06-penny-onboarding-questions-plan.md` (approved by Tim). Server only — no Android/iOS code.
PHP 7.4-compatible syntax where the surrounding file is (no `match`, no enums), MySQL 5.7, no namespaces, `require_once` + `new`.
Match the surrounding code's density and naming. Run `php -d memory_limit=2G vendor/bin/phpunit` — all must pass.

## 1. Four new onboarding modules — `app/Modules/Onboarding/Services/OnboardingModules.php`
Follow the existing module classes exactly (see `OnboardingBooksModule`, `OnboardingAccountantModule`). All owner `penny`,
skippable except Terms. Answers live in `ops_settings` via `OnboardingContext::saveSetting()`.

| key | class | title | question (Penny's words) | answers / setting | DONE when |
|---|---|---|---|---|---|
| `terms` | `OnboardingTermsModule` | Terms | "Before we start: here are Ittai's terms and privacy policy. Please read them and accept to carry on." | accept → `onboarding_terms_accepted` = TERMS version | setting equals the current version |
| `gst` | `OnboardingGstModule` | GST | "Is your business registered for GST?" | `yes` (+ GST number, + filing `monthly`/`quarterly`/`annual`), `no`, `unsure` → `gst_registered` ('yes'/'no'/'unsure'); number into `business_settings.gst_registration` (id 1) when given; `gst_filing` | `gst_registered` set |
| `yearend` | `OnboardingYearEndModule` | Year-end | "Who does your year-end — an accountant, or you?" | `accountant` / `self` → `onboarding_yearend` | set |
| `payroll` | `OnboardingPayrollModule` | Payroll | "How do you run payroll today?" | `none` / `app` (+ free-text name ≤ 60) / `accountant` → `onboarding_payroll`, `onboarding_payroll_app` | set |

- `skippable()` false for `terms`; `needs()` for gst/yearend/payroll: none. `summary()` one line each (e.g. "Accepted terms 2026-11-06.",
  "Not registered for GST — no GST is charged or claimed.", "Year-end: your accountant.", "Payroll: Wave.").
- `question()` for `yearend` when the answer is `self` may add: "The terms ask that an accountant or bookkeeper reviews your books before
  you file. You can still invite one on the next step." (Keep it one or two sentences.)
- `OnboardingService::defaultModules()` order: name, **terms, gst, yearend, payroll**, company, quickbooks, books, accountant, done.
  `normalise()` already places new modules after their default predecessor in a saved order — add a unit test proving a saved
  old order gets terms right after name.

### Terms version — one source
- The live text's version is in `public/includes/legal/ittai-legal.php` (`TERMS_VERSION`). `app/` cannot rely on a path to `public/`
  (production layout differs). Add `const TERMS_VERSION = '2026-11-06'` and the two URLs
  (`https://ittai.ca/terms/`, `https://ittai.ca/privacy/`) on `OnboardingTermsModule`, and a unit test that reads
  `public/includes/legal/ittai-legal.php` and fails if the two versions differ.
- Accepting (`OnboardingStepService`, key `terms`, do `accept`): save the setting; then, best effort and only when
  `!Tenant::isDefault()` (never Mowology): if the `terms_versions` table exists and has no `is_current = 1` row for doc_key `platform`,
  insert (`platform`, version, is_current 1, activated_at NOW()) — use INSERT IGNORE on the unique key then UPDATE is_current; then
  `(new TermsService($db))->recordAcceptance($userId, $ip, $userAgent)`. Missing tables must not fail the step (catch, log, still accept).
  IP/user agent: pass through `$in['_ip']`, `$in['_ua']` that the endpoints add (see 4). Check how `Tenant::isDefault()` is loaded elsewhere.

## 2. GST registration actually switches GST off — money
- New `TaxEngine::gstRegistered(PDO $db): bool` — false only when `ops_settings.gst_registered = 'no'`; true otherwise (missing table/row = true,
  today's behaviour).
- `TaxEngine::gstRate()`: return `0.0` when not registered, before the existing lookups. Today a 0 rate means "unset" and falls back to 5% —
  keep that fallback for registered businesses.
- Audit every caller of `TaxEngine::gstRate(` and `ExpenseGate::gstRate()` (`grep -rn "gstRate(" app public/crm`) for division by the rate or
  a rate>0 assumption; make each safe at 0. List them in your report.
- Receipts/expenses for a non-registrant: no input tax credit. In `LedgerSyncService` expense posting (the lines that debit the 2210 ITC
  account, ~lines 200–260), when not registered, add the GST to the expense line and post no 2210 line (the total stays equal). Same in
  any other place that posts 2210 for an expense (`LedgerRepostService`?). Read `app/Modules/Accounting/Services/LedgerSyncService.php`
  fully before changing. `ExpenseGate::taxCheck` must not flag "GST missing" for a non-registrant.
- Do NOT change GST collected on sales for registered businesses, the receipt parser's reading of GST, or Jobber-era logic.
- Tests: non-registrant → `gstRate()` 0.0; a contract/invoice total gets no GST; an expense with gst_amount 5.00 on 100.00 posts 105.00
  to the expense account and nothing to 2210; registered → unchanged lines.

## 3. Old phone apps never see steps they don't know
- Old Android shows "You're set up" for an unknown key. Add to `OnboardingService` an optional list of keys the client knows; modules
  in `OnboardingService::NEW_STEPS = ['terms','gst','yearend','payroll']` that the client does not know are left out of `map()`,
  `next()` and `readyToFinish()` (as if absent). Web always knows all.
- Phones declare it: `knows` = comma list, as a query parameter on `public/api/onboarding/map.php` and in the JSON body of
  `public/api/onboarding/step.php` (and wherever those build `OnboardingService`/`OnboardingStepService`). Absent → old app → hide all
  NEW_STEPS. Unit-test both.

## 4. Endpoints and web
- `OnboardingStepService::apply()` gains actions: `terms/accept`, `gst/answer` (registered, number, filing), `yearend/answer`, `payroll/answer`.
  Validate: GST number = 9 digits + optional `RT0001` (strip spaces); filing in the three values; payroll app name ≤ 60 chars, stripped.
- `public/api/onboarding/step.php` passes `_ip` (REMOTE_ADDR) and `_ua` into `$in` for terms.
- Web onboarding `public/crm/onboarding/step.php` (AppStack page): one screen per new step, following the existing `books`/`accountant`
  blocks there — buttons and small forms, CSRF as the page already does, styles only via existing classes or `public/crm/css/mowology-brand.css`
  (`.mw-` prefix), no inline `<style>`. Terms screen links open both URLs in a new tab.

## Report back (≤ 300 words)
Files changed; each gstRate caller and what you did; what you could not settle; test count.
