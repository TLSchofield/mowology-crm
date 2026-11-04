# Ittai Design System v1.2 — merge spec (Claude, 17 Oct 2026, from Tim's merge instruction)

**Base:** Claude Design's v1.1.1 system in `cd-source/` (README, Interactions, Accessibility, tokens, components). Keep its Six + One organisation, the permanent Ittai Symbol, the live AI Office Hexagon, its components, typography, handoff presentation and its SHARED status icon vocabulary (Option A). **Do not redesign the approved brand geometry** (hexagon, symbol, size ladder, lockup B). **Do not add AI departments**: exactly Charlie (centre) + Yui, Sam, Penny, Otto, Mia, Ada.

**Restored from Ittai v1.1:** field mode, offline/sync states, tenant colour generation + contrast testing, approval and communication lifecycles, accessibility/regression tests.

## 1. Tokens
`tokens/tokens.json` (Design System format) now has three themes: light, dark, **field**. `tools/build-tokens.mjs` builds `prototype/tokens.css` with precedence **field > explicit `data-theme` > OS > light** (`:root[data-mode="field"]`; dark rules carry `:not([data-mode="field"])`). Variables are `--page --surface --sunk --selected --line --text --meta --brand --status-* --state-* --focus --sync-* --tenant --on-tenant --tenant-mark --tenant-soft`.
**Tenant colours are never hand-written.** They are generated from `tokens/tenant.config.json` brand inputs (+ an optional checked pin) into `prototype/tenants.generated.json`. The prototype's tenant switcher applies a tenant by setting its generated values as custom properties on `<html>` (the one allowed dynamic inline style).

## 2. Two state vocabularies, one icon set (correction 4)
Icons are shared (clock, triangle, octagon, dashed circle, slashed circle; cloud family for sync), meanings are not:
| | Business task (a decision, a job, an invoice, a communication) | AI employee |
|---|---|---|
| Data field | `task.status` | `employee.state` |
| Words | Needs your approval · Approval saved on phone · Approved · Sending · Sent · Couldn't send · Not sent: no longer needed · Snoozed · Declined | Working · Waiting for approval · Blocked · Idle · Unavailable |
| DOM | `data-kind="task-status"` | `data-kind="employee-state"` |
| Accessible name prefix | "Task: …" | "Employee state: …" |
A task status and an employee state are never rendered in the same chip, and an employee state is never derived from a single task (it comes from the employee record).

## 3. AI Office Hexagon hit targets (corrections 1–3)
Keep the drawn geometry exactly (`cd-source/components/AIOfficeHexagon/README.md`). Replace the 28 px invisible-stroke hit paths with **wedge hit regions**: for each side, the annular sector between radius 36 (just outside Charlie's circle) and radius 102 (beyond its label), spanning the side's 60° (±30° from its normal). The six wedges + the centre circle (radius 34, its own link) tile the hexagon with **no overlap**, so a pointer can only ever resolve to one employee. Draw the hit regions as transparent `<path>`s inside each side's link. At phone size (the AI Office screen, hexagon scaled to 340 px wide) every wedge and the centre contain a ≥ 48 × 48 px square; at desktop (232 px) ≥ 44 × 44 px — if 232 px cannot give 44 px for the centre, scale the desktop hexagon up (geometry unchanged, uniform scale) until it does.
Keyboard (from cd Interactions §1): one tab stop, roving focus, → ↓ clockwise, ← ↑ anticlockwise, Home = Charlie, Enter/Space opens, Escape closes the workspace and returns focus. Visible 2 px `--focus` ring on the focused side (stroke the wedge outline) and centre. Accessible names as cd specifies ("Sam, Sales, Employee state: waiting for approval: follow-up for Bob Birch. Opens Sam's workspace.").

## 4. Symbol (correction 5)
`IttaiSymbol` takes NO state input; it renders only in `currentColor` (`--brand` or `--surface` reversed). It must never reference `--state-*`, `--status-*`, `--sync-*` or `--tenant*`.

## 5. Decisions (correction 7)
Remove the 20 px owner glyph from AIDecision: it repeats what the handoff line says. Keep: what happened, why it matters (task status icon + word + clause), handoff line with **you approve**, one dominant action + Not now.

## 6. Approval lifecycle (with offline) — corrections 9–11
`Proposed (Needs your approval) → [online] Approving… → Approved (recorded) → Sending/Executing → Sent (server confirmed) | Couldn't send`
`Proposed → [offline] Approval saved on phone · pending → [reconnect] Checking… → (revalidated) Approved → … | Not sent: no longer needed (reason) | Not approved: you no longer have permission`
- **Never show Approved, Sending or Sent until the (mock) server has recorded it.** Offline approvals stay visibly pending (sync-saved cloud + "Approval saved on phone · will send when online"), in the decision, the Inbox and the AI Office alike.
- **Revalidate before executing a queued approval** (server side): the approver still has the permission key (`approve.client_comms` / `approve.money`), the task is still in the state it was approved in (version match), the recipient still exists and is reachable (email/phone on file), and the business condition still holds (e.g. the invoice is still unpaid). Any failure → the task becomes "Not sent: no longer needed · <reason>" (or the permission message), nothing is transmitted, and the audit records the rejection.
- **Idempotency:** every approval carries an idempotency key generated once when the person taps (stored with the queued item). The server executes a key at most once; replays, double taps, reconnect retries and a retry after a timeout all return the original result. The UI disables the dominant button while in flight.
- **Failed sends:** "Couldn't send · <reason>" (octagon), stays in Needs your attention with Retry; Retry reuses the SAME idempotency key for the same content (a changed recipient = a new approval).
- Authorization levels A0–A3 (v1.1) apply; customer-facing sends are A2.

## 7. Communication lifecycle and Yui (correction 12)
`Received → Interpreted → Routed → Awaiting approval → Sending → Sent → Resolved`, plus `Failed to send` and `Snoozed` (cd README). **Yui owns** every communication record: coordination, history and accountability. **Specialist services perform transmission** (Email service, SMS gateway): the record shows "Yui · sent via SMS gateway · 9:21" and the audit names both. A specialist employee (Penny, Sam, Otto…) drafts or proposes; Yui sends.

## 8. Shared state
Dashboard (Needs your attention, AI office, Activity), Inbox (Yui's communications command centre) and AI Office screen read ONE store. Approving, failing or revalidating anything updates all three at once.

## 9. Synthetic isolation (correction 13)
The prototype is self-contained: an in-page mock server with latency, an online/offline switch and failure injection. **No fetch, XHR, WebSocket, sendBeacon, mailto or tel actions**; the only external requests allowed are Google Fonts. A persistent banner: "Prototype · synthetic data · nothing is sent". All names synthetic (customers: Alder & Pine Strata, Bob Birch, Harbourview Dental, R. Okafor, Cedar Court Strata).

## 10. Prototype screens (desktop 1440, tablet 768, phone 390)
- **Dashboard** (cd README layout): Charlie's brief, business pulse, Needs your attention (decisions), AI office (hexagon + seven rows with each employee's most important item), Autonomous activity.
- **Inbox** (Yui's command centre, cd Interactions §6): Requires attention / Live / History, each row customer · channel · time · owner · Yui's interpretation · next action.
- **AI Office** (phone: dedicated screen with the full hexagon ≤ 340 px + seven rows; desktop: the workspace sheet when a side is opened).
- Phone uses cd's 5-tab model (Home, Jobs, Inbox, AI Office, More). The crew app is out of scope here.
- Theme controls (light / dark / field) and a tenant switcher (Mowology / Brightline / Clearflow) in a small dev panel.

## 11. Test contract (the tests use ONLY these hooks)
`window.__ittai` = {
  `store: { get() }` → `{ online, decisions:[{id, status, version, idempotencyKey?}], employees:[{id, state}], comms:[{id, status, owner, via?}], activity:[…], audit:[{ts, actor, action, object, result, key?}], queue:[…] }`,
  `server: { setOnline(bool), failNext(channel, reason), payInvoice(id), revokePermission(key), removeRecipientContact(customer), editTask(id) (bumps the task version: 'changed after approval'), transmissions() → [{key, channel, to, ts}], latency(ms) }`,
  `ui: { go(view) }` (view: 'dashboard'|'inbox'|'office'),
  `tenant(id)`, `theme(light|dark|field)` }.
`data-testid`: `decision-<id>`, `decision-<id>-primary`, `decision-<id>-notnow`, `decision-<id>-status`, `hex`, `hex-side-<emp>`, `hex-centre`, `row-<emp>`, `employee-state-<emp>`, `comm-<id>`, `comm-<id>-status`, `sync-banner`, `live-region`, `workspace`, `workspace-close`, `tab-<name>`, `ittai-symbol` (the permanent symbol; added after T06 found it by position).
Seed: decisions d1 INV-2026-0233 ($546, 21 days, Penny drafted reminder → you approve → Yui sends, email), d2 Bob Birch QUO-2026-0002 ($1,407, Sam drafted follow-up → you approve → Yui sends, email), d3 Cedar Crew 18 min late at 2140 Larch St (Otto detected → Charlie escalated → you approve → Yui notifies, SMS), d4 Truck 04 no GPS (Ada; internal, A0, "Open diagnosis").

## 12. Canonical status codes (added after the first test run — the store holds CODES, the screen shows WORDS)
The first run showed the spec was ambiguous ("status"). From now: `store.get()` returns codes; the DOM shows the words; tests compare codes in the
store and words on screen. One table, used by both.
| Task code | Words on screen (prefixed "Task: " in accessible names) |
|---|---|
| `proposed` | Needs your approval |
| `attention` | Needs attention (internal items such as Truck 04: never an employee word like "Blocked") |
| `approving` | Approving… |
| `pending` | Approval saved on phone · will send when online |
| `checking` | Checking… (revalidating a queued approval after reconnect) |
| `approved` | Approved · recorded |
| `sending` | Sending… |
| `sent` | Sent · server confirmed |
| `failed` | Couldn't send · <reason> |
| `no-longer-needed` | Not sent: no longer needed · <reason> |
| `permission-denied` | Not approved: you no longer have permission |
| `snoozed` | Snoozed until <time> |
| `declined` | Declined |
| Communication code (Yui) | `received` `interpreted` `routed` `awaiting-approval` `sending` `sent` `resolved` `failed` `snoozed` (+ `pending` while an approval is saved on the phone) |
| Employee state code | `working` `approval` `blocked` `idle` `unavailable` → Working · Waiting for approval · Blocked · Idle · Unavailable |
A pending (offline) approval shows its pending words on ALL three surfaces: the decision, the Inbox communication and the owning employee's AI Office row
("Approval saved on phone"). The tenant check compares against the GENERATED value of each tenant role: a generated colour may equal the raw input
(Brightline's dark primary IS its brand #F2C200) — what is forbidden is a raw input used in a role that was not generated for it.
