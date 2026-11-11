# Plan: invoicing and getting paid, from Fable's review of 2026-10-13

Status: **Tim said yes to writing this plan (2026-10-13). Each fix is built and checked before the next.** Owners: Sam (pipeline, every client "yes") and Penny.

The review was read-only: no Stripe calls and no mail. The live data was clean: no duplicate charges, no invoice number used twice, and no paid invoice with a balance.

## Fixes, in order of the money they can cost a client

1. **The customer's "pay this invoice" page can charge twice.**
   - `public/customer/api/invoice-payment-intent.php:202-207` forgets the stored PaymentIntent on *any* Stripe error, including a network blip, and then creates a new one, skipping the succeeded/processing guard.
   - The office twin, `public/crm/api/stripe/create-payment-intent.php:133`, only forgets on `resource_missing`.
   - Fix: the same rule on the customer page. Any other error returns "try again in a minute", never a new intent.
   - Test: an injected Stripe client that throws a rate-limit error must not create a second intent.

2. **Autopay retry after a timeout can charge again.**
   - `AutopayService.php:243-258` retries an `api_error` with a new idempotency key.
   - Fix: before a retry, look up (read-only) a PaymentIntent with `metadata.invoice_id` in `succeeded/processing`; if one exists, reconcile it instead of charging. Or retry with the same key.
   - Production has had 3 failed attempts and 0 double charges.

3. **The webhook can count a part payment twice.**
   - The idempotency read (`webhook.php:176-185`) sits outside the transaction, with a network call between it and the row lock.
   - Fix: re-select the `stripe_payments` row `FOR UPDATE` inside the transaction, and stop if it is already `succeeded`.

4. **A card payment on an invoice already paid another way goes unnoticed.**
   - `webhook.php:296-301` only logs it.
   - Fix: write a `stripe_overpayment` activity, which becomes a Charlie alert with a refund suggestion. When an invoice is paid by cheque or e-Transfer, cancel its open PaymentIntent.
   - Today 6 such invoices still hold an open intent (INV-0175, 0144, 0210, 0091, 0101, 0102). They are listed for Tim; Penny never cancels or refunds on her own.

5. **Prepaid credit.** The portal says "Applied automatically to future invoices" (`public/customer/portal.php:466`), but nothing applies it, so autopay charges the full amount.
   - Tim's choice: apply credit automatically in the send path before autopay (the plan's default, with the line "credit applied: $X" on the invoice), or change the wording.
   - `ClientCreditService::applyToInvoice` also gets a row lock and an allocation row, which fixes the double-click double-apply.

6. **The desktop invoice page skips the contract check and the visit lock** (`public/crm/invoices/create.php`).
   - INV-0243 was correct (Tim: a mulch add-on), so this is a **warning**, not a block: "This client is on contract CTR-… — is this extra work?" The visit link gets the same `invoice_id IS NULL` lock the service uses.

7. **Low:**
   - Receipt and refund emails go to the billed recipient (the PM firm), not the building's primary contact.
   - The company card is no longer copied onto a contact.
   - Delete the unused `InvoiceService::ensurePaymentIntent()`.
   - Make the Stripe columns' collation match (prod is MySQL 8.0.37; `invoices.stripe_*` use `utf8mb4_0900_ai_ci`).
   - Lock invoice numbering.

## Unknowns, and how each is settled
| Unknown | How it's settled |
|---|---|
| Stripe error classes the SDK throws for a timeout or rate limit | Read the installed `vendor/stripe` source (one file) before writing item 1. |
| Whether autopay's lookup can use Stripe search (`metadata['invoice_id']`) on this account | One read-only call against test mode on the local site (fake keys can't, so a recorded fixture), then live read-only with Tim's OK. |
| Tim's choice on item 5 | Ask. Nothing changes until he answers. |
| MySQL version: CLAUDE.md says 5.7, prod is 8.0.37 | Keep 5.7-safe SQL; fix CLAUDE.md's statement after Tim confirms the host's version. |

## Steps and proof
For each item: a test that fails first, then `phpunit`, a render on the local site (the pay page with fake keys), `deploy-merged`, a server fingerprint (not a downloaded copy), OPcache, and a production check. No live charge is ever made to test anything.
