# Sources for mobile-ui-craft (read 2026-11-25; check again before relying on a number that matters)

- WCAG 2.2 target size minimum (2.5.8 AA = 24×24 CSS px; 2.5.5 AAA = 44×44): https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html
- WCAG 2.2 contrast minimum (4.5:1; large 3:1; large = 18pt or 14pt bold ≈ 24px / 18.5px; no rounding): https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html
- WCAG 2.2 non-text contrast (UI components, focus indicators 3:1): https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html
- MDN env() safe-area-inset: https://developer.mozilla.org/en-US/docs/Web/CSS/env
- iOS Safari zooms on focus below 16px (secondary source): https://css-tricks.com/16px-or-larger-text-prevents-ios-form-zoom/
- Response times 0.1 / 1 / 10 s (Nielsen): https://www.nngroup.com/articles/response-times-3-important-limits/
- prefers-reduced-motion: https://web.dev/articles/prefers-reduced-motion
- Design Motion free pattern library (ideas re-stated in our own words; their video and text are not copied): https://www.designmotionhq.com/patterns — data-table, hover-trap, color-accessibility, optimistic-ui, doherty-threshold, animation-timing, easing-curves, loading-states-system, skeleton-loading, bottom-sheets, focus-states, form-validation-timing.
- NOT fetched (client-rendered pages returned no text): Apple HIG layout (44×44 pt hit target) and Material 3 (48dp target). Both figures are standard knowledge; verify in a browser if a decision hangs on them.

- Motion UI Interface (Instagram reel "iPhone · 6 invisible details", seen 2026-11-25 as screenshots): press feedback 0 ms vs 120 ms; springs damping 0.8 / 1.5 % overshoot; momentum 1000 px glide, rate 0.998; rubber band 200 px pull → 86 px; interruptible (no 180 ms wait); continuous corners. Secondary source, numbers not independently checked.

- Design Motion reel on status columns, seen 2026-11-25 as screenshots ("5/5 fixes"): 12 % tint with teal-300 text; icon per status (Lucide Check / Clock / X); badge 22 px high, 12 px text, name 14 px and louder; label the state not the action; four hues. Secondary source, numbers not independently checked against our apps.

- Design Motion reel on optimistic UI, seen 2026-11-25 as screenshots ("5/5"): optimistic = rarelyFails && canUndo (like / check / reorder yes; Pay $240, Delete forever never); pending at opacity 0.6 → 1 with clock → check; failed: rolled-back state, shake 300 ms, toast "Couldn't save your like" + Retry; retry quietly (retry 2/3, queued: 3, Offline chip). Secondary source, numbers not independently checked.

- Design Motion carousel "Rules like these, checked in your app" (UI geometry, 8 sheets, seen 2026-11-25 as screenshots): ratios 1:1 / 4:3 / 16:9, aspect-ratio reserves the box, object-fit cover never fill, tightest crop 16:9→1:1 loses 43.75 % (keeps 56.25 %), 4:3→1:1 loses 25 %. The arithmetic checks out (9/16 = 56.25 %). Secondary source.

- Design Motion reel "Two colors that match. Then a third one shows up at the same size" (5 slides, seen 2026-11-25 as screenshots): 60/30/10; same-weight third colour (40/30/30) looks cheap; accent count 7 vs 2, three max; tint grays 4 % (#18181B → #16201F); blur test shows three flat zones. Slide 5 ends "Or let Claude Code do it" (a plug for their paid UX Engine). Secondary source, numbers not independently checked.

- Design Motion reels seen 2026-11-25 as Desktop screenshots: "The perfect text input" (6 slides: label outside, 44px/16px, real focus ring 2px+2px offset, border 3:1 at rest with #3F3F46 1.9:1 vs #71717A 4.1:1, errors after blur with aria-describedby, type=email autocomplete=email) and "Six rules every button should follow" (44px tap area, say the action, one primary, same-width loading, visible focus, disabled says why; the five parts focus ring / label / border / fill / shadow). Secondary source, numbers not independently checked; WCAG 1.4.11 itself is a published standard.

- Design Motion reel "Five decisions, one search" (UX Engine plugin promo, 5 decisions, seen 2026-11-25 as Desktop screenshots): full-screen sheet; one step at a time with done steps folded to one line; live count on the Show button (312 / 9 / 4 places); map/list share one pill in the thumb zone; recent searches before typing, zero keystrokes. Secondary source, a product promo.

- Design Motion reel "Five pieces, one first run" (UX Engine promo, seen 2026-11-25 as Desktop screenshots): one question; open on a checklist at 1/3 with step 1 ticked; empty state with one button; one coach mark near the thumb ("past three tips, nobody reads"); skip in a fixed corner, resume with progress kept. Secondary source.

- Design Motion reel "Why does an AI input feel premium?" (5 states, seen 2026-11-25 as Desktop screenshots): focus ring not border swap; suggestion chips before typing; grows with the text and the toolbar stays put; uploads in place, no modal; send becomes stop. Secondary source.
