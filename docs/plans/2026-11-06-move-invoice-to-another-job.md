# Move an invoice to another job (Tim, 2026-11-06)

Tim: "THIS IS LINKED TO THE WRONG PLAN, I NEED TO BE ABLE TO MOVE IT EASILY" — INV-2026-0499 was linked to the
Fall Service job but was really yew trimming. Nothing in the CRM can change an invoice's job today.

## What gets built

### 1. `app/Modules/Invoices/Services/InvoiceMoveService.php` (all the logic; page/API stay thin)
`class InvoiceMoveService { __construct(PDO $db) }`

- `candidates(int $invoiceId, string $search = ''): array`
  Jobs the invoice could move to. Each: `plan_id, plan_number, title, property_id, property_label, same_property(bool),
  visits: [{visit_id, visit_number, scheduled_date, status}]` where visits = that plan's **completed** visits with
  `invoice_id IS NULL OR invoice_id = 0` (plus the invoice's own current visit is never listed).
  Without search: plans at the invoice's property first, then other plans of the same company (`job_plans.company_id`
  or properties managed by that company: `properties.property_manager_id`), newest first, max 30, excluding the current plan.
  With search (≥2 chars): match `plan_number` or `title` LIKE across all non-cancelled plans, max 30.
- `preview(int $invoiceId, int $targetPlanId, ?int $targetVisitId): array` — read only. Runs every check below and returns
  `{ok, error?, from:{plan_number,title,visit_number,property_label}, to:{…same…}, property_changes(bool),
  recipients_before:[emails], recipients_after:[emails], recipients_will_change(bool), warnings:[strings]}`.
- `move(int $invoiceId, int $targetPlanId, ?int $targetVisitId, int $userId): array` — same checks, then one transaction.

**Checks (refuse with a plain-English error):**
- invoice exists; `amount_paid = 0`; status in (draft, sent, viewed, overdue) — paid/partial/cancelled/void refused
  ("Paid or part-paid invoices can't move — refund or cancel first").
- `stripe_payment_intent_id` set and status not draft → refuse (a payment may be in flight).
- target plan exists, is not the current plan+visit pair, status not cancelled.
- target visit (if given) belongs to the target plan, is `completed`, and has no invoice (`invoice_id` NULL/0) — re-checked
  under `SELECT … FOR UPDATE` inside the transaction (same pattern as `InvoiceFromVisitService::createFromVisit`).
- target plan billed under an active contract → refuse, same rule/message as createFromVisit
  (`ContractService::isPlanContractBilled`).

**Writes (one transaction, all or nothing):**
1. Old visit (`invoices.visit_id`, if any): `job_visits SET invoice_id = NULL, is_invoiced = 0 WHERE id = old AND invoice_id = this invoice`.
2. New visit (if given): `SET invoice_id = this, is_invoiced = 1 WHERE id = new AND (invoice_id IS NULL OR invoice_id = 0)`;
   rowCount must be 1 else roll back ("That visit was just invoiced by someone else").
3. `invoice_line_items SET visit_id = new (or NULL), service_date = new visit's scheduled_date (only when a new visit is given)
   WHERE invoice_id = this AND (visit_id = old visit OR visit_id IS NULL)` — lines tied to *other* visits are left alone.
4. `invoices SET plan_id, visit_id (new or NULL), property_id = target plan's property, company_id = COALESCE(target plan
   company_id, current company_id), contract_id = target plan contract_id, service_address/city/province/postal_code from the
   target property **only when the property changes**, pdf_path = NULL, pdf_version = 0, pdf_generated_at = NULL`
   (only `pdf_path` matters for the cache — see Known-Failure-Patterns "cached file is never auto-invalidated").
5. Recipients, only when the property changes: for a **draft**, replace `invoice_contacts` with
   `resolveManagementBillingRecipient(newPropertyId)` when that returns rows; otherwise keep the current recipients.
   For a sent invoice never touch recipients; preview lists them and adds the warning
   "Already sent to … — resend it after moving so they get the corrected copy."
6. `logActivityExtended($userId, 'Invoice moved', 'INV-… moved from PLN-…/V… to PLN-…/V…', company, null, null, invoiceId, newPlan, newVisit)`
   (function exists in app/Services/CrmFunctions.php; guard with function_exists for tests).
Return `{ok, invoice_id, message}`.

### 2. API `public/crm/api/invoice-move.php` (thin)
Session auth (`requireLogin()` like other `public/crm/api/*.php`), JSON out, never leak DB errors.
- GET `?mode=candidates&invoice_id=&q=` → candidates
- GET `?mode=preview&invoice_id=&plan_id=&visit_id=` → preview
- POST `mode=move` with `csrf_token` (verifyCSRFToken; AppStack pages expose `window.MW_CSRF_TOKEN`) → move
Use `?mode=` not `?action=` (router collision).

### 3. UI on `public/crm/invoices/view.php` and `edit.php`
- A small "Move to another job" link/button beside **Linked Plan** in view.php's Invoice Details card, and the same button
  near the top of edit.php (by the crew time panel). Only rendered when the invoice is movable (unpaid, not cancelled).
- Opens a Bootstrap 4 modal (AppStack): search box, list of candidate jobs (same building first), each with its unbilled
  completed visits as radio choices plus "No visit — link the job only". Choosing one calls preview and shows
  From → To, bill-to/property change, recipients before/after and warnings. **Move** button posts; on success reload view.php
  with `&flash=moved`.
- JS in a new file `public/crm/js/mw-invoice-move.js` (vanilla, no inline business logic); styles in
  `public/crm/css/mowology-brand.css` with `.mw-` classes and `--mw-*` tokens, no inline `<style>`, no hex.
- Bump `?v=` on any changed asset.

### 4. Tests
- Register the service in `tests/bootstrap.php`; `tests/Unit/Invoices/InvoiceMoveServiceTest.php` on the repo's SQLite test
  pattern (see other service tests there): moves visit links both ways, line items follow only the old visit, refuses
  paid/part-paid/cancelled, refuses a target visit that already has an invoice, refuses contract-billed target (or skip if the
  contract service can't load under SQLite, but test the rest), clears pdf_path, draft recipients replaced on property change,
  sent recipients untouched.
- Full `php -d memory_limit=2G vendor/bin/phpunit` must pass.

## Rules (from CLAUDE.md)
PHP 7.4-compatible syntax, MySQL 5.7 (no window/JSON functions), prepared statements, `h()`/htmlspecialchars on output,
AppStack vendor files untouched, no new business logic in page files.
