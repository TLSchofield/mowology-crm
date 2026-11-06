# ChatGPT's review of the head learning engine: what holds up (2026-11-05)

Source: ChatGPT's answer to `2026-11-05-head-learning-engine-brief-for-review.md` (shared link, read 2026-11-05).
Each point is checked against the code on `feature/language` and `claude/penny-line-items`.

## The big change I accept

**Recognise → Remember → Prepare → Approve → Learn**, with Sam's whole fall-cleanup quote as the first deliverable.
The brief framed this as a vocabulary problem. The real gap is *business memory*: connecting "this client asked"
to "this property's last same-service job, its scope, price and date" and preparing a draft from it.
- What exists: `CloserService::comparables()` (the 2–3 most recent accepted quotes for the same service, across all
  customers); `JobberImportService::forProperty()` (Jobber invoice history at an address); `InboundTopicClassifier`
  (routes the request to Sam); `EmailLeadService` service words.
- What's missing: one step that ties these together for a known client and produces a draft quote. The old price is
  shown as a **precedent with its date**, flagged for review, never assumed to be today's price.

## Holds up, and the code already has it

| Point | Where it already exists |
|---|---|
| Shared infrastructure with head adapters, not one universal algorithm | `HeadLessons` (migration 1348): one store, each head keyed by its own `subject_kind` (vendor, bank wording, service type…); `PennyLessons` is Penny's adapter |
| Field-level corrections | `head_lessons.field`, `said`, `correct`: one row per corrected field |
| Learning separate from permission to act | `AgentPolicy`: levels 0–4 *per action*, money actions capped at 2, kill switch, shadow mode, `agent_activity` ledger |
| Reversible learning | `HeadLessons::revoke()` |
| Contradiction (per subject) | `HeadLessons::rule()`: a newer, different answer resets the rule; old lessons fade |
| Attribution of who/what taught it | `head_lessons.source` (code path), `ref_type/ref_id`, `corrected_by` |
| Idempotency (mostly) | `sales_messages` unique `message_key`, `agent_tasks.dedupe_key`, `ExpenseGate` locked snapshots |

## Holds up, and it's a real gap to build

1. **Acceptances aren't recorded, only corrections.** `HeadLessons` skips "said == correct". So field-level acceptance
   rates and coverage can't be computed. Record each proposal and its per-field outcome (accepted / changed / rejected).
2. **Each proposal should carry its evidence and source.** It should list the signals that matched (known client,
   property, service phrase, prior job, negation found, competing services) and what produced each field (fixed rule,
   private lesson, shared rule, precedent, AI). The sure / likely / not sure level is derived from that evidence, and
   the head says "not sure" (abstains) when the evidence is thin.
3. **Negation.** "We don't need a fall cleanup this year" must not read as a request. Add a negation check that forces
   "not sure".
4. **Coverage beside accuracy.** A head that only answers easy cases looks better but is less useful. Sam's headline
   metric: % of eligible requests turned into a correct, ready-to-approve draft. Add coverage to the Progress tab
   when proposals are logged.
5. **Test a new phrase rule against past reviewed cases before it switches on.** It's cheap, and Penny already
   replays receipts (406-receipt replay on `claude/penny-line-items`).
6. **Cross-business sharing: design now, build later.** Count *businesses*, not confirmations. Treat 3 businesses as a
   candidate and 5 as eligible, with review for ambiguous or conflicting terms. Promote only normalised service terms,
   never extracted text. Say it in the terms of use (consent).
7. **Mia's ~25% failures are a reliability bug, not a learning problem.** Find the cause (`llm_calls.error`: model,
   timeout, image size) before redesigning anything. Separate task (Ada/Mia).

## Doesn't hold, or needs correcting

- **"PHP 7.4 and MySQL 5.7 are out of support."** My brief misstated the stack. Production runs PHP 8.3 (crons 8.1)
  and MySQL 8.0. The code is only *kept* 5.7-compatible by the repo rule. Not a priority.
- **Four maturity stages (observed / candidate / established / trusted).** Not needed as new machinery. Recognition
  stays "twice in a row is a rule" (`HeadLessons::rule()`). What a head may *do* is already a separate, per-action
  setting (`AgentPolicy`), so "trusted" is a policy decision, not a lesson stage. Store the counts so stages can be
  added later.
- **Six autonomy levels.** The existing five (0 observe … 4 delegated) cover it. Its "Sam asks you for more autonomy
  after 28 of 30 kept" idea is good: a later feature on top of `AgentPolicy`.

## Revised order

1. **Sam's fall-cleanup test**, with only the shared pieces it needs:
   - proposal + per-field outcome logging;
   - evidence and source on each proposal;
   - negation check.

   Acceptance criteria as ChatGPT listed them: routes to Sam; client and property found; service recognised; prior
   work, scope and price retrieved with dates; draft quote made but **not sent**; each field approved or corrected;
   corrections recorded; no duplicate drafts; the next similar request reuses what was learned.
2. **Yui's routing** onto the same proposal/outcome logging (she already learns from "Move to…").
3. **Penny's receipts.** Build on `claude/penny-line-items`: three no-AI ways to fill line items, gated by
   `LineItemFit::addsUp` (±$0.05). In replay, receipts whose items add up went from 43% to 55%. Not deployed yet.
4. **Mia's photos.** Fix the failure rate first.
5. **Otto's predictions** (visit length is already moving to GPS time: migration 1439).
