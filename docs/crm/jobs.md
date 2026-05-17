# Jobs / Visits — operational notes

## Transaction coverage

The following multi-write service paths are wrapped in DB transactions so a
mid-flow failure cannot leave half-written rows behind. All transactions follow
the `beginTransaction → try → commit / catch → rollBack` pattern, validation
runs **before** `beginTransaction`, and no DDL runs inside a transaction.

| ID | Path | Writes covered | Notes |
|----|------|----------------|-------|
| H5 | `app/Modules/Expenses/Api/expenses.php` — `handleCreate()` | INSERT `expenses` + INSERT `expense_line_items` | Non-critical follow-ups (price intelligence, OCR-correction learning, optional auto-send to accounting) run **after** commit so SMTP / learning failures cannot roll back the saved expense. |
| H6 | `app/Services/Receipts/ReceiptService.php` — `sendReceiptToAccounting()` | _After_ a successful SMTP send: UPDATE `receipt_email_log` (status='sent') + UPDATE `expenses` (status='forwarded', forwarded_at=NOW(), forwarded_to_accounting=1) | The initial INSERT into `receipt_email_log` (status='queued') and the `sendEmail()` SMTP call run **outside** any transaction — we never hold a DB transaction open across network I/O. On send failure, only the log row is updated to status='failed' (single-row autocommit); expense status is left untouched. |
| H7 | `app/Modules/Jobs/Services/VisitCompletionService.php` — `capture()` | UPDATE `job_visits` (labor / drive cost snapshots) + INSERT/UPDATE `visit_margin_snapshots` (margin upsert) | Steps 6–10 of `capture()` run inside a single transaction. The post-snapshot writes (timer-derived `actual_duration_minutes` recompute, geofence zone-session compute, plan rolling-average update) are independent and run after commit; failures there log but do not invalidate the snapshot. |
| H8 | `app/Modules/Jobs/Cron/weather_schedule_guard.php` — `captureSaltWeatherDecisions()` | INSERT IGNORE `salt_weather_decisions` | Runs after salt alerts fire (2pm cron). INSERT IGNORE prevents duplicate records on cron retries (UNIQUE KEY on `visit_id` + `trigger_date`). Stores full raw Environment Canada API response for legally defensible weather go-decision records. |

### Pattern

```php
$db->beginTransaction();
try {
    // multiple related writes …
    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('<context> failed: ' . $e->getMessage());
    throw $e;
}
```

### Rules

- Validation, anomaly detection, budget checks and any other read-only or
  non-DB work happens **before** `beginTransaction`.
- No `ALTER TABLE` / DDL inside a transaction (MySQL auto-commits DDL and
  silently breaks the surrounding transaction — see top-level `MEMORY.md`).
- Never call `sendEmail()` (or any other network I/O) while a transaction is
  open; commit first, do the network call, then start a second transaction
  to record the result if needed.
