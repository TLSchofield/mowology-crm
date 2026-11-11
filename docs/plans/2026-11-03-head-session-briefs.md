# Head harvest sessions — one fresh session per head, reporting to the parent for audit (Tim, 2026-11-03)

Order: **Mia → Ada → Otto → Yui → Kaname**. Each session: fresh worktree cut from `feature/language`, `use_worktree`,
`wake_when_done` so the parent is continued when it reports. Start them serially: each deploys to the same production and
the same `mowology-brand.css`; the next starts after the parent has audited the previous report.

## Shared background (paste into every brief)
Ittai (the Mowology CRM: PHP 7.4-compatible syntax on PHP 8.3, MySQL 5.7 rules, production tracks `feature/language`) is
harvesting workflow rules from Anthropic's Claude plugins at `~/.claude/plugins/marketplaces/knowledge-work-plugins/`
(small-business, sales, marketing, finance, operations, customer-support, human-resources) into its department heads.
On 2026-11-03 Sam and Penny were completed (`docs/plans/2026-11-03-penny-harvest.md`, `…-small-business-skills-harvest.md`).
Rules: a short plan Tim approves first (`docs/plans/2026-11-0X-<head>-harvest.md`, stop for his yes); Codex review of
every change (`scripts/codex-review.sh <name> <prompt.md> <code paths>`, sanitised code only); nothing sends, posts, books
or moves money by itself; CRM CSS only in `public/crm/css/mowology-brand.css` with `--mw-*` tokens; new logic as pure
static classes in `app/Modules/<Module>/Services/` with one PHPUnit test per rule registered in `tests/bootstrap.php`;
`composer install` in the worktree, `vendor/bin/phpunit` green. Pattern: survey (Sonnet subagent, numbers not prose) →
diff against what the head already does → plan → pure classes + tests → wire → read-only dry run on live data with
`scripts/prod-run.sh <snippet.php>` (rolled back; PHP without `<?php`, `$db`/`$out`) → Codex → fix → deploy ONE file per
`scripts/deploy-step.sh <path>` → OPcache reset at `/crm/api/opcache-reset.php` in Claude in Chrome (Tim's autofill signs in)
→ verify live → vault write-back (`~/Library/Mobile Documents/iCloud~md~obsidian/Documents/30-PROJECTS/Active/Mowology-CRM/Architecture/`)
→ update the head's skill in `.claude/skills/<head>` (bump version; `tests/Unit/Docs` green). Before editing any existing
file: `scripts/prod-pull.sh <one path>` and `cmp` against `git show origin/feature/language:<path>`; production has been
ahead of the branch on many files — pull live `--into-repo` and re-apply on top. zsh does not word-split `$VAR`: loop.
Two lessons: a plugin formula's first number is never shipped without the live dry run (three were wrong on our data);
absent is never zero — a missing source is named "n/a", never estimated. Report back with: what shipped (file:line per
rule), dry-run numbers, Codex findings and handling, what was NOT built and why, the status line
`Tests · Codex · Deployed · Checked live · Waiting on Tim`.

## Mia (marketing) — next
Survey: `small-business/skills/{social-content-engine, content-strategy, ad-manager, review-reputation, seo-ai-visibility,
brand-style, marketing-monday}` + `shared/voice-profile.md` + `marketing/skills/*`. Diff against `MiaCalendar`,
`MiaSequenceService`, `MiaSocialService`, `MiaWording`, `MiaCampaignService`, `MiaChannelsService`, `ReviewReplyDrafter`,
`SocialDraftPipeline`, `MiaSeasonThemes`, `app/Modules/Marketing/Api/*`. Expected pieces (verify): content mix 40/30/20/10
and posts-per-week by team size; caption shape (hook, benefit, CTA, 3–5 hashtags); email subject <50 chars, 100–250 words;
staged times in the future; voice profile from sent mail + the banned-opener slop test on every draft; ad bands (Working ≤15%
off trailing average / Watch 15–40% or frequency >3 / Problem >40% or 14+ days no results; pause only after 30 days or 50
results; budget +20–30% at a time; dollars never percentages alone); review replies <60 words, negative-then-silence = call;
the 200–400-word content brief (push / hold / reposition / seasonal / offers). Load the `mia-marketing` skill first.

## Ada (technology / security)
Survey: `operations/skills/{runbook, risk-assessment, compliance-tracking, status-report, process-doc}`, finance
`audit-support` + `sox-testing` (evidence standard: insufficient = verbal only, undated, no identifiable approver; sampling
by risk; remediation record root cause / plan / date / owner / retest; test the report a reviewer relied on). Diff against
`AdaSecurityRules`, `AdaSecurityWatch`, `AdaRules`, `AdaDeskService`, `BooksWatchService`, `security_posture` cron. Load
`ada-technology`.

## Otto (operations)
Survey: `operations/skills/{capacity-plan, vendor-review, change-request}`, small-business `inventory-planner` + `restock`
(7- and 28-day velocity; stockout on the higher rate, sizing on 28-day; target (60 + lead) days − inbound; buffer
min(30, max(7, lead/2)); overstock >120 days of cover, dead at 90; seasonality only with 400+ days), `payroll-prep` punch
rules weekly on `time_clock_entries` for Otto's desk (shift >12 h, day >16 h, 7 days straight — `PayrollRunChecks::punchFlags`
exists), `call-list` blocks. Diff against `OttoRules`, `OttoActionService`, `OttoBrainService`, `OpsDeskService`,
`RecurringBillService`, supplier lists. Load `otto-operations`.

## Yui (comms)
Survey: small-business `inbox-manager` (needs-you / drafted / handled; >5 "needs you" means the threshold is wrong; archive
never delete; rank by consequence not arrival), `outreach-composer` (cold 0/+4/+11/+21 with word ceilings 120/60/80/40;
never two sends within 48 h; stop on reply/bounce/4 touches; trades send 6–8 am or after 5 pm; slop test), `shared/
voice-profile.md`, `shared/untrusted-content.md` (bank-detail / new-payee / urgent-payment asks go to the owner with no draft,
verified by phone), customer-support `ticket-triage` / `draft-response` / `customer-escalation`. Diff against `YuiRules`,
`YuiDraftService`, `YuiBrainService`, `IcloudInboxRouter`, `InboundTopicClassifier`, `SupplierInboxService`. Load `yui-comms`.

## Kaname / Charlie (chief of staff)
Survey: small-business `monday-brief` (merge, don't staple; "the one thing" closes it; skip missing reports silently; ask
before posting bad news), `business-pulse` (rollup = worst section; forced red on named client + amount + deadline ≤7 d;
retune after 4 weeks), `report-builder` (spec: metrics with formula and source, grouping, comparison, period, cadence;
sanity: partial period, double counting, empty groups shown as 0, totals tie; max 3 findings; "n/a — source" never an
estimate), `report-pack` (a scheduled run asks nothing and never skips for a data gap), `daily-briefing` (sales plugin).
`PulseBands` and `SamSignals` already exist — reuse. Diff against `CharlieBriefService`, `CharlieRankService`,
`CharlieUrgentService`, `CharlieInboxService`, `HouseBriefAdapter`, `PennyBriefAdapter`. Load `charlie-chief-of-staff`.
