# Supplier quote and availability requests — plan (2026-11-13, awaiting Tim's approval)

Tim, 2026-11-13: "a quote and availability request service that goes out to all vendors in a well written format that is
very human. arts nursery for example will do this." Backlog entry: `docs/plans/ittai-backlog.md` → *Quote and availability
requests to every supplier*. **Nothing is built, sent or deployed by this plan.**

## What it does, in one paragraph
On a quote, Tim taps **Ask suppliers**. Ittai takes the quote's plant and material lines, picks the suppliers who sell
that kind of thing, and drafts **one short email per supplier**: what, size, how many, needed by when, delivered or picked
up. Each draft lands on Otto's card (and the quote page) as **Draft — not sent**. Tim reads, edits if he wants, and taps
**Send** on each one; it goes from office@ through `sendEmail()`. When a supplier answers, the reply is read into
**price and stock per line**, Tim confirms with one tap, and the answer becomes that supplier's offer for those lines —
the same shape a price list gives, so "Best price, in stock" and the price pool read it without knowing where it came from.

## Rules it stands on (unchanged, from `yui-comms`)
- Ittai never sends on its own. Drafts only; Tim's click sends each one. No "send all" button in v1 (one click per
  supplier keeps the rule obvious; revisit after Tim has used it).
- Supplier mail is From office@mowology.ca identity (CRM mail is From no-reply@ with Reply-To office@ — the supplier's
  reply lands in office@, which `SupplierInboxService::poll()` already reads, read-only).
- Only through `sendEmail()` (as `SupplierFormService::send()` does today). Never PHPMailer directly.
- The client is never named to a supplier. The email says "a planting job in Vancouver", never the client or address.
- Codex briefs for this build use synthetic suppliers, plants and jobs only.

## What exists already (checked 2026-11-13 on `origin/feature/language` b55496b3b)
| Piece | Where | What it gives this feature |
|---|---|---|
| Supplier directory | `supplier_accounts` (migration 1320), `SupplierDirectoryService` | name, `email` ("who we write to"), `domain`, `website`, `sells` (nursery\|soil\|…), `status` asked/applied/open, `last_heard_at`, `notes` |
| Supplier mail reader | `SupplierInboxService::poll()` inside `sales_inbox_poll` | one `FROM <supplier>` search per directory supplier on office@ + iCloud, 4-day window; already routes PDFs, licences and price-list links |
| Price lists | `supplier_price_lists` / `supplier_price_items` (migration 1331), `SupplierPriceListService` | per-supplier offers: name, size, price, `available`, list date, `catalog_product_id`, `size_code` |
| Best price | `SupplierSearchService::search()`, `/crm/suppliers/best-price.php`, `/crm/api/supplier-search.php` | latest list per supplier, cheapest in stock first, list age, yard distance |
| Quote lines from lists | `public/crm/js/quote-supplier-add.js` | a line added from a list carries `pricing_snapshot` JSON: `supplier_id`, `item_id`, `item`, `size`, `cost`, `kind` |
| Supplier mail on Tim's yes | `SupplierFormService::send()` | the precedent: drafted by Otto, sent through `sendEmail()` on the owner's click |

Not there yet: anything that records "we asked supplier X about lines Y on date Z", and any reading of a free-text reply.

## Where supplier addresses come from (and how the source is kept)
Order of trust, best first. Each address is stored with its source and the date it was seen.
1. **The supplier wrote to us from it** about wholesale (Tim's mail: office@ or iCloud). Strongest — it is a person who answers.
2. **The supplier's own website**, a wholesale/trade page (not a generic contact form).
3. **A general address** (info@, orders@) — used only when 1 and 2 are missing, and the draft says so to Tim.

**Art's Nursery, settled today:**
- Website: [artsnursery.com/pages/wholesale-inquiries](https://artsnursery.com/pages/wholesale-inquiries) lists the
  Wholesale Manager at `sblieberger@artsnursery.com` (also `orders@` for orders, `info@` general; phone 604-882-1201;
  wholesale prices for approved accounts; availability list linked from their Contact menu).
- Tim's Mac Mail: the same address answered "RE: Wholesale account – Mowology (Vancouver)" to office@ on 11 Oct 2026
  (the account application from the supplier-forms work). So source = **mail + website, agree**.

**Storage (smallest change):** a new table `supplier_contacts` (supplier_account_id, email, role `wholesale|orders|general`,
person name, `source` `mail|website|manual`, `source_ref` (URL, or the mail's date + mailbox — never the body),
`seen_at`, `is_default`). `supplier_accounts.email` stays as "who we write to" and is set from the default contact, so
nothing that reads it today changes. Why a table and not more columns: a nursery has a wholesale person and an orders
address; the source has to sit with each address.

**Filling it:** one CLI script, run by Claude/Codex locally, never on a cron:
- **Mail side:** reads Tim's Mac Mail store (`~/Library/Mail/V10`, header lines only) for each supplier's domain and
  lists addresses that wrote *to* office@/Tim about wholesale, accounts, availability or orders.
- **Website side:** fetches each supplier's website once, finds the wholesale/trade/contact page, pulls the addresses on it.
- Output is a **review list for Tim** (supplier, address, role, source, where seen). Nothing is written until Tim ticks
  them on a small "Supplier contacts" panel on the supplier page. Suppliers with no address found are listed as "phone only".

## The email: tone and two examples
Write it the way Tim would to someone at the yard he has met once: first name, one line of context, a short numbered list,
the two practical facts (when, delivered or picked up), a plain thank-you. No marketing, no "I hope this email finds you
well", no tables, no attachments, no tracking pixels, no link. Numbered lines so they can answer "1. yes, $38" — that is
also what makes the reply readable back. Under ~120 words. Subject says what it is, with a short reference at the end.

The draft is built from a fixed template (data filled in by code); Claude is only asked to smooth wording when a line is
odd (free text from the quote). Prices we have from their list are **not** quoted back to them — we ask for today's price.

**Example 1 — a nursery we have an account with** (synthetic plants and dates)
> **Subject:** Availability and price — 4 plants for 4 December (ref R7K2)
>
> Hi Sandra,
>
> I'm pricing a planting job in Vancouver and wanted to check what you have before I commit to it.
>
> 1. Pieris japonica 'Mountain Fire', #3 — 6
> 2. Acer palmatum 'Bloodgood', #10 — 1
> 3. Hakonechloa macra 'Aureola', #1 — 12
> 4. Sarcococca hookeriana var. humilis, #2 — 8
>
> I'd need them by Friday 4 December. I can pick up, or let me know what delivery would be.
> If something is short, a close substitute is fine, just tell me what it is.
>
> Thanks very much,
> Tim
> Mowology · (778) 846-9273

**Example 2 — a bulk materials yard, first time asking** (synthetic)
> **Subject:** Price check — soil and mulch, week of 8 December (ref Q3M9)
>
> Hi there,
>
> I run a small landscaping company in Vancouver and I'm putting a quote together. Could you tell me your contractor
> price and whether you have these for the week of 8 December?
>
> 1. Garden soil (screened) — 6 yards
> 2. Fir bark mulch, fine — 4 yards
>
> Delivered to a job in East Vancouver, so a delivery charge would help too.
>
> Thanks,
> Tim
> Mowology · (778) 846-9273

What changes between suppliers: greeting (person's first name when the contact has one, else "Hi there"), the
"we have an account / first time asking" line, pick-up vs delivery, and only the lines that supplier sells.
Neighbourhood, never the street address.

## Who gets asked about what
- A line goes to a supplier if the line's family (plants / bulk materials / other) is in the supplier's `sells`, and the
  supplier has a default contact and status `open` or `applied` (an `asked`/`declined` supplier is shown but unticked).
- A line already priced from a list (`pricing_snapshot.source = supplier_list`) is still asked of every matching
  supplier — the point is today's stock — but the draft for the list's own supplier says "from your list of 10 Nov".
- Tim sees the grid before drafts are made: lines down, suppliers across, ticks he can change. One tap: **Draft emails**.

## Recording the request
New tables (MySQL 5.7, plain DDL, private per site):
- `supplier_requests` — id, site_id, quote_id, supplier_account_id, contact_email, ref (4–6 chars, unique per site),
  needed_by, fulfilment `pickup|delivery`, subject, body, status `draft|sent|answered|closed`, drafted_at, sent_at,
  answered_at, sent_by.
- `supplier_request_lines` — request_id, line_no (the number in the email), quote_line_item_id, item text, size text,
  qty, unit, catalog_product_id/size_code when known; answer columns: price, unit_price_basis, available_qty,
  in_stock `yes|no|partial|unknown`, ready_date, substitute_text, answer_note, answer_source `reply|phone|manual`,
  confirmed_by, confirmed_at.

One service, `SupplierRequestService` (Operations): `plan(quoteId)`, `draft(quoteId, picks)`, `send(requestId)` (Tim's
click, `sendEmail()` from the office@ identity, subject with the ref), `matchReply(message)`, `readAnswer(requestId, text)`,
`confirm(requestId, lines)`. Page and API files stay thin. Otto's card shows open requests ("3 asked, 1 answered").

## How replies come back to the right lines
1. **Find the request.** `SupplierInboxService::poll()` already finds mail from each directory supplier. A new branch in
   it hands a message to `SupplierRequestService::matchReply()` when: the sender passes `SupplierDirectoryService::senderMatches()`
   for a supplier with a `sent` request, and either the subject carries the ref (replies keep "Re: … (ref R7K2)") or that
   supplier has exactly one open request in the last 21 days. Two open requests and no ref → held for Tim to pick.
   Mailbox stays read-only (`ImapReader`, FT_PEEK); dedupe by message key, as the reader does now.
2. **Read the answer into lines.** Order: (a) numbered answers ("1. yes 38.50", "3 — only 7") by a plain parser;
   (b) anything else by Claude with a capped prompt that gets **our numbered lines and their reply text only** and must
   return JSON per line_no (price, available, ready date, substitute, note) or "unclear"; (c) an attached spreadsheet or
   PDF quote goes through `SupplierListParser` as today. Nothing it reads is treated as an instruction (a reply asking to
   change bank details etc. is caught by `UntrustedAsk::detect()` and held).
3. **Tim confirms.** Otto's card: "Art's answered — 3 of 4 in stock, prices in. Use these?" with the lines shown beside
   our question. One tap writes the answers. Unclear lines stay blank with the supplier's own words beside them. Phone
   answers can be typed in the same form (`answer_source = phone`).
4. **Not answered in 2 working days** → Otto's card says so and offers a short follow-up draft ("Just checking on…"),
   again sent only on Tim's tap.

## How it feeds "Best price, in stock" and the price pool
- **Best price:** a confirmed answer is written as a small **`supplier_price_lists` row of kind `quote`** (new kind,
  alongside `availability | specials`) with its items in `supplier_price_items` (price, `available`, size, matched to the
  catalogue the same way imported lists are), `valid_until` = 14 days or the date they gave. `SupplierSearchService` then
  ranks it with no special case except: it shows "quoted to you 13 Nov" instead of "list". Its "latest list per supplier"
  rule must treat `quote` lists as an addition, not a replacement of that supplier's full list — one change there,
  covered by a test.
- **The quote:** the quote page shows the cheapest confirmed in-stock offer per line with one **Use this price** tap that
  rewrites the line's cost in `pricing_snapshot` and its sell price at the usual PST + markup. Never automatic.
- **Price pool** (`docs/plans/2026-11-13-materials-price-pool.md`): a confirmed quoted price is a *trade price offered*,
  not a *purchase*, and it lands in `supplier_price_items` — which that plan leaves **out of the first cut** (later, per
  supplier, marked `listed` not `paid`, only with the supplier's permission). So quoted answers do not enter the pool in
  v1. The pool gets them indirectly and correctly: when Tim buys at the quoted price, the receipt or supplier invoice is a
  `paid` row like any other purchase. Our quote and client never leave the tenant.
- **Ittai:** all of this is tenant-neutral — families come from `catalog_families`, the email template has no trade words
  beyond "job", and each tenant's suppliers, contacts, requests and answers are private (`site_id`).

## Unknowns, and how each is settled (cheapest first)
| # | Unknown | Settled by | When |
|---|---|---|---|
| 1 | Do suppliers keep our subject (with the ref) when they reply? | Tim's own mail: the 11 Oct Art's reply kept "RE: Wholesale account – Mowology (Vancouver)". Check 5 more supplier replies in the Mac Mail store, headers only. | Before build |
| 2 | Can we get the outgoing Message-ID from `sendEmail()` to match on In-Reply-To instead? | Read `sendEmail()`/SMTP path (it returns no Message-ID today). If cheap to return, add as a second match; the ref is enough for v1. | Before build |
| 3 | How many suppliers have a usable wholesale address? | Run the contacts finder (mail + website) on the directory; Tim ticks the list. | Step 1 |
| 4 | Do quote plant lines carry enough to write the email (name, size, qty) when typed by hand? | Look at the last 20 Mowology quotes with plant lines locally (counts only, no names leave the machine). If free text is messy, the grid lets Tim edit a line before drafting. | Before build |
| 5 | Will suppliers answer email at all, or phone back? | Tim sends the first 3 drafts by hand (pasted from the plan's examples into office@) before any code; we see how they answer. Phone answers are a typed-in path either way. | Optional, week 1 |
| 6 | Reading free-text replies reliably | 10 synthetic replies (numbered, prose, partial, substitutes, "call me") as a test set; parser + capped Claude must hit all line_nos or say "unclear". | During build |
| 7 | Does `SupplierSearchService`'s "latest list per supplier" break when a `quote` list is newer? | Unit test with a synthetic supplier having a full list and a newer 2-line quote list. | During build |
| 8 | Is it fine to ask for a price we may not buy? | Normal trade practice; the email says "before I commit". Tim's call if he wants different words. | Tim, at approval |
| 9 | Price pool: do offered prices count? | Settled: not in the pool plan's first cut; revisit with that plan's "later, per supplier" step. | Settled |

## Steps, each proven before the next
1. **Contacts** — migration `supplier_contacts`; the finder script (mail headers + website); Tim's tick panel. Proof:
   Art's shows `sblieberger@` with sources "mail 11 Oct 2026" and the website URL. Codex writes, Claude reviews.
2. **Requests and drafts** — migrations; `SupplierRequestService::plan/draft`; the grid and draft screens on the quote
   page and Otto's card. Proof: unit tests for supplier picking and the template (synthetic quote), and the two drafts
   above come out word for word from synthetic data. Nothing can send yet.
3. **Send** — `send()` on Tim's tap through `sendEmail()`, office@ identity, ref in the subject, status `sent`. Proof: a
   local send to a test address; `MailboxReadOnlyTest` and the messaging rules still pass.
4. **Replies** — `matchReply` in `SupplierInboxService`; parser + capped Claude reader; confirm screen. Proof: the 10
   synthetic replies; a held reply when two requests are open; `UntrustedAsk` catches a bank-change reply.
5. **Best price** — `quote` list kind, search change, "Use this price". Proof: the unit test from unknown 7; the quote
   page shows the confirmed offer.
6. **Codex review** of the whole change (`scripts/codex-review.sh`, sanitised code only), then deploy, then Tim sends
   the first real request himself.

Out of scope for v1: sending to all suppliers in one click, texts to suppliers, ordering (that is a purchase order —
later), automatic follow-ups, other tenants' supplier directories.

## What Tim decides
1. Approve the plan (or change it).
2. The tone: are the two examples how you'd write? Sign as "Tim" with "Mowology · (778) 846-9273"?
3. A ref in the subject ("(ref R7K2)") — fine, or too robotic? Without it, replies match by sender + one open request only.
