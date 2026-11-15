# Penny gives no financial or accounting advice (rule + plan, 2026-11-14)

Tim, 2026-11-14: "We must not offer financial or accountancy advice. With Penny." Reason (from the legal research the same day): Ittai holds customers' ledgers; the retention, location and privacy duties stay with the customer and we are a record-keeping tool, not an adviser. Rule 6 is now in the Penny skill (`.claude/skills/penny-bc-bookkeeping/SKILL.md`).

## The line
**Allowed (bookkeeping and information):** record, organise, match, ask one question at a time; say what she did and why ("filed as Fuel because past Kerrisdale receipts were Fuel"); proposals the owner or accountant confirms; report facts with their source ("taxable sales so far $X; CRA's small-supplier limit is $30,000"); show what is waiting; export for the accountant.
**Not allowed (advice):** telling the owner to register for GST, how to pay themselves (salary vs dividends), what to claim or deduct to save tax, which structure to use, whether a purchase is a good idea, tax results or refunds predicted, investment or loan guidance. These go to "ask your accountant" (and the accountant invite).

## Where it can leak (to audit)
1. AI prompts: receipt reader and Penny's read (`PennyReadService`), Ask Kaname (`CharlieAskService`: free text can be asked anything), look-back proposals, month-close, vendor learning.
2. Fixed copy on cards/emails/onboarding: e.g. "change it if you'd rather book mix oil there" (fine: a proposal), the GST $30,000 warning (backlog; must state the fact and say to ask the accountant), look-back "GST -$89.04" lines (fact), "How this works" text.
3. Words to look for: should, recommend, advise, you need to, you must, save tax, deduct, claim, write off, better to, optimize.

## Steps
1. Codex read-only audit of those surfaces (web + iPhone + Android strings, server prompts) for advice-like wording; table of findings with file:line (Claude reviews).
2. Guardrail in every AI prompt: a shared constant (`PennyNoAdvice::PROMPT`) with the rule and the refusal wording; Ask Kaname classifies advice questions and answers with the fixed line ("I can't give financial or accounting advice. I can show you what's in your books. Your accountant can answer this.") plus the invite-accountant action. Tests with sample questions.
3. Fix the flagged copy; the GST warning wording goes through the `regions` skill and the accountant first.
4. Visible disclosure: one line in the "How this works" info node on Penny's page, in onboarding's terms step, and in the terms ("Penny is a bookkeeping assistant, not an accountant or adviser"). The terms text needs a lawyer's or Sit Lim's read, not mine.
5. Same on iPhone, Android and web in one job (design/copy rule applies everywhere).

## Official sources instead of advice (Tim, 2026-11-14: "The AI responses could point to the GST page on CRA ... for example")
Whenever a tax or accounting question comes up (Ask Kaname free text, a warning card, onboarding), the answer is: the facts from the owner's own books, then "CRA explains this here" with a link to the official page, then "your accountant can advise". **Rules:**
- Links come from a **curated list per region** (`.claude/skills/regions/CA.md` data, topic key → title, URL, verified date), never written by the model. The AI picks a topic key; the server renders the link. A page that was not checked is not in the list.
- Verified 2026-11-14 by fetching: CRA "GST/HST for businesses" `https://www.canada.ca/en/revenue-agency/services/tax/businesses/topics/gst-hst-businesses.html`, whose sections include "Who needs to register" `.../gst-hst-businesses/when-register-charge.html` and "Get a GST/HST number" `.../gst-hst-businesses/gst-hst-account.html`. The $30,000 small-supplier figure was NOT on the first page: confirm which page states it before the GST warning links to it. Records: CRA RC188 "Keeping records" and the electronic record-keeping circular (see the legal research).
- Topics to curate first: GST/HST registration and small supplier, input tax credits, keeping records, payroll deductions (CRA), BC PST, WorkSafeBC. Each has a verified date and is re-checked on a schedule (the `regions` skill already tracks verified dates).
- Wording stays neutral: "CRA's page on registering for GST/HST", not "you should register". No links in SMS (project rule 11); in-app and email only; the phone opens them in Safari/Chrome.
- Same list serves iPhone, Android and web.
