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

## 13. The Kaname centre line (Tim, 6 Nov 2026 — added to v1.2, shipped in iPhone 1.3.98–1.3.99)
A head's decision screen (Penny's card first) hangs on one thin vertical line down the middle of the page, running
into the active tab's button (Kaname's on his screens). It reads as "this is Kaname at work": every decision leads back to him.
- **The line**: 1 pt, the `line` token (the nav bar hairline), centred. It runs from the first framed panel down to
  the Kaname button and never stops short of it.
- **Comparisons sit on it.** Two things being compared (a cheque and an invoice, two duplicate receipts) take the left
  and right of the line, values hugging it: left column right-aligned, right column left-aligned.
- **Agreement is a ringed tick on the line**: a page-coloured gap that cuts the line, a 1 pt `line` ring, and inside it
  a filled check in `brand` (indigo). A row that differs gets no tick and an `attention` wash on the differing value.
- **Text never sits on the line.** A full-width line of text (bank memo, "Or INV-…") gets a page-coloured background so
  the line breaks around it.
- **Panels are wrapped by a frame of the same weight**: 1 pt `line`, radius 14, page fill. The line passes behind it.
- **A section's icon sits centred on the line** at the top of its frame: filled `brand` circle, white glyph, in the same
  page-coloured gap and thin ring as the ticks, large (56 pt ring), in place of a words heading: Penny's "Your
  business" (building), duplicates (`doc.on.doc.fill`, copies). The heading's words move to the accessibility label.
- **Schedule (phones, 1.3.101)**: the days are one smooth strip that snaps the chosen day onto the Schedule bead's
  axis; the line leaves that day and runs through the stops into the bead. iPad keeps the week grid.
- **One column grid per panel.** Facts, photos and buttons share the panel's edges and one 46 pt gap (the tick ring sits
  in it), so the line (two columns) or the column dividers (three) pass through every row in the same place. No row
  gets its own side padding or width cap.
- **Two square buttons**, the panel's two halves either side of the gap, same size whatever their text: the primary
  filled (`tenant`), the other outlined. With three columns they still take the two halves (two choices, not three).
- **Three or more columns**: the best one (in the books / most data) takes the middle column, on the axis. The axis
  splits round that one receipt — one frame whose two sides run down the gaps, so the ticks sit on it — and rejoins
  below. The side receipts are not framed (ticks always sit on a line, never between frames). Two columns: the axis runs
  from the icon down the gap between them.
- **Two become one.** Once a decision joins things — duplicates merged, a cheque recorded on its invoice — the card
  shows one thing, centred on the axis (the kept receipt; the paid invoice with its cheque), and one way on, centred
  (Unmerge; Next). Unity, not two columns with a status.
- **Order**: the section icon first, then the numbers (a carousel of groups) hanging on the axis below it, then the
  panel. No self-congratulating lines ("I've been right N of N") — they add nothing and cross the axis.
- **Outlined buttons are page-filled**, so the axis passes behind them, never through.
- **Setup headings go once learned.** A "getting to know you" heading shows only while the head is still learning;
  once confirmed, only the framed result stays (Tim: "unnecessary once learned").
- **Desktop**: the same panel lies sideways from 768 px: the comparison on the left, the two squares on the right.
- **The line belongs to the active tab.** It sits on the axis of the tab you are on and runs into that tab's bead at
  12 o'clock: centred on Kaname (his bead is centred), down the left on Schedule, where the day's timeline rail sits on
  the Schedule bead's axis and carries on past the last stop into it (`ItTabSlot`, 1.3.99). Not centred by rule —
  connected by rule.
- **Time view: the hours ride the same axis (Tim, 2026-11-07).** Each hour is a pill like the Clock in pill (tenant
  outline, page fill, tenant numeral) threaded on the Schedule bead's axis; the axis runs up to meet the chosen day in the
  strip and down to the bead, exactly as the numbered timeline does. The hour's grid line leaves from the pill's right.
- **Only where something is in sequence or in pairs**: a timeline (Schedule) or a comparison (Penny's cheques and
  duplicates). Plain lists (Receipts, Search results) stay hairline rows with no line. One line per screen, never two.
- **The money's path (Penny's payment cards, 7 Nov 2026).** Down the line: the number row (the shared Kaname row, as on
  the duplicates), the bank icon (`building.columns.fill`, the money starts on the bank statement), the confidence
  triangle (which replaced Penny's sentence), the panel, the two squares, and the ledger (`text.book.closed`) at the bottom, seated on the panel's bottom edge where the centre line
  crosses it (as the business icon sits on its card's top edge; Tim, 2026-11-07) — an outline
  while the deposit waits, filled brand indigo once it is linked or recorded (in the books, counted once).
- **Confidence is the knowledge triangle, not words.** Low = broken (dashed) outline in `knowledge-discovered`; medium
  = solid `knowledge-understood`; high = `knowledge-mastered` gold with the mastered inner page-colour keyline (so gold
  differs from blue by more than colour). VoiceOver says "High / Medium / Low confidence".
  **Exception, 2026-11-07 (Tim):** the knowledge colours are otherwise "knowledge brain only; never an operational status
  colour" (`tokens.json`). This triangle on Penny's payment cards is the one other use; the rule stands for everything
  else. (`ItTokens.swift` is generated from `tokens.json`, so the note lives here and beside `PaymentConfidenceTriangle`.)
- **Two marks on the line (Tim's drawing, 2026-11-07).** (1) *The fraction*: a triangle on the line, only its left side
  and its base to the line drawn, in the line's own weight and shade, numerals and the joined label in `meta` (what
  they name is already decided; the line colour itself is too pale for text), numerals at title2; the joined count above its left side, the whole (3: invoice, payment, bank line) inside, what is
  joined to its right in small caps — "2⁄3, INV-… + PAYMENT": two steps are done already. Inferred, never written as
  "2 of 3". (2) *Mastery*: the knowledge triangle (gold / blue / broken) in a square box where the line crosses the top
  edge of the panel being matched, as the ledger sits on its bottom edge. Gold: Penny has mastered the parts she offers to
  join. Square, not round: circles on the line are ticks and section heads. The duplicates card has the mastery box only.
  The section icon (bank, copies) heads the section, above the number row.
- **Linked (Tim, 2026-11-07).** The panel is page-filled: the line stops at its top edge and comes back at the ledger.
  Inside, one filled brand-indigo block, wide (not the square-button shape), not tappable: link icon, invoice number(s),
  payer, "$409.50 e-transfer", text in `page` (indigo turns pale in dark mode). Over 10 invoices: the count and the first
  three. No Next, no Undo: the cards are a swipe-only carousel (swipe left for the next; its edge peeks in the right
  margin; past the last, on to the first unanswered card or receipts; VoiceOver "Next payment"). The filled ledger is the
  unlink: indigo ring once linked, a confirm sheet ("Take $X out of the books? It unlinks from N invoices and goes back to
  waiting." Unlink in `status-blocked` / Keep, the full list when long).


## 14. The meniscus tab bar (Tim, 2026-11-11 — the one bottom bar of every native app; shipped iPhone 1.3.130, Android 2.2.11)
The active tab lifts out of the bar as a **bead** in a notch cut into the bar's top edge, and slides between tabs. iPhone and Android draw the same bar from the same numbers: `tokens.json` → `meniscus` (generated into `ItMeniscusToken` in `ItTokens.swift`). **Change a number there, never in a platform file.** Android holds the same numbers today as constants in `MeniscusGeometry.kt` (verified equal 2026-11-11); `tests/Unit/Docs/MeniscusTokensTest.php` fails when either platform drifts from the tokens, and Android moves to generated Kotlin tokens (`ittai-kotlin-tokens.mjs`) in its own refactor. The behaviours below are the iPhone's as audited 2026-11-11; the Android audit confirms each or lists the gap.

- **Anatomy (top to bottom).** The bead (50) sits `rise` (22) above the bar's top edge, ringed by `gap` (6) of page colour; the notch is a cup under it that rejoins the straight edge through two shoulder curves (`shoulder` 14, handing over to the arc at `join-angle` 80°); the bar body is `bar-height` (58) tall. Each slot is bar-width ÷ tab count wide (3 to 6 tabs are supported; production uses 5): an icon box (`icon` 22, a larger icon such as Kaname's hexagon grows upward out of it) starting `icon-top` (14) below the edge, then the label (`label-size` 11, semibold when selected, one line) `icon-label-gap` (3) under it. The selected tab's icon rides in the bead (`bead-glyph` 24); its slot icon fades (`icon-fade` 0.18 s) so the collar never shows two. The bar fills under the home indicator; screens reserve `rise + bar-height` at the bottom.
- **Motion.** One spring, in slots: `spring-response` 0.38 s, `spring-damping` 0.78, landed when closer than `spring-rest` 0.002 slot, never longer than `spring-cap` 1.5 s, landing exactly. It is **sampled** at `sample-rate` 120 per second and handed whole to the platform animator (Core Animation keyframes on iPhone; the Android surface view), so a busy screen cannot stall a slide that has started. Bead first, screen second: the bead leaves on the tap and the destination screen is shown shortly after (not after the landing): one turn of the main loop on iPhone; on Android two frames of the main Choreographer, because the bead runs on its own thread and the delay keeps the new screen's heavy first composition off the bead's first frames (a platform difference, kept). A tap while sliding retargets from the current position and carries the current velocity into the new spring. Icons change with position: over the last `action-morph` (0.33) of a slot the selected tab's icon turns into its action (Receipts: the camera); the Add tab's + fades in over the first `roll-fade` (0.25) slot of travel toward it and rolls one full turn per slot travelled, ending at 45° (×); below `still-epsilon` (0.01) slot of travel the bead counts as still.
- **Behaviour.** Tap = select. Reselecting the selected tab runs its action (iPhone: Schedule cycles its view, Receipts opens the camera, Kaname returns home, Add closes the dial) but only once the bead is within `landing` (0.25) slot of it; a tap on the destination during a slide is ignored until then, and the raised target stays at the destination so a tap there never falls through to the screen. Long press (`long-press` 0.45 s) on a tab that has a long-press action; the release that follows a long press is not a tap. Add is an action, not a page: it opens the dial of creation actions above the bead.
- **Reduce Motion.** No slide and no roll: the bead jumps and the bar cross-fades (0.18 s); the Add dial changes without travel. The meaning never depends on motion.
- **Accessibility contract (every platform).** Each slot is a real button: label = the visible title, value = "Tab N of M" in the user's reading order, selected state announced, "Tap again: …" hint where reselect has an action, the long-press action also offered as a named accessibility action, and the bar exposes no gesture-only path. Large text: the labels are the system tab bar's fixed small size, and the platform's large-content viewer (iPhone Large Content Viewer, Android TalkBack) carries title and icon; an accessibility-size layout is an open decision (backlog). Hit targets at least 48; pressed slot drops to `pressed-opacity` 0.55.
- **Right to left.** Slot order mirrors; slots are addressed by a logical index and mapped to a physical one in one place (iPhone `ItMeniscusGeometry.physicalSlotIndex`); a tab's identity is never used as its position. The app ships English only today.
- **Tabs.** Admin: Schedule, Search, Kaname, Receipts, Add. Crew: Schedule, Search, Receipts, Account, Add. Time Clock is a page, not a slot (the bead stays on the last real tab). Do not disable or hide a tab when its content is empty; explain on the page.
- **Platform differences that are allowed:** haptics (iPhone's Schedule reselect only), the keyboard (iPhone removes the bar while a keyboard overlaps it), the platform's animator, the screen-switch delay above. Android also keeps a classic Compose bar behind a remote switch as a fallback. **Not allowed:** any number in the list above, the bead-first rule, the retarget rule, the accessibility contract.
- **Where it lives.** iPhone: `Core/Design/Ittai/ItMeniscusBar.swift` (SwiftUI + hosting), `ItMeniscusGeometry.swift` (pure maths, `ios/LogicTests/MeniscusGeometryTests.swift`), `ItMeniscusMotion.swift` (Core Animation layers), `App/AddDial.swift`, `App/MainTabView.swift`. Android: `ui/ittai/ItMeniscusBar.kt`, `MeniscusGeometry.kt`, `MeniscusSurfaceView.kt`. Web: no meniscus; the phone menu (`mobile-nav.php`) carries the same tab names and order and must follow section 14's tab list. Smoothness proof: `-mwBarDemo stall` and `ios/tools/bar-smoothness.py` (longest freeze 16 ms at the 2026-11-11 baseline; two frames is the gate).
- **Parity checklist when either platform changes the bar:** tokens first; the numbers test; the iPhone logic tests (3 to 6 tabs, LTR/RTL, spring boundaries); the stall demo; screenshot of admin, add, crew and receipts demos pixel-identical unless the change is meant to be visible; the same change or an explicit "not yet" in the backlog for the other platform.
- **Not decided yet (backlog, each needs Tim):** label scaling with Dynamic Type and an accessibility-size layout, the Add dial scrolling in landscape, the bar following the keyboard's frame and curve, enforcing tenant colour contrast in the bar, a haptic rule, adopting the iOS 26 floating bar.
