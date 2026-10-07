# How heads share facts

Tim asked how the department heads talk to each other cheaply, so the cost does not grow over time. The answer has four rules:

1. **One head owns each fact and writes it once.** Otto owns trip costs: the truck trail turns into `ops_trip_runs` and then into `ops_cost_facts`. Penny owns money: she writes which job a run belongs to (`ops_trip_job_costs`).
2. **Other heads read a small summary with SQL.** They never read the raw trail and never ask another head through an AI call. When Sam prices a quote he reads one row (`run:dump:any`), not 400 GPS pings.
3. **A cron recomputes the summaries, not a page load.** `trip_runs_daily` (1:20 AM) prices yesterday's runs. Penny then tags them to jobs and Otto refreshes the medians. A page only reads the result.
4. **AI is only for new cases, and its answer becomes a rule.** A stop nobody has named, or a receipt line the rules cannot place, gets asked about once. The answer is saved (a named place, a vendor word, a product link), so the next run is handled by plain SQL.

## The trip-overhead facts (migration 1218)

| Fact | Written by | Read by |
|---|---|---|
| `ops_cost_facts` rows `run:{dump,supplier}:{any,place:<id>}`: the medians of one-man runs (time on site, round trip, km, trip cost, dump fee) | Otto, `CostFactsService::refresh()`, run at the end of `trip_runs_daily` | Sam (`TripLineSuggester`) |
| `ops_trip_job_costs`: each run's time + km, its dump fee, and its receipt lines, split into job materials and shop stock | Penny, `TripAttributionService::attribute()`, same cron | Job profitability (`trip_overhead`) and Charlie's brief |

- **Sam** suggests a "Material pickup" line when a quote has mulch, soil or compost on it, and a "Disposal run" line when it has cleanup, green waste or haul-away work. The price is the fact's `median_cost` rounded up to the next $5. He only prices it once there are 3 or more runs behind the fact; until then the line says "not enough runs yet (n/3)". GST goes on top of the price and is never included in it. These lines are suggestions only: Tim adds them to the quote or ignores them.
- **Penny** gives each run to the job visited at that property that day. She sets `expenses.job_id` only on a single-purpose receipt, and only when `job_id` is still empty. A mixed receipt (Oakridge's mulch plus grass seed for the shop) is split by line. Stock stays job-less on 5200, and no inventory account is created.
- **Charlie** flags a job whose runs cost more than its quote allowed for trips, for example: "Oakridge Gardens needed 2 runs today ($76), not in the quote — also picked up shop stock."
