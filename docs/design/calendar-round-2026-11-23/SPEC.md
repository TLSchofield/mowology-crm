# Calendar views: Ittai v1.2 design round (2026-11-23)

Status: **design only, awaiting Tim's approval.** No PHP, JS or CSS of the real pages was changed. Built with the design studio (Codex as designer, 3 rounds, all 72 render checks clean: 6 screens x 4 widths x light/dark/field).

Mockups: `mockups/*.html` (open directly; `tokens.css` sits one level up). Screenshots at 1440, 1024 (light) and 375: `shots/`. Studio copy: `~/.mowology-codex/design-studio/ittai-calendar/round-3/`.

## Views covered (inventoried from code)

| View | Code today | Mockup |
|---|---|---|
| Week x Cards (desktop grid, default) | `public/crm/jobs/schedule.php` ~1630-2050 | `week-cards.html` |
| Week x Timeline (hour rows, a column per crew) | `public/crm/js/schedule-calendar-edit.js`, only when calendar edit is on | `week-timeline.html` |
| Day x Cards with map panel; Map = Day's right panel | `schedule.php` ~2076-2552 | `day.html` (split, map only, list) |
| List | `public/crm/jobs/partials/schedule-list.php` | `list-foreman.html` |
| Header, Today, filters, Job plans, view segments | `schedule.php` 1358-1446 | all screens |
| Salt and snow banner | `includes/snow-run-banner.php` | all screens |
| Weather per day, Anytime row, service legend | `schedule.php` 1644-1684, JS, 2059 | all screens |
| Foreman deadlines | `foreman_calendar_appstack.php` | `list-foreman.html` |
| Job details modal (other chat builds it) | new | `job-modal.html` |
| Skeletons and halo bloom | none today (text "Loading...") | `motion.html` |

Day x Timeline and Day x Cards share the Week patterns; the Day mockup shows the Day-only parts. Not checked: timeclock `my-schedule` and dashboard schedule widgets (separate pages).

## Decisions, before and after

| Part | Today | Proposed |
|---|---|---|
| Job block | Solid dark green fill, same for every service | White (`--surface`) block, hairline border, small service shape plus the service word, status as icon + word |
| Service colour | Six hex values set inline in `schedule.php` | Shape + word. No per-service token exists in v1.2, so no colour. Needs Tim's answer (see below) |
| Day header | Dark green bar | Plain header, today marked by a 2 px `--brand` rule and the word "Today" |
| Weather | Tiny text | 14 px, a line icon (cloud, rain, sun, snowflake), "Rain 6 / 3" in tabular numerals; "Salt tonight" as icon + word |
| Salt and snow banner | Mint, filled green buttons | One hairline strip, one outline "Start salt run" |
| Crowded day (24 jobs) | 20+ identical thin bars | Per crew, up to 3 blocks side by side, then a "+5 more" pill that opens a list. A Readable / Compact switch makes each block a one-line row. Week cards show 2 and "Show 22 more" |
| Anytime | Only in the timeline JS | A row above the hours, chips per crew with a count |
| Legend | Coloured dots | Shape + word pairs, collapsed on phones |
| Buttons | Many filled | One filled "Add job" per page; all else outline or text |
| Loading | Plain "Loading..." text | Skeleton shapes that match the real layout, `aria-busy` |
| Motion | None | Blocks fade and rise 6 px (220 ms, 20 ms stagger, max 12). One soft `--brand` ring blooms out once (700 ms) where a figure or job arrives or changes. Reduced motion: no shimmer, bloom or rise; a 1.2 s highlight shows the change instead |

Responsive: 1440 full; 1024 week cards show four days with "Next days"; 768 stacked header; 375 shows one crew at a time with a 48 px crew switcher, a weather chip strip, and a docked 56 px "Add job" bar (nothing floats over content). Targets 44 px desktop, 48 px below 1024. Light, dark and field modes all pass AAA contrast.

Job modal: 560 px centred panel on desktop, bottom sheet on phones. One filled "Open job"; outline "Move job" and "Skip visit"; text link "Message client". Loading and failed states designed ("Couldn't load this job. Check your connection and try again.").

## Wording (plain-language guide)

Verb + thing buttons; sentence case; "Nov 23 to 29, 2026" (no dashes); empty week: "No jobs this week. A job appears here once a quote is accepted." Error: "Couldn't move this job. Nothing was changed. Try again." Status words: Scheduled, On site, Done, Skipped, Needs attention, Locked, "Saved, not sent yet".

## Open questions for Tim

1. **Service colour.** Shapes and words work but are less quick than colour. Add per-service tokens to v1.2 (a design-system change, all platforms), or keep shapes?
2. **Default density** for the timeline: Readable (recommended, fits ~12 jobs a crew a day) or Compact?
3. **Map as its own view?** Today "Map" just opens Day. Keep it a state inside Day, or make it a full peer view?
4. **Modal vs Open job page.** Should the modal replace the job page for quick edits, or be a preview first?
5. **Mission Control KPI header and the Otto strip** (week view) were not redesigned; fold them into this round, or later?
6. **Phone.** The web CRM at 375 is shown as a fallback. Crews use the native apps; confirm that the phone web layout needs no more.
7. **Same motion on iPhone and Android** (backlog 2026-11-23): approve here first, then port.

## Not done / caveats

The map is synthetic (no tiles). The crew switcher, "Next days" and density switch are static in the mockups. Navy `ittai-brand.css` overrides `--mw-green` and `--mw-forest` today, so the "dark green" header may already show navy in the Ittai shell; check on the live page before the build.
