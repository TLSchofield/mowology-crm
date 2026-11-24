# Continuous Improvement Department (plan, agreed in part 2026-11-24)

Ada (development Ada, agent `ada-director`, NOT the customer-facing Ada in `app/Modules/Technology`) directs Sentinel, Artisan,
Guardian, Forge and Mercury. Same Claude Code install and subscription; no API keys, no paid services.

## Decided (Tim, 2026-11-24)
- Sentinel branch pushed and merged to `feature/language` through the gate. **Done.**
- `feature/language` protection: force-push and deletion blocked. A required-PR rule was NOT turned on because
  `scripts/merge-to-feature.sh` / deploy-safe push to that branch directly and would stop working. Revisit with Guardian's CI checks.
- Schedule: **once a day** (not every 30 min): one overnight cycle, dependency-driven inside it, one briefing at 07:00.
- Mac wakes for it (Tim runs one `sudo pmset repeat` line; a LaunchAgent only runs while he is logged in).

## Also decided
- Mercury's place: Ada's call (4th, after Artisan). Mercury reads the existing Search Console data (snapshot only).

## Still open
First CLI sign-in (`claude auth login`).

## Roster — DECIDED (Tim, 2026-11-24: "your recommendations")
Agents: Ada, Sentinel, Guardian, Artisan, Forge. Lenses (skill + memory + one weekly queue task, promoted only on proven need): Mercury, Empath, Atlas. Oracle: no spec, not built.

### Reasoning (recommendation as made, after Empath and Atlas were specified)
Nine agents plus Ada is too many to build and run on one subscription, and Ada's own rule is to add an agent only for a
demonstrated gap. Recommend: build Ada, Sentinel, Guardian, Artisan, Forge as agents; run **Mercury, Empath, Atlas** first as
*lenses* (a skill + memory + a weekly task each in Ada's queue) and promote one to its own agent only when its output proves a gap.
Reasons: Atlas's evaluation lab and knowledge records are mostly code that belongs in Ada's core; Empath has almost no customer data
to work from (Mowology + one rehearsal business, no analytics); Mercury's first value is deterministic SEO checks plus Search Console.
**Oracle** is named in Empath's and Atlas's specs but has no spec yet.

## Progress
- Ada core built and installed 2026-11-24 (`scripts/dept/`, 23 tests). First real cycle ran; Sentinel blocked at the sign-in step, named in the briefing.

## Build order
1. Ada core: SQLite queue + validated state machine, permission register, lock, pause/resume/stop-all, briefing, `ada` CLI. Sentinel is worker one.
2. Sentinel seeded eval set; Guardian (deterministic checks first). 3. Artisan. 4. Mercury. 5. Forge (needs Guardian + CI checks).
Each is its own reviewed commit; nothing is called "working" until it has passed signed-in live runs (stand-in tests are labelled as such).

## Limits
Plan usage is not measurable (only `total_cost_usd` and the "hit your limit" message); `--max-turns` does not exist in this CLI
(budget cap + wall-clock timeout instead); `--bare` needs an API key so it is not used.

## Apollo's Commercial Intelligence Department (built 2026-11-24, Tim: "Build all")
Eight agent definitions (`.claude/agents/`), commercial pipeline/compliance core (`scripts/dept/commercial.py`), agent runtime (`agents.py`), 72 tests.
Docs: `docs/commercial/` (ARCHITECTURE, OPERATING-GUIDE, LIMITATIONS, metric-definitions, first-deliverables, research). Mercury and Atlas were not pre-existing;
Mercury is now built under Apollo, Atlas stays a shared deterministic lesson-capture worker (the roster decision "lens" is kept for Empath only; Atlas's lesson capture is code).
Beacon and Ambassador are defined but dormant. Interpretation of "build all": all eight definitions built; dormant ones activate on the owner's command.
