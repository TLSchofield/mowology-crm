# Android spec — Claudia walk 2 (2026-11-03)

For the Android session (`codex/native`, Codex writes, Claude reviews). Plan: `2026-11-03-claudia-walk-2-plan.md`;
walk 1 findings: `2026-11-03-claudia-rehearsal-runbook.md` → "The walk". Tim approved: keep the current test package
for walk 2 (`ca.mowology.crew.test`, installs over the current app), axis style yes. Production `mowology` flavour
and the mowology.ca feed must not change. Nothing released. Ittai Design System v1.2 for every screen.

## A1 — a build with no TEST TENANT banner

- New flavour (name it `ittai`): `IS_TEST_TENANT=false`, new `IS_ITTAI=true`, host baked in with `-PittaiHost=<host>`
  (same mechanism as `tenantTestHost`), Ittai icon/label/splash as `tenanttest`, application id stays
  `ca.mowology.crew.test` for walk 2 (one place to change later).
- The all-native defaults (every `android.*` switch true unless the feed says an explicit false) key off `IS_ITTAI`,
  not `isTestTenant`. `tenanttest` keeps its banner and also gets `IS_ITTAI=true`. `mowology` gets `IS_ITTAI=false`.
- No `ItTenantBanner` when `IS_TEST_TENANT=false`; the source-scan test that requires the banner on every native
  screen becomes "banner present AND shown only for test builds".

## A2 — welcome

- `installSplashScreen()` in the first activity that draws, held until the feed answer or ~800 ms, whichever first
  (never longer than 2 s offline).
- One welcome screen before sign-up on a fresh install: the Ittai symbol, "Welcome to Ittai", one line ("Let's set
  up your business — it takes about five minutes."), one primary button "Get started". Shown once (until sign-in).
- No "Back" on the first screen a fresh install shows.

## A3 — onboarding in the axis style

The website's team section (Tim liked it): a number, a vertical kanji label, a large statement, a thin rule. On the
phone, stacked:
- Above each step's title: step number and kanji, e.g. `01 名前` About you, `02 会社` Company, `03 帳簿` QuickBooks,
  `04 会計士` Accountant, `05 完了` Done. Number and kanji come from the step order the server's map gives (do not
  hard-code the count; a step the server marks not needed is skipped).
- A thin progress spine down the left edge, one mark per step: filled = done, hollow ring = skipped, accent = current.
  Tapping the spine opens the step list (replaces the "All steps" text button).
- Once a step is done its heading becomes the answer in the large type ("Mowology Lawns & Landscapes", "QuickBooks —
  connected", "Accountant invited"), the question shrinks to the small label.
- Add the pattern to the design system tokens/components you already use (one composable, e.g. `ItAxisStep`), not
  per-screen styling. Claude will add it to the Design System doc.

## A4 — company

- Match rows show a chevron / are visibly tappable.
- Show registry names in normal case for display (`MOWOLOGY LAWNS & LANDSCAPES LTD.` → `Mowology Lawns & Landscapes
  Ltd.`; keep short all-caps tokens like `BC`, `GST`, `II`, `III` as they are). Display only; what is sent back is unchanged.

## A5 — QuickBooks

- Server change (live soon): every part row now has a `summary` BEFORE it is accepted (what Accept will bring in, e.g.
  "12 to add, 2 already here"), `done:false`. After accept, `done:true` and the summary says what was done. Show
  the summary line in both states (already the case — keep it).
- The step becomes done on the server once QuickBooks is connected and read, so Continue moves on. Accept stays
  available per row.
- Add "Accept all" (primary) above the rows when two or more rows are not done: calls accept for each not-done part in
  order business, clients, services, suppliers, accounts; stops and shows the message on the first refusal.
- Heading after connecting: "Bring in your QuickBooks" (axis: the answer line becomes "QuickBooks — connected").
- Show the `message` of the read reply (e.g. "Read 12 customers, 8 items, 4 suppliers.") as a quiet notice.

## A6 — accountant

- After invite: "Invitation ready for <name>" + one line "Send it to them — Ittai doesn't email it." + Share (primary)
  + Copy. Do not render the link text on screen (it is a working sign-in key and ends up in screenshots).

## A7 — finish

- "You're set up." with a short summary from the map: business name, QuickBooks (connected / not used / later),
  accountant (invited / later).
- Then the team, axis-list style, one line each, names from the server: Kaname (chief of staff), Yui, Sam, Penny,
  Otto, Mia, Ada. Button "Open my day".

## A8 — day screen

- The chief of staff's shown name comes from the server: sign-in/token responses will carry `user.chief_name`
  (e.g. "Kaname") and `user.business_name`. Fallback "Kaname" when absent. Replace the hard-coded "Charlie" in
  `OfficeLogic.kt:188` and anywhere else it is shown (internal key stays `charlie`).
- `OpeningOverlay.kt:173` "this morning": use the device clock — "this morning" before 12:00, "this afternoon"
  12–17, "this evening" after 17:00.

## A9 — the owner's day

When the signed-in user is the owner/admin:
- Receipts card line: "Snap a receipt — Penny files it." (not "the office reviews it").
- Hide "Mine / All crews" when the business has one person (the schedule answer's crew list has one member or none).
- "You're not clocked in" card: owners only see it if they use the time clock (any clock entry ever); otherwise hide.
- Empty day: instead of only "No jobs on your list today.", a nudge with two actions: "Add your first client" and
  "Add a job" (use the existing native New flows).

## A10 — QuickBooks return

- If the app can be reopened by a link (an intent filter for `https://*.ittai.ca/app/return` or a custom scheme),
  tell Claude which; the server's callback page will add a "Back to Ittai" button that uses it. If not, add an App
  Link for `https://<host>/app/return` that just brings the app to the front, and refresh the onboarding step on resume.

## Build and proof

- Tests per flavour; emulator walk of a fresh install against `rehearsal2.ittai.ca` once Claude says it exists
  (until then against `sage.ittai.ca` or the local harness). Screenshots of every screen to Claude.
- Publish the walk-2 APK (`ittai` flavour, `-PittaiHost=rehearsal2.ittai.ca`) at
  `public/crm/downloads/ittai-rehearsal2-test.apk` only after Claude's review; Claude deploys it.

## A11 — animated opening (added 2026-11-04, Tim: "Animation is pointed to now by ittai design")

Source: `~/Projects/design-studio/sandbox/ittai-logo-motion/index.html` (= v4-pinball.html; frames v4-frames.png). The
two-people app icon morphs into the "ittai" wordmark, the two gold dots hop onto the i's. Self-contained SVG + JS,
`render(t)` is a pure function of time. Design log: 4.8 s ("Medium") is the splash candidate; splash = animation
only, no tagline; navy background, icon gold.
- The Android 12 system splash stays the still icon on navy (it cannot play a 4.8 s web animation). Right after it,
  an opening screen plays the animation on the same navy, seamless from the still icon (frame 0 = the icon).
- Port `render(t)` to a Compose Canvas (preferred: native, no WebView start-up), or bundle the HTML as an asset in a
  WebView if the port would change the motion; say which and why.
- Full 4.8 s on the first launch after install; later launches skip straight to the end state (or a ≤0.8 s
  version). Tap anywhere skips. Respect the system "remove animations" setting (show the end state).
- It ends on the welcome screen: the wordmark end state, "One business, working as one.", primary "Set up my
  office", ghost "I already have an account" (replaces A2's welcome copy).
- Separate build after walk 2: do not change the staged walk-2 APK. Screenshots of start/middle/end + diff to Claude.
