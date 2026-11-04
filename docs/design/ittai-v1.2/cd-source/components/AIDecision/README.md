# AIDecision

A decision the office is holding for the owner. Each one answers four questions: what happened, why it matters, what Ittai recommends, and what you are approving.

## Anatomy

1. **Owner glyph:** a 20 px hexagon with the owning employee's side drawn in `text`. A centre dot means Charlie escalated it.
2. **What happened:** heading-16, for example "INV-2026-0233 · $546 is 21 days overdue".
3. **Why it matters:** state icon and word plus one meta-13 clause, for example "Overdue · adds to $2,953 overdue; first reminder unanswered".
4. **Recommendation and approval:** an AIHandoff line with **you approve** at 600, for example "Penny drafted reminder → **you approve** → Yui sends".
5. **Actions:** one dominant action and "Not now". The highest-ranked urgent decision fills its action with `tenant` and `on-tenant`; lower-ranked decisions use outlined `tenant`. Never two filled buttons in one decision.

## Lifecycle

| Stage | Display |
| --- | --- |
| Proposed | As above |
| Approving | Button disabled, label "Approving…" |
| Approved | Line: "Approved by you · 9:21" (recorded by the backend) |
| In progress | Cloud-sending icon, "Yui is sending…" |
| Completed | Leaves the list and appears in Autonomous activity as "Server confirmed · 9:21" |
| Failed | Octagon "Couldn't send", the reason, and Retry. Stays in the list. |

"Not now" asks nothing by default: it snoozes until tomorrow at 8:00 and records the snooze in the audit. The decision returns at that time with "Snoozed once".

## Ranking

Decisions are ordered by consequence (money at risk, customer impact, safety, time sensitivity) as computed by Charlie. The rank never changes while the owner is mid-action.

## Accessibility

- Each decision is an `article` with its heading.
- Button names include the object, for example "Send reminder for INV-2026-0233".
- Changes after approval are announced in a polite live region.
