# The head's spine — iPhone plan (2026-11-07)

Backlog entry: "The head's spine" (`docs/plans/ittai-backlog.md`, commits 007cc500c / 5f7852b42 on `claude/penny-linked-state`).
No design round (Tim, 2026-11-07): reuse `PennyCardView.sectionIcon` and the centre-line pattern from
`PennyChequeCard.swift` / Penny's business frame (`TeamView.swift` `onboardingSection`).

## The shape (every head, one view: `HeadSpineView`)

```
            (face 56, state ring)
                 Penny
            Penny · the steward
                   │
   Waiting now ──( ● )── 4 waiting      ← open by default: today's cards, unchanged
   [ existing cards hang here, in a frame on the line ]
                   │
  Missing receipts ( ● ) 2 to chase     ← closed; tap opens it in place, tap again closes
                   │
    From customers ( ● ) 1 message
                   │
              …    │
```

- **Circles on the line**, 44 pt ring (the tap target), the `sectionIcon` look: brand fill, white glyph, page gap, thin
  ring. Name on the left, what is waiting on the right (meta colour; nothing shown when nothing waits or no source).
- **Open in place:** the section's existing content hangs under its circle in the thin frame Penny's business section
  already uses; the line carries on below. Open/closed is remembered per head and section (`@AppStorage`).
- **Waiting now first:** the current cards (and the numbered guide) become the first circle, open by default.
- **Role line:** the English half of today's `archetype()` map, with the Japanese word-names dropped (Kanjō is out,
  council 2026-11-07). Names unchanged.
- **Large text** (accessibility sizes): no room for left/centre/right, so the line moves to the leading edge and the
  name and count stack to the right of each circle. VoiceOver reads "Missing receipts, 2 to chase, button, closed".
- Reduce Motion: no open/close animation.

## Each head

Counts all come from data the iPhone already loads; nothing new on the server.

| Head · role line | Sections (top to bottom) | Right-hand text · source |
|---|---|---|
| **Penny · the steward** | Waiting now (lines + payments/cheques/duplicates/receipts cards, as today) | "N waiting" · `PennyCardViewModel` payments + cheques + dupes + queue |
| | From customers (mail rows, now a section inside `PennyCardView`) | "N messages" · `vm.messages` |
| | Missing receipts (`PennyChaseAdminSection`) | "N to chase" · `PennyChaseViewModel.adminItems` |
| | What Penny knows (`PennyKnowledgeLiveView`) | none |
| | Your business (identity frame) | "to confirm" until confirmed · `BusinessIdentityViewModel` |
| **Sam · the pathfinder** | Waiting now (briefing, Next row, customer rows) | "N to follow up" · `SamCardViewModel.queue` |
| | Looks like a yes · Quotes to send · Just approved · Replies waiting · Questions for you · New leads | each "N" · the same arrays his folds count today (folds become circles) |
| | Sam's numbers | none |
| **Otto · the orchestrator** | Waiting now (his Action Board items) | "N waiting" · `HeadCardViewModel.items` |
| | Missed time (`OttoMissedEntry`) | count from `missedReview` if it has one, else none |
| | Suggestions (`OttoDeskSection`) | "N to review" · `ottoTotal` |
| | Properties without a pin (`unpinnedSection`) | "N" · `unpinned` |
| | Equipment (Add equipment button) | none |
| **Mia · the voice** | Waiting now (items) | "N waiting" · `items` |
| | Suggestions (`MiaSuggestionsSection`) | "N" · `miaSuggestions` |
| | Post draft (`postSection`) | "1 ready" · `post` |
| **Yui · the connector** | Waiting now (items) | "N waiting" · `items` |
| **Kaname · the linchpin** | Waiting now (items) | "N waiting" · `items` |
| **every head, last** | What I've learned (today's brain disclosure) | "N learned" · `HeadBrainSummary.units` |

**Ada · the foundation:** left out. She has no iPhone screen yet (shown "not available" in the office); she gets the
spine when her screen is built. Tim's sketch's "Profitability" for Penny has no iPhone data source yet — backlog.
Yui and Kaname get only two circles today; more sections (mailboxes, drafts; Ask) need their own data first — backlog.

## Steps and checks

1. `HeadSpineView` + `SpineSection` (new file `Features/Team/HeadSpine.swift`, registered with `ios/add_swift_file.py`):
   top block, nodes, line, open/close, large-text layout. Pure logic (count words, default-open) in a small testable
   helper.
2. Penny first: `ItWorkspaceView` renders the spine instead of guide / knowledge / content / onboarding. Mail moved out
   of `PennyCardView`; cards themselves untouched (their own line still runs bank → panel → ledger inside the frame).
3. Sam (folds → spine nodes), then Otto, Mia, Yui, Kaname through `HeadCardView` exposing its parts.
4. DEBUG flag `-mwSpineDemo penny|sam|otto|mia|yui|kaname` with invented data (as `-mwDupeDemo`). Simulator
   screenshots: light, dark, accessibility-XL text, for Penny and Sam at least, one for each other head.
5. Whole-app type-check + `xcodebuild` (outside the sandbox), then review (Codex is out until 2026-11-11 — ask Tim
   before using the `code-reviewer` agent), then Tim's go-ahead before pushing to `feature/language` (starts TestFlight).
   Version bump to the next 1.3.x at push.

Follow-ups: web and Android parity; Ada; Yui/Kaname extra sections; Penny "Profitability".

## As built (2026-11-07)

- `Features/Team/HeadSpine.swift` (SpineHead, SpineNode, SpineTail), `HeadSpineLogic.swift` (checked by
  `ios/LogicTests/HeadSpineTests.swift`), DEBUG `HeadSpineDemo.swift` (`-mwSpineDemo <head>`, `-mwSpineClosed`).
- Each SpineNode draws its own stretch of the line, so the head views emit nodes and the workspace stacks them.
- Penny's mail sits before Missing receipts (mail is inside `PennyCardView`); a closed circle is a brand ring with a brand
  glyph, an open one is filled (Penny's section icon). Open/closed is remembered per head and section.
- Screenshots: `docs/plans/renders/spine/` (light, dark, accessibility XL; `*-closed` = every section shut).
