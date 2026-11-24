# The Continuous Improvement Department — map and how to grow it
`ada roster` prints the live map from `scripts/dept/roster.json` (one file: status, role, spec, **build trigger** for each agent).

| State | Meaning | Agents today |
|---|---|---|
| live | runs nightly in Ada's cycle | Ada, Guardian (deterministic), Atlas (lesson capture) |
| live-needs-signin | built and tested; needs the one-time CLI sign-in | Sentinel |
| built-off | built and tested, not scheduled until you switch it on (`ada commercial on`) | Apollo, Mercury, Hunter, Scribe, Closer, Compass |
| dormant | defined; wakes on your command | Beacon, Ambassador |
| planned | spec only; Ada refuses to queue work for it | Artisan, Forge, Empath, Oracle |
| external | not an Ada agent | the Codex design studio |

## Building the next agent
1. Check its build trigger in `roster.json` / its spec in `docs/department/specs/`. Do not build early: an agent without data or an independent verifier only produces plausible text.
2. `python3 scripts/dept/scaffold.py <agent> [--model … --tools …]` creates the read-only, level-1 skeleton (definition, registry entry, test stub) and flips it to built-off.
3. Fill in the real mission and JSON schema, add its applier in `scripts/dept/agents.py` and its row in `PERMS` in `scripts/dept/ada.py`; write the applier tests (valid applied, invalid refused, untrusted text changes nothing).
4. Run it on stand-in output first, then once for real; schedule it only after you have read a real result.
5. Raise autonomy only by your decision, one task category at a time (`ada permissions`).
Rules every agent inherits: offline first for crew/field features (state and test works / queues / unavailable; CLAUDE.md 1e). From the engine: legal state moves only; only you approve; Guardian alone verifies and never the implementer; duplicate work refused; one retry then Blocked with the cause; pause/stop honoured; run budgets and a disk guard.

## Design work (Codex on your ChatGPT plan)
`scripts/codex-design.sh <name> <brief.md>` builds HTML/CSS/SVG rounds that are machine-checked against the v1.2 rules; Claude art-directs. Raster/photographic image generation is not part of this (no Codex image tool is available here; see the answer in the 2026-11-24 session notes).
