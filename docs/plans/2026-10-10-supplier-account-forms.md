# Plan — supplier account forms, filled and sent by Yui + Otto (2026-10-10)

Owner: "Can the CRM do this automatically… the end goal is automated business — Ittai."
First case done by hand: Cedar Rim's wholesale application (fillable PDF), filled from
business_settings + the 2026 City of Vancouver licence, sent 2026-10-10 on Tim's yes.

## What it does
1. **Yui spots it.** A reply from a supplier (known vendor, or an outreach we sent) carrying an
   application / credit / account form → routed to Otto: "Cedar Rim sent an account application".
2. **Otto fills it.** Fillable PDF fields matched to the company profile; the right documents
   attached (business licence, later insurance / WorkSafeBC clearance letter); newsletter box ticked
   with office@.
3. **Tim says yes.** Otto's card (web + iPhone) shows the filled form, the note and the recipients.
   One tap sends from the CRM (Reply-To office@). Nothing is ever sent without the yes.
4. **Otto follows up.** Supplier marked "account applied"; a reminder after 7 days without reply;
   any price list that comes back goes to the list importer (Inline plan).

**Never filled by Otto, always handed to Tim:** bank details, credit references, personal
guarantees, signatures, SIN/personal ID. Scanned (non-fillable) forms: Otto drafts the answers
for Tim to copy.

## Ittai split (tenant-ready from day one)
- **Private to each company:** profile values (legal name, trade name, address, GST, phones,
  office email), documents (licence, insurance, WCB) with expiry dates, which suppliers they
  applied to, the forms and emails. Sent from the company's own mailbox and name.
- **Shared knowledge:** the field-name dictionary ("gst_number", "GST#", "Business No." → gst),
  supplier form layouts already seen, which documents each supplier asks for.
- Onboarding asks for the documents once (OnboardingQuestions registry) and Otto reminds
  before each expires (licence renews every Dec 31).

## Unknowns → how each is settled (cheapest first)
1. **Can PHP on cPanel fill a PDF form?** No pdftk / Python there. Test a minimal pure-PHP
   AcroForm filler (set each field's /V + NeedAppearances, incremental update) on Cedar Rim's
   form; open the result in Preview and Acrobat-style viewers. Fallback: fill on the iMac
   (PyMuPDF, proven today) as a queue job. Settled before anything else is built.
2. **Where documents live.** Outside the web root (the licence is not public). Check the
   existing receipt/media storage path convention on prod and reuse it.
3. **How Yui recognises a form.** Rules, no AI: sender is a vendor or an outreach recipient, has a
   PDF attachment, and the PDF has form fields or the words "application" / "credit" /
   "account". Settled on today's inbox (Cedar Rim must match; John Wheeler must not).
4. **Field matching.** Start with a dictionary from the forms we receive; unknown fields are left
   blank and shown to Tim, and his answer is learned (shared: the field name; private: the value).

## Steps (each proved before the next)
1. Company documents: table + upload on Settings, licence stored with its expiry; Otto reminder.
2. Form filler service (pure PHP, unit-tested on Cedar Rim's PDF; round-trip check that fields
   read back).
3. Yui rule → Otto item "account application from X" with the filled PDF.
4. Otto's card: preview, recipients, Send (web + iPhone), follow-up after 7 days.
5. Supplier status on the vendor record (asked / applied / account open / list received).
