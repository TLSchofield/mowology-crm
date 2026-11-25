# A learning engine for every AI head — brief for an outside opinion

I'm building **Ittai**, a software platform for small field-service businesses (landscaping first, then other trades such as cleaning or plumbing). It started as the CRM for my own landscaping company, which is still its only user. The business runs through AI "department heads", each owning one area:

- **Sam:** sales (leads, quotes, follow-ups)
- **Yui:** customer communications (routes incoming messages to the right head)
- **Penny:** finance (receipts, bank lines, bookkeeping)
- **Mia:** marketing (campaigns, photos, social)
- **Otto:** operations (schedule, crews, visits)
- **Charlie:** chief of staff (morning brief, priorities)
- **Ada:** technology (system health)

Stack: PHP 7.4+/8.x, MySQL 5.7, shared hosting. The AI is Claude, called over HTTP. Every business's data stays separate.

## What I want

A system that **improves with every business that uses it**, and still slowly improves for my business alone if nobody else ever signs up. Saving AI cost is a side effect, not the goal: all heads together currently spend about $17 a month on AI.

## The trigger case

A client replied to one of our invoice emails: *"can we book you guys in for a fall cleanup?"*

- At first it was filed under finance, because the thread was an invoice. A rule change now looks at the customer's own words, so it goes to Sam (sales) with no AI involved.
- A fixed word list already recognises "cleanup" as a service.
- What's missing: turning the request into a **ready quote for my approval**: this client, this property, the fall cleanup we did last year, at last year's price.

## The proposal: one engine, the same loop for every head

**Recognise → suggest → owner confirms → it learns.** This is modelled on something that already works for Penny's bank lines:

1. **Rules first, no AI.** A rule suggests the answer (for bank lines: which account a payment belongs to). A learned rule only switches on after the owner has confirmed it twice, on two different items. Only the owner's decisions teach it, never an automatic import.
2. **AI only on request.** When no rule is sure, the card shows an "Ask Penny" button. Only that click calls the AI. Answers are cached per payee and there's a daily cap.
3. **The owner approves, and that teaches.** Nothing is filed without approval. Approvals and corrections create rules, or switch off wrong ones, so step 2 is needed less over time.

### Word pools

- Each item a head recognises (a service for Sam, a topic for Yui, an expense category for Penny, a photo tag for Mia) has a **pool of phrases**, stored as data per business rather than written into the code.
- **Phrases weigh more than single words**, so "fall cleanup" counts more than "fall". **Negative words** count against a match ("fell", "injury").
- **Confidence comes in three levels: sure, likely, not sure.** Each level is decided by which rules matched, for example: known client, one clear service, done for them before. There's no percentage invented up front. Later, a real percentage comes from measurement: how often the owner kept each kind of suggestion unchanged.
- The owner's corrections add phrases to a pool after two confirmations.

### Two layers: private and shared

- **Private (per business):** their own words, emails, clients and prices. Their words override the shared ones.
- **Shared (per trade):** a starter word pool, so a new landscaping business works on day one. A phrase gets **promoted** into the shared pool only when several different businesses confirm the same meaning. Only "phrase → service" ever leaves a business, never the email, the client or the price. Shared phrases carry a region ("fall" vs "autumn", "yard" vs "garden").
- Other trades build their own shared pools the same way, so no developer has to "teach" Ittai a new trade.
- A similar shared layer already exists for vendor knowledge (vendor names and categories shared across businesses).

### What exists today, per head

| Head | Input | What it recognises | Today |
|---|---|---|---|
| Sam | customer email | which service they want | fixed word list in code, no learning |
| Yui | any customer message | which head owns it | topic word rules; the owner's "Move to…" teaches routing |
| Penny | receipts, bank lines | vendor → category, bank wording → account | lesson store + bank rules after 2 confirmations; but 0% of receipts are read without the AI |
| Mia | photos | photo tags | AI every time, about 25% of requests failing, no learning from the owner's tag fixes |
| Otto | crew notes | issue type | nothing yet |
| Charlie | everything | what matters most | ranking already learns from what the owner opens |
| Ada | system checks | healthy or not | fixed rules (nothing to learn) |

There's already a shared **lesson store** for all heads. So far only Penny is connected to it. The plan is to build the engine on that store rather than start a new system.

### Order

1. **Sam first:** build the engine, with the trigger case as the first test.
2. **Yui's routing:** she already learns from "Move to…", so it's a short step.
3. **Penny's receipts:** fix the 0% read-without-AI.
4. **Mia's photo tags.**

Each step is measured on a dashboard already built for this, which shows each head week by week: right-first-time (suggestions the owner kept unchanged) and AI requests, both by week.

## Honest limits

- Today only one business teaches it, so the shared pool starts as my words plus a starter set.
- My business gets perhaps a handful of service requests a week, so learning from confirmations will be slow at first.
- The risk is building a big generic framework before anything uses it. That's why one head goes first.

## What I'd like your opinion on

1. Is "one engine on a shared lesson store, each head bringing its own vocabulary" the right shape, or should each head keep its own simpler rules?
2. Is "two owner confirmations" the right bar for a learned rule? What about for promoting a phrase into the shared pool across businesses: how many businesses, and should a person review it?
3. Is "sure / likely / not sure, decided by which rules matched" better than a numeric confidence score at this stage?
4. What could go wrong with sharing phrase → meaning across businesses (bad data, one business's habits spreading, privacy, regional language)?
5. Is the order right (Sam, Yui, Penny's receipts, Mia), or is there a higher-value first step?
6. Anything you'd cut, or anything important that's missing?
