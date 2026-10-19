# Ittai Design System v1.2 — source tokens

Frozen baseline, approved by Tim on 2026-10-17. The brand book is the Claude Design artifact https://claude.ai/artifact/YDZaYnmRc8CAbqtz8oC5uk.

- `tokens.json`: every colour (light / dark / field), type style, spacing, radius and stroke. This is the source.
- `tenant.config.json` holds tenant brand inputs, and `tenants.generated.json` holds the generated, contrast-checked tenant colours. Never hand-write a tenant hex.
- `scripts/ittai-swift-tokens.mjs` generates `ios/MowologyCRM/MowologyCRM/Core/Design/Ittai/ItTokens.swift` from these files. Re-run it after any token change; don't edit the Swift by hand.
- `scripts/ittai-css-tokens.mjs` generates `public/crm/css/ittai-tokens.css` (the web `--it-*` tokens, scoped to `.it-design`) from the same files.

**Every display size (1.2.1, Tim, 2026-10-19).** Every screen and mockup is built and checked at 375, 768, 1024 and 1440 px (plus the owner's real screen) before it counts as done: no sideways scroll, no text under 12 px (a scaled diagram's labels move into readable text), and below 1100 px the decisions come first and side-by-side layouts stack. Full rule: the brand book's **Responsive** section.
