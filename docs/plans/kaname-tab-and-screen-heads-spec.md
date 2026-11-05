# Kaname tab and screen heads: spec for iPhone and Android

Agreed with Tim on 2026-11-04. One spec for both phones, so they can't drift apart (the old top bar was copied from iPhone to Android with its bug, and both always lit Charlie). No code until Tim has read this.

## The idea

- **Kaname is the hexagon.** He's the chief of staff, the pivot (要). His tab is where all the heads appear together.
- **Each work screen belongs to one head of department.** That head is shown on their screen. Nobody else is.
- **Kaname is a fixed product name**, like a symphony app's "Maestro". Every business meets the same Kaname. His photo and pronoun stay settings for each business.

## 1. The Kaname tab (replaces "AI Office")

- **Tab label:** "Kaname", with the hexagon icon. When the tab is selected, the hexagon rides in the meniscus bead.
- **Position: the centre slot** (Tim, 2026-11-04). Kaname means the pivot, and the centre is the pivot of the bar.
  - It's the strongest visual position: when he's selected, the bead sits dead centre.
  - Either thumb reaches it.
  - On Android it's the slot the raised "+ New" circle used to hold.
- **Admin bar, both phones:** Schedule · Time · **Kaname** · Search · Receipts.
  - Profile and Sign Out stay inside Kaname's tab, where they already live today.
- **Crew bar: unchanged.** Search stays in the centre and Account stays last, so the field layout crew know doesn't move.
- **"Time" replaces "Time Clock" as the tab label on both phones**, already the Android label. Every tab label is then one word. The screen's own title can stay "Time Clock".
- **Order, top to bottom:**
  1. **Ask Kaname:** one box for asking him anything. A first version can open his chat or route the question to a head. This build only needs the box and where it leads; how smart it is comes later.
  2. **His brief:** today's top item with its actions, as now.
  3. **The hexagon:** the six heads around him, each with their state.
  4. **The seven rows**, as now.
- Tapping a head opens their workspace. Coming back returns to Kaname.
- **Admins only for now.** Crew keep "Account" in that slot. Whether crew get a Kaname of their own is still the council question.

## 2. A head on each work screen

| Screen | Head | Notes |
|---|---|---|
| Schedule | Otto | |
| Time Clock | Otto | |
| Receipts | Penny | |
| Search | none | A plain lookup: clients, properties, near you. Used in the field, and crew have it too. |
| A screen opened from one of these | same head as its tab | e.g. a receipt's detail still shows Penny. |

- **Always shown:** the head's face, about 28 pt with their state ring, sits at the leading edge of the screen's title bar.
  - Tapping it opens that head's workspace in the Kaname tab.
  - Screen readers hear "Otto, operations, open workspace".
- **Only when something needs you:** a one-line card under the title, such as "Penny: City of Vancouver Landfill to review".
  - It shows only when the head's state is *approval* or *blocked* and the server gave a reason.
  - Tapping it opens that item. It can be dismissed until the reason changes.
- **Otherwise:** no band. The screen keeps its full height and nothing sits over the search field.
- **No state badges on faces.** The ring colour carries the state. Seven identical "Working" clocks told you nothing.

The data already exists. The ready brief (`team/head-brief-ready?head=charlie`) returns `heads[slug].state`, `reason` and `needs_you`. **No server change is needed.**

## 3. What goes away

- **The seven-face strip across the top** (iPhone `ItOfficeBar` in `ItAIOffice.swift`; Android `ItOfficeBar` in `ui/ittai/ItOffice.kt`), together with:
  - its environment slot on iPhone (from 1.3.71);
  - the highlight fix from 1.3.70.

  Moving between heads now happens in Kaname's tab.
- **Ada in the first slot.** She stays a greyed corner of the hexagon until she works.
- **"Charlie" on the iPhone.** Every string the user sees reads Kaname.
  - Internal keys stay `charlie` (tables, `?head=charlie`, `charlie.jpg`), as on the web.
  - The web already shows Kaname through `HeadIdentity`. The iPhone and Android read his name the same way, but it is fixed, not per business.

## 4. The fixed name (web)

- Remove the `chief_name` override from `app/Services/HeadIdentity.php`, so `chiefName()` always returns "Kaname". Keep `chief_pronoun` and `chief_photo`.
- Check whether any business has `chief_name` set before removing it, and tell Tim if one does.

## 5. Bringing the two phones together

- **iPhone today:** Schedule · Time Clock · Search · Receipts · AI Office.
  - Admins become Schedule · Time · Kaname · Search · Receipts.
- **Android (Ittai build) today:**
  - bottom tabs: Schedule · Time · New · Search · Receipts;
  - "AI Office" is an entry button in the top bar.

  Admins become Schedule · Time · Kaname · Search · Receipts:
  - "New" moves to a button on Schedule, like the iPhone's "+ Job";
  - the top-bar entry goes.
- Older Android labels ("Clock", "Timesheet") become "Time" in the Ittai build. The production `mowology` flavour is untouched.

## 6. Order of work

1. **iPhone (Claude):**
   - Kaname tab in the centre and "Time";
   - title-bar faces and the "needs you" line;
   - remove the strip;
   - Charlie → Kaname strings.

   Then compile, logic tests for "when the line shows", a Codex review, and TestFlight.
2. **Android (Codex writes, Claude reviews), same spec:**
   - Kaname in the centre and "New" moved to Schedule;
   - the bead's collar: fill the bead–cup gap with the bar's colour plus the edge line, as iPhone 1.3.72 does (`ItMeniscusBar.kt` draws the bead over a see-through gap);
   - the hexagon drawn 1.35× the size of the other icons, in its slot and in the bead (iPhone 1.3.73), because the thin outline reads small;
   - Ittai flavour only;
   - production `mowology` stays untouched.
3. **Web:** fixed name in `HeadIdentity` once the `chief_name` check is done.
4. **Check:** both phones side by side on the same three screens (Schedule, Receipts, Kaname), in light and dark.

## Not in this piece

- How smart Ask Kaname is: answers, search across records.
- Crew Kaname.
- The rest of the AppStack removal on the desktop.
