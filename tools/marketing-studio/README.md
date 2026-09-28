# Marketing Studio

A local, single-user workbench that walks one piece of marketing copy from brief to shipped, and hands the writing to Claude Code.

**Start it:** double-click `start.command` (a copy sits on the Desktop as "Mowology Marketing Studio"), or in the Claude desktop app use the `marketing-studio` preview. It serves on `http://127.0.0.1:8740/` and binds to localhost only.

**The loop**

1. Choose the piece type, write the brief, check the voice card and compliance flags.
2. "Send to Claude" writes `outbox/<slug>.md`, copies a prompt to the clipboard and opens the Claude app.
3. In any Claude Code session for this repo, type `/studio` (or paste the prompt). Claude reads the brief and `.agents/product-marketing-context.md`, runs the named skill(s), writes `outbox/<slug>.draft.md` and marks the piece `review`.
4. The Studio shows the draft with the review rubric; send it back with notes or move to Ship.

**Files:** `server.py` (stdlib only), `app/` (vanilla HTML/CSS/JS), `state/pipeline.json` (your pieces), `outbox/` (briefs and drafts; ignored by git), `design/directions.md` (the art direction).

Workflow content (piece types, awareness → lead map, proof list, rubric, keyword hints) is plain data in `app/content.js`.

## Tactics cards

Each piece type surfaces three to six concept cards from Pip Decks' *Brand Tactics* and *Storyteller Tactics* (in our own words, `app/content.js` → `tactics`). Every card carries Mowology's standing answer where one exists; the owner adds the answer for the piece in hand, and both go to Claude in the brief under `## Tactics`. The Voice step shows the standing answers as a strategy card. To change a standing answer, edit `content.js` and `.agents/product-marketing-context.md` together so the skills and the Studio agree.
