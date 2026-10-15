# Penny learns from corrections + crew job visibility (2026-10-14)

## A. Penny learns from what the owner already does

### Evidence (production, queried 2026-10-14)
- `head_lessons`: 287 rows, **read by nothing** (confirmed in the code: no SELECT anywhere).
  - About 270 rows are bulk clean-up sessions, e.g. `visa_personal_to_1300`, 46 rows in one second.
  - About 10 are real one-at-a-time corrections (`expense_gate:desktop_modal`, `owner_answer`, `ios_update`).
  - Trap: lesson #286 is filed under subject "home depot" with the vendor corrected to "Dunbar Lumber" (the reader misread
    the vendor). Learning "home depot → Dunbar Lumber" would be wrong.
- Receipt suggestions: 56 accepted unchanged, 9 edited, 0 rejected, 67 pending. Each decision's `outcome_json` already
  stores right/wrong per field. That is enough to show her track record, and too little to train a model.
- Penny is a Claude call over a context (`ReceiptBookkeeperService::buildContext`: the receipt, the vendor's history,
  shared hints). The cheapest learning is to put the owner's past corrections into that context, not to build a new
  rules engine.

### Unknowns, and how each is settled
1. Which lessons are safe to show her?
   - Show only one-at-a-time ones: source `expense_gate:*` (not `*_import`), `penny_card`, `owner_answer`.
   - Fields: category, payment method, asset tag, job.
   - Never the `vendor` field (misreads), and never bank-wording rows (bank rules already cover those).
   - Settled by the query above.
2. Does more context make her worse or more expensive?
   - Replay about 10 receipts she got wrong before, in a non-live run, and compare per-field accuracy before and after.
   - Cost: about 10 Claude calls. If she isn't better, it doesn't ship.
3. Does the prompt version change break scoring?
   - `promptVersion()` is stored per suggestion. Bump it, so before and after can be compared.

### Steps
1. **Record more** (small):
   - a rejection writes a lesson carrying its reason;
   - each decision keeps how long it sat before you decided.
2. **Use the lessons** (medium): `buildContext` gains a `your_corrections` block for this vendor, with up to 8 safe lessons,
   newest first, plus her track record for this vendor per field from `outcome_json` (for example "category right 5 of 6").
   Prompt rule: "When the owner corrected a field the same way twice for this vendor, use that unless the receipt
   clearly says otherwise; say so in the note."
3. **Counter-lessons** (medium):
   - When the self-audit finds that a confirmed receipt was later changed (by you or the accountant), that change becomes
     a lesson with source `self_audit`.
   - A bank rule whose line was later moved elsewhere loses its confirmation; at zero it switches off.
4. **Slower trust** (small): a part-number name needs 2 confirmations before the reader uses it (now 1). Existing names
   with 1 confirmation stay; only new ones wait.
5. Verify:
   - phpunit;
   - the replay comparison (step 2's gate);
   - Codex review;
   - deploy;
   - watch accepted-unchanged vs edited over the next 2 weeks on Penny's stats.

Deferred: decay over time and a single learning service across bank, receipts and payers. Revisit when there are 200 or
more decisions.

## B. Crew job visibility toggle + job-type filter (Tim's ask)

### What is true now (code-checked)
- Web and Android schedule (`jobs/schedule.php`): every role gets every crew's stops. The crew dropdown is not enforced
  on the server.
- iOS (`Schedule/Api/day.php`): crew get only their own stops; admins and managers get everyone's.
- `my-schedule.php` embeds every other crew's jobs (address and GPS) for the "near a property, clock in" prompt.
- A job-type (service) filter and a crew filter already exist on the desktop schedule.

### Plan
1. Setting `crew_job_visibility` in Time Clock settings: **own** (crew see only their stops) or **all**.
   - **Default: own** — matches iOS today and closes the Codex privacy finding. Tim may choose "all".
2. Server-enforced on web, Android and iOS (`schedule.php`, `day.php`, `my-schedule.php` proximity list):
   - crew + own: only their stops;
   - crew + all: everyone's.
3. Managers and admins: a **Mine / All crews** switch and a **Job type** filter on the phone schedule (web/Android
   header and iOS). It is remembered per person. Desktop keeps its dropdowns, defaulting to the same choice.
4. Verify:
   - phpunit;
   - local render as crew, manager and admin with each setting (count of stops);
   - Codex review;
   - deploy;
   - iOS compile-check + TestFlight.
