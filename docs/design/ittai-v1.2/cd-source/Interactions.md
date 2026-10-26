# Interactions

How the AI Office behaves. Every interaction has a mouse, keyboard, touch and screen-reader path, and nothing is reachable by hover alone.

## 1. Selecting a department

Selection highlights one employee across the office: their hexagon side, their spoke and their row.

| Input | Selects | Clears |
| --- | --- | --- |
| Mouse | Hover a side, its label, the centre or a row | Pointer leaves |
| Keyboard | Focus moves onto a side, the centre or a row | Focus leaves |
| Touch | Finger down on a side, the centre or a row (press state) | Finger up |

**Visual result.** The selected side goes to `stroke-side-selected` (5 px). The other five drop to 25% opacity, and its spoke turns `text` at 1.5 px. The matching row takes the `selected` background. Selecting the centre (Charlie) keeps all six sides at full strength, turns all six spokes `text` and thickens Charlie's circle to 2.5 px. Changes last 150 ms and are instant under reduced motion.

**Keyboard model.** The hexagon is one tab stop with a roving focus:

- Tab into the hexagon and focus lands on Charlie (or the last selected position).
- → or ↓ moves clockwise (Yui → Sam → Penny → Otto → Mia → Ada).
- ← or ↑ moves anticlockwise.
- Home returns to Charlie.
- Enter or Space opens the focused workspace.

The seven rows below are ordinary links in the same order, so the list is a complete alternative to the map.

## 2. Opening a workspace

Click, tap, Enter or Space on a side, a label or a row opens that employee's workspace. The centre opens Charlie's.

- **Desktop and tablet:** a 560 px sheet from the right with `shadow-dialog`. The dashboard stays visible and the hexagon keeps the open employee selected.
- **Phone:** a full-screen view with a back button.
- The address changes (`/office/penny`), so Back closes the workspace and a link can be shared.
- Focus moves to the workspace title. Escape closes it and returns focus to the side or row that opened it.

**Every employee workspace** has the same order:

1. Header: portrait with state ring, name, AI tag, department, state word.
2. Pending approvals: AI Decision components, highest consequence first.
3. Current assignments.
4. Verified handoffs.
5. Recent actions, each with its confirmation state.
6. A link to the full audit history filtered to this employee.

**Charlie, Chief of Staff:** business-wide priorities (ranked), open escalations, coordination in flight (handoffs currently between employees) and the briefing history.

**Yui, Communications intake:**

- Incoming messages by channel (email, SMS, phone, website, portal), each with sender, intent and urgency.
- Routed conversations, showing who now owns each one.
- Unresolved communications.
- Customer communication history.

## 3. Handoffs

Handoffs appear wherever they explain a decision or a result: on each decision, in workspaces and in Autonomous activity.

- Every displayed step corresponds to a recorded event; each step opens its audit entry.
- A handoff answers five questions: who received the work, who owns it, what happened, what happens next, and whether your approval is required.
- Customer-facing steps always end with Yui ("Yui sends", "Yui notifies").
- Hovering or focusing an employee name in a handoff selects that employee on the hexagon (desktop).
- Keep to one line. No flowcharts.

## 4. Approval workflow

| Step | What the owner sees | Backend truth required |
| --- | --- | --- |
| Proposed | Decision in Needs your attention, employee state Waiting for approval | Draft recorded |
| Approving | Dominant button disabled, "Approving…" | Request in flight |
| Approved | "Approved by you · 9:21" | Approval recorded |
| In progress | Cloud-sending icon, "Sending…" | Job queued |
| Completed | Moves to Autonomous activity, "Server confirmed · 9:21" | Delivery confirmed |
| Failed | Octagon "Couldn't send", reason, Retry; returns to Needs your attention | Failure recorded |

- **Not now** snoozes the decision until a stated time (default tomorrow 8:00) and records that in the audit. It never discards.
- **One dominant action per decision.** The highest-ranked urgent decision is filled; others are outlined. There are never two filled buttons in one decision.
- **Undo** appears only while an action is genuinely reversible (for example, a deposit match). Messages already delivered to customers show View only.

## 5. Audit

Every event records actor, action, object, time, confirmation state and source system. The full audit history is reachable from Autonomous activity and from every workspace. It is read-only and filterable by employee, state and date.

## 6. Yui's communications command centre

Yui's workspace answers a different question from the dashboard. The dashboard asks "what does the owner need to decide?" Yui asks "what is everyone telling the business, and what are we doing about it?"

- **Three sections:**
  - **Requires attention:** failed sends, urgent, unrouted, and anything waiting on your approval, ranked in that order.
  - **Live communications:** routed and moving with a specialist.
  - **Communication history:** sent and resolved, with search across everything.
- **Each row** shows the customer, channel, time and owner, Yui's one-line interpretation, and the next action. These come before any raw metadata.
- **Selecting a communication** opens its detail: original message, Yui's assessment (intent, urgency and why, tone, confidence), customer context, the proposed response with its one dominant action, the assigned specialist and routing history, and the audit trail. Back or Escape returns to the list with focus on the same row.
- **The table view** is kept as an option for scanning every field at once.

## 7. Touch and field rules

- Hit targets: 44 px on desktop and 48 px on phones. Each hexagon side has a 28 px invisible stroke plus its label as its hit area, and the centre circle is 68 px.
- No long-press or hover-only actions.
- Phones use the list, not the hexagon.
