# Google contacts in onboarding — iPhone task (Codex iOS build)

Plan: `/Users/timschofield/.claude/plans/playful-strolling-planet.md`. Server contract: `2026-11-12-google-contacts-codex-spec.md` (built in parallel; do not change it).
Read `ios/MowologyCRM/CLAUDE.md` first and follow it (`ErrorDetails.describe`, `AppDiagnostics.shared.record`, `@MainActor` view models,
the APIEndpoint rules). Design: Ittai v1.2 (`it.*` tokens, `ItSpace`), copy the habits already in `Core/Design/Ittai/ItPhoneContacts.swift`.

## What the owner sees
On the existing phone-contacts card's intro, a second choice under **Choose clients**: **Use Google contacts**. Tapping it:
1. `step(["knows":"contacts","key":"contacts","do":"google_start"])` → `{ok, url, nonce}`.
2. Open `url` in `ASWebAuthenticationSession` (callback scheme `ittai-google`, `prefersEphemeralWebBrowserSession = true`). The server redirects to
   `ittai-google://done?ok=1&nonce=…&grant=…` (the grant is a one-time secret that only this redirect carries — it is what stops someone who merely knows the nonce from collecting the list) or `…?ok=0&reason=denied|expired|failed`.
3. `ok=1` → `step([... "do":"google_list","nonce":nonce,"grant":grant])` (never log or persist the grant) → `{ok, contacts:[wire contacts]}`; decode into `[PhoneContact]` and enter the **existing** picking phase
   (same list, search, select-all, "Add N clients", batches of 100, done screen, finish). Only difference: every `import` body carries `"source":"google"`.
4. The user closing the browser sheet (`ASWebAuthenticationSessionError.canceledLogin`) or `reason=denied` → back to the intro, **no error card**. `reason=expired|failed` or a server error
   → the existing failed phase with the precise text (`ErrorDetails.describe`); a server `not_configured` message is shown as sent.
   A cancelled request (`URLError.cancelled` / `CancellationError`) is never a failure (see `PhoneContactsViewModel.load` for the rule).

## Code
- `Core/Contacts/GoogleContactsSession.swift` (new): protocol `GoogleContactsSigningIn { func authenticate(url: URL) async throws -> URL }` and the live `ASWebAuthenticationSession`
  implementation (presentation anchor = key window; `@MainActor`; throws a typed `GoogleContactsSessionError.cancelled` for the cancel case). No logging of the URL (it carries state).
- `Core/Models/PhoneContactsModels.swift`: `OnboardingStepResponse` gains optional `url: String?`, `nonce: String?`, `contacts: [PhoneContactWire]` (decode leniently, default `[]`);
  a `PhoneContactWire` `Decodable` matching the wire keys (`ref, first, last, company, emails, phones[{label,number}], address{street,city,province,postal}?`) mapped to `PhoneContact`.
  Keep the existing demo initialiser compiling (add default values).
- `Features/Onboarding/PhoneContactsLogic.swift` (pure, no UIKit): `googleResult(from url: URL) -> GoogleSessionResult` (`.ok(nonce, grant)`, `.denied`, `.expired`, `.failed`, `.unrecognised`) and `wire(...)` takes a `source`.
- `Features/Onboarding/PhoneContactsViewModel.swift`: new phase handling `chooseGoogle()`; a stored `source` ("phone" | "google") set when picking starts and added to `import` bodies;
  the `GoogleContactsSigningIn` is injected (default live; DEBUG demo gets a fake that returns a canned URL and `DemoPhoneContactsAPI` answers `google_start`/`google_list` with ~12 synthetic people).
  Retry resumes the right operation (`retryOperation` gets `googleStart`, `googleList`).
- `Core/Design/Ittai/ItPhoneContacts.swift`: the intro's second button; same accessibility habits (labels, announcements). Keep `-mwContactsDemo` working and let it also exercise the Google path.
- `ios/LogicTests/PhoneContactsLogicTests.swift` is NOT in your scope; write extra cases as plain `[String]`-returning functions in `Features/Onboarding/GoogleContactsLogicChecks.swift`.

## Do not
Do not edit `project.pbxproj`, `Info.plist`, `project.yml` (Claude registers files). No Google SDK, no token handling on the phone, nothing logged but error text and counts.
