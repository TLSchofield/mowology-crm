# Yui harvest — what the inbox, support and outreach skills add to the comms desk (2026-11-03)

Status: **PLAN — waiting for Tim's yes.** Survey (Sonnet, numbers only) of small-business `inbox-manager`,
`outreach-composer`, `speed-to-lead`, `ticket-deflector`, `reactivate`, `review-reputation`, `lead-triage`, `crm-autopilot`,
`shared/untrusted-content.md`, `shared/gmail-inbox-traps.md`, and customer-support `ticket-triage`, `draft-response`,
`customer-escalation`, `kb-article`. Diffed against `YuiRules`, `YuiDeskService`, `YuiDraftService`,
`UnclaimedReplyService`, `InboundTopicClassifier`, `DraftSafety`.

**First, a reconciliation (commit bdc8a5eb2).** Production was ahead of `feature/language` on Yui: the phone and
business name now come from `BusinessIdentity`, the "Offer autopay" card (`YuiAutopayOffer`), model calls go through
`LlmGateway`, sends are recorded by `AgentActivity`, and the card carries a text-inbox panel. Live copies are in the
branch, with their tests (one fixture needed a Mowology identity). Suite 3534 green.

## What the survey found
Voice and slop rules are already built (Mia's). Four sources carry rules Yui lacks:
- **`shared/untrusted-content.md`** — a message that asks to change bank / remit-to / payee, to pay urgently, to wire or
  buy gift cards, to hand over a password or code, to change an authorised contact, or to send client data to a new
  address gets **no draft** and goes straight to the owner, who verifies by the phone number already on file, never the
  one in the message. Lookalike sender domains are checked character by character.
- **`inbox-manager`** — only what the owner must act on is "needs you"; rank by consequence ("what happens if this is
  missed for 24 hours?"); a bare acknowledgement is not needs-you; an unhappy customer gets an acknowledgement draft only
  (no offer, fix or compensation) and sits at the top; never state a price, date or number the owner has not given.
- **`customer-support/draft-response` and `ticket-triage`** — an unanswered question is chased after 2–3 business days;
  absolute dates, not "soon"; replies as short as the question.
- **`outreach-composer`** — never two sends within 48 h; stop on any reply. (Yui sends one note per Tim's click, and
  already rests a sent item for 14 days, so only the 48 h line is new — see "Not built".)

## What the live data says (read-only, `prod-run.sh`, 2026-11-03 15:50)
| Source | Today |
|---|---|
| Yui's desk (222 ms) | inbox 3, promises 9, accounts 11, renewals 5, arrears 10; the brief carries 39 lines |
| The three inbox replies | Joe "Hi Tim," (1 day) · **Nigel texted "I'll be 5 mins… the bin man" (6 days)** · Tony "All right" (7 days) |
| Staff in the client inbox | Nigel is **contact #24 and also user #6** — a crew manager's text sits in the client queue for six days |
| Quotes shown to Tim | "Joe replied "Hi Tim,"" and "Karen: "Hi Tim"" — **the quote is the greeting**, never the message |
| Acknowledgements | 12 bare "ok / all right / thanks" inbound in 30 days; two of three inbox items are this kind or staff |
| Inbound volume | 222 in 30 days (164 texts, 58 emails) |
| Payment-detail asks | **none in 90 days** — except a client's "The password is invoice number: INV-2026-0378" (a PDF password), which a loose pattern would wrongly hold: the rule must fire on *asking for* a credential, not mentioning one |
| Upset replies (90 days) | "That is unacceptable" (#50, Oct 14), "I was not happy with you this summer … hired a new gardener" (#1472, Oct 9), "the guys never came" (#53, Sep 15); and a benign "cancel our recurring cutting, done for the season" (#46, Nov 2) that must **not** read as a complaint |

## The pieces (each a pure class + tests; wired into `YuiDeskService` / `YuiDraftService`; no migration)

### A. Hold for verification — `UntrustedAsk` (shared/untrusted-content)
Scans inbound mail of the last 14 days on any lane, not handled, from a human. Five kinds, each a precise pattern
for *asking* (change of bank / payee / remit-to / payment method; urgent payment, wire or gift card; asking for a
password, login, one-time code or verification link; change of authorised signer or contact; client / payroll /
financial data to a new address). Result: one inbox item per message, `hold`, rank 0 (above everything), text
"HOLD — <name> asks to change payment details: «quote». No draft written. Call the number on file before you do
anything." Plus a **lookalike-domain** note when the sender's domain is within 2 edits of one we hold for that contact
but not equal, or a different domain from every address on file ("not an address we have for them"). The card shows
no Draft / Write button on a held item, only Open and Handled ("I called, it's fine"). `YuiDraftService::draftReply()`
and the send path refuse a held item server-side. Auth codes in cold mail are counted, never quoted (gmail-inbox-traps).

### B. Reply clock — `ReplyClock`
Weekday age of a waiting client reply: under 1 → fresh; 1–2 → waiting (shown "waiting 2 days", priority 2); **3+ →
late, priority 1**, text ends "— they have waited N days". Today: Nigel and Tony would read late, if they were clients.

### C. What needs Tim — acknowledgements and staff
- `YuiRules::ackOnly()`: a reply of ≤ 4 words from a list (ok, k, all right, sounds good, thanks, got it, will do,
  cool…), no "?" and no yes/approval, where **our last message to them was not a question**, is not needs-you. It
  leaves the inbox; the headline says "12 acknowledgements need nothing" (a count, never itemised — inbox-manager).
- **Staff are not clients:** contacts whose email or phone matches an active user are excluded from the client inbox
  and counted ("1 staff text"). Nigel's text is Otto's world, not a waiting client.

### D. Unhappy client — `UpsetSignal`
A client reply with a complaint cue (not happy, unacceptable, disappointed, never came / showed, still waiting,
third time, ridiculous, complain, "hired someone else", "cancel" only *with* a cue) **or** two or more inbound
messages since our last outbound, becomes priority 1 and "Unhappy: …". The Claude draft is told to **acknowledge
only** — "Sorry, that's not right. I'll call you today." — no offer, fix, price or blame, under 60 words; Tim decides
the rest. "Cancel — done for the season" is an ordinary reply.

### E. Honest quotes — `ReplyExcerpt`
The quoted line skips a leading greeting ("Hi Tim,", "Hello,"), signatures and quoted history; first substantive line,
90 characters. Fixed once in `UnclaimedReplyService`, so Sam's replies and Charlie's brief get it too (file equals live).

### F. Draft checks — `DraftChecks` (inbox-manager: never state a price, date or number the owner has not given)
After Claude drafts: a **dollar amount**, a **month-day date** or a **weekday / "tomorrow"** that appears nowhere in
the thread is flagged beside the existing bank-detail flag ("mentions $120 — not in the conversation", "promises
Thursday — check you can keep it"); more than 120 words is flagged. Flags only; Tim edits and sends.

## Not built, and why
- **Outreach cadences** (0 / +4 / +11 / +21 days, word ceilings, send windows, stop rules): Yui never runs a sequence;
  Mia's campaigns and Sam's follow-ups own them, and Tim's click sends. The "never two sends in 48 h" line is held
  back until Yui can see Mia's and Sam's outbound for the same contact in one place.
- **P1–P4 response clocks, escalation briefs, KB articles, refunds:** support-desk constructs; no equivalent here.
- **Quiet clients against their own rhythm:** already built as Sam's `ClientRhythm`; Yui's check-ins measure a mail gap.
- **An urgent email to Tim for a held ask:** `CharlieUrgentService` is ahead on production; the hold is priority 1 in
  the brief, with the urgent email listed in the backlog.

## Dry run before anything ships
A: run the five patterns over 90 days of inbound — expect zero holds, and no hit on the PDF-password line.
B/C/E: Nigel and Tony leave the inbox; Joe's quote reads his real first sentence; the three promises' quotes too.
D: the Oct 14 / Oct 9 / Sep 15 replies fire; "done for the season" does not. F: run on the last Claude drafts stored
in `yui_actions` — expect few flags.

## Verification
`tests/Unit/Comms/YuiHarvestRulesTest.php`, Codex on the new classes, `YuiDraftService`, `YuiDeskService`,
`YuiRules`, `UnclaimedReplyService`; deploy dependency-first (pure classes, rules, services, card), OPcache, Yui's card
and Charlie's brief checked in Chrome; `MailboxReadOnlyTest` untouched (Yui opens no mailbox).
