# AIEmployee

One AI employee as a row: portrait with state ring, name, AI tag, department, current assignment, the pending item and the operational state. Seven rows in fixed order form the text equivalent of the AI Office Hexagon.

## Anatomy

- **Portrait:** a 36 px disc in `sunk` with the initial (12/600), inside a 2 px state ring. The ring is solid for Working, Waiting for approval and Blocked, dashed for Idle and dotted for Unavailable. Use 40 px on phones and 44 px in workspace headers.
- **Name:** body-14 at 600. The **AI** tag is caption-12 inside a 1 px `line` border with `radius-sm`. It is mandatory on every AI employee.
- **Department and assignment:** meta-13. The assignment is a single line with an ellipsis; its full text goes in the accessible name.
- **Right column:** the pending item (13/500, tabular) above the OperationalState.

## Order

Charlie, Yui, Sam, Penny, Otto, Mia, Ada: the centre first, then clockwise from the top.

## Behaviour

- The whole row is one link to the employee's workspace.
- Hovering or focusing the row selects the matching hexagon side.
- Rows are 48 px tall on desktop and 64 px on phones, separated by hairlines. They are rows, not cards.

## Accessible name

"Penny, AI, Finance. Waiting for approval: reminder for INV-2026-0233, $546. Opens Penny's workspace."
