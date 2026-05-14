# Schedule / Hot-Path Performance Notes

## Phase 3 — Composite Indexes (migration 1023, applied 2026-05-09)

The architectural audit flagged 10 missing composite indexes on hot-path queries.
After cross-checking the live schema, **9 of 10** were applied; 1 was skipped as a
strict duplicate. The `uniq_acct_source` UNIQUE index for the Phase 3 Task 14 batch
upsert was also skipped — the equivalent already exists on
`accounting_transactions`.

### Indexes added

| Table            | Index                         | Columns                                               |
| ---------------- | ----------------------------- | ----------------------------------------------------- |
| `job_visits`     | `idx_jv_stop_status`          | `(stop_id, status)`                                   |
| `job_visits`     | `idx_jv_plan_date`            | `(plan_id, scheduled_date)`                           |
| `job_plans`      | `idx_jp_recur_status_horiz`   | `(is_recurring, status, visits_generated_through)`    |
| `calendar_stops` | `idx_cs_property_date`        | `(property_id, stop_date)`                            |
| `expenses`       | `idx_e_status_date`           | `(status, expense_date)`                              |
| `quotes`         | `idx_q_status_created`        | `(status, created_at)`                                |
| `invoices`       | `idx_i_company_status_due`    | `(company_id, status, due_date)`                      |
| `properties`     | `idx_p_status_created`        | `(status, created_at)`                                |
| `contacts`       | `idx_c_prospect_lifecycle`    | `(prospect_status, lifecycle_stage)`                  |

### Indexes intentionally not added

- **`idx_cs_date_crew_route` on `calendar_stops (stop_date, crew_id, route_order)`** —
  exact duplicate of the existing `idx_route` (created in migration 200). Adding a
  second copy would only cost INSERT/UPDATE performance.
- **`uniq_acct_source` on `accounting_transactions (source_id, source_type)`** —
  the columns named in the audit do not exist; the table uses `reference_id` /
  `reference_type`, which already carries `UNIQUE KEY uq_source` (migration 980).
  The functional purpose (idempotent batch upsert) is already covered.

### EXPLAIN verification (production, 2026-05-09)

Ran the audit's two canonical queries plus four others that exercise the new
indexes. All but one picked up the new index; the holdout still uses an existing
single-column index (no full scan).

| Query                                                     | Index used                  | Type    | Notes                       |
| --------------------------------------------------------- | --------------------------- | ------- | --------------------------- |
| `calendar_stops` 1-month range + `job_visits` join         | join: `idx_jv_stop_status`  | `ref`   | covering ("Using index")    |
| `expenses WHERE status='pending' AND expense_date >= …`    | `idx_e_status_date`         | `range` | covering                    |
| `job_plans` recurring active needing visit generation      | `idx_jp_recur_status_horiz` | `range` | covering                    |
| `quotes WHERE status='sent' ORDER BY created_at DESC`      | `idx_q_status_created`      | `ref`   | backward index scan         |
| `contacts WHERE prospect_status=… AND lifecycle_stage=…`   | `idx_c_prospect_lifecycle`  | `ref`   | covering                    |
| `invoices WHERE company_id=… AND status=… AND due_date<…`  | `idx_company_id` (existing) | `ref`   | optimizer kept the single-column key for this synthetic query — the new composite is in `possible_keys`, will be picked up on selectivity changes |

**Outer `calendar_stops` scan note:** the audit's first EXPLAIN still reports
`type=ALL` on the outer table (803 rows). The optimizer judges a small-table
scan cheaper than `idx_date` lookup at this row count. The win for that query
is on the join side — `job_visits` lookup is now `ref` + `Using index` instead
of a per-row probe across `idx_stop` / `idx_status`.

### How it was applied

Migration ran via a one-shot token-gated PHP runner (uploaded, executed once,
self-deleted). The runner:

1. Inserted into `migrations_log` with `status='success'` so re-runs are blocked.
2. Executed the 9 `CREATE INDEX` statements outside any transaction (DDL is
   auto-commit on MySQL — wrapping in `BEGIN`/`COMMIT` would silently break it).
3. Captured EXPLAIN output for the audit queries before deleting itself.

The migration file (`database/migrations/1023_phase3_performance_indexes.sql`)
is now the system of record. Re-running it via the admin migrations UI is a
no-op because the log row blocks duplicates.
