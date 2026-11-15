# Kaname's ask box looks in the supplier lists — plan (2026-11-15, awaiting Tim's approval)

Tim, 2026-11-14, after typing "Impatience" on the iPhone Kaname tab and getting "I can't find anyone or anything matching…":
"Does the search not look in products availability lists?" … "I thought it was a smart search?"

## Why it failed today (checked in code and on production)
`CharlieAskService::ask()` tries, in order: the heads' counts and totals (`CharlieFactAnswerer`), one client's facts
(`ClientFactAnswerer`), then a word search of contacts, companies, properties, quotes, invoices and visits (`context()`).
Only if that word search finds a record does Claude see the question. "Impatience" matched no record, so the code stopped
and answered "can't find anyone" for free; the model never saw it, and supplier lists are not in what it is given.
Separately: no supplier list on production has an Impatiens line today (0 rows on 2026-11-14), so the right answer now is
"no supplier has it on a current list".

## What changes
A new step **after the CRM word search finds nothing, before "can't find anyone"**:
1. Ask `SupplierSearchService::search()` with the question's words (it already handles sizes like "#5", reads only each
   supplier's latest list, ranks cheapest in stock first).
2. If nothing is found, try a **spelling fallback** against the shared catalogue: the nearest genus or common name
   (`catalog_products.group_key`, `common_names`, `catalog_aliases`) within a small edit distance ("impatience" → Impatiens,
   "hydrangia" → Hydrangea), then search again with that name and say so ("I read that as Impatiens").
3. Answer **in code, not by the model** (like the facts answers: free, not counted against the 30 asks), with a button to
   the Best price page for the full list. Three shapes:
   - found: "Impatiens walleriana #1 — Inline Nurseries has 40 at $3.10 (list of 10 Nov); Specimen Trees has 12 at $3.40."
     Up to 3 suppliers; "list N days old" when over 30 days; "sold out" offers last.
   - known plant, not on any current list: "No supplier has Impatiens on a current list. Newest lists: Inline (10 Nov),
     Specimen Trees (3 Nov) …" (honest about what was searched).
   - nothing at all: today's "can't find anyone" line, plus "or a plant or product name".
4. **Permission:** supplier prices only for users with `products.view` or `quotes.edit` (the `best-price.php` rule). Others
   get "a supplier has it" with no prices, or the old line. `ask()` gets the user's permissions via the existing auth helpers.
5. **Same answer everywhere:** the response shape (`answer`, `source`, `actions`) is unchanged, so the iPhone Kaname tab and
   the web card show it without an app update. New `source = 'supplier'`, recorded in `charlie_asks` like the others.

Not in this change: a question that names both a client and a plant ("price Impatiens for the Smith job") keeps going to
the CRM path; mixing the two is a later step. Care-card facts ("when do I cut back…") are a separate source, also later.

## Unknowns, cheapest first
| # | Unknown | Settled by |
|---|---|---|
| 1 | Does "Impatience" sit close enough to "impatiens" and far enough from other genera for one edit-distance rule? | Run the rule over the real distinct genus list (local copy, names only) before building; pick the threshold from the data. |
| 2 | How do `terms()`' rules (capitalised words first, stop words) treat "do we have impatiens in 4 inch"? | Unit tests on synthetic questions; "4 inch" must reach the size parser. |
| 3 | Does the iPhone render an answer with one action button for a non-client answer? | Read the Swift side that renders `actions` (`ClientFactAnswerer` already returns buttons); no app change expected. |
| 4 | Is `SupplierSearchService::search()` fast enough inline (it joins every supplier's latest list)? | Time it on production with `scripts/prod-run.sh` (read-only) for 5 questions. |

## Steps
1. Spelling fallback as a pure static function + tests (synthetic names). 2. The supplier step in `ask()` + the answer
wording + tests with a fake search. 3. Codex review (`scripts/codex-review.sh`, sanitised code). 4. Deploy with
`scripts/deploy-file.sh`; ask Kaname "Impatience" and "japanese maple #5" on production and screenshot the answers.
