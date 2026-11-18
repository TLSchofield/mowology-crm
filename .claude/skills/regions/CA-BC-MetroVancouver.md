# Metro Vancouver (cities Mowology serves)

Live values: `municipal_rules` / `municipal_areas` (read by `MunicipalRuleService`; edited at
`/crm/ops/municipal-rules.php`). Every stored row is **unverified** as of 2026-10-11 — Otto's
suggestions from them say "Rule not yet confirmed". Status key in `SKILL.md`.

## Where the work is (properties by city, 2026-10-11)
Vancouver 1,344 · Burnaby 46 · North Vancouver 25 · Richmond 20 · West Vancouver 12 · Surrey 9 ·
New Westminster 7 · Port Moody 4 · Delta 3 · Coquitlam 2 — plus misspellings ("van" 8, "Vanvouver" 2).

## Vancouver
| Rule | Status | Source | Live value | Review by |
|---|---|---|---|---|
| Power equipment (mowers, trimmers, edgers; not chainsaws): weekdays and Saturdays 07:00–22:00, Sundays/holidays 10:00–22:00. | Unverified | Noise Control By-law 6555 — vancouver.ca power-equipment page | `municipal_rules` ids 1–3 | 2027-01-31 |
| Leaf blowers within 50 m of homes: weekdays 08:00–18:00, Saturdays 09:00–17:00, none on Sundays/holidays. | Unverified | same | ids 4–6 | 2027-01-31 |
| West End: gas leaf-blower ban (whether battery blowers too, and the exact boundary, unconfirmed; first cut by postal prefixes V6E, V6G). | Unverified | same | id 7, `municipal_areas` West End | 2027-01-31 |
| Council motion (2021) to phase out gas landscaping equipment — confirm current by-law; not enforced. | Unverified | Turf & Rec news | id 8 | 2027-01-31 |
| Business licence: General Contractor, renews each Dec 31 (2026: 26-105147). The City emails it from licenceoffice@vancouver.ca; Yui files it. | Verified (licence PDF) | City of Vancouver licence | `company_documents`, `business_settings.business_license` | 2027-01-31 |
| Metro Vancouver lawn-watering restrictions (summer stages) shape fall aeration/overseeding timing. | Research — confirm the current stage rules | Metro Vancouver | — | 2027-01-31 |

## Richmond
| Rule | Status | Source | Live value | Review by |
|---|---|---|---|---|
| Power equipment: weekdays/Saturdays 07:00–20:00, Sundays/holidays 10:00–18:00 (general "daytime", not landscaping-specific). | Unverified | Noise Regulation Bylaw 8856 | ids 9–11 | 2027-01-31 |

## Burnaby
| Rule | Status | Source | Live value | Review by |
|---|---|---|---|---|
| Noise Bylaw 7332 covers lawn mowing; hours not found yet. | Unverified — Tim fills | burnaby.ca | ids 12–14 | 2027-01-31 |

## No rules stored yet
North Vancouver (City and District differ), West Vancouver, Surrey, New Westminster, Port Moody, Delta, Coquitlam.
