# How Charlie operates

## What he is for (owner, 2026-10-11)
A Chief of Staff selects what hits the owner's desk, in order of importance. Charlie does not do
the heads' jobs; he decides what Tim sees first and keeps the business's own deadlines.

## What a brief line must be (owner, 2026-10-11)
- **A decision, not status**: "Send 28 invoices? Tap", not "28 invoices unsent".
- **Ordered by the cost of waiting**: money and penalty dates first.
- **Three at most**, readable on a phone while Tim is away from the desk.
- **Shown under the head who would act on it**: tax / payroll → Penny; vehicles, equipment,
  safety, licence → Otto; contract renewals → Sam. Charlie signs only cross-head or unowned items.
  "House" lines become Penny's.

**Status: the re-labelling is AGREED IN PRINCIPLE, NOT BUILT.** Today deadline lines carry head
`charlie` (`DeadlineService::item()` keys every one `charlie:deadline:…` whatever its category —
categories are `tax, payroll, safety, licence, insurance, vehicle, equipment, contract, other`),
and Work Queue lines carry head `house`, which `TeamCardService::headKey()` and the Action Board
both file under Charlie. Insurance and other have no agreed owner yet (unverified). Most head lines
are still status sentences ("3 receipts are ready for you to approve"; House lines are the Work
Queue title plus description) — decision wording is also not built.

## What he may do
- Ask every head for its brief, rank it, remember it (`charlie_items`, `charlie_briefs`).
- Show Tim: the one thing, the rest, each head's top 3, bad news first.
- Email the morning brief once, weekdays 07:00–09:59 America/Vancouver
  (`CharlieDeskService::isSendTime()`), to the owner (`ops_settings.charlie_owner_user_id`).
- Email an urgent alert once per alert (`charlie_alerts`): failed autopay ≥
  `charlie_urgent_payment_min` (default $500, last 48 h) or Otto's same-day weather NOT_OK. Email
  only — push is not confirmed working for this.
- Keep the deadlines calendar: Done / Snooze (max 7 days) / Not this year
  (`DeadlineService::mark()`). Overdue stays open until Tim marks it — never rolls forward.
- Hold an inbox proposal under Tim's conflict rules (`collections_first` 14 days late,
  `one_message_week` 7 days); a held proposal is shown with its rule and an Override button; a rule
  overridden twice in 60 days gets a proposed rewrite (`ConflictRules::rewrite()`).
- Answer "Ask Charlie" from CRM data: facts by SQL first (free), then Claude on matched records
  only; daily cap `charlie_ask_daily_cap` (default 30); every ask logged in `charlie_asks`.

## What he may not do
- Pay, file, sign, send to a client or supplier, or change a record. Inbox buttons post from Tim's
  browser to the head's own endpoint, with Tim's CSRF token, so the head's checks run.
- Let item data make him post anywhere new (`CharlieInboxService::safeActions()`).
- Call a head's writing or AI-spending methods while building a brief.
- Learn from anyone but the owner.
- Restore the once-a-day opening brief: `MorningBriefStore.oncePerDay = false` in
  `ios/MowologyCRM/MowologyCRM/Features/Splash/CharlieMorningBrief.swift` stays off **until Tim
  says** (2026-10-11).

## How he learns (`CharlieDeskService`, `CharlieRankService`)
| Tim does | Effect |
|---|---|
| Opens an item, or it vanishes from its head's brief (dealt with) | its kind beats every other kind still waiting from that morning (K 0.08) |
| "Not now" / "Not today" (dismiss, web) | every waiting kind beats it (K 0.12); 3 of a kind in 30 days → Charlie asks whether to leave that kind out |
| "Later" (snooze) | hidden until tomorrow; teaches nothing |
| Answers "which first?" on a close call (runner-up ≥ 90% of leader) | direct lesson (K 0.25) |
| Item drops off a head that sent only its top few | closed without learning |
| A head fails | resolves nothing |

Weights stay between 0.25 and 4. A customer's clear yes always ranks first (×5).
Badges come only from what Tim did (`CharlieBadgeService`), and streaks are lost again.
