# Heads learn only what repeats — and from outcomes (2026-10-15)

Tim: "Yes do all" (Codex design reviews: docs/reviews/2026-10-15-head-learning-codex.md).
One rule for every head, the one Penny already follows: **once is a hint, twice in a row is a rule; a newer different
answer resets it; old lessons fade; a person can unlearn.** Then each head's outcome loop.

## Phase A — the shared rule (HeadLessons)  · S
`HeadLessons::decide($rows, $minRepeat = 2, $maxAgeDays = 365)` (pure) and `->rule($head, $kind, $key, $field)`:
newest answer wins only when it is repeated `minRepeat` times **in a row** (a different newer answer resets the streak),
the newest is within `maxAgeDays`, and the newest isn't a revoke. `->revoke(...)` records an unlearn row.
Returns `{value, streak, total, confidence, since}` or null. Tests on the rule.

## Phase B — wording learned only when repeated (Sam, Mia)  · M
Sam `SamFollowupService::learnedBody()` and Mia `MiaWording` / `MiaDeskService`: today the single latest edit becomes
the template for everyone. Now an edit becomes the template only when the owner has made **two similar edits** for
that template + channel (text similarity ≥ 80 % after the fill-ins are taken out); a newer different edit doesn't
replace it until it is itself repeated. Every edit also goes to `head_lessons` (history = rollback). Tests.

## Phase C — Charlie: opened is not done  · S
Opening an item stops counting as "Tim cares about this kind"; only an explicit act (done / acted / dismissed with a
reason) or a verified disappearance teaches. Preference scores fade (half-life) and can be reset. Tests.

## Phase D — outcome loops (one head at a time, each its own deploy)
- **D1 Sam** — record every owner decision in `head_lessons` (lead accept/dismiss, question answers, not-now, subject
  edits); per template × stage: replies and wins within N days → the desk shows which wording works; timing medians cached daily.  · M
- **D2 Otto** — supplier-job picks: confirmations and "nothing bought" recorded with vendor / material / stop; ranking
  reads `rule()` per vendor+material → job type before "last receipt here"; weather reversals recorded.  · M
- **D3 Yui** — routing: an address rule after one move, a domain rule only after 3 consistent moves (via `rule()`),
  rules fade; "is work / not work" tidy answers teach sender rules.  · M
- **D4 Mia** — themes / campaigns ranked by accepted jobs and revenue (engagement only as an early signal); scores need
  a minimum sample and fade by season.  · M
- **D5 Charlie** — deadline lead days from early / late history (after repeated evidence); proposal outcomes recorded.  · M
- Reuse before Claude (Sam / Yui / Charlie): a past approved reply to the same kind of message is offered first.  · M

## How each phase ships
Read the head's code first (and the vault's notes for it) → tests → local site → Codex review (it changes what the
heads say to clients) → deploy (3-way merge with live) → production preview where data changes → report.
Live-only files are copied from production first (the Comms module was live-only).
