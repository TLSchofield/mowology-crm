# Sam — the sales head

Sam's job: more quotes accepted, no lead forgotten. He sits in the department heads deck on
the dashboard (`public/crm/includes/sam-card.php`), under Penny. **Sam never sends anything
himself** — every follow-up is Tim's click.

![Sam's card (stub render, made-up customers)](sam-render.jpg)

## What he shows

| Part | Source |
|------|--------|
| Numbers: $ waiting, win rate, days to a yes, won after a follow-up | `SalesDeskService::stats()` |
| Follow-up carousel — one card per **customer** (a property manager with ten quotes gets one note) | `SalesDeskService::queue()` → `groupStale()` |
| Suggested email + text for each card | `SamFollowupService::draft()` |
| New leads, best first, each with "what to do next" | `SalesDeskService::leads()` → `rankLeads()` |
| Questions for Tim (quotes that ran out) | `SamQuestionService` |
| Badges, brain | `SamBadgeService`, `SamBrainService` → shared `app/Services/HeadBrain.php` + `public/crm/js/head-brain.js` |

API: `/crm/api/sales-head.php` (shim → `app/Modules/Sales/Api/sales-head.php`), `?mode=desk`,
POST `send` / `park` / `draft_reply` / `answer` / `lead_dismiss`. Permission `billing.edit`.

## When is a quote "waiting"?

- Status `sent` or `viewed`, still inside `valid_until`, not a test record (`ZZTEST`).
- Grouped by customer (`COALESCE(quotes.contact_id, properties.site_contact_id)`).
- Due when the **last touch** — quote sent, follow-up (`quotes.follow_up_sent_at`), an
  office@ email to them, a send through Sam — is older than the wait: the median days to
  accept for that service once 5+ quotes were accepted (clamped 3–14), else the
  `ops_settings` `sam_stale_days` (default 5).
- **The customer wrote back after our last email → "Waiting on you"**, shown first, even
  inside the wait and even after 3 follow-ups.
- At most 3 follow-ups. Snooze / "Not now" hides the customer for 7 days.
- Past `valid_until` → a question instead: lost (→ status `expired`), keep (+30 days), or
  won another way (opens the quote to record the yes).

Dollars: `COALESCE(NULLIF(total_amount, 0), amount)` — writers fill one or the other.

## Drafts, and how Sam learns

- Templates per situation: `first_nudge`, `second_nudge`, `viewed`, `last_call`, `multi`,
  `reply` — written with the copywriting skill and the voice card in
  `.agents/product-marketing-context.md` (no exclamation marks, never "just checking in",
  one easy ask, snow quotes say why now once).
- When Tim edits and sends, his version is stored with the customer's details put back as
  `{placeholders}` (`sam_followups.learned_body`) and becomes Sam's draft for that situation
  next time ("written your way").
- A follow-up "won" if one of its quotes was accepted within 21 days — the scorecard behind
  "Won after a follow-up", the Revived/Closer badges and the brain.
- **Draft a reply** (only when the customer wrote back): Claude, on Tim's click only, max 20
  a day, with `app/Services/Copy/mowology-copy-rules.md` as the system prompt plus the
  quotes, the thread and Tim's last three sent emails. Tokens recorded on the row.

## Sending

`sendEmail()` / `sendSms()` only (CLAUDE.md rule 9). The server re-reads the card, so the
address and quotes come from the CRM, not the browser. Email: Tim's text + a link to each
quote (customer portal token), wrapped by `EmailWrapper`. Text: only with SMS consent, and
refused unless ≤160 characters, no links/domains, no special characters (rule 11 — checked
in the browser and again on the server). Each send updates `quotes.follow_up_sent_at/count`,
the activity log, `sam_followups` and `sales_messages`.

The nine automatic quote follow-up `automation_rules` (ids 2, 6, 10, 14, 18, 22, 26, 30, 34)
were switched off on 2026-10-05 at Tim's request — Sam is the only follow-up path. Seven of
them were duplicates of the same rule, each had fired 132 times.

## Conversation history (office@, read-only)

`app/Modules/Sales/Cron/sales_inbox_poll.php` (every 15 min, registry key
`sales_inbox_poll`) reads office@ INBOX + Sent with `OP_READONLY` / `FT_PEEK` — it never
marks, moves or deletes mail. Only mail from or to a known contact email is kept, and only
the new text (quoted history stripped, ≤800 chars) in `sales_messages`. First run backfills
90 days. Tim's personal mail is not read. Texts customers send to Tim's phone are not seen.

## Setup

1. Migration `database/migrations/1140_sales_head.sql` (Database → Migrations). Until it
   runs the card renders nothing.
2. cPanel cron: `0,15,30,45 * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Sales/Cron/sales_inbox_poll.php`
3. OPcache reset after deploying.

## Tests

`tests/Unit/Sales/*` and `tests/Unit/Core/HeadBrainTest.php`.
