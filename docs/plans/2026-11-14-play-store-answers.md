# Google Play Console: draft answers for ca.ittai.app (2026-11-14)

Status key: DONE entered by Tim · READY safe to enter now · CHECK needs a decision or a screenshot first.
Evidence: `2026-11-14-android-play-data-audit.md` (Codex read-only audit of the code), the live privacy policy (https://ittai.ca/privacy/, 2026-11-06) and terms (https://ittai.ca/terms/, version 2026-11-06).

## App content tasks
| Task | Answer | Status |
|---|---|---|
| Privacy policy | `https://ittai.ca/privacy/` (live; names Mowology Lawns & Landscapes Ltd). Add before production: contacts-list access, AI-training statement, deletion page link. | READY |
| Ads | No ads. No ad SDK and no `AD_ID` found (check the merged Play manifest once built). | READY |
| Target audience | 18 and over only. | READY |
| Government apps | No. | READY |
| Health | No health features. | READY |
| Trader status | Declared by Tim. | DONE |
| Content rating | Category: utility / productivity / other. No violence, sexual content, profanity, drugs, gambling. The social/UGC question: the business emails and texts its own clients; no public sharing; word it from the actual screen. | CHECK (screenshot) |
| Financial features | Bookkeeping, invoicing, bank-statement import, optional QuickBooks link; no lending, banking, payments processed in-app (Ittai takes no card payments for other businesses). Pick from the actual options. | CHECK (screenshot) |
| Sign-in details (app access) | Login required; reviewers cannot self-sign-up. Needs a dedicated demo business and test login on the live server (Tim's go-ahead). | CHECK (later, with the Play build) |
| App category | Business. Contact: a public business email, phone and the website. | CHECK (Tim: which email) |

## Data safety draft (from the code that remains in the Play build)
Collected, all over HTTPS (encrypted in transit: Yes), processed to run the service, no advertising, no analytics, no sale:
- **Personal info:** name, email address, phone number (account), physical address (client properties), user IDs.
- **Financial info:** receipt and expense data (vendor, tax amounts, totals, category, payment method).
- **Location:** approximate and precise, foreground only, optional (nearby properties, new client at current location, geotagged photos).
- **Contacts:** optional; only contacts the person selects are uploaded (prominent in-app disclosure exists).
- **Photos and videos:** receipt, job, plant, label and equipment photos.
- **App activity:** schedule, time, notes, checklists, search terms, office decisions.
- **Not collected:** crash logs go to a local file only (no upload, no crash SDK); no advertising ID; no device ID unless push is added (Ittai has no Firebase client); no web-browsing history; files and documents: no.
- **Shared with third parties:** declare nothing as "shared" only if every outside party is a service provider acting for the business (hosting in Canada, Anthropic AI assistants, Google maps/receipt reading, Intuit when the owner connects QuickBooks). This classification is a legal judgement: Tim and Sit Lim confirm before submitting.
- **Deletion:** users can request deletion; needs the web page and in-app action below. Business records the law requires (six years, CRA) are retained and the page says so.

## Work this creates for the Play build
1. Native just-in-time location disclosure and request (the only requests found live in the tracking setup screen, which the Play build removes).
2. Remove `ACTIVITY_RECOGNITION` (both entries); decide `POST_NOTIFICATIONS`: remove with the tracking screen, or add a just-in-time request if Ittai push is kept.
3. In-app "Delete account / request deletion" under Account + a public web page (`ittai.ca/delete-account/`) that states what is deleted, what must be kept and for how long, with the 30-day export window from the terms; endpoint that records the request and notifies the office.
4. Remove the Account screen's "Update now" and "Allow updates"; verify the merged manifest has no install-package permissions, no `AD_ID`, no tracking service.
5. Privacy policy and terms edits (contacts, AI training statement, deletion, Penny and Kaname named as assistants, records duty), reviewed by Sit Lim, one new version of each, plus the sign-in re-accept decision.
