# AIHandoff

One line showing how work moved through the office: who received it, who owns it, what happened, what happens next, and whether your approval is needed.

## Grammar

`Actor verb → Actor verb → you approve → Actor verb`

- **Completed steps:** `text` at 500.
- **Your step:** "you approve" or "you decide", `text` at 600.
- **Pending steps:** `meta` at 400.
- **Failed step:** `state-blocked` with the octagon, for example "Yui couldn't send".
- **Arrows:** a plain "→" in `meta`, with no arrowheads drawn and no boxes.
- Customer-facing steps end with Yui. Charlie appears only when he prioritised or escalated something.
- Keep it to one line and skip routine relay steps. A handoff that needs more than five steps belongs in the audit history.

## Confirmation suffix

A completed handoff ends with its confirmation state from the backend: "Server confirmed · 9:12", "Recorded · 9:21" or "Couldn't send · Retry". Nothing reads as done without one.

## Truthfulness

Each step must map to a recorded event with an id, and selecting a step opens that audit entry. Never compose a handoff from inferred or expected events; show expected steps as pending.

## Interaction

On desktop, hovering or focusing an employee's name selects their side on the AI Office Hexagon.
