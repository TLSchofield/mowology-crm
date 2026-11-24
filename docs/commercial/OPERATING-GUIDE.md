# Operating guide — commercial department

Install/update: `scripts/dept/install.sh`. One-time: sign the bundled Claude CLI in (`…/claude.app/Contents/MacOS/claude auth login`). Wake: `sudo pmset repeat wakeorpoweron MTWRFSU 02:25:00`.

| You want to | Run |
|---|---|
| See the morning briefing | `~/.mowology-dept/briefings/<date>.md` (cycle 02:30, rebuilt 07:00) |
| See pipeline / claims | `ada pipeline` · `ada capabilities` |
| Allow Compass (Mon) and Mercury (Tue/Thu) to run | `ada commercial on` (off by default) |
| Verify a product claim (needed before Scribe/Closer may use it) | `ada verify-capability "<name>" --note "checked <how>"` |
| Confirm the legal basis to contact a prospect | `ada confirm-eligibility OPP-… --note "…"` (your decision; ask a lawyer when unsure) |
| Approve a draft for you to send | `ada approve-outreach OPP-…` then send it yourself, then `ada mark-sent <draft#>` |
| Record an opt-out (applies everywhere) | `ada suppress --email … --reason "…"` |
| Wake Beacon / Ambassador | `ada activate beacon` · `ada activate ambassador` |
| Pause everything / one agent / emergency | `ada pause` · `ada pause hunter` · `ada stop` |
| Revoke a permission | `ada revoke hunter research-public-web --reason "…"` |
| Rehearse the whole workflow safely | `ada demo-commercial` (synthetic prospect, own folder, nobody contacted) |

Approvals and eligibility need a real terminal; scripts and agents are refused. Everything an agent proposes arrives as a draft or a task awaiting you.
