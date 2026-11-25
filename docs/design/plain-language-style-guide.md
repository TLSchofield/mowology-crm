# Plain-language style guide for the CRM

For every word a person reads in the CRM: buttons, page titles, empty states, errors, hints, confirmations. Written for the newcomer in the first week, who is not technical and is busy. Use it on each page as it moves to Ittai v1.2.

Voice: a calm, capable colleague. Short, specific, no hype. This matches the learned voice of the owner: about nine words a sentence, say what happens and when, no exclamation marks, no emoji.

SMS and email have their own hard rules (`CLAUDE.md` rules 8, 9, 11). This guide does not replace them.

## The seven rules

1. **One idea per sentence. Aim for 15 words or fewer.** Two ideas, two sentences.
2. **Use the word the person uses.** Quote, job, invoice, client, visit, crew. Never record, entity, object, instance, entry, payload.
3. **Say what happened, then what to do.** "Your quote didn't send. Check the email address, then try again." Never only the first half.
4. **Active voice, present tense.** "We sent the invoice." Not "The invoice has been sent."
5. **Name the thing.** "Save invoice", not "Save". "Delete this quote", not "Delete".
6. **Sentence case everywhere.** "Create invoice", not "Create Invoice". Names and acronyms keep their capitals.
7. **No exclamation marks, no filler, no blame.** Cut "please" unless asking for a favour, "simply", "just", "successfully", "oops".

## Words to use and avoid

| Avoid | Use |
|---|---|
| Utilize, leverage | Use |
| Initiate, commence | Start |
| Terminate | End, stop |
| Authenticate | Sign in |
| Navigate to | Go to |
| Invalid | Not right, not allowed (say which) |
| Failed, error | Didn't work, couldn't (say what) |
| Submit | The real action: Send, Save, Book |
| Entity, record | The real thing: client, invoice |
| Unable to | Can't |
| Network error | Can't reach the server |
| Database error | Something went wrong on our side |

## Message patterns

**Page title:** a noun the person already says. "Invoices", "Quotes". One line under it saying what the page is for, only if the title is not enough.

**Primary button:** verb + thing. "Create invoice", "Send quote", "Record payment". One filled button per page. Secondary buttons are outlined and also verb + thing.

**Empty state** (the most important screen for a newcomer): three parts.
1. What this page is for, in one sentence.
2. Why it's empty, only if that's not obvious.
3. One button for the first action.

> Before: "No invoices found. Complete a job to create your first invoice!"
> After: "You haven't sent an invoice yet. They appear here once a job is done. [Create invoice]"

> Before: "No job plans found. Create a plan or accept a quote to get started!"
> After: "No job plans yet. A plan is made when a client accepts a quote. [Create plan]"

**Errors:** say what couldn't happen, why if we know, and what to do. Never show a code, a table name or "CSRF".

> Before: "Network error. Please try again."
> After: "Couldn't reach the server. Check your connection and try again."

> Before: "Save failed: " followed by technical text
> After: "Couldn't save this quote. Nothing was changed. Try again, or call us on (778) 846-9273 if it keeps happening."

> Before: "Invalid CSRF token" or "Invalid request. Please try again."
> After: "This page timed out. Reload it and try again." (the technical reason goes in the server log, not on screen)

**Form field errors:** next to the field, naming the field. "Add a first name." Not "First name is required."

**Confirmations (after an action):** say what changed. "Invoice sent to Alder Test Residence." Not "Success!" Fade on its own; no button.

**Destructive confirmations:** name the thing and the result. "Delete quote QUO-2026-0007? This can't be undone." Buttons: "Delete quote" and "Keep it". Never "OK" and "Cancel".

**Status words:** Draft, Sent, Paid, Overdue. One word, sentence case, same word on every page and on the phone apps. Do not invent a second word for the same state.

**Numbers and dates:** money as $1,102.50. Dates as "Nov 6, 2026" in lists, "Friday, Nov 6" for the next few days, "today" and "tomorrow" when true. Times as 9:30 am. Say "3 days late", not "3d overdue".

**Time and waiting:** if it takes more than a second, say what's happening: "Sending…", not a bare spinner.

**First-week hints:** one sentence, one verb, dismissible, and gone for good once the person has done the thing. "Add your first client to start a quote."

## Checks before a page ships

- Read every visible string aloud. If you stumble, rewrite it.
- No string over 15 words in a button, tab, label or status.
- No technical words (CSRF, token, null, undefined, query, JSON, 500) on screen.
- Every error says what to do next.
- Every empty state has one button.
- The same state has the same word on this page, the phone app and the emails.
- Run the word list above against the page: `grep -n -i -E "utilize|invalid|failed|submit|network error|database error"` over the page file and its JS.

## Where this is checked

Each page's pass in plan Step 5 (`docs/plans/net-version-simpler-faster.md`) includes this check. Strings are found in the page file, its inline JS, the API files it calls (the `'error' =>` messages) and `alert(` calls. Fix the wording in place; do not change behaviour.

## Evidence this was written from (2026-11-21)

A scan of the quotes, invoices and jobs pages and their scripts found: about 30 `alert()` messages of the form "Network error", "Save failed: …", "Delete failed: …"; "Invalid CSRF token" in 19 places and "Invalid request. Please try again." in 6; empty states ending in an exclamation mark; "Database error." as a returned message; and title-case buttons ("Create Plan", "Clear Selection"). These are the first things to rewrite.
