# When a building's contact leaves — spot it from who paid and from the mail (plan, 2026-11-07)

**Why (Tim, 2026-11-07, Mia's thank-you to Erin Hope-Goldsmith for $175.02):** "Erin left the strata, another
person has paid the bills, we should be able to figure this out as it happens with the agents." And: "There are
email communications and payment records that inform this." Property managers and strata reps change often. Today
nothing notices: Mia thanks the old person, Penny's reminders and new invoices keep going to her, and Tim finds out
by reading the draft.

**Status:** plan only, waiting for Tim's OK. Nothing built.

## What the code does today (checked 2026-11-07 on feature/language a3fa164ee)
- `MiaThanksService::create()` picks the thank-you recipient from `resolveReminderRecipients()` — the invoice's
  send snapshot, then property-manager routing, then the invoice's contact. **Who actually paid is never looked at.**
- The payer is recorded but unused here: `etransfer_notifications.sender_name` / the sender's address on the
  Interac email, the bank memo on `transaction_id` rows, Stripe's payer email.
- `property_contacts` has a role (`manager`, `billing`, …) and `is_primary`, but no way to say "was the contact,
  left on <date>".
- The Comms inbox readers have no auto-reply / bounce / "no longer with" handling (grep finds none).

## Signals (both are evidence Tim says already exists)
1. **Payment record — a different payer.** A payment on a property-managed or strata invoice whose payer (e-Transfer
   sender name and address, Stripe email, bank memo name) matches neither the invoice recipient nor anyone linked to
   that property/company. Strong when the payer's address is on the same firm's domain as the old contact.
2. **Email — the old contact is gone.** On office@ / iCloud: an auto-reply or bounce to something we sent her
   ("no longer with", "has left", "mailbox is not monitored", 550 user unknown), or a new person at the same firm or
   strata writing about the same building ("I've taken over from Erin").

One signal makes a question, never a change. Two agreeing signals (new payer + bounce) make it a confident question.

## What happens (proposes, Tim confirms — the "confirmation teaches" rule)
- **Mia holds the thank-you** instead of offering it to Erin: "Paid by <payer>, not Erin. Has the contact changed?"
  Buttons: **Thank <payer> instead** · **Erin is still right** · Skip.
- **One question, owned by Yui** (people and mail) and shown wherever that building appears: "Erin may have left
  <strata>. <payer> paid the last invoice and Erin's mail bounced. Make <payer> the contact?" On **Yes**: <payer>
  becomes the `manager`/`billing` contact for that building (created if new), Erin is marked as having left (kept for
  history, never deleted), future invoices, reminders and thank-yous go to the new person. Sent invoices are not
  re-sent. On **No**: remembered for that payer + building, so it is not asked again.
- "Erin is still right" also teaches: that payer is an accepted second payer for the building.

## Unknowns and how each is settled, cheapest first
1. **What Erin's payment row actually holds** (method, sender name/address, which invoice). Read-only prod lookup
   (`scripts/prod-run.sh`) — blocked this session by the permission check; needs Tim's permission once.
2. **What the "left" emails look like** — search office@ / iCloud mail already stored (inbound tables) for Erin's
   address and for the payer's name. Same read-only lookup.
3. **Where "left" is stored:** add `ended_at DATE NULL` to `property_contacts` (one migration, MySQL 5.7 safe), or
   reuse an existing contact status. Check `contacts` columns on prod first (prod schema differs from repo).
4. **How often payers legitimately differ** (a strata treasurer paying for the manager, a firm's accounts clerk).
   Count, over the last year of payments, how many payers don't match the recipient — sets whether signal 1 alone is
   worth asking, or only with a same-domain or email signal.

## Steps and proof
1. Read-only prod lookups for unknowns 1, 2 and 4; write the counts here.
2. `ContactChangeService` (app/Modules/Comms/Services) — pure matcher: payer vs linked people, and the mail-phrase
   list; unit tests from Erin's real case (names anonymised) and the counts' false-positive examples.
3. Mia: hold + three buttons on the thank-you card (web + iPhone parity, Android later).
4. Yui question card + the Yes/No writes (migration if `ended_at` is chosen); routing honours `ended_at`.
5. Mail signal in the inbox pollers (auto-reply / bounce / takeover phrases) — feeds the same service.
6. phpunit, Codex review, deploy behind `ops_settings.contact_change_enabled`, check on Erin's building live.
