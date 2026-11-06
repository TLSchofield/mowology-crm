# Heads progress — one page for every head's numbers over time (plan, 2026-11-05)

Tim's ask: "a management dashboard of the AI heads … it must be detailed and display their stats", showing
how each head is getting on across the whole of Ittai. Real numbers only; plain words; Tim is the only user.

## What production holds today (read-only check, 2026-11-05)

| Source | Goes back to | What it can show by week |
|---|---|---|
| `expense_suggestions` (Penny) | 5 Oct | receipts read, kept as-is vs changed (right first time), tokens → AI cost |
| `llm_calls` (every head) | 28 Oct | AI calls, cost, failures per head and skill (only 2 weeks so far) |
| `quotes` (Sam) | Feb | won / lost / still out by month |
| `sam_followups` (Sam) | to check | follow-ups sent, quotes won after one |
| `otto_suggestions` (Otto) | Oct | 1,416 made — **every one open or expired, none answered** |
| `mia_suggestions`, `yui_actions` | — | **empty** (Mia's 190 AI calls are her writing, not suggestions) |
| `agent_activity` / `agent_tasks` / `agent_eval_runs` | — | 1 row / 0 / 0 |

Penny right first time by week, from what is already there: 86% (5 Oct), 84% (12 Oct), 94% (19 Oct), 89% (26 Oct).
So the page can show real history today for Penny and Sam, two weeks of cost for all, and honest "nothing yet"
for the rest. That emptiness is itself worth showing: Otto's suggestions go unanswered.

## Decision: a second view of Agent Studio, not a new page, not Owner Freedom

- Agent Studio already answers "who is each head and what are they doing"; "how are they getting on" is the same
  audience and question one step further. A tab (`agents_appstack.php?view=progress`) keeps one place for heads.
- Owner Freedom is about Tim's own time and money; mixing per-head learning curves in would blur it. It can link here.

## Unknowns and how each is settled (cheapest first)

1. **Which numbers can be rebuilt from dated rows?** Settled above from production. Rebuilt: Penny (right first
   time, receipts read, AI cost), Sam (won/lost/win rate), cost for all heads, Otto/Mia/Yui decided-vs-expired.
2. **Which can't?** Queue sizes at a moment (Penny waiting, Otto open, Yui arrears, consent count). → a nightly
   snapshot table, so from tonight they gain history. Cost: one migration + one line in the crons registry.
3. **Is "fewer AI calls per piece of work" measurable?** Yes for Penny (AI calls ÷ receipts read per week, and
   code-alone share — 0% today, every suggestion is the model). Others: calls per week only, until each has a
   unit of work. Said so on the page rather than invented.
4. **Sam's follow-up table and Otto/Mia/Yui outcome columns** — check columns with `/schema` before writing SQL.
5. **MySQL 5.7-safe weekly grouping** — `YEARWEEK(x, 3)` and `GROUP BY`; no window functions. Unit-test the
   week-bucketing in PHP, not in SQL.
6. **Tenant-neutral** — reads only the business's own tables; labels come from `AgentRegistry`; no trade words.

## What gets built

1. Migration `head_stat_snapshots` (`snap_date`, `head`, `stat_key`, `value DECIMAL`, unique on the three).
2. `app/Modules/Agents/Services/HeadProgressService.php` (read-only, fails soft like `AgentStudioService`):
   - `weekly(int $weeks = 12)`: per head, a row per week of rebuilt numbers (above).
   - `snapshot(string $date)`: writes today's point-in-time numbers from each desk's `stats()`; the only writer.
   - `history(head, key)`: snapshot series for the page.
   - Pure helpers (week buckets, trend arrow, "too few to judge" rule: under 5 decisions = no %) unit-tested.
3. Cron key `head_stat_snapshot` (nightly) in the business crons registry, calling `snapshot()`.
4. Page: the Progress tab. Top: one card per head (7) — this week vs 4 weeks ago for its main number, AI cost
   this week, and a plain sentence ("Penny kept 89% of receipts as she read them, up from 86%"). Below: per head,
   a weekly table (12 weeks) with small inline SVG sparklines, and a "not measured yet" line where the data is
   empty, naming why (e.g. "Otto: 1,416 suggestions, none answered — they expire before anyone looks").
   Styles in `mowology-brand.css` (`.mw-hp-*`), Ittai v1.2 tokens, no chart library.
5. Unit test `tests/Unit/Agents/HeadProgressServiceTest.php`; register in `tests/bootstrap.php`.

## Verification

- `vendor/bin/phpunit` green. Render the page locally with the no-DB harness (empty → "not measured yet" lines).
- Run `weekly()` on production through `scripts/prod-run.sh` (read-only) and check Penny's figures equal the
  numbers above and Sam's equal the quotes count.
- Codex: `scripts/codex-web-build.sh` writes the page/service from this spec, Claude reviews; then
  `scripts/codex-review.sh`. Real findings fixed.
- Deploy via prod-pull → cmp → `deploy-file.sh`, OPcache reset; Tim runs the migration in Database › Migrations;
  open the tab live and compare three numbers against the prod-run output; next morning check one snapshot row per head.

## Not in this round

Charts of trends beyond 12 weeks; editing anything from the page; iPhone/Android versions of the view.
