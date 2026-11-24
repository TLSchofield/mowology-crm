# Brief — ittai-social-tiles — Round 2 (revision of round 1; copy previous/ into out/ then change only what is listed)
**Name:** ittai-social-tiles   **Round:** 2
**What is being designed:** The same eight Ittai social tiles (1080×1080 square + 1080×1920 story each), marketing surfaces, Shippori Mincho for the one statement, Inter elsewhere, tokens only.
**Who and where:** unchanged — a field-business owner on a phone, LinkedIn or Instagram, end of day.
**The one decision or action this exists for:** unchanged — stop the thumb, read one sentence.
**States to show:** Light ground and NAVY ground per tile (see fix 1).
**Synthetic content:** unchanged; no figures, no prices, no client names.
**Files wanted in out/:** same file set, plus tile-04-plant.html now holds FOUR artboards: slide-1, slide-2, slide-3 squares (1080×1080 each, class "artboard slide-1" etc.) and ONE story artboard of slide 1 only. Keep index.html and tiles.css. NOTES.md listing what changed.
**Must reuse:** unchanged refs and the frozen symbol.
**Must NOT do:** unchanged, plus: never switch the document to `data-theme="dark"` for the navy variant (see fix 1).

**Round 2+ only — Critique to fix (numbered) / keep as is (numbered):**
Fix:
1. NAVY VARIANT IS BROKEN. `?theme=dark` currently flips the whole token set to dark mode, so the ground renders as the pale dark-mode brand value (#A9B9DA-ish periwinkle) and the dark wordmark master disappears into it (only the gold dots show). Required: the navy variant keeps the LIGHT token set and only re-skins the artboard — `html[data-variant="navy"] .artboard { background: var(--brand); color: var(--page); }` with the document still `data-theme="light"`; sub/meta/strapline text on navy use `var(--page)` (never `--meta`); use refs/ittai-wordmark-dark.svg on navy (its light-blue letters and lighter gold read on navy) and the symbol in `var(--page)` (the symbol asset is an <img>; if it cannot be recoloured, inline the SVG once with `fill="currentColor"` unchanged in geometry). URL switch: `?variant=navy`. Photo/sunk slots on navy: a translucent page-colour fill (`color-mix(in srgb, var(--page) 12%, transparent)`) with a 1 px `var(--page)` 40 % keyline, label in `var(--page)`.
2. tile-04: the three carousel slides are crammed into one square. Make three separate square artboards (one slide each, full-size statement, same lockup and footer as other tiles) and one story artboard of slide 1. Slide 1: statement "What is this plant?" + one large square photo slot (≈55 % of the width). Slide 2: "Ittai knows." + a [NAME] line. Slide 3: "And what it needs this month." + the three-row care list (Water / Prune / Feed) with hairlines, rows ≥ 96 px tall at 1080.
3. Every tile gets the footer strapline "Every i dotted. Every t crossed." in the same position and size as tile-01 (bottom-left, Inter 500, ≈ 30 px at 1080). On tile-07 keep it centred under the lockup as now (no second copy).
4. Patterns are invisible at 8 %: raise to 12 % on the four tiles that have them (01, 03, 05, 08 or whichever four) and keep them off the other four. The route line on tile-05 may stay.
5. Tiles 02, 03, 05, 08: the copy block sits in the upper half with an empty lower half. Centre the copy block vertically between the lockup and the footer (margin:auto already — check it actually centres after the footer is added).
Keep:
6. Statement sizes, line breaks and letter-spacing.
7. Lockup proportion (symbol as tall as the letters) and position.
8. Tile-06 before/after slots and labels; tile-03 [FIGURE] placeholder line; tile-08 copy.
9. Empty sunk slots, no stock imagery.
