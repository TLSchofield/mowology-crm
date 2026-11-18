# Ittai social tiles — round 2

## What changed

- Replaced the old dark-theme switch with `?variant=navy`. The document remains `data-theme="light"`; only each artboard is re-skinned with light-theme `--brand` and `--page` roles.
- Added the navy wordmark master and an inline, geometry-matched `currentColor` symbol for the navy artboards. Navy secondary copy and empty slots use `--page` treatments.
- Rebuilt tile 04 as three independent 1080 × 1080 carousel artboards plus one 1080 × 1920 story artboard showing slide 1 only.
- Added “Every i dotted. Every t crossed.” to every artboard, retaining tile 07's centred treatment.
- Set patterns to 12% opacity on tiles 01, 03, 05 and 08 only.
- Centred the copy between lockup and footer on tiles 02, 03, 05 and 08.
- Raised the index's light/navy controls to 48 px below 1024 px, fixing the Round 1 machine finding.

## Files

- `index.html` — responsive contact sheet and light/navy variant switch.
- `tiles.css` — shared artboard, typography, pattern, navy, slot and carousel styles.
- `tile-01-office.html` — office square and story.
- `tile-02-quickbooks.html` — QuickBooks square and story.
- `tile-03-penny.html` — Penny square and story with `[FIGURE]` placeholder.
- `tile-04-plant.html` — three square slides and one slide-1 story.
- `tile-05-crew.html` — crew square and story with route line.
- `tile-06-before-after.html` — before/after square and story.
- `tile-07-wordmark.html` — centred lockup and strapline square and story.
- `tile-08-founding.html` — founding-business square and story.
- `NOTES.md` — this handoff.

## Verification and uncertainty

Static checks confirm 18 artboards total, four artboards in tile 04, 18 straplines, patterns on exactly four specified tiles, no literal colour values in the HTML/CSS, no legacy variant hooks, and the required file set. A browser renderer is not installed in this workspace, so I could not produce or visually inspect fresh screenshots here.
