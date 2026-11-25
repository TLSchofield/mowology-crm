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
