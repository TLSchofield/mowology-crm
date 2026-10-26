# OfficeCompact

A small live status glyph for the AI office: six state-coloured sides and Charlie's ring. Use it in phone headers, navigation and summary panels.

## Spec

- Sizes: `hex-compact` (32 px), or 20 px inline with text.
- Built on a 32 unit grid: vertex radius 13, corner radius 3, sides 2.5 units with round caps (`pathLength="100"`, dasharray `64.6 35.4`, offset `-17.7`), and Charlie's ring at radius 5 (1.5 units).
- No labels and no spokes. Idle sides are dotted (`0.01 21.52`), and Unavailable sides drop to 1 unit.
- Positions are the same as the Full hexagon: Yui on top, then clockwise.

## Accessibility

The glyph never stands alone. As a button it carries an accessible name with the counts ("Office: 3 need approval, 2 working, 1 blocked, 1 idle"). In a panel it sits beside the visible count text.

## Behaviour

Tapping it opens the AI Office view (the list on phones).

## Don't

- Don't use it as the logo; use IttaiSymbol.
- Don't draw it below 20 px.
