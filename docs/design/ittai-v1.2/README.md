# Ittai Design System v1.2 — source tokens

Frozen baseline, approved by Tim on 2026-10-17. The brand book is the Claude Design artifact https://claude.ai/artifact/YDZaYnmRc8CAbqtz8oC5uk.

- `tokens.json`: every colour (light / dark / field), type style, spacing, radius and stroke. This is the source.
- `tenant.config.json` holds tenant brand inputs, and `tenants.generated.json` holds the generated, contrast-checked tenant colours. Never hand-write a tenant hex.
- `scripts/ittai-swift-tokens.mjs` generates `ios/MowologyCRM/MowologyCRM/Core/Design/Ittai/ItTokens.swift` from these files. Re-run it after any token change; don't edit the Swift by hand.
- `scripts/ittai-design/build-tokens.mjs` generates `tokens.css` here (the `--page`, `--text`… variables the design studio's mockups link) from the same files. On `feature/snow-contracts`, `scripts/ittai-css-tokens.mjs` also generates `public/crm/css/ittai-tokens.css`.

**Every display size (1.2.1, Tim, 2026-10-19).** Every screen and mockup is built and checked at 375, 768, 1024 and 1440 px (plus the owner's real screen) before it counts as done: no sideways scroll, no text under 12 px (a scaled diagram's labels move into readable text), and below 1100 px the decisions come first and side-by-side layouts stack. Full rule: the brand book's **Responsive** section.

**AAA, everywhere (1.2.2, Tim, 2026-10-19: "buttons etc are AAA standard").** Every text and control meets WCAG 2.2 AAA in light, dark and field mode:
- **Text contrast 7:1** against whatever it sits on — page, card, sunk panel, selected row, soft chip — including secondary (meta) text, status colours and the tenant green used as text. Large text (18 pt+, or 14 pt+ bold) and focus rings 4.5:1.
- **Filled buttons:** the label is `on-tenant` (white in light/field), never the body text colour. Never set a blanket text colour on a screen or the app root — it overrides button labels (the iPhone's dark-on-green "Snap it", 1.2.2).
- **Targets:** every button, link, switch and row action at least 44 × 44 pt (48 pt on phones, 56 pt for the main action); never icon-only for a primary action — it carries its words.
- **Nothing floats over content:** bars and action buttons sit in their own area (a bottom bar / safe-area inset) so the list scrolls clear; text is never covered.
- **Text sizes** follow the phone's text size (Dynamic Type / rem); no text under 12 pt; containers grow with it rather than clip.
- **Proven, not eyeballed:** `scripts/ittai-contrast.py` (iPhone `ItTokens.swift` or `tokens.json`) and `tests/Unit/Docs/IttaiContrastAaaTest.php` fail on any pairing under AAA. Tenant colours are derived by `derive-tenant.mjs` to 7:1 as fill AND as text on the sunk tone.
- 1.2.2 colour changes (hue kept, lightness only): light meta #56635B→#48534C, attention #84530F→#74490D, progress #2F5E8C→#2A557E, blocked #A12C1F→#982A1D, Mowology green #1B5F3C→#1A5A39; dark meta #A3AFA8→#AFB9B3, green #5AB080→#72C897, blocked #EC8B7D→#ED8E81; field green #1B5F3C→#1A5A39.
