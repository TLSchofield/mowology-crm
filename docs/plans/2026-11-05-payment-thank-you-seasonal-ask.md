# Plan: a payment thank-you that Mia turns into a seasonal ask

Status: **plan only, written 2026-11-05. No code until Tim says go.** Owners: Penny (the payment) → Mia (the email).
Skills used: `penny-bc-bookkeeping`, `mia-marketing`, `copywriting`.

## Why
Tim, 2026-11-05, on Alison Birdsall's $232.84 e-Transfer that cleared her account: "Penny should hand the thank-you
email to Mia because it's a marketing opportunity. Mia should check if the property has ever had a fall cleanup around
this time (maybe a bigger bill in Jobber records). Now would be a great time to ask if there is nothing scheduled.
Can we automate that?"

## What exists today (read 2026-11-05)
- **No payment thank-you is sent.** The only one is `EtransferNudgeService`: a one-time email, per payer ever, only
  for transfers that had to be *claimed*, asking them to switch to the auto-deposit address. Alison's was
  auto-deposited, so she got nothing.
- **Mia already has a "seasonal" rule:** `MiaFinder::seasonal()` finds a contact who had a service around this date
  last year and nothing of that service booked since. But it reads only CRM visits (`job_visits` / `job_plans`), and
  `MiaFinder::current()` **leaves out anyone with an active plan**. Alison has an active lawn-care plan, so she is
  never offered a fall cleanup, though a lawn customer is the likeliest to want one.
- **Jobber history is kept:** `jobber_invoices` (migration 1239) has the property, issue date, subject, line items
  and total for every Jobber-era invoice (before 2026-02-25).
- **Consent:** Alison paid an invoice, so `ConsentLedgerService` gives implied consent for two years. An email that
  carries an offer is a marketing email under CASL, so it needs `MiaWording::compose()` (sender, address,
  unsubscribe link) and the check at send time.

## Scope for the first build (Tim, 2026-11-05)
**Fall cleanup only**, "until we streamline services and how the system reads them". Mia looks for a fall cleanup
(by name in CRM visits and Jobber invoices, or the 2× bill clue in Oct–Dec) and asks only about that. The generic
"any seasonal service" matching below waits for the services clean-up (backlog). The code keeps the service as a
parameter so widening it later is a list change, not a rewrite.

## How it works
1. **Penny records a payment** (e-Transfer, cheque, card, any path that clears an invoice). She hands Mia one event:
   contact, property, amount, invoices paid, and "account now clear" or "balance left".
2. **Mia looks at the property's history** (new `MiaSeasonalHistory`, one place, used by the thank-you and by
   `MiaFinder::seasonal()` so both see the same history):
   - CRM visits of a one-off service in the window (today −14 days to +30 days, in any of the last 3 years);
   - Jobber invoices for that property in the same window whose subject or line items name the service; and,
     as a weaker clue, a Jobber invoice in the window at least 2× that property's usual invoice ("probably a cleanup").
   The service is matched by the business's own service names, not a hard-coded word, so another trade's
   seasonal job works the same way.
3. **Mia checks nothing is already in hand:** no visit of that service booked from today on, no open quote or quote
   request (Sam owns those), not muted, not asked about it in the last 60 days, consent allows marketing email.
4. **Mia drafts the thank-you** in Tim's voice. With history and nothing booked, it carries one short ask.
   Without, it's a plain thank-you (no offer; still checked by `MiaCampaignService::problems()`).
5. **Tim approves it with one tap** on Mia's card (web and iPhone), as every Mia send is today (rule 1:
   Mia drafts, Tim approves). It shows why: "Fall cleanup on 12 Nov 2024 (Jobber INV 1873, $412). Nothing booked."
   He can edit the text, send it, send the plain thank-you instead, or skip.
6. **A reply saying yes goes to Sam** (the existing reply routing), who books it.
7. **Every outcome teaches Mia:** sent as drafted, edited, sent plain or skipped is recorded (`HeadLessons`), and
   whether the ask led to a booked visit within 30 days (`MiaFinder::outcome()`). That record is what could
   later justify sending these without a tap, per service, and only if Tim says so (his rule of 2026-11-05:
   confirmation teaches and builds trust).

## Why the thank-you, and what keeps it working (Tim, 2026-11-05: "the email is more likely to be opened if they've just paid")
The thank-you is the carrier because it is expected and wanted: it answers "did my payment arrive?", so it gets read,
and it lands while goodwill is highest (account just cleared). Three things protect that:
1. **Speed.** Its value is being expected, so it must arrive the same day. Mia drafts it the moment Penny records the
   payment and Tim gets a push ("Thank-you for Alison ready, with a fall cleanup ask"). If Tim hasn't tapped by the
   end of the day, the ask is dropped and the plain thank-you waits for him, so a late tap never sends a stale offer.
2. **The subject stays a thank-you.** "Thank you, Alison". Never the offer in the subject: a subject that reads as a
   promotion loses the reason it gets opened, and makes it a marketing email in the inbox too.
3. **The plain thank-you is transactional.** Confirming a payment is a transaction message under CASL, so it can go to
   anyone, including people who unsubscribed from marketing. Only the version with an ask needs marketing consent and
   the unsubscribe footer; for someone without consent, Mia drafts the plain one.

**Measuring it, honestly.** Open tracking exists (`TrackingLinks`), but Apple Mail (common among clients; share not measured)
which "opens" every email automatically (`SendTimeService::isProxyOpen()` already filters these), so opens are not
the measure. The measures are **replies** and **a visit of that service booked within 30 days**, compared with
Mia's ordinary seasonal emails (`MiaFinder::outcome()`). Shown on Mia's card as "Thank-you asks: 6 sent, 3 booked".

**Other carriers considered:** the invoice email itself (opened too, but it asks for money, a worse moment to ask
for more); a crew note from the visit ("leaves heavy on the lawn", a photo) is strong extra evidence and is a later
addition: Mia can quote it in the ask when the crew logged one.

## Draft copy (Tim's voice; copywriting skill; checked against Mia's copy rules)
With history, nothing booked:

> **Subject:** Thank you, Alison
>
> Hi Alison,
>
> Thank you for your payment of $232.84. It's received, and your account is up to date.
>
> Last year we did a fall cleanup at Larch Street in mid-November. Most of the leaves are down now, so it's a good
> time to do it again. Would you like me to put it on the schedule? Just reply to this email.
>
> Tim
> Mowology · (778) 846-9273
>
> *[sender name, postal address and unsubscribe link added by `MiaWording::compose()`]*

Plain, no history:

> **Subject:** Thank you, Alison
>
> Hi Alison,
>
> Thank you for your payment of $232.84. It's received, and your account is up to date.
>
> Tim

Why it's written this way: the thank-you comes first and stands on its own, so the ask reads as a reminder, not a
pitch. The ask is specific (what we did, when, where) because a true fact from her own history is the strongest proof
we have. The reason to act now is real (the leaves are down), and the reply is one step. No price, no discount, no
exclamation marks. Street name only, never the house number in a subject line. Alternatives for Tim to choose from:
- "Would you like the same again this year?" (shorter, assumes she remembers it)
- "We have a few spots left before the end of the month." **Only if it's true that day** (from the schedule);
  otherwise it's fake scarcity and is not used.

## Unknowns and how each is settled (cheapest first)
| Unknown | How |
|---|---|
| How Jobber invoices name a fall cleanup, and how `line_items` is stored | Read-only `scripts/prod-run.sh`: Oct–Dec `jobber_invoices` subjects and line items, counted by wording. |
| Does the "2× usual bill" clue find cleanups or noise? | Same prod-run: list Oct–Dec invoices at 2× the property's median, with their subjects; Tim eyeballs 10. |
| How many thank-yous a month, and how many would carry an ask? | Same prod-run over the last 60 days of payments: count, and how many fit steps 2 and 3. |
| Does Alison's case come out right? | Prod-run preview for her property: the history line Mia would show, and the draft. |
| A claimed transfer already gets Penny's auto-deposit email | Decision below; tested both ways in unit tests. |
| Property managers and stratas | The existing rule: their properties go to the PM bucket, never a homeowner thank-you ask. Thank-you only, plain. |

## Steps, each proven before the next
1. **Read-only checks** above. Stop if Jobber records can't tell a cleanup from regular visits.
2. **`MiaSeasonalHistory`** (pure parts unit tested: window across the new year, keyword match from service names,
   the 2× clue, CRM and Jobber merged); `MiaFinder::seasonal()` switched to it. Registered in `tests/bootstrap.php`.
   Proof: phpunit, then a prod-run comparing the old and new seasonal list.
3. **The hand-off and the draft:** one call from the payment-recording path (`InvoiceReconciliationService::applyAllocation()`,
   the one place payments are recorded) into a Mia suggestion of a new kind `payment_thanks`; wording in `MiaWording`;
   copy checked by `MiaCampaignService::problems()`. Nothing is sent from the payment path itself.
   Proof: unit tests; prod-run preview on Alison's payment shows the right draft and sends nothing.
4. **Mia's card** (web, then iPhone): the draft, the history line, Send / Send plain / Edit / Skip.
   Proof: local render, iPhone design render, then compile.
5. **Codex review, deploy** (`deploy-safe.sh`, OPcache), TestFlight. Proof on production: Alison's draft appears on
   Mia's card with the history line; nothing goes out until Tim taps.
6. **Write-back:** Mia skill (`one-place.md`-style row for `MiaSeasonalHistory`, `surfaces.md`), Penny skill
   (the hand-off), Obsidian, Tim-To-Do.

## Decisions (Tim, 2026-11-05)
1. **Every payment gets a thank-you; the seasonal ask goes even with a balance left, unless they're badly overdue.**
   Tim: "people are lame in this industry but if they are not horribly overdue we need to do the work."
   Rule: no ask when any of their invoices is more than **60 days past due** (a setting,
   `mia_thanks_overdue_days`, default 60; Tim can change it). With a balance left, the first line says what was
   received, not "your account is up to date".
2. **A claimed transfer:** Penny's "please use the auto-deposit address" request is folded into this thank-you,
   one email, not two (agreed).
3. **Wording:** the draft above (agreed).

## Not doing
- No sending without Tim's tap. No texts (marketing is email only). No discounts. No property-manager asks.
