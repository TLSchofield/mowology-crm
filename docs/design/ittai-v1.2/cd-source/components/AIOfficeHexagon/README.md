# AIOfficeHexagon

The interactive Six + One map of the AI office: six specialist sides around Charlie's centre circle, joined by six hairline spokes.

## When to use

- Dashboard (desktop 1440) beside the seven employee rows.
- Tablet (768) above or beside the rows.
- The AI Office workspace header.
- Phones: only on the dedicated AI Office screen, scaled to the screen width (up to 340 px) with the seven rows below. Elsewhere on phones, use the Office Compact summary.

## Anatomy

| Part | Spec |
| --- | --- |
| Canvas | `hex-full-width` × `hex-full-height` (232 × 204), centre (116, 102) |
| Hexagon | Flat-topped, vertex radius 72, apothem 62.35 |
| Sides | Six paths. Each runs from the midpoint of one corner arc to the next (corner radius 12), so its ends wrap softly into the corners. `stroke-side` (3 px), round caps, trimmed 5.5 px at each end for an even 8 px gap. Each side is drawn with `pathLength="100"`, `stroke-dasharray="84.44 15.56"` and `stroke-dashoffset="-7.78"`. |
| Spokes | Six `stroke-hairline` lines in `line`, from radius 40 to 52 along each side's normal |
| Centre | Charlie: circle radius 34, `surface` fill, `stroke-centre` ring in his state colour. Inside: state icon + "Charlie" (12/600) and a compact work count (12, `meta`, tabular), e.g. "27 in flow" |
| Labels | Name (12/500, `text`) with its state icon (12 px) outside the matching side |

## Fixed positions

Yui is on top, then clockwise Sam (upper right), Penny (lower right), Otto (bottom), Mia (lower left) and Ada (upper left). Charlie is always the centre. Positions never change with state, size, theme or tenant.

## States

Each side shows the employee's operational state through the state token, line style, label icon and accessible name:

- **Working:** solid side.
- **Waiting for approval:** solid side.
- **Blocked:** solid side.
- **Idle:** dotted side (`stroke-dasharray="0.01 8.434"`, same offset).
- **Unavailable:** a 1 px side.

The centre uses the same vocabulary on its ring.

## Interaction

The full interaction rules are in the Interactions section; in short:

- **Select** (hover, focus or press): the chosen side goes to 5 px, the other five to 25% opacity, its spoke to `text`, and its row to `selected`.
- **Open** (click, Enter, Space or tap): opens that employee's workspace. The centre opens the Chief of Staff workspace.
- **Keyboard:** one tab stop. The arrow keys move clockwise or anticlockwise, Home returns to Charlie, Enter opens.

## Data contract (consumer provides)

```ts
type State = 'working' | 'approval' | 'blocked' | 'idle' | 'unavailable';
interface OfficeEmployee { id: 'yui'|'sam'|'penny'|'otto'|'mia'|'ada'; name: string; department: string; state: State; assignment: string; pendingItem?: string; }
interface AIOfficeHexagonProps {
  charlie: { state: State; inFlow: number; heldForOwner: number };
  employees: OfficeEmployee[];      // exactly six, any order: placed by id
  selectedId?: string | null;       // controlled selection ('charlie' or an id)
  onSelect?(id: string | null): void;
  onOpen(id: string): void;         // workspace navigation
  stateAsOf: string;                // ISO time of the backend snapshot; stale > 2 min shows "Updated 9:12" under the count list
}
```

States must come from backend records. If the snapshot is stale or unreachable, every side falls back to Unavailable with the reason, never to an invented state.

## Do and don't

- Do keep the count list ("3 need approval · 2 working · 1 blocked · 1 idle") beside the map.
- Do pair it with the seven rows; the rows are its text equivalent.
- Don't add a seventh side, move Charlie to the perimeter or reorder the sides.
- Don't animate sides to suggest activity.
- Don't use the live map as a logo; use the Ittai Symbol.
