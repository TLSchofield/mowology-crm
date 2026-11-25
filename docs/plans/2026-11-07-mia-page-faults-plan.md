# Mia's page on the iPhone — six faults from Tim's screenshot (2026-11-07, 8:02)

Each one traced to its source. Three come from the server (and also show on the web dashboard), three from the app.

| # | Fault | Where it comes from | Fix |
|---|---|---|---|
| 1 | Raw Google error in red (project number, console URL) | Server: `MiaChannelsService::decidePost` returns `$e->getMessage()` from `GbpService` when **Post to Google** fails; the app shows `error` as given | Server: log the raw text (`error_log`, tagged for Ada) and return a plain line: "Google posts are paused: the Business Profile connection is switched off in Google. Copy the post for now." for API-disabled / not-used errors, otherwise "Google didn't take the post. Copy it for now." No URLs or numbers. Same text on the web card. |
| 2 | "Hey TIM" | Server: `MiaDeskService::firstName` does `ucfirst()`, which keeps a name stored all in capitals | A name stored all upper or all lower case is shown as "Tim". Any other mix is left as stored. |
| 3 | "nobody needs a message from you today" above a campaign and a reply | Server: `MiaDeskService::headline` counts only Mia's drafted messages, not the items under it | When there are no drafts but items wait: "Hey Tim — no messages to send today, but 2 things below need you." The extra `$top` argument is already in the signature; `brief()` passes its items. The web card (no items passed) keeps today's line. |
| 4 | "+7 more on the dashboard" above the items | App: `HeadCardView.itemsSection` (my spine change put it at the top) | Move it under the last item. |
| 5 | "Tim replied … Answer them." reads as Tim answering himself | Server: `UnclaimedReplyService::item` uses the customer's first name only | First check the record (read-only) to see who sent it. **If it's a customer who is also called Tim:** use first name + last initial whenever the customer's first name matches the owner's ("Tim R. replied …"). **If it's Tim's own outgoing mail read as a reply:** that's a mailbox reader bug, so drop the item. The poller fix becomes its own task for Yui. |
| 6 | The four "how it works" steps fill the top | App: `ItWorkspaceView.guide` | The steps show the first time you open each head. After that they fold into one "How this works" row at the foot of Waiting now (tap to open), so the items come first. Remembered per head. |
| 7 | "Mia is said twice": the navigation bar title and the name beside the face | App: `TeamView` `.navigationTitle(name)` on the head workspace, plus `SpineHead` | Keep the name beside the face (Tim asked for it there, 2026-11-07) and leave the bar title empty on every head's page. VoiceOver still reads the name from the spine's first row. |

## Steps
1. The app (#4, #6): build, then take demo screenshots for Mia, Penny and Otto.
2. The server (#1, #2, #3, plus #5 after the read-only check). Write PHPUnit tests for `headline` and `firstName` and for the error mapping.
3. Run `vendor/bin/phpunit`, then the code-reviewer agent (Codex is out).
4. With your OK, push to `feature/language`. That starts TestFlight 1.3.127, and the three PHP files are deployed through `deploy-safe.sh`, then checked on production.
