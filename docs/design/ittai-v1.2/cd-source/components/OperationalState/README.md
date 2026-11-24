# OperationalState

The state of an AI employee shown as an icon and a word together, in the state's colour. It is used in rows, workspace headers, hexagon labels and announcements.

| State | Token | Icon | Hexagon side | Portrait ring |
| --- | --- | --- | --- | --- |
| Working | `state-working` | Clock | Solid | Solid |
| Waiting for approval | `state-approval` | Triangle | Solid | Solid |
| Blocked | `state-blocked` | Octagon with bar | Solid | Solid |
| Idle | `state-idle` | Dashed circle | Dotted | Dashed |
| Unavailable | `state-unavailable` | Slashed circle | 1 px | Dotted, thin |

- Size: 12 px icon with caption-12 text in rows, or 14 px icon with body-14 in workspace headers.
- On phones the word can shorten to "Needs approval".
- The state is set by the backend only. If the data is stale for more than 2 minutes, show Unavailable with "Last update 9:12" rather than a guessed state.
