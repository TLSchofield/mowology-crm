# Quotes — money, lines, approval, recipients

## Totals and GST
- **GST is always added, never included** (Tim, 2026-10-06). Total = subtotal + 5% of subtotal.
  Terms say "GST (5%) is added to all prices." (`database/migrations/1187_gst_added_terms.sql`
  replaced "All prices include GST." in default terms, the contract template and open quotes).
- The total is `calculateQuoteTotals()` — two copies that must stay in step:
  `app/Services/CrmFunctions.php` and `public/includes/functions.php`. Both skip optional lines
  and `client_declined` lines.
- After a client unticks a line, `recalculateQuoteTotals` in
  `public/customer/api/quote-upsell.php` rewrites the stored subtotal / tax / total (its own 5%).
- `QuoteLineChoiceService::totals()` and `QuoteApprovalService::summary()` each carry their own
  `TAX_RATE = 0.05`.
- **Risk:** `QuoteService::calculateTotals()` delegates to `calculateQuoteTotals()` when loaded;
  its fallback takes GST from each line's `tax_rate` and adds **0%** when lines carry none. Not
  seen to fire — unverified. Make sure the helper is loaded wherever you call it.

## Lines the client declines (`QuoteLineChoiceService`, migration 1315)
- The client can untick a line on `public/customer/quote.php`; the total and GST update; signing
  accepts exactly what is ticked. The line stays on the quote marked "declined by client".
- Rules (`QuoteLineChoiceService::allowed()`, `QuoteLineChoiceService::canDecline()`):
  never on a contract quote; only while `sent` / `viewed`; at least one line stays ticked;
  upsells, optional and $0 lines can't be unticked; the office can lock a line
  (`QuoteLineChoiceService::setLocked()`) or switch tick boxes off per quote
  (`quotes.allow_line_decline`, NULL = `ops_settings.quote_line_decline_default`, seeded 1).
- **Every reader of `quote_line_items` that turns lines into money or work must skip
  `client_declined`:** totals (both copies), plan conversion
  (`getQuoteLineItemsWithStatus()`, `createPlanFromQuote()`), the PDF, the edit form. Use
  `QuoteLineChoiceService::notDeclinedSql()` in SQL.

## Approval → Sam's summary → tray job (`QuoteApprovalService`, migration 1316)
- Accept paths that call `QuoteApprovalService::afterApproval()`: `public/customer/quote.php`
  (signature), `public/crm/customer/quote.php` (legacy), `public/crm/quotes/view.php`
  approve_verbal (`QuoteService::approveVerbal()`). The nightly sweep in
  `app/Modules/Jobs/Cron/generate_visits.php` catches the rest. **A new accept path must call
  it**, or the job waits a night.
- Summary: every line ✓ / ✗ with amount, approved vs quoted total, who approved and how.
- Non-contract → tray job (`stop_id = NULL`, shown by `getUnscheduledVisits()`). Anything that
  "ensures a calendar stop" for every scheduled visit would pull tray jobs onto today.
- Contract → "set up from the contract". Snow contract signing runs
  `SnowContractService::setupFromSignedQuote()` (daily route, one invoice per run).

## Who gets the quote (`QuoteRecipientService`, migration 1186)
- `properties.site_contact_id` is the BILLING contact — for managed buildings often the firm's
  accountant, who must never receive quotes.
- New quote recipient (`QuoteRecipientService::forProperty()`): property `quote_contact_id` →
  related company `quote_contact_id` (property manager → billing company → company → …) →
  site contact. Once Tim picks "Send to", `quotes.recipient_chosen = 1` and sends follow
  `quotes.contact_id` (`QuoteService::applyChosenRecipient()`).
- **Strata / managed buildings: CC the property manager** (`QuoteRecipientService::ccForQuote()`):
  building manager row → on-site contact who is a property manager → management company's
  primary contact who is a property manager. Never the recipient again, never an address without
  a valid email, never when the quote's `cc_property_manager = 0`. Sam's follow-ups CC the same
  way (`QuoteRecipientService::ccEmailsForQuotes()`).
- Before giving a management-company contact an email, check the company's invoice routing —
  an email can silently redirect invoices (see traps).

## Sending
- Quote email from `public/crm/quotes/view.php` → `sendEmail()` with the PM CC, then
  `QuoteService::markSent()`. Reply-To is office@ so replies reach Sam.
- Quote-ready SMS: `sendQuoteNotificationSms()` exists in `app/Services/Messaging/MessagingService.php`
  (the legacy SmsGateway copy was deleted 2026-10-12) but has **no caller** — clients
  get no quote text today. Any text must follow the SMS rules (≤160, no URL, "check your email",
  (778) 846-9273).
- First real view of the link pushes Tim once (`QuoteViewNotifier::notifyFirstView()`); views
  from a logged-in CRM session and within 5 minutes of sending don't count; status stays `sent`
  (the accept form shows only for `sent`) — Sam reads `viewed_at`.
- Document numbers: `QUO-YYYY-NNNN`. Test quotes carry `ZZTEST` and are left out of Sam's numbers.
