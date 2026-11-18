# Plan: the crew schedule page on Android matches the iPhone

Status: **Investigated 2026-10-14 (read-only). Waiting for Tim's yes.** Owner: Otto. Ada keeps the shared code.

## What Tim saw
On the Android crew app:
- the photo spot on the job card was missing;
- Start / Complete Job showed twice;
- the week strip and the stop cards looked worse than on the iPhone ("iOS looks better").

He also asked for the camera to be as good as the iPhone's.

## Causes (from the code; live copies fingerprinted)
1. **Two button rows.** When GPS moves a card to the top, the card's details are forced open, and they include a Start / Complete row for each visit. The card's own footer used to be hidden on that top card (`2984a5e2`). Commit `7b9f598f` removed that rule.
2. **No photo spot.** The Before / After / More strip is drawn by `renderPhotoStrip` (`schedule-pill-workflow.js`:1650), but only when the card is opened by a tap. Moving a card to the top by GPS (`mw-hero-promoted`, live `schedule.php`:3353) never draws it.
3. **The week strip** is single letters over numbers in circles (live `schedule.php`:2500, `mobile-cards.css`:117). The iPhone's `WeekStripView.swift` uses rounded tiles: a 3-letter weekday, the date, and a work dot (orange = to do, green = all done).
   - The red Monday is the Thanksgiving holiday (`company_holidays`), not a problem.
4. **The card header.**
   - The web leads with the client name; the iPhone leads with the property name.
   - The iPhone also shows arrival time + distance, crew, and visit count. The data is already returned by `getCalendarStops`.
5. **The Android camera, compared with the iPhone:**
   - photos are marked failed after 3 retries;
   - Before/After uploads full-size photos (up to 15 MB) with no preview or retake;
   - one shot per tap;
   - no markup;
   - location comes from the page's cached position, not the photo's;
   - camera permission handling is weak;
   - its own v1 queue, not the shared v2 component.
   - On both platforms, a repeat upload can't be spotted, because there's no idempotency key.

## Steps (each tested on the local site at 412 px before production)
1. Draw the photo strip when a card is moved to the top by GPS.
2. Show one action row on the top card.
3. Week strip:
   - iPhone tiles, using `--mw-*` tokens;
   - work dots from a per-day count in a service;
   - the holiday shown as a red date, not a red fill.
4. Card header like the iPhone:
   - headline order: property, then company, then contact (one tested helper);
   - "09:00 est. arrival" and distance;
   - crew line, gated on a permission key;
   - visit count.
5. Camera, web side:
   - retry forever with back-off;
   - resize to 2048 px, keeping the photo's own time;
   - multi-shot Before / After / More through the shared camera;
   - the shared v2 photo queue;
   - an idempotency key on both platforms.
6. Deploy:
   - edit the LIVE `schedule.php` / `mobile-cards.css` and deploy with `deploy-merged`;
   - bump the service worker cache past mw-v76;
   - reset OPcache;
   - fingerprint the changed files on the server;
   - check the page renders to `</html>` on a logged-in crew account.
7. Later, only with Tim's go: a native camera plugin and markup on Android. That needs a new APK, and no APK is released without Tim.

## Unknowns
| Unknown | How it's settled |
|---|---|
| Is the GPS-moved card the only place the photo strip is missing? | Local render with a faked location |
| Was Tim's phone on an old cached page? | Service worker version (live mw-v76) and the APK version on that phone |
| Can the iPhone's day counts (`week.php`) be reused for the web dots? | Read and call it locally |
