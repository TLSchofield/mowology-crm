# Sam: from "can we book you in for a fall cleanup?" to a draft quote ready for your yes (plan, 2026-11-05)

First step of the head learning engine (`2026-11-05-head-learning-engine-review-verdict.md`):
Recognise → Remember → Prepare → Approve → Learn. No AI anywhere in this round.

## What it does

When a known client emails asking for work, Sam's card shows:

> **Meghan wants a fall cleanup** at [property]. Last done Nov 2025: "Fall Clean Up", $X (Jobber invoice). **Sure**
> because: known client · one property · "fall cleanup" · done before. [Make draft quote] [Not a request] [Wrong service]

**Make draft quote** creates a *draft* (never sent) prefilled with last time's lines and price, each marked "from Nov 2025".
You edit and send it as usual. What you changed (service, lines, price, property) is recorded, field by field.

## Unknowns, and how each was settled (2026-11-05, read-only on production)

| Unknown | Answer | Source |
|---|---|---|
| Is the email recognised as a request for work, and sent to Sam? | Yes, rules already do this | `InboundTopicClassifier::topic()` returned "sales: book you guys in" on her wording |
| How is a service named? | Free text everywhere. `CloserPricing::serviceKey()` already maps text to keys (`cleanup` ← "fall clean", "leaf", "clean up"…) | `quotes.service_type`, `quote_line_items.service_type`; `CloserPricing.php:45` |
| Email → client | `SalesInboxService::contactMap()` / `classifyAny()` (exact email, then quote number, address, domain) | `SalesInboxService.php:68, 398` |
| Client → property | `properties.site_contact_id`, `property_contacts`, `company_properties`, `properties.client_id`; `ContactService::getManagedProperties()` | migrations 001, 1048, 1122 |
| Where last fall's work is | **Not in CRM quotes** (one "fall clean up" line, at $0). It's in the Jobber import: `jobber_invoices.line_items` as text, e.g. "Spring Clean Up (1, $950.00)". Fall 2025: 45 cleanup invoices, 16 matched properties, 6 unmatched | production query |
| How a draft is made | `QuoteService::create($data, $lines, $userId)` always creates status `draft`; the recipient comes from `QuoteRecipientService` | `QuoteService.php:249, 278` |
| Pattern for the proposal table | `sam_acceptances` (unique `message_key`, status open / accepted / dismissed, `decided_by`, `decided_at`) | migration 1366 |
| Permission to act | `AgentPolicy`: add `sam.prepare_quote` at level 2 (prepare, you approve). Nothing is ever sent | `AgentPolicy.php` |

**Still unknown: how many requests like this arrive.** Step 1 settles it by replaying the last 90 days of Sam's
inbound mail through the rules before anything is shown to you.

## Rules for the level (no invented percentage)

- **Sure:** known client, exactly one property, one service named, the same service done before at that property, no negation.
- **Likely:** known client, one service, but either several properties or no history for that service.
- **Not sure:** unknown sender, no service word, two or more services, or negation ("don't need", "cancel",
  "not this year", "no longer"). Sam lists what he saw and makes no draft. The "Ask Sam" AI button comes in a later round.

Each proposal stores its evidence (what matched) and where each field came from (rule, Jobber invoice, CRM quote),
so the card can say *why*.

## Steps

1. **Replay first (read-only).** A pure `SamRequestRules` (intent + service key + negation + level) runs over the last
   90 days of inbound Sam mail on production. Output: how many requests, levels, how many have client, property and
   history. **You see these numbers before step 2.**
2. **Migration + service.**
   - Table `sam_requests`: `message_key` unique, contact, property, `service_key`, `level`, `evidence_json`,
     `precedent_json` (source, date, lines, price), `draft_quote_id`, status open / drafted / dismissed,
     `dismiss_reason`, `outcome_json`, `decided_by` / `decided_at`.
   - `SamRequestService`: `propose()`, `makeDraft()`, `dismiss()`, `recordOutcome()`.
   - Jobber line parser for "Name (qty, $price)" (pure, tested).
   - Unit tests for each rule, including negation and several properties.
3. **Hook.** After `SalesInboxService` routes a message to Sam, call `propose()` (fails soft and never blocks
   ingest). Proposals show on Sam's web card in the existing "to quote" lane (`UnclaimedReplyService` HEAD_LANE
   sam). **Make draft quote** calls `QuoteService::create()` with precedent lines and opens the draft.
4. **Learn.** When the draft is sent, compare it with the proposal: service, each line, price, property. Each change
   becomes a `HeadLessons` row (head `sam`, field-level). The proposal's `outcome_json` keeps accepted vs changed,
   so acceptances are counted too.
   - **Not a request** → negation / intent lesson.
   - **Wrong service** → phrase lesson.

   Lessons are recorded only. They start steering suggestions in round 2 (the word pool).
5. **Measure.** Add Sam rows to Agent Studio › Progress:
   - requests recognised;
   - drafts made;
   - drafts sent unchanged per field;
   - **coverage**: share of request-for-work emails that became a draft.
6. **Build, review, ship.** `scripts/codex-web-build.sh` builds from this plan, Claude reviews, then
   `scripts/codex-review.sh` (client-facing quotes). Deploy by `deploy-safe.sh` after prod-pull, run the migration,
   then check live with a real or test email from a known client: card shows, draft made, nothing sent, and a
   duplicate email makes no second proposal.

## Not in this round

The AI "Ask Sam" button; the word pool as data and its learning; the shared per-trade pool; the iPhone card (web
first, iPhone parity owed and noted in the `sam-sales` surfaces file); automatic sending at any level.

## Honest notes

- History is thin: about 16 properties have a fall cleanup on record. Other clients get **Likely** and a draft
  without a price.
- The Jobber price is from 2025 and shows as a precedent with its date, never as today's price.
- `CloserPricing::serviceKey()`'s word list is landscaping-only. Round 2 moves it into data per service (the word pool).
