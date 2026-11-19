# Plan — Otto knows when the crew went to the yard

Status: **approved by Tim 2026-10-13 ("yes"); built 2026-10-13 (migration 1359, `SupplierStopService`, `SupplierJobSuggestService`).** Owner: Otto (Operations), with Penny.

## Why
On 2026-10-13 Penny couldn't place three Lawnboy slips (Oct 7, $386.40). Penny only saw two lawn cuts
scheduled that day. The GPS settled it in one query:
- Nigel's phone was at 6015 Tisdall from 8:13 am to 3:33 pm;
- it was at Lawnboy from 11:26 to 11:37, and the slips were photographed at 11:22 and 11:32;
- the truck tracker showed the same.

Otto couldn't do this himself because **Lawnboy has no map coordinates**. 12 of 21 saved supplier
locations are missing them.

## What gets built
1. **Every supplier on the map.**
   - Geocode the `vendor_locations` rows that have no lat/lng, and every new one as receipts arrive
     (`ReceiptIntakeService` already creates them).
   - Store the result once; never call the geocoder at page load.
2. **Supplier stops.**
   - From crew phones (`crew_location_history`) and trucks (`vehicle_location_pings`), find stops:
     at least 3 minutes within 80 m of a supplier location. Short drive-bys don't count.
   - Kept in a `supplier_stops` table: who, which vehicle, which supplier, arrive/leave times.
   - Built by Otto's existing cron, for the past day.
3. **Stop → receipt.** Join each stop to that supplier's receipt from the same day. Use the printed
   purchase time when the reader got one (`extractPurchaseTime`), otherwise the photo time.
   - A stop with a receipt becomes evidence on the receipt: "Lawnboy 11:26–11:37, Nigel, truck".
4. **Receipt → job.** For a material receipt with no job:
   - Otto suggests the job the same crew was on before and after the stop: GPS-tagged visits first,
     then that day's scheduled visits.
   - He prefers a job whose accepted quote lists the material (mulch, black earth, soil…).
   - He shows his reason; one tap confirms.
   - Each confirmation is a head lesson for Otto (stop → job) and Penny (vendor + material → job
     type). It stays in shadow until it agrees often enough (the Penny plan's rule).
5. **Missing receipt.** A supplier stop with no receipt by the next evening becomes one item on Otto's
   card ("Lawnboy, Oct 7 11:26, no receipt yet"). Fuel stops are included; drive-bys are not.

## Unknowns, and how each is settled (cheapest first)
| Unknown | How it's settled |
|---|---|
| Geocoder and key | Server-side Google Geocoding with the existing Maps key. Check the key's API and HTTP-referrer restrictions first (already on Tim's list); if it's referrer-locked, use a separate server key from `secrets.php` (Tim adds it). |
| Stop thresholds (3 min / 80 m) | Replay Oct 7 and Sep 25 (both known) plus a week of fuel stops on production read-only; tune until known stops are found and drive-bys aren't. |
| Phone vs truck when both exist | Use both; a stop seen by either counts; label which. |
| Receipt time when the photo is taken later (No 006 shot next morning) | Printed time first; if none, the receipt date plus the nearest stop that day at that supplier. |
| Privacy | Crew GPS is already used for work records under the consent rules. Stops are used only for business purchases and shown to admins/managers. Never to clients. |
| Tenants (Ittai) | Supplier coordinates are **shared** (the vendor knowledge commons). Stops, crews and jobs are **private** to each tenant. |

## Steps, each proven before the next
1. Geocode the missing supplier locations (preview first, then save). Proof: Lawnboy's pin is within
   80 m of 8655 Cambie, and every row has coordinates or a reason.
2. `SupplierStopService` with unit tests on fake tracks. Proof: on production read-only, it finds the
   Oct 7 11:26 Lawnboy stop and the Sep 25 8:23 one, and doesn't list the 9:44 pass.
3. Stop → receipt evidence on the receipt editor. Proof: the Oct 7 slips show their stop.
4. Otto's job suggestion + lesson. Proof: replaying Oct 7 suggests 6015 Tisdall (PLN-2026-0109),
   which Tim already chose.
5. Missing-receipt item on Otto's card. Proof: a week replay lists only real stops.
6. Local site first, then the 3-way merge deploy, production check, Otto and Penny skills updated.
