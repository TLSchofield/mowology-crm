# How the heads learn — Codex design reviews (2026-10-15)

Tim: "Have Codex continue with the other heads" (after its review of Penny's learning). Codex read each head's
code (code-only, client names redacted) and answered: what it learns from, what it ignores, efficiency, whether a
lesson can make it worse, and the five changes with the biggest payoff. Claude checked the load-bearing claims
against the code (marked ✓). Full reports: the session scratchpad (codex/head-*.md).

## The pattern across all five
- **`HeadLessons` records corrections but no head reads them back** ✓ (`app/Services/HeadLessons.php`: "Nothing here
  changes … any learner yet"). It already has `countConsistent()` for "the same correction twice" — the gate Penny now uses.
- **One edit becomes the rule for everyone** ✓ — Sam (`SamFollowupService::learnedBody()` takes the single latest edit
  per template) and Mia (`MiaWording`) promote one owner edit to the template with no repeat, confidence or rollback.
- **Outcomes aren't fed back**: wins, replies, revenue and lateness are counted for badges, not used to choose.
- **No decay / unlearn**: Charlie's preferences and mutes, Otto's weather rules, Mia's send-time and theme scores are permanent.
- **Repeated work**: Sam / Yui / Charlie call Claude afresh for replies or questions they have answered before.

## Per head — the top changes (Codex's ranking; size S/M/L)
**Sam** — 1 gate template learning through `HeadLessons::countConsistent()` with history and rollback (M); 2 score
template × channel × service × stage by reply / win / loss / latency (L); 3 record every owner decision — lead
accept/dismiss, question answers, skip/snooze, subject edits — in `head_lessons` (M); 4 segment by service and client
type (strata vs homeowner) (M); 5 cache timing medians and reuse past replies before calling Claude (M).

**Otto** — 1 read `HeadLessons` in supplier-job ranking and missed-visit picks (M); 2 record every supplier decision
incl. "nothing bought" with vendor / material / stop features (M); 3 recency, confidence and revoke on his rules (M);
4 cache per-plan duration summaries from all trustworthy visits (M); 5 learn weather reversals and delivery outcomes,
not just the first click (L).

**Yui** — 1 route moves, tidy choices / undos, edited sends through `HeadLessons` (L); 2 an address rule after one
correction, a domain rule only after 3 consistent ones, with decay (M); 3 a move corrects the topic too, not just the
destination (M); 4 learn "is work / not work" tidy answers into sender rules (M); 5 reuse approved replies before
calling Claude (M). (Its point that attachments only kept for Penny's mail is fixed today.)

**Charlie** — 1 learn the outcome and edits of his proposals through `HeadLessons` (M); 2 deadline lead time by
category from early / late history (M); 3 decay, confidence and a visible reset / unmute (M); 4 cache repeated
questions before calling Claude (M); 5 "opened" is not "done" — learn wins only from confirmed action (S).

**Mia** — 1 record final-vs-draft edits and dismissal reasons through `HeadLessons`, apply only consistent ones (M);
2 rank themes / campaigns by accepted jobs and revenue, engagement only as an early signal (M); 3 version learned
templates, promote only on repeat or success, with rollback (M); 4 learn caption, GBP, review-reply and photo
preferences (M); 5 decay and minimum samples on theme, photo and send-time scores (S–M).

## Suggested order (one shared fix first)
1. A shared "learn only what's repeated" step in `HeadLessons` (consistency, recency, revoke) — every head uses it.
2. Sam and Mia template promotion behind it (stops one edit rewriting every customer's email).
3. Charlie "opened ≠ done" (small, removes the biggest false signal).
4. Then per-head outcome loops (Sam wins/replies, Mia revenue, Otto supplier picks, Yui routing certainty).
