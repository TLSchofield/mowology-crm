# Kaname is not shown on every sign-in (plan, 2026-11-13)

Status: **plan only, awaiting Tim's approval. No code written, nothing deployed.**

Tim, 2026-11-13: "he no longer needs to be displayed every log in" — asked "for everyone?" → **"for everyone"**
(every business, Mowology included). Separate decision, not built here: for books-only businesses (Heritage Tree Service,
`docs/plans/2026-11-13-heritage-trees-year-end-plan.md`) Kaname is NOT off (Tim's correction, 2026-11-13): he stays as the single visible head, with the flow line and Penny's question cards on his screen; what goes for that business is the sign-in brief card (covered by the rule below, for everyone) and the other heads in the top bar. A per-business setting chooses which heads the top bar shows (default all, so Mowology is unchanged); the Heritage plan, findings 12 and 13, builds it.

Note on sources: this worktree's branch is an old base. Everything below was read from `origin/feature/language` (what
production tracks) and, for Android, `~/Projects/mowology-android-codex` (branch `codex/native`). Vault/skill path names
there are `kaname-chief-of-staff` (the skill was renamed from `charlie-chief-of-staff`; code keys stay `charlie`).

## 1. How it works today — and exactly where "every open" is decided

Kaname's brief is built on the server in one place and the phones decide on their own whether to play it. The every-open
behaviour is therefore **two client constants, not a server rule**, set on 2026-10-11 so Tim could see the brief after each new
build ("until I give go ahead" — memory `project_brief_every_open.md`, skill `traps.md` "Once-a-day rule hid the brief").

| Surface | What plays | Where "every open" is decided |
|---|---|---|
| **Server** | Nothing decides it. `charlie_morning_brief` cron (every 15 min) rebuilds the ready copy in `head_brief_ready` (migration 1319) through `HeadBriefReadyService::rebuildCharlie()`; served by team-mobile `?mode=brief&head=charlie&ready=1`. The 07:00 email (weekdays) is separate and once a day. | — (data only) |
| **iPhone** | `OpeningOverlay` (`Features/Splash/CharlieMorningBrief.swift`): brain → ITTAI → strapline → Kaname's face → brief (≤3 lines) + "Start the day". Shown to **admins only**. | `MorningBriefStore.oncePerDay = false` (line 59) makes `shownToday` always false; `RootView.showBrief` (line 17) = `user.isAdmin && !shownToday`. The splash itself (`showSplash = true`) plays on every launch regardless. `markShown()` already records the day in `UserDefaults` — the once-a-day machinery exists, just switched off. |
| **Android** (`ca.mowology.crew`, repo `mowology-android`) | `office/OpeningOverlay.kt`: same sequence → card "No deadlines in the next few weeks" + quote + "Start the day" → Schedule. | `OfficeLogic.BRIEF_ONCE_PER_DAY = false` (OfficeLogic.kt:392; `shownToday` at :405, `OfficeStore.shownDay` per user) **and** `day/DayScreens.kt:370` calls `OpeningOverlay(… wantsBrief = { true } …)` — hard-wired true, so even flipping the constant would not stop it unless that call also consults `shownToday`. The brief card needs `snapNow.hasContent`, but the splash/brain intro plays on every open regardless. |
| **Web dashboard** | `includes/charlie-card.php` (Kaname card) and `includes/action-board.php` ("Needs you now") on `dashboard_appstack.php`; Ittai shell adds `ittai-kaname-band.php` (alerts-only band, hidden on `kaname_appstack.php`). Owner only. | Not an interruption: they are page content that renders whenever the dashboard is opened. There is no "login moment" on web to gate. |
| **Kaname tab / page** | iPhone and Android Kaname tab (centre slot), web `kaname_appstack.php` — his full brief, one tap away. | Always available; unaffected. |

Why Tim saw "No deadlines in the next few weeks" first on Android: that card is a real brief with nothing urgent, shown on every open because of the two constants above.

## 2. The new rule — recommendation

**One rule, decided in one server function, obeyed by every client** (so iPhone and Android cannot drift again — the parity
trap this project keeps hitting).

> The opening brief plays **at most once per business-local day, on the first open that has something worth saying**, and
> **again later the same day only when something new and urgent has appeared**. Everything else is one tap away on the
> Kaname tab. The rule is the same for every business; which heads the top bar shows does not change it.

Precisely:

1. **No per-business "off" in this rule.** Kaname stays on for every business. A separate per-business setting chooses which
   heads the top bar shows (default all; a books-only business shows only Kaname). This rule does not read it: the brief's
   lines come from Kaname's own column and the Kaname tab is always there. (Compatibility: see §6.)
2. **First open of the day** (business timezone, per user per device — the existing `lastShownDay` stores): play the brief
   *only if it has content* (today's lines or a deadline). An empty "nothing needs you" brief is **not** shown — Tim
   found that card pointless; it stays on the Kaname tab.
3. **Urgent re-show (same day):** a new item since the last time the brief was shown that is one of: a Kaname urgent alert
   (`CharlieUrgentService`: failed payment ≥ the configured minimum, same-day weather), an overdue or ≤2-day legal/statutory
   deadline (`DeadlineService`), a held conflict or a decision waiting (`CharlieInboxService`). Not "any priority-1 line" —
   58 of 200 lines are priority 1 (traps 2026-11-03), so that would be every open again.
4. **The rule governs only the sign-in brief card (see §6): never the Kaname tab, the flow line or Penny's questions. Never on a cold start from a notification tap, or for crew** (admin/owner only, as today).
5. The server returns the verdict inside the ready reply: `open_now: true|false`, `reason: "first_open|urgent|none|off"`, and
   `urgent_key` (a stable id of the newest urgent item). The client sends back nothing new: it keeps `lastShownDay` plus
   the `urgent_key` it last showed (two small stored values per device), and compares.
6. **The splash is a separate question** (see unknowns): the brain/ITTAI/strapline animation is brand, not brief. Recommendation:
   keep a short (~1 s) ITTAI mark on a cold start only, and drop the 2.8 s brain/strapline hold when no brief follows.

Alternatives considered: (a) never auto-show, only the Kaname tab — simplest, but a legal deadline can then be missed by a
phone nobody opens the tab on; the urgent re-show covers this at low cost. (b) once a day *regardless of content* — repeats
the pointless empty card. (c) server-side "shown" ledger per user — correct across devices, but needs a migration and
cannot know a phone actually rendered it; not worth it for one owner. Recommendation stands.

## 3. Unknowns and how each is settled (cheapest first)

| Unknown | How settled |
|---|---|
| What counts as "urgent enough to interrupt" for Mowology specifically | Read `CharlieUrgentService::find()`, `DeadlineService::brief()` and `CharlieInboxService` on the **local site** (`docs/LOCAL_SITE.md`) with seeded data; list what each would trigger today. Tim confirms the list (one question in chat). |
| Does the splash (brain/ITTAI/strapline) stay on every launch? | Ask Tim — it is a brand decision (Ittai v1.2, `project_ittai_visual_direction_kanso`). Plan assumes the short-mark recommendation above. |
| Does the web dashboard Kaname card / Action Board stay? | Recommend yes (content, not interruption; the books-only top bar setting does not hide it, since Kaname stays on). Ask Tim in the same message. |
| Can the iPhone/Android compare `urgent_key` reliably? | Add `open_now/reason/urgent_key` to **both** `HeadBriefReadyService::buildCharlie()` and team-mobile's live reply in the same commit (trap 2026-10-11 "ready copy and live reply drift"); PHPUnit proves they match. |
| Android overlay with `wantsBrief = { true }` — does anything else depend on it (e.g. routing to the Kaname tab via `pendingHead`)? | Read `OfficeViewModel` and `DayScreens.kt` around :355–380 before editing; unit-test `OfficeLogic` rule; run on the emulator via `scripts/local-signup.sh` (resets an unclaimed local business). |
| Timezone of "first open of the day" | Business timezone is already used for the 07:00 email; reuse its source (`business_settings`), not device time. Settled by reading, tested with a fixed clock. |
| Does an older app build (not updated) misbehave against the new reply? | It ignores the new fields and keeps its current every-open behaviour — harmless. Android forces updates per build (`reference_android_field_release.md`); iPhone arrives by TestFlight. |
| The testing trap that caused every-open in the first place | Add a **DEBUG-only** override (iPhone launch flag `-mwAlwaysBrief`; Android debug-build pref) so a developer can always see the brief after a new build without touching the production rule. |

## 4. Steps and how each is proven

1. **Server rule** — new `KanameOpenRule` in `app/Modules/ChiefOfStaff/Services/` (pure function: enabled, today's items,
   urgent items, last shown day, last urgent key, business-local now → verdict). Registered in `tests/bootstrap.php`;
   `tests/Unit/` covers: off, first open with/without content, second open same day, new urgent key, same urgent key,
   next day, crew user. Add to both reply builders. Proof: `vendor/bin/phpunit`; local site JSON for `?mode=brief&head=charlie&ready=1`.
2. **Android** (Codex builds per `scripts/codex-build.sh` in the Android repo; Claude reviews): drop `wantsBrief = { true }`
   for a function of the server verdict + stored last-shown; flip `BRIEF_ONCE_PER_DAY` semantic to the new rule; shorten splash
   when no brief. Proof: unit tests on `OfficeLogic`, then the local emulator walk — first open shows the brief once, second open goes
   straight to Schedule, seeded urgent item brings it back, a business whose top bar shows only Kaname gets the same behaviour.
3. **iPhone**: same change in `MorningBriefStore` / `RootView.showBrief`; keep `markShown()`. Proof: LogicTests (`ios/LogicTests`),
   whole-app `swiftc -typecheck` and the iPhone 15 simulator with a synthetic flag, as in the directions. **iPhone is a TestFlight
   build on every push — I will not push iOS without asking Tim first.**
4. **Web**: no behaviour change planned; confirm the dashboard card and band do not read the new verdict. If Tim wants the card
   hidden when the day's brief is empty, that is a follow-up.
5. **Codex review** (`scripts/codex-review.sh`, or `code-reviewer` agent if Codex is out) on the server rule and both client diffs
   before anything ships; fix real findings.
6. **Ship order** (each only on Tim's go): server → deploy via the feature-gate scripts (`deploy-safe.sh`; commit first; OPcache
   reset) → Android field release → iPhone push. Server first is safe because old apps ignore the new fields.
7. **After**: update the `kaname-chief-of-staff` skill (`surfaces.md` parity row for "Opening brief", `traps.md` entry replacing
   "Once-a-day rule hid the brief", `operating-rules.md` line 45), the memory note `project_brief_every_open.md`
   (superseded), and the Obsidian Decision-Log entry; `HeadSkillsTest` must stay green.

## 5. Risks, especially to Mowology's own daily use

- **Tim stops seeing a line he would have wanted.** Mitigation: urgent re-show list agreed with him (unknown 1); Kaname tab one tap;
  the 07:00 email and urgent alert emails are untouched.
- **Missed legal deadline** if the urgent test is too narrow. Mitigation: deadlines ≤2 days / overdue are in the test; PHPUnit case for each.
- **Rule drift between phones** (the project's recurring trap). Mitigation: verdict is computed once on the server.
- **Empty or failed server reply** → client must fail *quiet*: no brief rather than a stuck overlay (the 2026-10-10 silent-skip trap
  in the other direction); the Kaname tab still works.
- **Heritage/books-only compatibility:** the rule has no per-business off, so the top-bar setting needs no change here. Risk to check: a
  books-only business has few or no other heads' lines, so the brief is often empty and therefore not shown on first open
  (intended; the flow line and questions on the Kaname tab carry the work).
- Android and iPhone code live outside this worktree's base; changes there need their own branches (Android repo `mowology-android`,
  iOS on `feature/language`), so this plan's branch carries documents only.

## 6. The flow line and the Kaname tab (added 2026-11-13, from Tim via the Heritage session)

Tim: "kaname has the questions flowliner on it." **Constraint: the new rule governs only the brief card that opens on
sign-in (`OpeningOverlay` / `wantsBrief`). It must never hide the Kaname tab, the flow line, or Penny's question cards
(Record / Not now, etc.).** Read from `origin/feature/language` and the Android `codex/native` branch:

| Surface | How the flow line is attached today | What happens when the brief card is not shown |
|---|---|---|
| **Web** | The flow line is the "job strip": `includes/ittai-job.php` (Penny), `-yui`, `-mia`, `-mia-ba`, `-sam`, `-otto`, included from `appstack_head.php` when the Ittai shell is on. Each shows **only on its own head's pages** (nav block `here`), owner only, fed by that head's `*JobAdapter` (Penny: `PennyJobAdapter`, `PennyMonthJobAdapter`, `PennyReceiptJobAdapter`). **`kaname_appstack.php` includes no strip.** Kaname's own "questions" (`CharlieDeskService::questions()` / `answer()`, "which first?", mute) render in the dashboard Kaname card (`charlie-card.js` `renderQuestions`). A Kaname flow line that offers decisions one at a time (~6 items) is in `ittai-backlog.md` (2026-11-11), **not started**. | Nothing changes: the web has no sign-in brief, and none of these read the rule. |
| **iPhone** | Kaname tab = Team tab (`HeadCardView`): face, brief column, **Ask Kaname** box (`HeadCardView.swift:428`). The only job carousel found in the Team card is Yui's (`YuiJobsNode`); no Penny or Kaname flow line was found there (not verified further). | Tab, Ask box and any card are separate views from `OpeningOverlay`; unaffected, provided the rule gates *presentation* only. |
| **Android** | Kaname tab (`OfficeScreens.kt`: hexagon centre "N in flow" from `ItOffice.kt:299`, Ask box). No job strip found. | Same: separate from `OpeningOverlay.kt`. |

Two couplings the rule **must not break** (both from reading the code):
1. The loader that fills the ready brief, brain cache and the Kaname tab's state (`MorningBriefLoader.load` on iPhone via
   `RootView`; `vm.startBriefLoader()` inside `OpeningOverlay` on Android) also feeds the Kaname tab and the hexagon. So when
   the verdict is "don't show", **skip only the card, not the load**. On Android the loader starts inside the overlay and the
   day screen waits for `overlayDone` (`DayScreens.kt:366-372`), so the not-shown path must start the loader itself and mark
   `overlayDone` at once. Proof: Android emulator walk — with the card suppressed, the Kaname tab still shows its brief and
   "N in flow".
2. Tapping a line in the brief routes to a head (`pendingHead`) — unaffected when the card is absent.

**Books-only businesses (Heritage) — corrected 2026-11-13.** Kaname stays on and *hosts* the flow line and Penny's question cards
on his own screen; the other heads leave the top bar until the owner turns their features on. So:
- There is no `kaname_enabled` flag and no `flow_host` field in this plan (both dropped). Nothing moves the flow line to another head.
- A per-business **top-bar heads setting** (default all heads, so Mowology is unchanged) is owned by the Heritage plan (findings 12 and 13).
  This plan's only dependency on it: the Kaname tab and the flow line must keep working when the bar shows Kaname alone, and the
  opening rule must not read the setting.
- Kaname's flow line must therefore carry Penny's items (her adapters) on the Kaname screen on web/iPhone/Android when Penny's head is
  not in the bar. Today the web strips show only on each head's own pages and the Kaname page has none (table above), so hosting them
  on Kaname's screen is Heritage-plan work, not built here. Unknown, for the Heritage session: whether Kaname's "one decision at a time"
  design (backlog 2026-11-11) is the same flow line.
- **"Books-only" is a starting point, not a business type (Tim, 2026-11-13: he may start using invoicing, for example).** Nothing in this
  plan may key off a `books_only` label. The top bar follows which features the owner has turned on; turning on invoicing adds the
  heads that feature needs (Sam, Yui) and their lines, flow-line items and cards start appearing on Kaname's screen and in his
  brief with no change to the opening rule. Proof to add: PHPUnit for the rule with the same business before and after a head's lines
  appear (empty brief → not shown; the next day with invoicing lines → shown once).
- The sign-in brief card is the one Kaname element a books-only business loses, and only through this plan's rule (mostly empty brief → not shown).

## Decisions I need from Tim

1. Approve the rule in §2 (once a day on first open *with content* + urgent re-show only).
2. Which things count as urgent for Mowology (default list: failed payment ≥ minimum, same-day weather, overdue/≤2-day deadline,
   held conflict, decision waiting)?
3. Splash: short ITTAI mark on cold start only (recommended), or keep the full brain/strapline animation every launch?
4. Web dashboard Kaname card: leave (recommended) or hide when empty?
