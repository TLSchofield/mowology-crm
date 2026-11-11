# Signed quotes that arrive by email (2026-10-15)

Tim: "Alena has emailed back with quote auths, how come Sam doesn't know? Otto? Have the PDFs been attached to the
quotes? Have they then been turned into jobs?" → "And fix".

## What happened (production, this session)
- Alena Radosovska (VML, contact #1535) emailed "Snow Removal - 2025/2026 - Signed quotes" to mowology@icloud.com on
  Oct 14 with 9 signed + APPROVED PDFs ("KENWOOD - MOWOLOGY - SNOW 0060 - SIGNED.pdf" …). The iCloud reader stored it,
  routed to Sam — **without its attachments**: `SalesInboxService::keepAttachment()` keeps files only when the head is Penny.
- Tim's forward to office@ kept the original Message-ID, so the office@ reader counted it as already kept — attachments
  lost again.
- No quote number in the subject → `quote_id` NULL. Sam's follow-up queue is per quote / per contact; the quotes went to
  Ron (#32), the reply came from Alena (#1535) → Sam drafted a chase to Ron for quotes already signed.
- Nothing accepted the quotes → no snow contracts, nothing for Otto. (Fixed by hand today: QUO-2026-0060…0069 except
  0059 accepted with Alena's signed copies; contracts CTR-2026-0039…0047 set up.)

## Fix
1. **Keep the files.** Inbound client mail keeps its PDF / photo attachments whatever head it goes to (not just Penny),
   and a copy of an already-kept email (same Message-ID, another mailbox or a forward) adds the files the first copy lacked.
2. **Link the email to its quotes.** Quote references are read from the subject, body **and attachment filenames**
   ("SNOW 0060", "QUO-2026-0060"), matched only to open quotes of the sender's company or contact.
3. **"Looks like an acceptance" on Sam's desk** (web card + iPhone): a reply with signed / approved / accepted words or
   signed-quote PDFs lists the quotes it matched, each with its PDF. Tim taps **Accept** per quote → the same path used
   today (accepted, Sam's summary, contract / tray job, Otto) and the signed PDF is filed on the quote. Nothing is accepted
   without the tap.
4. **Stop chasing what's answered.** A reply from anyone at the quote's company (contact or same company) after the quote
   was sent pauses Sam's follow-up on that company's open quotes, shown as "VML replied — look first".
5. **Signed copy on the quote page.** The quote page lists the signed PDFs filed on it.

## Verify
Unit tests (quote refs from filenames, company-level pause, attachment keep rule); local site; Codex review (client
mail, quotes → contracts); deploy; production preview on Alena's email (should find 9 quotes); iPhone build for the card.
