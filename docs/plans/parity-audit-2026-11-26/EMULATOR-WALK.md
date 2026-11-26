# Android emulator walk — 2026-11-26 (build from `codex/native` 582d366, tenanttest debug, local test business)

Device: emulator `claudia` (Android 14). Not yet run on a real phone or the Samsung tablet (SM-T227U): the tablet cannot reach the local test business, so only a layout check on its sign-in screen is possible there.

| Check | Result |
|---|---|
| Add task online | Pass. Server row created, assigned to the signed-in user. |
| Add task with no connection | Pass. Row kept on the phone (`field_create_outbox.user.N`, kind TASK, QUEUED, own request id). Sent once when the connection returned, outbox emptied, server holds exactly one row per task, none duplicated. |
| Feedback after offline Save (first build) | FAIL, fixed in 582d366: screen closed with no message. Now shows "Saved on this phone. It is sent when you have signal." and a "n waiting to send / See" line above the schedule; line clears after sending. |
| Labels (M16) | FAIL, fixed in 582d366: Title and Note showed only as placeholders. Now a label above each field, example inside. |
| Explicit sign-out wipes read caches | Pass. Schedule, people, office, plus_* keys gone; `native_auth_explicitly_signed_out` set; unsent-create queue and the clock state of a still-running shift kept on purpose. |
| Shared phone, no signal, app killed and reopened after sign-out | Pass. Opens the welcome / sign-in screen, never the previous person's saved day. |
| Different person signs in | Pass. Manager signed in; nothing of the crew member's day shown. |

How to test offline on the emulator: switching Wi-Fi/data off is NOT enough (the app reaches the test server through `adb reverse tcp:443`, which stays up). Remove it with `adb reverse --remove tcp:443`, then restore it AND switch Wi-Fi and data back on (the sending worker waits for a connected network).
`scripts/local-android.sh install` fails with two devices attached; use `adb -s emulator-5554 install -r <apk>`.

Not yet tested: keyboards and Done key on every form, the offline Add job / Add client queue, large-screen layout, the quiz route fix on a real Android phone, TalkBack.

## Round 2 — Add client + job offline, keyboards (2026-11-26, same debug build 582d366)

**Passed**
- Add client + job with no signal: Save kept it on the phone ("1 waiting to send / See"); on reconnect it was sent once. Local server: one new property, one contact, one job plan (PLN-2026-0007), no duplicates; the outbox for that user was empty afterwards.
- Phone field opens the phone keypad with a Done tick. Price per visit opens the number keypad with a decimal point and a Done tick. Street address keypad shows the Done tick.
- Service list opens offline (cached).

**Found, not fixed (Android, New client)**
1. "Create client and job" is greyed until a Service is chosen, with no sentence saying so (M17: disabled says why). Same family as the reasons already added on iPhone.
2. After saving offline there was no "Saved on this phone" notice on this form (Add task has one). The waiting line appears, so the work is not lost, but the feedback is inconsistent.
3. The empty-day card still says "Nothing on today — let's put your first work in" while a queued job waits; it should say a job is waiting.
4. Add job needs a GPS fix and gives up after 4 seconds ("No usable position yet"); the emulator's fake GPS could not satisfy it, so the Add job form itself was not exercised here. Needs a real phone or the tablet.
5. Autocorrect turned "St" into "Sth" in Street address: autocorrect should be off for address fields.

**Not tested yet:** Done key on every other form, large-screen layout (tablet), TalkBack, a real phone.
