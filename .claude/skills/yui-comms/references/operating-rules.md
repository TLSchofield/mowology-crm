# How Yui operates

## Her lane
- **Yui (Comms):** every one-to-one conversation with existing clients that is not about a
  quote, promises ("council approved it") with no accepted quote, account gaps (PM firms with
  nobody to write to), renewals and check-ins, arrears conversations (Penny hands over at 60+
  days overdue). Five sections in `YuiDeskService::desk()`.
- **Not hers:** quote replies and any clear "yes" to work are Sam's (`UnclaimedReplyService::lane()`
  → 'quote'); billing mail is Penny's, schedule/site mail Otto's, reviews/newsletter Mia's
  (inbound routing, see traps for where that code lives).
- A reply is in exactly one lane, so it is never shown twice. "Handled" under either prefix
  (`sam:reply:` / `yui:reply:`) hides it.

## She drafts; Tim sends
- Nothing sends without Tim's click on her card (web `public/crm/includes/yui-card.php` or the
  `send` mode of `app/Modules/Comms/Api/yui.php`). The iPhone card sends nothing — it opens the client.
- Inbox replies: Claude drafts on Tim's click only (`YuiDraftService::draftReply()`), capped at
  `CLAUDE_DAILY_CAP` (20) a day, thread from `sales_messages` as context.
- Renewals, check-ins, arrears: templates in Tim's voice; when Tim edits and sends one, his
  version becomes the next draft (`YuiRules::learnFrom()`, email only, never for replies).
- Every send is logged to `sales_messages` (so the reply counts as answered) and `yui_actions`
  (sent unchanged vs edited — her badges, `YuiBadgeService::compute()`).
- Permission: billing.edit (same as Sam). The card renders nothing before migration 1191.

## Texts
- Checked before sending by `YuiRules::smsProblems()`: ≤160 characters, no links or domains,
  no odd characters, contains (778) 846-9273, points them to their email. The web card runs
  the same check in `public/crm/js/yui-card.js` — change both together.
- Only to a contact with consent (`hasSmConsent()`), only through `sendSms()`.
- `sendSms()` truncates anything over 160 to 157 + "..." — that can cut off the phone number.
  Never rely on truncation; check first.

## Email
- Through `sendEmail()` (or `sendCrmEmail()`); never PHPMailer or native mail() directly.
- Client replies can continue in the thread they started; supplier and subscription mail is
  from/to office@ only.

## She learns only from Tim
| Lesson | Taught by |
|---|---|
| Her template wording | Tim editing and sending a template draft |
| Which head owns a sender + topic | Tim's "Move to…" (learned per address, then business domain) |
| Clues (strata plans, roles, contact details) | suggestions only — Tim applies or rejects (`clue_suggestions`) |
| Customer ↔ text thread | the bridge's salted hash match against CRM phone numbers (`TextBridgeService::numbers()`) |

## Pushes
- Yui sends no push today (no `PushDispatcher` call under `app/Modules/Comms/`). If one is added,
  set `data['head'] = 'yui'` so `PushHeads::brand()` titles it "Yui [Comms]", queue with
  `PushDispatcher::notifyUser()`, never send inline. iOS uses `ApnsService::send()`; Android
  tokens go to `FcmService::send()` (live status unverified — `FcmService::isConfigured()` needs
  Firebase secrets).
