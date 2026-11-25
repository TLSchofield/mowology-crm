# Ada — department orchestration core (development Ada, not the customer-facing Ada)

Engine: `ada.py` (stdlib Python + SQLite). State: `~/.mowology-dept/dept.db`, briefings in `~/.mowology-dept/briefings/`.
Install: `scripts/dept/install.sh` (cycle 02:30, briefing 07:00; replaces Sentinel's own jobs). Wake the Mac: `sudo pmset repeat wakeorpoweron MTWRFSU 02:25:00`.

| Command | Does |
|---|---|
| `ada status` / `ada tasks [--state X]` | queue overview |
| `ada cycle` | queue the day's audits, run what is runnable (max 3 runs), import proposals, prioritise (max 3 ordinary for you), write briefing |
| `ada approve\|reject\|revise <id> --reason "…"` | the ONLY way work becomes Approved; needs a real terminal; feeds Sentinel's ledger too |
| `ada pause [agent]` / `resume [agent]` | stop/continue new runs |
| `ada stop` / `unstop` | emergency: kill runs, unload schedule, refuse all ticks |
| `ada revoke <agent> <category> --reason` / `ada permissions` | permission register |
| `ada unblock <id> --reason` | return a Blocked task to where it was |

Enforced in code (see `tests/test_ada.py`, 23 tests): legal transitions only, by the right actor; only the human approves/releases; Guardian alone verifies and never the implementer;
duplicate work refused (dedupe key); one retry then Blocked with the cause; interrupted runs recovered; limit hold; disk guard; run cap per cycle.
Not built yet: Artisan, Forge, Guardian, Mercury, Empath, Atlas, Oracle (only Sentinel has a worker). Plan usage is not measurable; only API-equivalent cost and the limit message.
