# Accessibility

Target: WCAG 2.2 AA throughout, with AAA (7:1) for primary text.

## Contrast (measured)

| Token | On surface · light | On surface · dark |
| --- | --- | --- |
| `text` | 17.3:1 | 14.5:1 |
| `meta` | 6.3:1 | 7.5:1 |
| `state-working` / `focus` | 6.8:1 | 7.8:1 |
| `state-approval` | 6.5:1 | 7.9:1 |
| `state-blocked` | 7.3:1 | 6.9:1 |
| `tenant` (outlined action) | 7.6:1 | 6.4:1 |
| `on-tenant` on `tenant` | 7.6:1 | 7.0:1 |
| `brand` (wordmark) | 11.4:1 | 8.6:1 |

Every text and status token stays above 5.2:1 on `selected` as well, so highlighted rows remain readable. `line` is decorative only: the spokes and dividers never carry information that is not also in text.

## Never colour alone

Each state combines four signals: colour, line style (solid, dotted or 1 px), an icon shape (clock, triangle, octagon, dashed circle, slashed circle) and a word. Idle and Unavailable share a colour but differ in line style and icon. Waiting for approval and Blocked differ in lightness as well as hue.

## Keyboard

- Visible 2 px `focus` ring, offset 2 px, on every interactive element, including the SVG sides.
- Hexagon: one tab stop with roving focus. Arrows go around, Home returns to Charlie, Enter or Space opens (see Interactions).
- Workspaces trap focus while open and return focus on close. Escape always closes.
- Skip link to Needs your attention.

## Screen readers

- The hexagon is `role="group"` named "AI office", and each side and the centre is a link. Example accessible name: "Sam, Sales, waiting for approval: follow-up for Bob Birch. Opens Sam's workspace."
- Decorative SVG (spokes, glyph icons inside labelled text) is `aria-hidden`.
- The seven rows are the full text equivalent of the map and are read in the same order.
- State changes after an approval are announced through a polite live region: "Approved. Yui is sending the reminder." Then: "Server confirmed at 9:21."
- The Compact glyph has a text label summarising the counts.

## Motion

Only opacity, stroke width and background change, over 150 ms. Under `prefers-reduced-motion: reduce` everything is instant. Nothing pulses, spins or animates to imply activity.

## Touch and outdoors

- Targets are 48 px on phones and 64 px for the crew's main action.
- Phone body text is 16 px minimum.
- Surfaces are opaque.
- The hexagon is not used on phones.

## Text size

The layout must hold at 200% browser zoom and with iOS Dynamic Type at its largest accessibility size on phones: rows wrap, nothing truncates the state word, and the hexagon labels keep their positions.

## Test checklist

- axe-core: zero serious or critical issues on the dashboard, every workspace and the audit view, in both themes.
- Keyboard-only walkthrough: select each department, open each workspace, approve, snooze, open the audit and return.
- VoiceOver (iOS and macOS) and NVDA (Windows): the same walkthrough, checking names, order and announcements.
- Contrast re-checked whenever a token changes.
- Reduced motion, 200% zoom, the largest Dynamic Type size, and forced colours (Windows High Contrast).
