# Yui's decision carousel on iPhone — build spec for Codex (Tim approved 2026-11-10)

**Why:** Tim: "Yui needs a decision carousel like Penny has an expenses one." One decision at a time, decided on the card,
no other screen. The server queue already exists and is live on the web: `YuiJobAdapter::jobs()` behind
`/crm/api/yui.php?mode=jobs` (session login, so the iPhone cannot call it).

## 1. Server: two modes on `app/Modules/Team/Api/team-mobile.php` (JWT, the way `otto_decide` / `mia_decide` are done)
- `GET mode=yui_jobs` → `{ok, jobs}` from `YuiJobAdapter::jobs($db, $ownerName)`. Same permission as the web card:
  the JWT user must hold `billing.edit` (check how team-mobile checks permissions elsewhere; never role names).
- `POST mode=yui_act` with `{request: {...}, fields: {...}}`: `request.mode` must be one of `send`, `handled`, `who_confirm`,
  `who_not`, `who_undo`, `draft`, `who_accounts`; merge only the keys the card's `fields` list names; then call exactly what
  `app/Modules/Comms/Api/yui.php` calls for that mode (`YuiDeskService::find()` then `YuiDraftService::send()` / `handled()`,
  `InboundQuestionService::confirm()` / `notClient()` / `undo()` / `accounts()`, `YuiDraftService::draftReply()`).
  Extract the shared dispatch into one function both endpoints call rather than copying it. `send` re-reads the item on
  the server; never trust a recipient, amount or attachment from the phone. Statement sends still require `closing`
  to match (YuiDraftService refuses a changed balance).
- Tests in `tests/Unit/Team/` for the allow-list and the field merge (a field not in `fields` is dropped).

## 2. iPhone: Yui's card on the Team tab gains the carousel
Copy the pattern of `PennyCrewChaseCard` (`ios/MowologyCRM/MowologyCRM/Features/Receipts/PennyChaseViews.swift`,
`shownId` / `shownIndex` scroll position, "1 of N") and the two-panel duplicate layout Tim showed (spine down the middle,
filled action left, outline action right). Design system v1.2 tokens only, 44 pt targets, status icon + word.
- **statement**: left = who wrote and what they asked; right = account, period, balance, a picker of `right.recipients`
  (each "on file" or the words "Not on file for this account"), subject + note editable. Filled "Send statement",
  outline "Handled".
- **who**: left = sender, subject, their words, "N earlier from them"; right = first name, last name, account picker
  (`right.candidates`, plus "Another account…" calling `who_accounts`). Filled "Add to account", outline "Not a client".
  After either, a 6-second Undo (`undo.request`).
- **reply**: left = their words; right = to, subject, note (with "Draft with Claude" → `draft`). Filled "Send", outline "Handled".
- Nothing shows as done until the server says ok; a refusal shows its message on the card. An empty queue hides the carousel.
- Bump MARKETING_VERSION; pushing to feature/language starts TestFlight.

## 3. Checks
Simulator with synthetic data through a DEBUG launch flag (as `-mwTimelineDemo`), every card kind, Dynamic Type large,
dark and field modes. Review by Codex (or code-reviewer) before push. Update `.claude/skills/yui-comms/references/surfaces.md`.
