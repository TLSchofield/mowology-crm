# How Sam operates

## He proposes; Tim sends
- Never sends to a client on his own. Every follow-up card carries a draft that Tim sends,
  edits, skips or snoozes (`SamFollowupService::park()`, 7 days). Sending anything to a client
  or supplier needs Tim's yes.
- The nine automatic quote follow-up `automation_rules` (ids 2,6,10,14,18,22,26,30,34) were
  disabled at Tim's request (2026-10-05). Sam is the only follow-up path. Do not re-enable them:
  `app/Modules/Marketing/Cron/automation_runner.php` still writes `quotes.follow_up_sent_at`.
- Claude drafts a reply only on Tim's tap, capped at 20 a day
  (`SamFollowupService::draftReply()`); every Claude draft is labelled and Tim checks it. House
  writing rules: `app/Services/Copy/mowology-copy-rules.md`.
- Pushes to Tim's iPhone are information only ("X just opened QUO-…", "Just approved"). They
  never change a quote's status.

## What counts as "waiting" (`SalesDeskService`)
- A quote sent or viewed, not accepted, still inside its valid-until date.
- Grouped by customer: a property manager with ten open quotes gets one note, not ten.
- Due once the last touch (sent, follow-up, office email) is older than the wait for that
  service — learned from how long accepted quotes took (median, 5+ quotes), else
  `sam_stale_days` (default 5).
- The customer wrote back after our last email → "they replied, you haven't" comes first.
- A clear "yes" always ranks first on Charlie's board.

## Every approval is stated line by line
- `QuoteApprovalService::summary()` lists every line ✓ / ✗ with its amount, the approved total
  next to the quoted total, and says "All N lines approved" when nothing was left out. The same
  summary feeds the push, the "Just approved" list (web + iPhone) and the job description.
- Approved non-contract quote → `createPlanFromQuote()` with only the approved lines, then the
  visit is taken off the calendar (`stop_id = NULL`) so it waits in the Unscheduled tray.
- Contract quote → no tray job; set up from the contract. Snow contracts set themselves up on
  signing (`SnowContractService::setupFromSignedQuote()`).
- Once per quote: the `quote_approvals` row is claimed first, so the hook and the nightly sweep
  never both make a job. `quote_auto_job_since` keeps old approvals out of the tray.

## He learns only from Tim's real decisions
| Lesson | Taught by | Where |
|---|---|---|
| Follow-up wording per situation | Tim edits a draft and sends it → his version (with `{placeholders}`) becomes the draft | `SamFollowupService` |
| Wait per service | How long accepted quotes took (5+ quotes) | `SalesDeskService::learnedWaits()` |
| Expired quote outcome | Tim answers lost / keep / won | `SamQuestionService::answer()` |
| Badges | Unchanged sends, follow-ups that led to a yes | `SamBadgeService::badges()` |
| Closer site minutes | Tim's timed + measured visits, weekly fit | `CloserService::recalibrate()` |

## The Closer only shows
- `CloserService::forQuote()` shows his price beside the quote's own price. He never writes a
  price and never changes a rate. Tim edits the rate card (`CloserSettingsService::save()`,
  admin only). Satellite or drawn measurements are drafts — "confirmed after a site visit".

## Mail is read, never touched
- office@ is opened read-only (no mark, move or delete); only mail from or to a known contact is
  kept, ~800 characters of new text. Tim's iCloud is read by `IcloudInboxRouter`; the rest of his
  personal mail is never stored. Texts arrive from the Mac bridge as salted hashes — the raw
  number is never stored (`TextBridgeService`).
- Joining mail from someone not on file to a quote is rules only, no AI: quote number in subject
  → property address in subject → company domain held by exactly one contact with a quote (never
  free mail) → a known manager of the building (`SalesInboxService::joinUnknown()`).
