THIS IS A NON-INTERACTIVE BUILD. Do NOT reply with a plan or ask anything: read the code, make the edits, run the tests, and finish with the file-by-file summary. A reply without changed files is a failed round.

# Task 27 — the three missing New rows: New client · New quote · Add a note (Tim, 2026-10-27: "Build that")

CONTEXT: commits a4a9f88 + 835b245 built the adopted crew navigation (Task 26: `.codex-inputs/task-26.md` if present, else read `day/NewSheet.kt`, `day/NewSheetLogic.kt`, `day/DayScreens.kt` around `newSheetOpen`). Standing rules from Task 24 still hold: Ittai tokens only via `It.palette` / `ItTextStyle` / `ItSpace` / `ItRadius` / `ItSize`, no hex literals, every target ≥ 48 dp, status is icon + word, text in sp; the production `mowology` flavor keeps EXACTLY its current look and code paths; everything new is reachable only when `crewSchedule` is true in `day/DayScreens.kt`. No version bump. Pure logic goes in `*Logic.kt` objects with JUnit tests in `android/app/src/test` (the existing `*LogicTest.kt` are the pattern). Tests must pass for BOTH flavors.

The New sheet today offers Snap a receipt · New job · Clock in. Tim wants the three rows the mock had and the sheet omitted. Final order, top to bottom:

    Snap a receipt · New job · New client · New quote · Add a note · Clock in

The server side is DONE and deployed (do not change or invent server behaviour; these are the exact contracts):

  (1) POST /api/schedule/field-job   (the same endpoint `FieldToolsLogic.FIELD_JOB` already posts to, same JWT, same
      permission as New job: a 403 carries the server's words)
        { action: "create_client", client_request_id, first_name, last_name?, phone?,
          property_address, property_city?, property_postal_code?, lat?, lng? }
        → 200 { success: true, contact_id, property_id, contact_name, address, city }
        → 400 { success: false, error: "Name and address are required." }
      Creates the client and ONE property, no job. Same body fields as today's `createClientJobBody` minus the job
      fields; `client_request_id` must equal the Idempotency-Key header as today (the `write()` helper does this).
  (2) POST /api/schedule/client-note   (new; JWT; needs the right the Search tab already needs — a 403 carries the
      server's words)
        { property_id, content, note_type: "general", client_request_id }
        → 200 { success: true, contact_id }
        → 400 { error } (empty note, note over 2000 characters, no property) · 404 { error } (property has no client)
  (3) EXISTING, unchanged: a note on a VISIT = `DayViewModel.addNote(visitId, text)` (POST /schedule/visit-work
      add_note through the durable day queue — offline-safe already). Use it as is.
  (4) EXISTING, unchanged: a quote starts from a visit through the recommendation flow,
      `FieldToolsViewModel.openReco(visitId, visitLabel)` → `RecommendScreen` (its primary button creates the quote).
      There is NO native "blank quote" and no server route for one. Do not invent one.

──────────────────────────────────────────────────────────────────────────────
A. THE SHEET (`day/NewSheetLogic.kt` + `NewSheetLogicTest`, `day/NewSheet.kt`)
──────────────────────────────────────────────────────────────────────────────
- `enum class NewAction { SNAP_RECEIPT, NEW_JOB, NEW_CLIENT, NEW_QUOTE, ADD_NOTE, CLOCK_IN }`.
- `NewSheetLogic.rows(receiptsOn, addJobOn, quoteOn, noteOn = true, timeTabPresent = true)` in the order above, each
  only when its gate is true. Gates (wired in `DayScreens.kt`): `addJobOn` as today (`fieldOn && fieldVm != null`) gates
  BOTH New job and New client (same server permission); `quoteOn = fieldOn && fieldVm != null` (the recommendation flow
  lives in `fieldVm`); `noteOn = true` under `crewSchedule` (a visit note needs nothing extra; the client-note right is
  the server's to refuse, with its words shown). Tests: the full six in order; receipts off; field off (→ receipt, note,
  clock only); none → empty.
- Labels in `NewSheetLogic.label`: "New client" / "New quote" / "Add a note". Icons, new vector drawables, 24 dp
  viewport, 2 dp strokes, tinted at draw time like the three existing ones: `ic_new_client.xml` (a person with a small
  plus at top-right), `ic_new_quote.xml` (a document: rectangle with two text lines), `ic_new_note.xml` (a pencil).
  Add them to the `when` in `NewSheet.kt`. Six rows must fit a 390 × 844 dp phone with the title, handle, Cancel and
  the Close region all visible: if they don't, make the row list scrollable rather than shrinking rows under 56 dp.

──────────────────────────────────────────────────────────────────────────────
B. NEW CLIENT (`day/FieldToolsViewModel.kt`, `day/FieldToolsScreens.kt`, `day/FieldToolsLogic.kt` + test)
──────────────────────────────────────────────────────────────────────────────
A client-only mode of the EXISTING add-job flow (same locate → prefilled address, same cards, same `write()` ledger),
so nothing about Add a job changes.
- `JobFlowState`: add `val clientOnly: Boolean = false` and `val createdClient: CreatedClient? = null` where
  `data class CreatedClient(val contactId: Int, val propertyId: Int, val contactName: String, val address: String, val city: String?)`
  lives in `FieldToolsLogic.kt` with `FieldToolsLogic.parseCreatedClient(json: JSONObject): CreatedClient?` (null when
  `property_id` is missing or ≤ 0) — unit-tested.
- `FieldToolsViewModel.openNewClient()`: like `openAddJob()` (same locate, same seed) but the state starts with
  `newClient = true, clientOnly = true`. `backToNearby()` in client-only mode closes the flow instead (there is no
  nearby step to go back to). `closeAddJob()` clears `createdClient` too.
- `FieldToolsViewModel.createClient(client: FieldClientDraft)`: guard `client.isValid` and `!saving`; then exactly the
  `sendJob` pattern (same error wording per `WriteOutcome`, same seed rotation on success, same `IN_PROGRESS_WORDS`,
  `waitingWords("the client")`) with `actionId = FieldToolsLogic.createClientOnlyActionId(client, seed())`
  (= `"job/client_only:" + the same name|address hash + ":" + seed` — a DIFFERENT ledger id from the client-and-job
  action, so the two never replay each other) and `body = FieldToolsLogic.createClientBody(client, lat, lng, key)`
  (= `createClientJobBody` without `jobPayload`, action `"create_client"`; unit-tested: field names, lat/lng only when
  both present, `client_request_id` = key). On `Accepted`, set `createdClient = parseCreatedClient(json)` (and
  `success = "Client added."` if parse fails, so nothing is silently lost).
- UI in `FieldJobScreen`: step title "New client" (already the `newClient` title). In client-only mode `newClientItems`
  shows the two existing cards (Client / Property — extract their fields into a shared private composable so the card
  code is not duplicated) and, instead of `JobFormCard`, a full-width primary `ItButton("Save client")` enabled when
  `client.isValid && !job.saving` ("Saving…" while saving), plus the existing error text under it when `job.error` is set.
  When `createdClient != null`: replace the form with a done card — `ItCard` with a check icon + "Client added" (HEADING_18),
  the name and address (BODY_16 / META_13), the meta line "The office reviews new clients afterwards.", then two full-width
  buttons: primary "Add a job for them" → `vm.pickProperty(FieldJobProperty(id = propertyId, address, city, distanceM = 0,
  contactName, plans = emptyList()))` with `clientOnly = false, newClient = false, createdClient = null` (lands on the
  existing property step, where the job form is), and ghost "Done" → `vm.closeAddJob()`. `BackHandler` on the done
  card = Done.

──────────────────────────────────────────────────────────────────────────────
C. ONE TARGET PICKER FOR NEW QUOTE AND ADD A NOTE (`day/NewTargetLogic.kt` + test, `day/NewTargetScreen.kt` new)
──────────────────────────────────────────────────────────────────────────────
Both actions need "which property / visit?". One screen answers it.
- `data class NewTarget(val propertyId: Int?, val visitId: Int?, val label: String, val address: String)` and
  `object NewTargetLogic` with:
  · `fromStops(stops: List<DayStop>): List<NewTarget>` — one target per stop, `label = stop.headline`,
    `address = stop.fullAddress`, `propertyId = null` (today's stops do not carry a property id — check `DayStop`; if it
    does, use it), `visitId` = the first visit whose status is not completed/skipped, else the first visit (null when
    the stop has no visits — then the stop is left out). Order: in-progress stops first, then the rest in `routeOrder`,
    completed last. Unit-tested with three stops.
  · `fromSearch(p: SearchProperty): NewTarget` — `propertyId = p.id`, `visitId = p.todayVisitId`, `label = p.label`,
    `address = p.fullAddress`.
  · `quoteRoute(t: NewTarget): QuoteRoute` = `OpenReco(visitId, label)` when `visitId != null`, else `NeedsJob` —
    a sealed class, unit-tested.
  · `noteRoute(t: NewTarget): NoteRoute` = `VisitNote(visitId)` when `visitId != null`, else `ClientNote(propertyId)`
    when `propertyId != null`, else `None` — unit-tested.
- `NewTargetScreen(title: String, prompt: String, stops: List<DayStop>, plusVm: SchedulePlusViewModel?, onPick: (NewTarget) -> Unit, onBack: () -> Unit)`:
  full-screen under the `CrewTopBar` pattern used by the entry screen (leading = Back 48 dp + title, symbol centred),
  `BackHandler` = onBack. Body: a `LazyColumn` with (a) a META_13 prompt line, (b) section label "Today" and one 56 dp
  row per `fromStops` target — label (BODY_16 500 `text`), address (META_13 `meta`), the visit's status word + icon on
  the right exactly as today's cards show it (reuse the existing status chip/composable), chevron; `Role.Button`,
  contentDescription = label; empty today → `ItEmptyState("Nothing on today's schedule.")`; (c) section label "Search"
  with the Search tab's text field bound to `plusVm.setQuery` and its results rendered with the existing `PropertyRow`
  (make it `internal` and give it an `onOpen` that calls `onPick(fromSearch(p))`), reusing `PlusState.results` /
  `searching`; when `plusVm == null` the Search section is absent. Nothing here calls the server beyond what the Search
  tab already calls.

──────────────────────────────────────────────────────────────────────────────
D. NEW QUOTE (routing only; `DayScreens.kt`)
──────────────────────────────────────────────────────────────────────────────
- New sheet → `NEW_QUOTE` sets `pickTarget = NewAction.NEW_QUOTE` (a `rememberSaveable` state in `DayApp`, next to
  `newSheetOpen`). While set, `NewTargetScreen(title = "New quote", prompt = "A quote starts from a visit. Pick the
  property.")` is shown full-screen (same place the entry screen is drawn; nothing else of the tab is visible).
- On pick: `quoteRoute(t)` → `OpenReco` → `pickTarget = null; fieldVm.openReco(visitId, label)` (the existing
  `RecommendScreen` then shows, as from today's cards). `NeedsJob` → an `ItDialog`/confirm: title "No visit here today",
  body "Quotes come from a visit. Add a job at this property first — the quote follows from it.", primary "New job here"
  → `pickTarget = null; fieldVm.openAddJob(from = FieldToolsLogic.fromSearch(p))` when the target came from search
  (keep the `SearchProperty` in the picker's callback for this — pass `onPick: (NewTarget, SearchProperty?) -> Unit`),
  ghost "Not now" → stay on the picker.

──────────────────────────────────────────────────────────────────────────────
E. ADD A NOTE (`day/NoteComposerScreen.kt` new, `day/FieldToolsViewModel.kt`, `day/FieldToolsLogic.kt` + test, `DayScreens.kt`)
──────────────────────────────────────────────────────────────────────────────
- New sheet → `ADD_NOTE` sets `pickTarget = NewAction.ADD_NOTE`; `NewTargetScreen(title = "Add a note", prompt =
  "Who is the note about?")`. On pick: `noteRoute(t)` → `None` is impossible from the picker (every row has a visit or a
  property) — if it happens, show `ItEmptyState` words "This property has no client on file. Ask the office." and stay.
  Otherwise `noteTarget = t` (another saveable state) and show `NoteComposerScreen`.
- `NoteComposerScreen(target: NewTarget, saving: Boolean, error: String?, onSave: (String) -> Unit, onBack: () -> Unit)`:
  full-screen, same top bar pattern (Back + "Add a note"). Body: target label + address (BODY_16 / META_13), a multi-line
  `ItTextField` (min 5 lines, label "Note", `imeAction = Default`), a META_13 counter "n / 2000" that turns
  `statusAttention` (or the palette's attention colour — say which) over 2000, the error text when set, and a full-width
  primary `ItButton("Save note")` enabled when `text.isNotBlank() && text.trim().length <= 2000 && !saving`
  ("Saving…" while saving). Keep the typed text across rotation (`rememberSaveable`).
- Routes, in `DayScreens.kt`: `VisitNote(visitId)` → `vm.addNote(visitId, text)` (the existing durable path; it
  shows its own "Saved on the phone — waiting to send." notice when offline), then `noteTarget = null; pickTarget = null`
  and the day notice "Note saved." when online (reuse `vm.notice(...)` if accessible; otherwise the existing path's own
  feedback is enough — say which). `ClientNote(propertyId)` → `fieldVm.addClientNote(propertyId, text)`:
  · `FieldToolsLogic.clientNoteBody(propertyId, content, key)` = `{ property_id, content (trimmed), note_type: "general",
    client_request_id: key }`; `clientNoteActionId(propertyId, seed)` = `"note/client:$propertyId:$seed"`;
    `const val NOTE_DOMAIN = "note/"`; `const val CLIENT_NOTE = "/schedule/client-note"`; `noteTooLong(text)`
    (> 2000 after trim) — unit-tested.
  · the ViewModel method uses `write(actionId, NOTE_DOMAIN, CLIENT_NOTE, bodyFor = …)` and reports through a small
    `NoteState(saving, error, saved: Boolean)` on the field state (`FieldToolsState.note`), wording per `WriteOutcome`
    exactly as `sendJob` (Refused = the server's words; Waiting = `waitingWords("the note")`). On `Accepted`:
    `saved = true`; `DayScreens` observes it, clears `noteTarget`/`pickTarget`, shows "Note saved." and calls
    `fieldVm.clearNote()`.

──────────────────────────────────────────────────────────────────────────────
F. WIRING AND BACK (`DayScreens.kt`)
──────────────────────────────────────────────────────────────────────────────
- Sheet `onPick`: `NEW_CLIENT -> fieldVm?.openNewClient()`; `NEW_QUOTE`, `ADD_NOTE` → `pickTarget = action`; the
  existing three unchanged. The sheet closes first, as today (`newSheetOpen = false; entryOpen = false; morePage = null`).
- Draw order: `fieldState.job != null` and `fieldState.reco != null` keep their early returns (so New client and the
  quote's RecommendScreen take the whole screen as Add a job does). Then `noteTarget != null` → `NoteComposerScreen`;
  then `pickTarget != null` → `NewTargetScreen`; then the entry screen; then the tabs. `BackHandler`s: composer → back to
  the picker; picker → back to the tabs.
- A rejected token (`openSignIn`) and the offline read-only day keep their current behaviour: the picker and composer
  are only reachable when the sheet is, and the sheet only under `crewSchedule`.

──────────────────────────────────────────────────────────────────────────────
G. DO NOT TOUCH
──────────────────────────────────────────────────────────────────────────────
`ItSymbol`, `CrewTopBar`'s layout, the legacy `ItTabBar`/`ItTopBar` paths, anything under `!crewSchedule`, the existing
`createClientJob` / `createJob` / `addVisit` bodies and action ids, the receipts and camera code, the server. No new
dependencies. Both flavors' unit tests green; name every new test in the summary.
