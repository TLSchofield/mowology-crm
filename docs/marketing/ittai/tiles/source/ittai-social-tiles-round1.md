# Brief template (fill every line; delete nothing)
**Name:** ittai-social-tiles   **Round:** 1
**What is being designed:** A sheet of social-post tiles for Ittai's own channels (LinkedIn + Instagram), one HTML file per tile, each tile a fixed 1080×1080 artboard (plus one 1080×1920 story variant per tile) — marketing surfaces, NOT working screens, so the approved marketing layer applies: Shippori Mincho for ONE display statement per tile, Inter 400/500/600 for everything else, sage and one vector pattern allowed as ground at 6–12 % behind copy or 100 % as a plain ground, never behind text at full strength.
**Who and where:** A small field-business owner scrolling LinkedIn or Instagram on a phone at the end of the day. A second ago they were looking at someone else's post.
**The one decision or action this exists for:** Stop the thumb and make them read one sentence about Ittai. Each tile carries exactly one statement and the wordmark lockup; no buttons (social tiles have no controls).
**States to show:** Light ground and navy ground variant of every tile (two files or a data-theme switch); nothing else.
**Synthetic content:** No client names, no prices, no figures. Tile copy (verbatim, sentence case):
 1. hero: "Six specialists. One office." sub: "Ittai is the AI office for a small field business." footer strapline "Every i dotted. Every t crossed."
 2. "Keep QuickBooks. Ittai does the typing." sub: "Snap the receipt. Penny codes it."
 3. "Penny did the books. You checked them." sub: leave a clearly marked [FIGURE] placeholder slot 1 line, meta style — CD fills later.
 4. carousel 3 slides (tenant example, gardening): slide 1 "What is this plant?" with a 1:1 photo slot (empty sunk rectangle labelled "photo"), slide 2 "Ittai knows." + name slot, slide 3 "And what it needs this month." + 3-row care list, rows with hairlines.
 5. "Where is the crew?" sub: "Otto planned the route. You can see it without calling."
 6. before/after: two equal photo slots side by side labelled Before / After in caption-12, statement "Show the work." sub "One tap sends it to the client and to your page."
 7. wordmark tile: lockup only on page ground, strapline under it. Nothing else.
 8. "A few founding businesses." sub: "Message Tim." No number, no price.
**Files wanted in out/:** tile-01-office.html … tile-08-founding.html, each containing the 1080×1080 artboard and the 1080×1920 story artboard stacked, with `data-theme="light"` and `data-theme="dark"` (navy ground = `brand` colour ground with `page`-coloured type and the dark wordmark master) selectable; index.html contact sheet linking all.
**Must reuse:** refs/ittai-wordmark-navy-gold.svg (light), refs/ittai-wordmark-dark.svg (navy ground), refs/ittai-wordmark-navy-mono.svg under 24 px only; assets/symbol/ittai-symbol-64.svg unchanged for the horizontal lockup (symbol as tall as the wordmark letters, gap one stem width); refs/patterns.svg symbols for grounds (one pattern family per tile, at most 4 of the 8 tiles use one).
**Must NOT do:** redraw or typeset the wordmark; use gold anywhere except the wordmark; put a pattern behind body text at full strength; any text under 24 px on a 1080 artboard (it renders at phone width — treat 1080 px as 390 px: minimum type 32 px, statement 80–120 px); stock-photo placeholders (empty sunk slots only); buttons or UI chrome; literal hex outside the marketing-sage declaration; Mowology's green (these are Ittai brand surfaces, not tenant surfaces); the word "ittai" lowercase in running text.
**Round 2+ only — Critique to fix (numbered) / keep as is (numbered):** —
