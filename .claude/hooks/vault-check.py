#!/usr/bin/env python3
"""
Mowology CRM — Architecture vault pre-flight.
Called by the PreToolUse hook on Edit|Write.

CLAUDE.md §1c says to read the Obsidian Architecture vault before changing
code, but as written it only covers refactors — so a bug fix (2026-09-24, the
Android tracking-token outage) sailed straight past it. This hook makes the
check unconditional: the first time Claude edits a code file in a session, the
vault index is injected into its context.

Fires ONCE per session (marker keyed on session_id), and only for code — not
for .md files and not for anything under .claude/. Never blocks the edit: any
failure exits 0 silently, because a missing vault must not stop work.
"""

import json
import os
import sys
import tempfile

VAULT = ("/Users/timschofield/Library/Mobile Documents/iCloud~md~obsidian/"
         "Documents/30-PROJECTS/Active/Mowology-CRM/Architecture")
INDEX = os.path.join(VAULT, "_Architecture-Index.md")

GUIDANCE = """ARCHITECTURE VAULT PRE-FLIGHT (CLAUDE.md §1c) — you are about to change code in this project.

Before going further, read the vault doc(s) covering the system you are touching. This is a targeted read of one file, not a survey:
  - Known-Failure-Patterns.md — the silent-failure traps; grep it for the subsystem name FIRST
  - Integration-Map.md — what this system connects to and its coupling risks
  - Feature-Impact-Chains.md — blast radius + what to test
  - Decision-Log.md — why it is built this way, before you "simplify" it
  - Tech-Debt-Map.md — known-broken, deliberate workarounds, half-built

Vault path: {vault}

Treat it as a starting hypothesis, not ground truth — spot-check anything load-bearing against the current code.
If nothing in the vault covers this area, say so in one line and carry on.

--- _Architecture-Index.md ---
{index}"""


def main():
    try:
        payload = json.load(sys.stdin)
    except Exception:
        return

    path = (payload.get("tool_input") or {}).get("file_path") or ""
    session = payload.get("session_id") or "nosession"

    # Docs, notes and Claude's own config are not "coding".
    if path.endswith(".md") or "/.claude/" in path or path.startswith(VAULT):
        return

    marker = os.path.join(
        os.environ.get("TMPDIR", tempfile.gettempdir()),
        "mowology-vault-check-" + "".join(c for c in session if c.isalnum() or c in "-_"),
    )
    if os.path.exists(marker):
        return
    try:
        open(marker, "w").close()
    except OSError:
        pass

    try:
        with open(INDEX, encoding="utf-8") as fh:
            index = fh.read()
    except OSError:
        return

    print(json.dumps({
        "systemMessage": "📓 Architecture vault check (first code edit this session)",
        "hookSpecificOutput": {
            "hookEventName": "PreToolUse",
            "additionalContext": GUIDANCE.format(vault=VAULT, index=index),
        },
    }))


if __name__ == "__main__":
    main()
