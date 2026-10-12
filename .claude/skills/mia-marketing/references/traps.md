# Traps already hit — read before any change to sending, posting or tokens

Add a new entry the same day a mistake is found: date, what happened, the signature, the rule.

## 2026-09-25 — Facebook/Instagram dead since 2026-06-18, looking "Verified"
Commit `3223bde0` removed the legacy key fallback in `SocialEncryption` claiming the new
`SOCIAL_ENCRYPTION_KEY` matched; on prod it did not. Tokens stayed in `social_accounts`
(`?action=diag` → `has_token: true`) but would not decrypt; the error said "no page token", the
badge said "Verified", failures sat in an audit log. Nothing posted for three months.
Fixed 2026-09-26 (`db679a7f`): `SocialEncryption::decrypt()` returns null for undecryptable and
'' for absent, `SocialAccountHealth::sweep()` revalidates hourly from the publisher cron and emails
the office, `tests/Unit/Social/SocialCredentialContractTest.php` guards it.
**Status:** `docs/crm/mia-channels.md` (2026-10-06) still listed "Reconnect Facebook" as Tim's to-do.
Commit `d5e2a88b` (2026-10-07, branch `feature/social-connect-fixes`, not on this branch) says Meta
was reconnected and duplicate account rows appeared. Whether posting works now is **unverified** —
check the health status and a real post before saying so.
**Rules:** a decrypt failure is not an absent credential; never remove a fallback in the same
commit that deletes the tool proving it is unneeded; a stored "Verified" flag is not health.

## 2026-09-25 — Silent Meta failures that read as "working"
Graph pinned to expired `v19.0` was silently rerouted to Meta's oldest live version. Instagram
insights asked for deprecated `impressions`, so the whole call failed and landed as zeros.
Fixed: `MetaService::graphVersion()` (`v23.0`, overridable by `META_GRAPH_VERSION`), `views`
instead of `impressions`, null on failure. **Rule:** a defensive zero plus a batched metric list is
a silent outage — return null and record an error.

## 2026-09-25 — Publisher stale lock shorter than its batch (open)
`app/Modules/Social/Cron/social_publisher.php` releases locks older than 5 minutes; a 10-post batch
with Instagram container polling can take longer, so the next run can publish the same post again.
No unique key on the queue. Still 5 minutes on 2026-10-11. **Rule:** a stale-lock timeout must
exceed the worst-case batch; check `platform_post_id` before an irreversible publish.

## 2026-09-25 — Turning on a publisher cron dumps the backlog
The publisher auto-enqueues every `approved` post at `COALESCE(scheduled_at, NOW())`. Approving =
publish now. **Rule:** count the approved backlog before scheduling a queue-draining cron. ~20
posts were stuck at `publishing` with attempts exhausted after the key failure.

## 2026-09-27 — Review requests silently skipped since they shipped
`ops_settings.google_review_url` was empty, so `ReviewRequestService::maybeSend()` logged
"google_review_url not set — skipping" and sent nothing. Set 2026-09-27. Before 2026-10-05 it also
only ran for crew-flagged visits from `pow-actions.php`; now every completed visit, from both
completion paths. **Rule:** an automatic sender that never sends is broken, not quiet — check
counts (`review_request_sent_count`), not logs.

## 2026-10-05 — Confirmed opt-ins were refused
The opt-in confirm handler never set `consent_email_express_at`, so confirmed people failed
`canSendMarketing()`. `ConsentLedgerService::refresh()` now fills it from a confirmed opt-in.
**Rule:** one consent source (the ledger); keep the contact fields in step from it.

## 2026-09-22 — Google indexed every page four ways
`head.php` built the canonical from `REQUEST_URI`; http/www/.php/trailing-slash aliases each
canonicalised to themselves. Fixed with `.htaccess` 301s keyed on `THE_REQUEST` plus a normalised
canonical (Decision-Log). `public/services/.htaccess` `DirectorySlash Off` is load-bearing.
**Rules:** never link `.php` paths; `/services`, `/about`, `/contact`, `/home` are CMS database
pages (the `.php` files are fallbacks) — edit them in the CMS; pagination is `?p=`, never `?page=`.

## Google accounts — which one owns what (as of 2026-10-07)
- Real GBP listing MOWOLOGY: owned by tlscell@gmail.com. A Jobber-made duplicate
  ("Mowology Lawns & Landscapes", unclaimed) still existed 2026-10-07; removal is manual.
- GBP API access was requested 2026-10-07; until approved, "Connect Google Business" shows
  "No GBP locations found" and `GbpService::mode()` returns `drafts_only` — Tim copies drafts.
  On approval: enable the three Business Profile APIs, publish the OAuth app out of Testing
  (Testing tokens die every 7 days), connect as tlscell, tick "approved" on the Listings page.
- Search Console: the real property is `sc-domain:mowology.ca` under Tim's second Google account;
  the CRM is already connected (`public/crm/gsc/connect.php`). Do not reconnect it.

## Prod ahead of the repo
Several social files were rescued from prod (`social/schedules.php` once bound 13 values into 11
placeholders). Production `public/` is not fully audited. **Rule:** `cmp` every existing file
against prod before deploying it; three-way merge `mia-card.*`, `MiaCampaignService.php`,
`mowology-brand.css`, `database_appstack.php`.

## The live campaign sender had no send-time consent check (found 2026-10-11)
The repo's `campaign_sender.php` re-checked consent before each send; production's copy did not
(the check never deployed — prod matched the repo minus those lines). 69 sends went out on list-time
consent only; 68 had consent when sent, contact 24 (a same-second yes/withdrawn) did not.
Fixed: the sender now refreshes the ledger once per run and `canSendMarketing()` asks
`ConsentLedgerService::allows()` whenever it knows the contact id — one consent source.
**Rule:** after touching any send path, `scripts/prod-file.sh` it — a missing gate on prod is silent.

## 2026-10-12 — Fable review
Rules now enforced in code:
- Commercial automation emails (`automation_runner.php` send_email for any trigger but requested
  quotes and bills owed — `CaslRules::isCommercialTrigger()`) ask `canSendMarketing()` with the
  contact id at send time and go out only through `CaslRules::composeHtml()` (postal address +
  unsubscribe, or null = not sent). Same for referral invites and review requests.
- Referral reward notices are treated as transactional (not consent-gated) but carry the footer.
  **Tim to confirm.** `ReferralRules`: no self-referral (email/phone), no existing clients, one
  referral per person; awards only for a different contact's first completed visit.
- A withdrawal is undone only by express consent (`ConsentLedgerService::evaluate`); prod contacts
  28, 78, 1316 lost implied email consent that a later job had re-granted.
- CMS block/page endpoints need `marketing.edit`; block HTML goes through `CmsHtmlSanitizer` on
  save and on render (rich_text, custom, text sections).
- Tracking links are signed (`TrackingLinks`): unsigned clicks redirect only to mowology.ca and
  count nothing; unsubscribe links are never tracked. Opens count only with a signed pixel.
- `campaign_sender.php` holds a MySQL named lock and claims each row pending → sending; a row left
  in `sending` >1 h fails ("interrupted") instead of being re-sent.
- Meta page tokens stay in the session (`?action=pages` no longer returns them).
Not done: emails sent before 2026-10-12 carry unsigned links — their clicks/opens no longer count.
