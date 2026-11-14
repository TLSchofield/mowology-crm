# iPhone sign-up and onboarding: build notes (2026-11-13)

Unknowns settled by reading, one line each, with the file read.

## Which business a brand-new person signs up at (OPEN, needs the owner)
- `signup.php` and `signup-confirm.php` answer 404 `not_available` on the default tenant and otherwise act on the business whose host received the call (`public/api/auth/signup.php`, `Tenant::isDefault()`). The owner is "the first admin user" of that database (`app/Modules/Onboarding/Services/SignUpService.php`).
- Android never looks anything up: each build has its host baked in (`config/ServerConfig.kt`, tenanttest flavor `test.ittai.ca`).
- The iPhone's `Core/Network/Server.swift` is still one constant, `https://mowology.ca` (step 7 of the one-app plan, "iPhone reads the address from sign-in", is not built; `docs/plans/ittai-backlog.md` line 613).
- `ittai.ca/api/sign-in.php` (`BusinessDirectory`) finds a business from email AND PASSWORD. A person signing up has no password yet, so it cannot be used. No email-to-owner-business lookup exists on the server.
- Built: a seam, `SignUpHostResolving` in `Features/Onboarding/SignUpTransport.swift`, defaulting to `Server.base`. On mowology.ca that answers 404 and the app says "Sign-up is not available on this server yet." That is the honest result today.
- Decision needed: add a directory call (for example `ittai.ca/api/signup-lookup.php`: owner email -> business address, only for an unclaimed business) or another way to choose the business. Then write one resolver type. The rest of the app also needs `Server.base` made runtime-settable, or a new owner's token still goes to mowology.ca after sign-up.

## Endpoints and JSON (all copied from Android and checked against the server)
- Code: `POST /api/auth/signup.php` `{first_name,last_name,full_name,mobile (10 digits),email (lowercase),password,audience:"mobile",terms_accepted:true}` -> `{ok,sent:["email"],expires_in:900}` (`SignUpLogic.kt`, `signup.php`).
- Claim: `POST /api/auth/signup-confirm.php` same fields plus `code` -> HTTP 201 `{ok,token,user{id,email,name,role,business_name,chief_name},expires_in,business,onboarding{next}}`. Wrong or expired code: 400 `{error:"code_wrong"|"code_expired",message}`. The code lasts 15 minutes and 5 wrong tries expire it (`SignUpService.php`).
- Field errors: 400 `{error:"validation",fields{...}}`; `full_name` folds into the first-name field; owner-email mismatch comes back on `email`.
- Other statuses: 404 not available, 409 already claimed, 429 rate limited, 502 code could not be sent.
- Onboarding: `GET /api/onboarding/map.php?knows=terms`, `POST map.php {key,do:"skip",knows}`, `POST /api/onboarding/step.php {key,do,...fields,knows}`, `GET /api/onboarding/quickbooks-start.php` -> `{url}` (`OnboardingViewModel.kt`, `step.php`, `OnboardingStepService.php`).
- Step bodies: name `save{first,last,preferred}` / `reach{channel,mobile}` / `quiet{from,to,weekends}`; terms `accept`; company `search{name}` / `confirm{record_id}` / `manual{name}`; quickbooks `yes` / `no`; books `ack`; accountant `invite{name,email}`; done `finish`.
- The path is whatever the server returns. With `knows=terms` it is About you, Terms, Company, QuickBooks, Your books (only when QuickBooks is No), Accountant, Done. Android declares no new steps, so it never sees Terms.

## Token
- `signup-confirm.php` returns the same mobile JWT shape as `token.php`. `AuthSession.acceptSignUp` runs the same persistence as `login` (shared private `adopt`): Keychain token + user, published state, `FixStore.claim`. No second auth path.
- Onboarding requests send `AuthSession.token` as Bearer and call `logout()` on 401, as `APIClient` does. They do not go through `APIClient` because it replaces a step error `{error:"validation",message}` with the word "validation".
- The step still owed is kept per user in UserDefaults (`ittai.onboarding.next.<id>`), so a relaunch resumes it. Existing logins have no such entry, so they open exactly as before.

## Validation (Android's exact strings)
- Names 1-40 characters each. Mobile 10 digits (a leading 1 on 11 digits is dropped). Email: text before the `@` and a dot after it. Password 10+ characters, must match confirm. Code exactly 6 digits.
- Terms checkbox (owner decision 2026-11-13): unticked, required; "Create my login" is disabled until every field is valid AND it is ticked; `terms_accepted:true` goes in both calls; a server `fields.terms` error shows under the box.

## Not copied
- Android "Sign-up is switched off" logic (`NativeSignUpSwitch`): the phone just shows the 404 message.
- Android saved-login offer (Google Smart Lock). iOS autofill via `.newPassword` content type does the job.
- Android phone-contacts card inside onboarding, and the GST / year-end / payroll / contacts steps: not declared in `knows`, so the server leaves them out.
- QuickBooks Yes path: only Connect (Intuit in the browser, reload on return) then Continue. The Read / Accept all / "Send new invoices" panel is not built.
- The "all steps" map screen (tap the timeline on Android) is not built.
- Android's Welcome has an empty test-tenant banner; the iPhone has none.

## Decisions I made that you may want to change
- Opening colours: Android draws white letters and gold `#C9A65A` on navy `#233A5E`. The iPhone does the same, from `ItTokenHex` (`brand` light for navy, `knowledgeMastered` dark for gold). Letters are `Color.white`.
- Timeline (owner 2026-11-13): numbered circles that turn into ticks, NO kanji. 28 pt circle, 1 pt brand border (2 pt + surface fill when current); pending shows its number (12 pt semibold); done = brand fill + white 11 pt bold checkmark; skipped = line-colour border; 1 pt hairline x 18 pt joins circles. Heading `NN  Title` (server title, else English fallback).
- Done review: Terms row first (Accepted / Not yet) when the terms step is in the map.
- Status bar: `solidStatusBar` page-colour strip above every scrolling screen.
- The opening plays once per install (`ittai.opening.played`), as on Android. Reduce Motion, or a replay, shows the end state straight away.
