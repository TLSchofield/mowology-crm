# Ittai Design System v1.2 — source tokens

Frozen baseline, approved by Tim on 2026-10-17. The brand book is the Claude Design artifact https://claude.ai/artifact/YDZaYnmRc8CAbqtz8oC5uk.

- `tokens.json`: every colour (light / dark / field), type style, spacing, radius and stroke. This is the source.
- `tenant.config.json` holds tenant brand inputs, and `tenants.generated.json` holds the generated, contrast-checked tenant colours. Never hand-write a tenant hex.
- `scripts/ittai-swift-tokens.mjs` generates `ios/MowologyCRM/MowologyCRM/Core/Design/Ittai/ItTokens.swift` from these files. Re-run it after any token change; don't edit the Swift by hand.
