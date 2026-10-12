# Traps already hit — read before any change to quotes or follow-ups

Add a new entry the same day a mistake is found: date, what happened, the signature, the rule.

## 2026-10-10 — A client's "yes, but not that line" was invisible
Monica (Macdonald PM) emailed "council approved, but not the sprinkler line" on QUO-2026-0073.
She was not a contact, so office@ skipped the mail, and the office would have had to rebuild the
quote by hand. Fixed by `SalesInboxService::joinUnknown()` (1314) and client line untick (1315).
**Rule:** a reply from someone not on file can still be about a quote; a partial yes is stated
line by line, never by deleting lines.

## 2026-10-10 — Domain join picked a placeholder contact
The company-domain rule joined Monica's email to the only @macdonaldpm.com contact — "Valued
Customer", a placeholder with no quotes. Now joins by domain only onto a contact who has a quote;
PMs join through `contacts.employer_company_id = properties.property_manager_id`.
**Rule:** never join mail to a contact without a quote; never on free mail domains.

## 2026-10-10 — Unscheduled tray was always empty
`getUnscheduledVisits()` selected a column that does not exist (`jp.start_date`); its
`catch → return []` showed "nothing to do" for everyone. Found only because QUO-0073's tray job
didn't appear. **Rule:** after an approval, check the job is actually in the tray; run any
widget SQL once on prod after touching it.

## 2026-10-10 — Giving a PM contact an email can redirect invoices
Macdonald's billing contact was Monica with no email; invoices reached the company billing
address only through that fallback. Adding her email would have sent every invoice to her.
**Rule:** before adding an email to a management-company contact, check the company's
`invoice_routing_method`.

## 2026-10-10 — Sales files on prod were ahead of the repo
`SalesDeskService`, `SalesInboxService`, `EmailLeadService`, `TripLineSuggester` were ahead on
prod; hand-merges were needed. InboundRouteService is still referenced by
`app/Modules/Sales/Api/sales-head-mobile.php` but absent from this repo. **Rule:** `cmp` against
the live copy before deploying any Sales file; pull live dependencies into the repo.

## 2026-10-08 — Quote builder init died silently
On `public/crm/quotes/create.php` the init called `hydrateFeatherIcons` before its script loaded:
totals read $0.00, the hidden `line_items` stayed empty until the first edit, the property was not
pre-selected and the submit listener was never attached — but the rows rendered, so it looked
fine. **Check:** on a fresh load the hidden line items are non-empty and the subtotal non-zero.

## 2026-10-08 — Signing page fatal after the signature committed
`public/customer/quote.php` declared `formatCurrency()` unguarded; loading the CRM helpers there
for snow set-up was a "Cannot redeclare" fatal after the signature was saved. Guarded.
**Rule:** a PHP fatal on prod is a truncated page with HTTP 200 — verify the page reaches `</html>`.

## 2026-10-08 — The Closer's depot geocode fails
The CRM's Google Maps key is browser-only, so `CloserService::depot()` fails silently
("Depot geocode failed"). Drive minutes need a depot pin (rate card) or a server key in secrets.

## 2026-10-07 — "Quote opened" pushes from Tim's own clicks
Tim checking his own link counted as the client's view. Now staff sessions don't count and a
view within 5 minutes of sending doesn't spend the push (`QuoteViewNotifier::decide()`).

## 2026-10-06 — Quotes went to the accountant
`QuoteService::getWithContact()` overwrote the quote's contact with the company's primary
contact, so Vancouver Management quotes went to Jodi Peacock (the accountant). Fixed with
"Send to" / "Quotes go to" (1186). **Rule:** on PM-managed buildings check "Send to" before
sending; quotes never go to a billing-only contact.

## 2026-10-06 — Terms said "All prices include GST"
Every quote added 5% on top while the default terms said GST was included. Fixed by
`database/migrations/1187_gst_added_terms.sql`. **Rule:** GST is always added, never included —
in prices, terms, templates and Sam's wording.

## 2026-10-05 — Automatic nudges beside Sam
Nine automatic quote follow-up rules were sending on their own. Disabled at Tim's request; Sam
(propose-only) is the only follow-up path. **Rule:** do not re-enable them or add another
automatic sender.

## 2026-10-05 — Quote SMS carried a bare domain, then was found wired to nothing
`sendQuoteNotificationSms()` put the site's domain in every text (carriers drop those). Its text
was fixed, but it has no caller, so no client gets a quote text. The vault names a shared SMS
template file for the fix that is not in this worktree — verify before relying on it.
**Rule:** check SMS text with `SamFollowupService::smsProblems()`; decide hook-in vs delete before
building another quote text.

## 2026-07-31 — Pipeline stats 500 from a migration that never ran
`pipeline.php?action=stats` crashed because migration 1110's columns were not on prod.
**Rule:** Sam's services probe columns (`ready()`, `hasColumn()`) — keep doing that, and check the
live schema before trusting a stage or quote column.

## Open (as of 2026-10-10, check before assuming done)
Migrations 1314/1315, OPcache reset and a 90-day sales inbox backfill were owed after the
2026-10-10 deploy; QUO-0073's job sat unplaced in the tray and Monica's reply was drafted, not
sent. Verify on prod before relying on any of it.

## 2026-10-12 — Fable review
Rules now enforced in code:
- `QuoteService::markSent()` never changes accepted/declined/expired (Resend Link reset them to
  sent); `statusAfterSend()` is the rule.
- Verbal approval refused on accepted (made a second tray job) or declined quotes.
- Client accept/decline (`public/customer/quote.php`) only when sent/viewed and not past
  `valid_until`, never in `?_preview=`, and only with the per-quote `answer_nonce`
  (`QuoteService::acceptNonce()`). Expired quotes show "call us". The legacy
  `public/crm/customer/quote.php` 301s to the real page.
- The client PDF link is `customer/api/quote-pdf.php?token=` (the CRM endpoint sent clients to login).
- `quote-upsell.php`: an add after approval calls `QuoteApprovalService::afterPostAcceptChange()`
  (note back on "Just approved", activity row, push to admins); after approval only post-approval
  extras can be removed; bundle matching is exact, in PHP.
- Quote SMS = `QuoteService::quoteSentSms()` (no link, (778) 846-9273), tested with `smsProblems()`.
Not done: a post-approval extra is not added to the existing job automatically — the office adds it.
