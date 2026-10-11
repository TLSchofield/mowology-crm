# Where Ada will appear — PLANNED, NOT BUILT

## Existing places she plugs into (verified 2026-10-11)
- **Charlie's brief.** `CharlieBriefService::collect()` asks every head through a list of sources
  (`app/Modules/ChiefOfStaff/Services/CharlieBriefService.php`). Each head returns
  `['head', 'headline', 'count', 'items' => [key, text, url, priority, kind, value, since]]`;
  a head that is not deployed is simply absent, and one that throws is listed as failed.
  Planned: an 'ada' source with the same contract (read-only, cheap), added beside Penny, Sam,
  Otto, Mia and Yui. Her items carry stable keys (system + problem) so dismissals stick.
- **Ready copy for the iPhone opening screen.** `HeadBriefReadyService::rebuildCharlie()` keeps
  a built brief in `head_brief_ready`, rebuilt by the `charlie_morning_brief` cron every 15 min.
  Ada's items reach the iPhone through it with no app change — but her `brief()` must stay fast;
  the cron already spends ~11 s of a 29 s budget (Tech-Debt-Map).
- **Database Manager** (`public/crm/database_appstack.php`): Crons tab (registry + last runs),
  migrations, schema snapshot. Ada's health page should link to these, not duplicate them.
- **System log** page `public/crm/system-log_appstack.php` (table `system_log`).

## New surfaces (planned)
- **Health page** (CRM, AppStack, admin only): one row per system, green / amber / red,
  "last worked", evidence line, hand-off text with a copy button.
- **Team card** in the heads deck (`public/crm/includes/dept-heads-deck.php`), like the other heads' cards (face, column via
  `TeamCardService::column()`).
- **iPhone**: through Charlie's ready copy only, at first. A native health screen is not planned.
- **Ittai**: per-tenant rows once a tenant model exists.

## Rules
1. Logic in one planned server service; page, card, brief and any API only call it.
2. Ada's brief must never be the reason Charlie's brief is late — cap her work per run, and
   read precomputed results if a check is slow.
3. When a surface is added, list it here with its date.
