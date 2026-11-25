# IttaiSymbol

The permanent Ittai mark: six softened sides around one circle. It is monochrome and stable. It shares the AI Office Hexagon's geometry, but it is never coloured by status, never animated and never shows activity.

## Logo vs live visualization

| | Ittai Symbol (logo) | AI Office Hexagon and Office Compact (live) |
| --- | --- | --- |
| Colour | One ink: `brand`, or reversed to `surface` | State tokens per employee |
| Changes | Never | With real backend state |
| Labels | None | Names, icons, counts |
| Where | Wordmark, favicon, app icon, sign-in, marketing, nav tab | Dashboard, AI Office screen, status summaries |

## Rules

- **One colour.** Use `brand` on light grounds, `brand` (dark value) on dark grounds, or `surface` reversed out of a brand field. Never status colours, never two colours, never `tenant` green.
- **Centre.** The ring keeps radius 9 at every size. It is what makes Ittai more than a segmented hexagon. Don't enlarge it.
- **Spokes.** Spokes appear only at 32 px and above. At 16 px the centre becomes a solid disc so it survives as a favicon.
- **Clear space.** Leave one side-gap (about one eighth of the symbol's width) on every side.
- **Orientation.** Never rotate it: Yui's side is always on top.

## Size ladder (v1.1.1)

| Size | Sides (of 64) | Centre | Spokes |
| --- | --- | --- | --- |
| 16 px · favicon, tab | 6, round ends | Solid disc | None |
| 24 px · nav, lockup | 5 | Ring, 4 | None |
| 32 px · small app icon | 4 | Ring, 2.5 | 2 |
| 64 px and up · app icon, sign-in, marketing | **3.5** (was 4, −12.5%) | Ring, 2.5 | **1.3** (was 1.5) |

The 64 px and larger strokes were lightened optically. The geometry is unchanged: vertex radius 27, corner radius 6, ring radius 9, spokes from 12 to 18. The side trim was recalculated for the thinner stroke (`stroke-dasharray="75.34 24.66"`, `stroke-dashoffset="-12.33"`) so the corner gaps stay the same.

## Lockups

- **B, recommended for the permanent identity:** symbol plus the wordmark "Ittai 一体" in Zen Kaku Gothic New 18/500, symbol height 24 px, gap 10 px, no brackets.
- **A, with corner brackets:** kept for comparison. The 「 」 brackets move to the interface as a motif (sign-in, "Held for your decision", marketing headings). They are no longer part of the logo.

## Production checklist

1. Build the final SVGs on whole-pixel grids for 16, 24, 32, 64, 180 (Apple touch icon), 192 and 512 px.
2. Export a monochrome `favicon.svg` plus a PNG fallback, and test both in light and dark browser tabs.
3. Test the app icon on light, dark and tinted home screens at 60 and 180 px.
4. Apply the lockup to the sign-in screen, email signature and invoice header, each using lockup B.
