# Receipt AI: a business profile instead of Mowology and BC (plan, 2026-10-26, awaiting Tim's approval; nothing built)

Tim, 2026-10-26: the audit's "needs the tenant work: the prompt and checks that name Mowology and assume BC taxes".
Rule that governs it: Ittai tenants will be other trades in other provinces; Mowology is tenant #1, not the template (CLAUDE.md §10).
Hard constraint (Tim, 2026-10-24): do NOT change how the app works for the existing business — Mowology must read, check and prompt EXACTLY as today.

## What is hard-coded today (verified in the code, 2026-10-26)
| Where | What it assumes | Tenant-neutral version |
|---|---|---|
| `ReceiptBookkeeperService::SYSTEM_PROMPT` | "In BC, GST is 5% and PST is 7%"; "the Dodge Ram truck"; "mowers, trimmers, blowers"; the example "Marine Dr Town Pntry is a Chevron" | tax sentence, vehicle and equipment labels, and examples come from the profile (the business name and owner already do: `BusinessIdentity`) |
| `ReceiptBookkeeperService::check()` | GST at most 5% of subtotal, PST at most 7% | the profile's rates (HST provinces: one tax in the GST slot; Quebec: QST in the PST slot) |
| `ReceiptBookkeeperRules` | diesel = the truck, gas under $50 = equipment, EGO items = equipment: three rules written as code | a per-business rule list (settings), seeded for Mowology with exactly these three |
| asset tags `truck / equipment / stock` | landscaping words | same stored values, labels per business ("vehicle", "tools"...) |
| `ExpenseGate::gstRate()` | already reads `TaxEngine::gstRate($db)` (a per-business setting) | nothing to do |
| `ReceiptParser` / matcher | 5% assumed when READING receipts | out of scope here (noted for a later pass) |

Already tenant-aware: the business name/owner in the prompt (`BusinessIdentity`), the GST rate used by `ExpenseGate`, the per-business database, caps and usage log.

## Design
One small class, `ReceiptProfile::forBusiness($db)` (pure core + thin loader), returning:
`province`, `gst_rate`, `pst_rate|null`, `pst_label` (PST / RST / QST), `hst` (bool), `vehicle_label`, `equipment_label`, `tag_labels`,
`example_vendor_note`, `owner_rules` (list), `equipment_gas_max`.
- Province comes from `BusinessIdentity` (already there). Rates come from the `tax_rates` table per the `regions` skill ("live value"), with the BC rows as
  today's values. A province with no rows yet is "unsupported": the checks say "tax not checked — ask your accountant" and never guess.
- Prompt and checks are built FROM the profile. For Mowology the profile reproduces today's text byte for byte.
- Owner rules become data: `ops_settings.receipt_owner_rules` (JSON list of `{field, value, pattern, max_total?, strength, reason}`), evaluated by the same engine.
  Mowology is seeded with its three rules, worded as today.

## Unknowns, and how each is settled (one source each)
1. **Does the profile reproduce Mowology's prompt exactly?** A golden test: assemble the prompt for the Mowology profile and compare it to today's, byte for byte; `promptVersion()` must not change.
2. **Where do rates live and what do they hold?** Read `tax_rates` and `TaxEngine::gstRate()` and the `regions` skill rows (BC verified; others "research"). Facts carry a source and a review-by date; anything not verified is marked "confirm with accountant".
3. **HST and QST fit the two tax slots?** HST goes in the GST slot (CRA treats it as GST for credits), QST/RST in the PST slot. Settled by reading `GstReportService` and the ITC maths; a test with an Ontario receipt (13% HST, nothing in the PST slot).
4. **What breaks if a tenant has no rules?** Rules default to none: the AI still reads and proposes; nothing is forced.
5. **Claudia's province** — Tim to say. Until then Alberta and Ontario are test fixtures, not live.

## Steps and verification
1. `ReceiptProfile` + tests (pure; BC reproduces today's numbers; AB, SK, ON, QC fixtures).
2. Prompt from the profile + the golden test (Mowology byte-identical).
3. `check()` rates from the profile + tests (BC unchanged; HST province accepts 13% in the GST slot; unsupported province flags "not checked").
4. Rules as data: seed Mowology's three rules, prove identical hits to `ReceiptBookkeeperRules::evaluate()` on the existing test cases, then retire the hard-coded body behind the engine.
5. Local run of the full suite; Codex review (money and tax); preview on live data: re-run `check()` and the prompt for Mowology's 154 pending reads and confirm nothing changes; deploy; read-only live check.
6. Extra backlog: reader tax assumptions (`ReceiptParser`), a settings screen for the rules, per-business tag labels in the app screens.

## Update 2026-10-26: Claudia is in Vancouver, BC
Her province matches Mowology's, so BC rates (GST 5%, PST 7%) are right for her and HST/QST/other provinces move to LATER (fixtures only, not built now).
What her reads need is the rest of the profile: her own vehicle/equipment wording and examples, and her own owner rules (none by default: the AI proposes, nothing is forced).
New field: `gst_registered` (yes/no). A business that is not GST-registered has no input tax credits, so "GST to claim back" and the GST-rate checks do not apply
to it: the GST paid is part of the cost. Needed from Tim or Claudia before her first real read.
Order now: step 1 (profile + BC) -> step 2 (prompt from the profile + golden test) -> step 4 (rules as data); steps 3 (other-province rates) deferred.

## Decisions needed from Tim
- Claudia's province (and whether she is GST-registered).
- OK to store HST in the GST slot (the usual accountant treatment)?
- OK that rules are settings (JSON) for now, with a screen later?
- Priority against the other open items (the 17 old receipts, the by-code tier, a cheaper model test).

Risks: changing a prompt that feeds real reads (mitigated by the byte-for-byte golden test and re-checking the 154 pending reads); a wrong provincial rate (mitigated by "unsupported = unchecked, never guessed"); touching `check()` which drives approval flags (mitigated by tests on the existing cases).
