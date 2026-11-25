# Apollo's Commercial Intelligence Department — architecture (built 2026-11-24)

**One engine, two departments.** Ada's engine (`scripts/dept/ada.py`, SQLite `~/.mowology-dept/dept.db`) runs both departments:
tasks carry a `department` (`ada` product, `apollo` commercial, `shared` for Atlas). One state machine, one lock, one pause/stop, one permission register,
one approval queue for the owner, one morning briefing with a "Commercial position (Apollo)" section.

| Layer | File | What it enforces in code |
|---|---|---|
| Queue + governance | `scripts/dept/ada.py` | legal task moves by the right actor; only the owner approves (interactive terminal); dedupe; retry once then Blocked; recovery; pause/stop; permissions; 50/50 run split (2 + 2 per cycle) |
| Pipeline + compliance | `scripts/dept/commercial.py` | 20-stage pipeline; dedupe on domain and name+city; department-wide suppression (email, domain, phone, name); eligibility confirmed by the owner before `contact_eligibility_verified`; outreach approval; **nothing in the code sends anything** (the owner sends and records it); drafts allow only VERIFIED capabilities, email needs sender id + unsubscribe, SMS 160 chars no URLs |
| Knowledge | `commercial.py` (`knowledge`, `capabilities`) | agents write drafts with a source; only the owner or Guardian makes knowledge authoritative; newer authoritative statements supersede, never overwrite; conflicts surface in the briefing; lessons distributed selectively |
| Agent runtime | `scripts/dept/agents.py` | bounded prompt (verified claims, own memory, authoritative lessons); external text fenced as untrusted data; the CLI runs read-only with no Write/Edit/Bash/MCP in an empty folder; JSON validated and applied by code |
| Definitions | `.claude/agents/{apollo,mercury,hunter,scribe,beacon,closer,ambassador,compass}.md`, `scripts/dept/registry.json` | version-controlled; tools, model, autonomy level, budget per run |
| Demo | `scripts/dept/demo.py` | the ten-step workflow on a SYNTHETIC prospect with a stand-in CLI |

**Agents:** Apollo (evaluates, level 2), Mercury (observes web, 1), Hunter (researches public pages, 1), Scribe (drafts, 3), Closer (plans, 3), Compass (researches, 1),
Beacon and Ambassador (**dormant**: `ada activate beacon|ambassador`), Atlas (deterministic lesson capture, shared with the product department).
Subagents cannot spawn subagents, so the engine dispatches each as its own queued task and passes results through the database.

**Not built (by design, this phase):** anything that sends email/SMS, posts, buys, advertises, changes pricing or reads customer data. Autonomy levels 4-5.
**Reused, not rebuilt:** Ada's engine, Sentinel's ledger pattern, `docs/marketing/competitor-checklist.md` (Compass starts from it), the app's consent concepts (not its data).
