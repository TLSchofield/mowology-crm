# Plan: one payer identity record ("who is this money / message from?")

Status: **Tim said yes to the plan on 2026-10-14. Nothing is built until he approves this file.** Owners: Penny (money), Yui (messages), Sam (recipients). Ada keeps the shared code.

## Why
Every day this week the same question was answered by hand, in a different place each time:
- "Jo Gal" on an Interac alert is **Joseph Gallentine**; the $2 over is a tip.
- "Kanco" in a bank memo pays for **Beedie** buildings; "Darren" pays **Kelly Cannon**'s invoices.
- Alex Wang's payments arrive under a different sender from his billing contact.
- A text from short code 100001 names a sender but not an invoice.

The CRM already learns some of this, but in **four separate places that don't read each other**. Production counts, from a read-only query run 2026-10-14:

| Store | Rows | What it knows | Who reads it |
|---|---|---|---|
| `etransfer_sender_payers` (migration 1132) | 2 | e-Transfer sender → payer | `EtransferInboxService::paysFor`, `BankInvoiceMatchService` |
| `bank_memo_payers` (1284) | 6 | memo text pattern → payer name | `BankInvoiceMatchService::memoPayerRows` only |
| `clue_suggestions` | 2 | strata, role or company hints | `ClueService` |
| contact names and emails (`contacts` 1,517, `client_contacts` 1,512) | — | the billing person | everyone, each with its own matcher |

On top of that, **at least nine functions compare names their own way**: `ShareholderAccountService::isFromShareholder`, `WavePayrollImportService::nameMatches`, `DepositBackfillService::memoPayer`, InvoiceReconciliationService (`bankMemoWords`, `payerMatchesWords`, `namesAgree`, `extractNameWords`), `InboundTopicClassifier::billingSender`, and the InvoiceRouting recipient resolvers. So does about 30 modules' worth of sender/email matching (Yui, Sam, receipts, suppliers, consent, text bridge).

One fact taught in one place (Tim saying "Jo Gal is Joseph Gallentine") is invisible to the others. Also, **17 active contact names occur more than once** (same query), so name-only matching can pick the wrong person.

## What changes
One table and one service. The vendor side already has this shape (`VendorIdentity` in Knowledge); this is its customer twin.

**`payer_aliases`** (tenant-private; it never enters the shared commons):
- `kind`: `bank_memo` | `etransfer_sender` | `email` | `phone` | `sms_name` | `display_name`
- `alias_key`: the normalised form (lowercase, punctuation stripped, emails lowercased, phones in E.164)
- `alias_raw`, `client_id`, `contact_id` (nullable), `pays_for_client_id` (nullable: who this person pays *for*, e.g. a property manager or a spouse)
- `source`: `tim` | `learned` | `import`; `times`, `last_seen`, `confirmed_by`, `created_at`
- Unique on (`kind`, `alias_key`, `client_id`).

**`PayerIdentityService`:**
- `normalise(kind, raw)` is the one normaliser, replacing the nine.
- `resolve(kind, raw)` returns ranked candidates, each with its reason ("Tim confirmed", "learned 4 times", "name match only").
- `learn(kind, raw, clientId, source)` is the only writer. A `tim` row beats any number of `learned` rows.
- `aliasesFor(clientId)` drives a new "Also pays as" list on the client page, where Tim can add or remove an alias.

**Migration:** copy the 2 + 6 existing rows across, keep the old tables read-only for one month, then drop them.

## Other areas this improves (found by the grep above)
1. **Penny's deposit matching.** `BankInvoiceMatchService::suggest` and the auto-link get one lookup instead of three. A confirmed alias plus an exact amount can auto-link; a name-only match stays a suggestion.
2. **Interac alert texts** (bridge 1.1, short code 100001). These carry only the sender's display name. "Jo Gal" resolves the first time Tim confirms it, not every month.
3. **Payment clues from texts** (`PaymentClueService`). A text from a known phone number is tied to the client with no name guessing.
4. **Wave payroll and shareholder detection.** `nameMatches` and `isFromShareholder` use the same aliases (Tim, Katrenna, crew bank names), so an owner draw is never read as wages or as income.
5. **Yui's inbox routing and Sam's lead/reply matching.** A sender email or phone resolves to its client, including people who write from a work address or a spouse's address. Fewer "unknown sender" items in the inbox tidy.
6. **Reminder and invoice recipients.** `pays_for_client_id` records "the property manager pays for this building" as data, rather than recomputing it each time. That is the root of the Jodi and PM-firm misroutes.
7. **Consent.** A consent or unsubscribe given from any alias applies to the person, not just the one address (CASL).
8. **Duplicate contacts.** The 17 repeated names become a merge list: two records sharing a confirmed alias are probably one person.
9. **The iOS and crew apps** call the same JWT lookup, so the caller ID on an incoming call matches the CRM.
10. **Ittai.** The "Switch from Jobber" import seeds aliases from the old system's client emails and phones on day one; the table is per tenant by design.

## Unknowns, and how each is settled (cheapest first)
| Unknown | How it's settled |
|---|---|
| Whether `contacts` already holds alternate emails or phones in other columns | One `information_schema` query before writing the migration |
| Whether name normalisation differs for a reason in each of the nine matchers | Read each one (they're short) and write their current cases as tests first; the new normaliser must pass all of them |
| How Interac shortens names ("Jo Gal") | Use the alerts already stored (mailbox `interac`) as fixtures; no new data is needed |
| Whether auto-link on alias + exact amount is safe | Run it in shadow mode for 2 weeks: log what it *would* link and compare with Tim's choices. It is only turned on if there are zero disagreements. |
| MySQL 5.7 vs 8.0 | Keep 5.7-safe SQL (no JSON, no window functions), as CLAUDE.md says |

## Steps and proof
1. Write tests that pin the nine matchers' current behaviour.
2. Build the migration, the service and a backfill; check them on the local site with copies of the 8 existing rows.
3. Switch the readers one at a time (Penny first, then Yui/Sam, then payroll). Each switch is its own commit with its own test.
4. Add the client page's "Also pays as" list; check it on the local site as admin and as manager.
5. Deploy with `deploy-merged`, check server fingerprints, reset OPcache, then check production read-only.
6. Shadow-mode auto-link for 2 weeks, then ask Tim before turning it on.

Estimated size: about 2 days of work, 9 call sites, one migration.
