# Ittai works when the internet doesn't — selling point (Tim, 2026-11-13)

Tim: the offline hardening is a feature and suits the site. Trigger: a large provider outage took Microsoft down for a day.
This page holds the copy AND the proof it needs. **Do not publish a line until its proof is ticked** (rule: only claim what
live data proves). Tenant-neutral: no trade words (standing rule 10, multi-discipline tenants).

## The promise (one line options)
- "Your day doesn't stop when the signal does."
- "No signal, no problem. Ittai keeps working and catches up on its own."
- Subline: "Clock in, finish the job, snap the receipt. Everything waits safely on the phone and sends when you're back online."

## What it does (each line = a claim, with its proof status)
| # | Claim for the page | Backed by (code read 2026-11-13) | Proved on a real phone in airplane mode |
|---|---|---|---|
| 1 | Your day is on the phone: today's and tomorrow's schedule are saved each evening and open with no signal | StoredDayStore + OfflineScheduleWorker (Android); schedule cache (iPhone) | [ ] Android [ ] iPhone |
| 2 | Clock in and out, start and finish jobs, take breaks with no signal. Each tap keeps its real time | PendingAction queue with `client_time` | [ ] Android [ ] iPhone |
| 3 | Photos and receipts are kept on the phone from the moment you take them, and send when you're back | Photo and receipt queues (a receipt photo is on the phone from the shot, iPhone 1.3.131) | [ ] Android [ ] iPhone |
| 4 | Add a client, a job, a note, a quote or an invoice draft with no signal | Create outbox (iPhone), Android outbox + office documents | [ ] Android [ ] iPhone |
| 5 | Nothing is ever sent twice: every saved item carries its own ID, so a retry can't double it | `client_request_id` + server idempotency | [ ] needs a deliberate double-send test |
| 6 | Nothing goes to a customer by itself: drafts wait for you, and sending is one tap when you're online | Quote/invoice send is online-only, explicit | [ ] |
| 7 | If something is refused after the fact, you're told what and why, and what depended on it. It never vanishes | Refusal words + dependents (OfflineStartLogic) | [ ] |
| 8 | GPS points taken offline are kept and sent later | Tracking queue | [ ] |

## What we must NOT claim (true limits today)
- Not "fully offline": the office side (head cards, Search, Yui and Subcontract decisions, sending an invoice or a statement) needs a connection.
- Not "works for days": the saved schedule is today and tomorrow. How long a phone stays signed in with no connection, and whether queued items expire, are **unchecked** — check before any "for a day/for days" line.
- Not "invoice from the field offline": a crew member can't create an invoice from a visit with no signal today.
- No messaging feature exists; never imply one. Client emails/texts come from the server.
- Android native is still behind switches and not in crews' hands; copy that names Android waits for the APK. The iPhone side is what can be claimed first, once proved.

## To earn the claims
1. Airplane-mode run on the iPhone TestFlight build and the Android test APK against the test tenant: steps 1–8 in the table, then reconnect and check nothing lost or doubled. (Offer: a one-page checklist; Tim runs it, or the test is done with Tim on the phone.)
2. Check the sign-in lifetime and queue age limits in code (quick read); state the true duration.
3. Then copy goes to the site (public/ and Ittai site), reviewed through the copywriting skill; "a day" only if step 2 allows it.

## Draft section for the page (after proof)
**Works when the signal doesn't.**
Your crews work in basements, on hillsides and in dead zones. Ittai keeps working there.
- **Your day is already on the phone.** Tomorrow's schedule downloads each evening.
- **Tap now, send later.** Clock in, finish the job, take the photo. Each tap keeps its real time, and nothing waits on the signal.
- **Receipts and photos are safe from the shot.** They are saved on the phone before anything else happens.
- **Never twice.** Every saved item has its own ID, so catching up can't double anything.
- **You stay in charge.** Quotes and invoices wait as drafts. Nothing reaches a customer until you send it.
