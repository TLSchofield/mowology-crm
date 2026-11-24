---
name: ittai-design-studio
description: Run an Ittai design round as a studio — Claude is Creative Director, Codex is the visual designer. Use when Tim asks to design, redesign, mock up or art-direct any Ittai screen, component, empty state, email, icon sheet or marketing visual ("design a screen for Ittai", "mock up…", "make this look like v1.2", "design studio", "/design"). Writes the brief, has Codex build HTML/CSS/SVG to the v1.2 design system, renders it at 375/768/1024/1440 in light/dark/field with automatic checks, critiques it, and revises up to three rounds. Not for building the real app screens in PHP/Swift/Kotlin (use the normal build flow once a design is approved) and never for changing the frozen symbol.
---

# Ittai design studio

Claude is the **Creative Director**: decides what is being designed and why, writes the brief, looks at the renders, critiques, and signs off.
Codex is the **visual designer**: it builds the HTML/CSS/SVG. Codex runs on Tim's ChatGPT plan, so the token-heavy drawing goes there.
Designs live in `~/.mowology-codex/design-studio/<name>/round-N/` until Tim approves; nothing here edits the app.

Say "Using ittai-design-studio" in a line when you start.

## The loop (max 3 rounds, then bring Tim the best one and what is still open)
1. **Brief** — fill `references/brief-template.md`. One screen or component per brief. Always: who uses it and where (office desktop / crew phone outdoors / truck tablet), the one decision or action it exists for, the states to show, synthetic data. If a brief conflicts with `references/v1.2-rules.md`, say so and follow the rules unless Tim overrides.
2. **Build** — `scripts/codex-design.sh <name> <brief.md> [--ref <path>…]`. Exit 0 = built and checks pass, 2 = built but checks fail, 3 = setup/safety stop, **75 = Codex out of usage: give the same brief to the `web-builder` agent and say so** (`Codex: limit — built by web-builder`).
3. **Look** — open `round-N/shots/contact-sheet.html` and actually view the screenshots (Read the PNGs). The machine checks (sideways scroll, text under 12 px, literal colours, targets, AAA contrast, unnamed controls) only catch rule breaks, not bad design.
4. **Critique** — use `references/critique-rubric.md`. Write the critique as the next brief: what is strong (keep), what is wrong (fix), one change at a time where possible. Numbered, specific, no "make it better".
5. **Revise** — run the script again with the revision brief; it feeds round N-1 and its check findings to Codex automatically.
6. **Sign-off** — only when every machine check passes AND the rubric passes at all four widths and all three modes. Report to Tim with the contact sheet path, what you chose and why, and what you would still change.

## Rules that never bend
- Design system v1.2 (frozen baseline, 2026-10-17) — `references/v1.2-rules.md`. Tokens only; the symbol is frozen; AAA 7:1.
- Check **every** width (375, 768, 1024, 1440) and mode (light, dark, field) before calling it done. A first render that only works on desktop is not a round.
- Nothing shows "done" until the server confirms; designs must show the unconfirmed state too.
- Synthetic data only. Never paste real client names, addresses or amounts into a brief.
- Codex sees only the design system, the brief and `--ref` files you name. Never point it at app code, docs, tests or data.
- Raster/AI-generated imagery: Codex reports it has an image tool but this is **unverified here** — for photographic or illustrated assets use the `image` skill and ask Tim first; icons, diagrams and UI are SVG/CSS.

## Known gaps to tell Tim about (as of 2026-10-25)
- Tokens are AAA on this branch (`scripts/ittai-contrast.py docs/design/ittai-v1.2/tokens.json` passes). The same fix (`032151de`) lives on `feature/snow-contracts`, so merging the two branches should be conflict-free on those files. Never edit `ItTokens.swift` by hand: `node scripts/ittai-swift-tokens.mjs --check` fails if it differs from `tokens.json`.
