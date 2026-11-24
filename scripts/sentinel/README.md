# Sentinel — Ittai's product auditor

Founding member of Ada's Continuous Improvement Department. **Level 0: read-only.** Registry and promotion rules: `registry.json`.
Agent: `.claude/agents/sentinel.md`. Expertise and output format: `.claude/skills/sentinel-product-audit/SKILL.md`.

| What | Where |
|---|---|
| Install / update / remove | `scripts/sentinel/install.sh [--uninstall]` (copies to `~/.mowology-sentinel/bin`, loads launchd daily 07:00, weekly Sun 08:00) |
| One-time sign-in | `…/claude.app/Contents/MacOS/claude auth login` (the bundled CLI has its own login) |
| Run now | `sentinel run daily` · `sentinel run weekly` |
| Decide | `sentinel accept\|reject\|modify <id> "why"` (reason mandatory) · `sentinel list` |
| Reports | `~/.mowology-sentinel/reports/YYYY-MM-DD/daily.md` + `shots/` |
| Backlog (generated) | `~/.mowology-sentinel/backlog.md` |
| Memory (edit freely) | `~/.mowology-sentinel/memory/{product,taste,audit-history}.md` |
| Ledger (append-only) | `~/.mowology-sentinel/ledger/decisions.jsonl` |
| What it may visit | `targets.json` (public pages only) |
| Failures | `~/.mowology-sentinel/runs.log` — each names its cause; exit 75 = not signed in or usage limit |
| Tests | `python3 -m unittest scripts/sentinel/tests/test_sentinel.py` |

Safety: the agent has no Write/Edit/deploy/git-write tools (registry `tools_denied`), sees a code-only snapshot of `origin/feature/language`
(no docs, tests, data, binaries), and visits public URLs only. Every proposal needs seven parts and a measurable acceptance
criterion or the ingest step drops it; at most three per daily run; rejected ideas are suppressed unless 40+ characters of new evidence are cited.

Not yet verified live (needs the CLI signed in): the model run itself, that `claude -p` loads the Playwright/DevTools MCP from the
generated `mcp.json`, and that the `--allowedTools` patterns are enforced as written. The first signed-in `sentinel run daily` settles all three.
