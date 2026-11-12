# Phone contacts in onboarding — iPhone task (Codex iOS build)

Tim, 2026-11-11: build the onboarding on the iPhone, then reuse it as the template for Android. Each onboarding screen sits
on the **Kaname** tab (`MainTabView` → `TeamView`). This step: bring the phone's contacts in properly. Server half is done
(spec `2026-11-11-phone-contacts-onboarding-codex-spec.md`). Read `ios/MowologyCRM/CLAUDE.md` first and follow it exactly
(APIEndpoint three switches, `ErrorDetails.describe`, `AppDiagnostics.shared.record`, `@MainActor` view models).

Design: Ittai v1.2 (`Core/Design/Ittai/*`, tokens `it.*`, `ItSpace`, `ItRadius`, `.itText`). Copy the card shell and
accessibility habits of `ItPennyOnboarding.swift` (VoiceOver announcements, reduce motion, Dynamic Type incl. accessibility sizes).
No new colours, no new fonts. Plain English, short sentences, no jargon.

## 1. What the owner sees (one card on Kaname's screen, below the company card, never a new tab)

The card appears only when the server map says the `contacts` step is `todo` (section 4). States:
1. **Intro** — "Do you keep your clients in your phone? Pick the ones you work for." Subline: "Only the contacts you choose
   leave your phone. They arrive with no marketing permission, so Ittai never emails or texts them until they say yes."
   Buttons: **Choose clients** (primary), **Not now** (ghost → `do:"skip"`).
2. **Asking permission** — iOS shows its own Contacts prompt (the app's `NSContactsUsageDescription` text).
3. **Denied / restricted** — "Ittai can't see your contacts. You can turn that on in Settings." Buttons: **Open Settings**
   (`UIApplication.openSettingsURLString`), **Not now**. No guilt copy.
4. **Picking** — an alphabetical list inside the card (a `LazyVStack` in a bounded-height `ScrollView`, or a full-screen sheet if
   cleaner; field-mode sheets must follow `feedback_ios_field_mode_sheets_dark`: explicit dark background, not system default).
   Each row: name (or company when there is no name), one line of detail (mobile number or email, else city). A search field filters
   by name, company, email, digits of the phone. **Nothing is pre-selected.** Header shows "N picked", with **Select all shown** and
   **Clear**. Primary button **Add N clients** (disabled at 0). **Not now** stays reachable.
5. **Sending** — progress "Adding 40 of 120…" (batches of 100). Cancel is not offered mid-batch.
6. **Done** — "Added 12 clients. 3 were already in Ittai." (created / matched from the batch results). One button **Continue**
   → `do:"finish"` with `count` = created + matched total. Then the card collapses to a one-line row "N clients from your phone".
7. **Failed** — shows the precise error with `ErrorDetails.describe(error)` (never a generic label), **Try again** resumes from the
   first batch not yet confirmed (a batch already answered is never re-sent), **Not now**.

## 2. Reading the address book (new `Core/Contacts/PhoneContactsReader.swift`)

- `CNContactStore`. Keys: given, family, organization, emailAddresses, phoneNumbers (with labels), postalAddresses. No photos, no
  notes, no birthdays — fetch only what is sent.
- Behind a protocol (`PhoneContactsProviding`) so the view model is testable and a fake feeds the DEBUG demo (section 5).
- `enumerateContacts` is blocking: run it off the main actor, return `[PhoneContact]` sorted by display name, skipping a card
  with no name, no company, no email and no phone.
- Authorisation: handle notDetermined (request), authorized, denied, restricted. The iOS 17.2 SDK has no `.limited`; treat any other
  future value as "try to enumerate, and if it returns nothing say so honestly" (`@unknown default`).
- Phone label cleanup: `CNLabeledValue.localizedString(forLabel:)`; the wire label is the lowercased localized label ("mobile", "iphone",
  "home", "work", "main", "other") — the server only distinguishes mobile/iphone.
- Wire payload for one contact (exact keys): `{ref, first, last, company, emails:[String], phones:[{label, number}],
  address:{street, city, province, postal} | null}`. `ref` is `CNContact.identifier`. First postal address only; `street` is
  `CNPostalAddress.street` with newlines replaced by ", ".

## 3. Pure logic (`Features/Onboarding/PhoneContactsLogic.swift`) — checked by `ios/LogicTests`

Pure functions, no UIKit/Contacts imports: display name, search match, "select all shown" set arithmetic, batching
into chunks of 100, building the wire dictionary from a plain `PhoneContact` struct, tallying results
(`created`/`matched`/`skipped`) and "next batch to send". `ios/LogicTests` is NOT in your scope (Claude wires the file into it
afterwards), so write the test cases as a second file `Features/Onboarding/PhoneContactsLogicTests.swift` containing plain
functions that return `[String]` failure messages (empty = pass), with no XCTest import, so they can be run from the existing harness.

## 4. Server contract (already built; do not change the server)

Auth: the app's JWT as for every other call. All calls are admin-only on the server.

- `GET  /api/onboarding/map.php?knows=contacts` → `{ok, steps:[{key,title,state,head,skippable,data}], next, ready_to_finish}`.
  The step with `key:"contacts"` has `state:"todo"|"done"|"not_needed"` and `data:{answer:"imported"|"skipped"|null, count:Int}`.
  The card shows when that step's state is `todo`; when `done` and `answer == "imported"` show the one-line summary row from `data.count`;
  when skipped show nothing.
- `POST /api/onboarding/step.php`, body always includes `"knows":"contacts"`:
  - `{key:"contacts", do:"import", contacts:[…≤200…]}` → `{ok:true, next, data, message, results:[{ref, outcome:"created"|"matched"|"skipped", contact_id:Int?}]}`
  - `{key:"contacts", do:"finish", count:Int}` → `{ok:true, next, data:{answer:"imported", count}}`
  - `{key:"contacts", do:"skip"}` → `{ok:true, next, data:{answer:"skipped", count}}`
  - Errors: HTTP 400 `{ok:false, error, message}` (`validation`, `too_many`). Show `message` plus the status via `ErrorDetails`.
- Add `onboardingMap` (GET, `knows` query item) and `onboardingStep` (POST) to `APIEndpoints.swift` in all three switches
  (`url`, `requiresAuth` = true, `httpMethod`), following the `businessIdentity*` cases. Decodable models go in `Core/Models/`.

## 5. Hosting on Kaname and the demo flag

- New `Features/Onboarding/PhoneContactsViewModel.swift` (`@MainActor`, `ObservableObject`, takes an API protocol and a
  `PhoneContactsProviding`, like `BusinessIdentityViewModel`) and `Core/Design/Ittai/ItPhoneContacts.swift` (the card).
- `TeamView` shows the card under `businessIdentitySection` **only once the company is confirmed**
  (`businessIdentity.state == .confirmed`) and the map says `contacts` is `todo`. Keep the diff to `TeamView` small.
  The map load happens when Kaname appears, with the same error handling as the company card.
- DEBUG-only launch flag `-mwContactsDemo` (like `-mwTimelineDemo`): bypasses sign-in for this card, uses a fake reader with ~25
  synthetic people/companies (including one with no name, one with two phones, one with an address, one duplicate email) and a fake
  API that returns plausible `results`. Release builds contain none of it.

## 6. Do not

- No contacts permission request until the owner taps **Choose clients**. Nothing is read at launch.
- No storing contacts on the phone beyond memory; nothing logged (not names, numbers or emails — `AppDiagnostics` gets error
  text and counts only).
- No Google or Gmail here. No change to the server, the frozen symbol, or other cards.
- Do not edit `project.pbxproj`, `Info.plist` or `project.yml` (Claude adds `NSContactsUsageDescription` and registers the new Swift files).
