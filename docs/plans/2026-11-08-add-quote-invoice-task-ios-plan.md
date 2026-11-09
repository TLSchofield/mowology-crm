# Add → Quote, Invoice, Task on the iPhone, all working offline (Tim, 2026-11-08)

Tim: "Quotes, Invoicing, Task (could be client or for the business, e.g. pick up salt) need to be available in app.
Maintaining fully offline capabilities throughout."

## What is there today (checked in the code)
- **Add menu:** `App/AddDial.swift` lists only Job and Client. Both open `FieldJobView` on Schedule.
- **Offline today:** clock-ins, job start/stop, photos, receipts, recommendations, trip reports and the schedule cache
  each have their own queue in `Core/Offline/`. **Job and Client creation do not work offline**: `FieldJobView` posts
  straight to the server.
- **Server:**
  - Quotes: `QuoteService::create()`.
  - Invoices: `InvoiceFromVisitService::createFromVisit()` (from a done visit) and `InvoiceService`.
  - Tasks: `public/crm/api/tasks.php` (general and purchase tasks, all logic inline in the page).
  - All three are web, session-only pages. **None has a phone (JWT) endpoint.**

## Unknowns, and how each gets settled
1. **What an invoice starts from:** a done visit, or a blank invoice for a client? → Tim (question A).
2. **Sending while offline:** may a quote or invoice made offline go to the customer by itself when the phone
   reconnects? → Tim (question B). Recommendation: no. Offline makes a **draft**; it syncs and gets its number when
   online, and Send appears then. Nothing reaches a customer without a tap while online.
3. **What the phone needs to hold for offline use:** clients and properties, the price list (services and rates), crew
   names, and recent visits for invoicing. → read the existing mobile endpoints. Settled before the build.
4. **Numbers (QUO-2026-…, INV-2026-…):** only the server assigns them. Offline drafts show "Draft — numbered when
   online". Each draft carries an id made on the phone, so a retry never makes a second copy (as `TransitionQueue`
   does).
5. **Who may create what:** reuse the web's permission keys (quotes.create, invoices.create, tasks.create); crew
   see Task only. → read `authz.php`.

## Build, in order (each step proved before the next)
1. **One shared offline outbox** in `Core/Offline/` for "create" actions. It saves to disk, drains when the phone
   reconnects, and shows a sync line ("2 waiting to sync" / "synced" / the exact error). Job and Client move onto it
   first, so the current Add flows also work offline. This step proves the outbox.
2. **Cache for offline use:** clients and properties, price list, crew. Refreshed when online, read when offline.
3. **Task:** for a client or for the business, with a title, due date, who does it and an optional purchase list
   ("pick up salt"). New `app/Modules/Tasks/Services/TaskCreateService.php` (rule 10: no new logic in pages) with
   PHPUnit tests, plus a JWT endpoint.
4. **Quote:** client and property → lines from the price list → total with GST → save as draft (offline) → Send when
   online, through the existing `QuoteSendService` (PDF and email as on the web).
5. **Invoice:** per answer A. Saved as a draft offline, then sent online through `InvoiceFromVisitService::send()`
   (PDF required, as now).
6. **Add menu:** Job, Client, Quote, Invoice, Task. Crew see what their permissions allow.
7. **Proof:** PHP tests, iOS logic tests for the outbox, simulator screenshots with the network off (airplane mode)
   and then back on, a code-reviewer pass, then push for TestFlight and deploy the server files. Each step ships
   on its own.

Android and web parity: follow-ups.

## Questions for Tim
- **A.** An invoice starts from: a done visit (recommended: it fills the lines from the visit's work) / a blank invoice for
  a client / both.
- **B.** Made offline: a draft that you send once online (recommended) / sends by itself when the phone reconnects.

## As built (2026-11-08)

Built with the two recommendations above (Tim said go without picking): an invoice starts from a finished visit; anything made offline is a draft sent later by a tap. Shipped in
1.3.128 (outbox, Task) and 1.3.129 (Quote, Invoice). Not yet proved on a phone in airplane mode.
- Quote and Invoice are office-only (admin/manager) in the Add menu. Crew get Job, Client, Task and invoice from the visit.
- A phone quote goes to whoever QuoteRecipientService picks; it is sent from Sam's Quotes to send.
- Add a job with no signal can only make a new client and job; a cached nearby list is still to do.
- Android and web: follow-ups.
