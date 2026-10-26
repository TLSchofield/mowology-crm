# Ittai Design System

**Version 1.1 · Kanso** — the design language of Ittai (一体, "one body"), an AI office for small field-service businesses. Seven AI employees run the office; the owner approves consequential decisions; crews do the work outdoors. Mowology is the first tenant.

> Your business has an office, even when you're out working.

## What changed in 1.1

- **Seven employees, not six.** Charlie moves to the centre as Chief of Staff; Yui (Communications & Customer Relations) joins at the top.
- **Six + One replaces the circular ring.** The AI Office is a hexagon of six specialist sides around Charlie's centre circle, joined by six hairline spokes.
- **Approved form: Variation C (Ittai unity)** with Variation B's softened side ends: each side wraps 12 px into its corners and ends round.
- **Live vs stable.** The live operational visualization (Full, Compact) is now separate from the stable monochrome **Ittai symbol**, which never reflects activity.
- **Five operational states** replace three: Working, Waiting for approval, Blocked, Idle, Unavailable.
- **Yui on every customer-facing handoff.** Anything that leaves the business is sent by Yui.
- New components: AI Office Hexagon, Office Compact, Ittai Symbol, Operational State, AI Employee, AI Decision, AI Handoff.

**1.1.1 refinements**
- **Symbol strokes:** sides are 12.5% lighter at 64 px and above, and spokes go from 1.5 to 1.3. Geometry and the centre ring are unchanged.
- **Lockup:** the permanent lockup is the symbol plus "Ittai 一体" with no brackets. Corner brackets become an interface motif only.
- **Communication lifecycle:** Yui's communications have their own lifecycle, separate from the employees' operational states.
- **Mobile:** phones get their own interaction model, with a dedicated AI Office screen that shows the full hexagon.

## Principles

1. **Clarity first, originality second, decoration last.** About 70% Linear-like precision (type, hierarchy, hairlines, restraint), 20% Japanese minimalism, 10% Ittai identity.
2. **Ma.** Space carries structure. Rows are grouped by space and separated by `line` hairlines; no border around every section, no coloured stripes down card edges.
3. **Kanso.** Remove what does not earn its place. One dominant action per decision.
4. **Ittai.** Office and field are one system: the same states, the same names, the same handoff grammar on desktop and phone.
5. **Honest AI.** Nothing reads "sent", "done" or "approved" until the backend has recorded it. Every AI employee is visibly marked **AI**.

## Content fundamentals

- **Sentence case** everywhere. No bold all-caps labels.
- **Plain operational language**: "Truck 04 has no GPS since yesterday 4:42 PM", not "Telematics anomaly detected".
- **Tabular numerals** for money, times, job numbers and counts.
- **Corner brackets 「 」** are an interface motif. They frame sign-in, marketing headings and the "Held for your decision" label, drawn as two 1.5 px L-shapes, never typed and never decorative. They are not part of the permanent logo lockup.
- **Handoff grammar**: `Actor verb → you approve → Actor verb`. Completed steps in `text` at 500; your step in `text` at 600; pending steps in `meta`. Examples: "Penny drafted reminder → **you approve** → Yui sends"; "Otto detected → Charlie escalated → **you approve** → Yui notifies".
- **Confirmation states** in that order: Proposed · Approved (recorded) · In progress · Server confirmed · Failed. Undo appears only when the action can actually be reversed.

## Colour roles

| Role | Tokens | Rule |
| --- | --- | --- |
| Neutrals | `page`, `surface`, `sunk`, `selected`, `line`, `text`, `meta` | Faint green bias. `surface` is always opaque. |
| Tenant | `tenant`, `on-tenant` | Exactly one role: the dominant action and the current-nav mark. |
| Brand | `brand` | Ittai indigo: wordmark, symbol, sign-in. Never inside working screens. |
| Work status | `status-complete`, `status-attention`, `status-blocked`, `status-progress` | Always icon + word. |
| AI state | `state-working`, `state-approval`, `state-blocked`, `state-idle`, `state-unavailable` | Aliases of work status; always line style + icon + word. |

Light mode is the default desktop presentation; dark mode is designed with equal care, and every token has a dark value. All text tokens meet 4.5:1 on `page`, `surface` and `selected` in both themes; `text` exceeds 14:1.

## Typography

Inter for the whole interface at weights 400, 500 and 600 only; 600 for titles, customer names and the main action. Sizes 12 · 13 · 14 · 16 · 18 · 22 · 28 · 36 and nothing between. Zen Kaku Gothic New appears only in the wordmark.

## Space, radius and depth

4 px grid: 4, 8, 12, 16, 20, 24, 32, 40, 48, 64. Radii 6, 10, 14 and full. Shadows only for sticky bars, menus and dialogs. Rows before cards.

## The organisation: Six + One

Seven AI employees work as one office. Positions are **fixed** in every view, theme and size:

| Position | Employee | Department |
| --- | --- | --- |
| Centre | Charlie | Chief of Staff — coordination, priorities, escalations, owner briefings |
| Top | Yui | Communications & Customer Relations — receives all incoming communication |
| Upper right | Sam | Sales — leads, quotes, follow-ups |
| Lower right | Penny | Finance & Bookkeeping — invoicing, receivables, reconciliation |
| Bottom | Otto | Operations — scheduling, crews, people, purchasing, equipment, fleet, quality, safety |
| Lower left | Mia | Marketing — campaigns, SEO, content, reputation |
| Upper left | Ada | Technology — platform health, integrations, monitoring |

Lists always follow the same order: Charlie, then clockwise from Yui. Charlie is never placed on the outer hexagon. No employee is added, removed or renamed without approval.

## Operational states

| State | Side on the hexagon | Ring on the portrait | Icon | Word |
| --- | --- | --- | --- | --- |
| Working | Solid, `state-working` | Solid | Clock | Working |
| Waiting for approval | Solid, `state-approval` | Solid | Triangle | Waiting for approval |
| Blocked | Solid, `state-blocked` | Solid | Sharp-cornered octagon | Blocked |
| Idle | Dotted, `state-idle` | Dashed | Dashed circle | Idle |
| Unavailable | 1 px hairline, `state-unavailable` | Dotted, thin | Slashed circle | Unavailable |

Charlie uses the same vocabulary on the centre circle, which is a different shape from every side, so his state is never confused with a specialist's. States come only from backend data; nothing is animated or invented to look busy.

## The Six + One family

- **Full** — the interactive AI Office Hexagon (232 × 204) with names, state icons, spokes and Charlie's centre. Dashboard, tablet and AI Office workspace.
- **Compact** — a 32 px or 20 px live status glyph: six sides plus Charlie's ring, no labels and no spokes. Phone header and summary panels. Always paired with an accessible text summary.
- **Symbol** — the permanent monochrome Ittai mark in `brand` (or `surface` reversed out of a brand field). It is tested at 16, 24, 32, 64 and 128 px, with lighter strokes from 64 px. Spokes appear only at 32 px and above. It is never coloured by state and never animated. The logo and the live visualization are separate things.

## Communication lifecycle

Yui's communications move through their own lifecycle. It is shown as a neutral outlined pill and never uses the operational-state colours:

Received → Interpreted → Routed → Awaiting approval → Sending → Sent → Resolved, plus Failed to send and Snoozed.

What needs attention is flagged separately with work-status icons and words: Couldn't send, Urgent, Needs your approval, Unrouted. An employee can be Working while one of their conversations has Failed to send.

## Iconography

Line icons at 1.5 px on a 16 px grid; status icons are solid shapes (check, triangle, octagon) or a stroked clock. No emoji, no pictorial illustrations in working screens.

## Responsive

Desktop 1440 and tablet 768 show the Full hexagon beside or above the seven rows.

Phones (390) get their own interaction model, not a compressed desktop:
- Bottom navigation with five tabs: Home, Jobs, Inbox, AI Office, More.
- **Home:** Charlie's brief, then a tappable AI office status summary, the six metrics in two columns, expandable decision cards, and below them autonomous activity and the audit history.
- **Decisions:** where your authorization is needed, a card's first action is "Review & approve". It expands to show exactly what will be sent before "Approve and send".
- **AI Office tab:** a dedicated screen with the full interactive hexagon scaled to the screen width (up to 340 px), so every side and the centre stay large enough to tap.
- **Inbox tab:** Yui's communications command centre.
- **Targets and insets:** touch targets are 48 px or more, and the header and bottom navigation respect the safe areas.

## Further sections

- **Interactions** — selection, opening workspaces, approvals, audit and keyboard/touch behaviour.
- **Accessibility** — contrast, focus, screen readers, motion and the test checklist.
