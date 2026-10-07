<?php
/**
 * BankDuplicateCleanup — take out bank lines that overlapping statement imports added twice.
 *
 * Found 2026-10-05: Vancity statements imported with overlapping dates (sessions 33-40)
 * re-added the same lines 2-4 times, mostly pre-authorized bills the import had wrongly
 * treated as card payoffs (those skipped the duplicate check; both fixed in
 * BankImportService). A copy is the same date, amount and description in a DIFFERENT
 * import. Within one import, identical lines can be real (two coffees), so a group keeps
 * as many lines as the import that has the most of them.
 *
 * Nothing is lost (bookkeeping-agent guardrails):
 *   - each extra copy is snapshotted first (bank_duplicate_removals, migration 1133);
 *   - its journal entry is REVERSED, not deleted;
 *   - its statement row (bank_import_rows) is kept, marked is_duplicate and pointed at
 *     the line kept, so the statement still adds up;
 *   - only then is the copy removed from accounting_transactions, so reports stop
 *     counting it.
 * Copies something depends on (invoice payment, linked receipt/invoice, e-Transfer) are
 * kept in preference; a group where two copies are depended on is left for a human.
 *
 * Run from public/crm/api/run-bank-duplicates.php (dry-run first, typed confirm).
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/LedgerService.php';

class BankDuplicateCleanup
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'bank_duplicate_removals'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array{groups: array, extras: int, amount: float, skipped: array} */
    public function plan(): array
    {
        $rows = $this->db->query("
            SELECT t.id, t.transaction_date, t.type, t.amount, t.description, t.import_session_id,
                   (t.matched_invoice_id IS NOT NULL OR t.matched_expense_id IS NOT NULL
                    OR EXISTS (SELECT 1 FROM invoice_payment_allocations a WHERE a.transaction_id = t.id)
                    OR EXISTS (SELECT 1 FROM etransfer_notifications n WHERE n.bank_transaction_id = t.id)) AS linked
            FROM accounting_transactions t
            WHERE t.reference_type = 'bank_import' AND t.import_session_id IS NOT NULL
            ORDER BY t.id
        ")->fetchAll(PDO::FETCH_ASSOC);
        $plan = self::findExtras($rows);
        $plan['amount'] = round(array_sum(array_map(fn($g) => array_sum(array_column($g['remove'], 'amount')), $plan['groups'])), 2);
        return $plan;
    }

    /** @return array{removed: int, failed: int, errors: array} */
    public function apply(int $userId): array
    {
        if (!$this->ready()) return ['removed' => 0, 'failed' => 0, 'errors' => ['Run migration 1133 first.']];
        $ledger = new LedgerService($this->db);
        $done = 0; $failed = 0; $errors = [];
        foreach ($this->plan()['groups'] as $g) {
            $keepId = (int)$g['keep'][0]['id'];
            foreach ($g['remove'] as $x) {
                $id = (int)$x['id'];
                try {
                    $snap = $this->db->prepare("SELECT * FROM accounting_transactions WHERE id = ?");
                    $snap->execute([$id]);
                    $row = $snap->fetch(PDO::FETCH_ASSOC);
                    if (!$row) continue;
                    $entryId = $ledger->findEntryIdBySource('bank_import', $id);
                    if ($entryId) $ledger->reverseEntry($entryId, $userId, 'duplicate bank line (overlapping statement import) — kept #' . $keepId);
                    $this->db->beginTransaction();
                    $this->db->prepare("INSERT INTO bank_duplicate_removals (transaction_id, kept_transaction_id, snapshot_json, removed_by) VALUES (?, ?, ?, ?)")
                       ->execute([$id, $keepId, json_encode($row), $userId]);
                    $this->db->prepare("UPDATE bank_import_rows SET is_duplicate = 1, duplicate_of_id = ?, transaction_id = NULL WHERE transaction_id = ?")
                       ->execute([$keepId, $id]);
                    $this->db->prepare("DELETE FROM accounting_transactions WHERE id = ?")->execute([$id]);
                    $this->db->commit();
                    $done++;
                } catch (Throwable $e) {
                    if ($this->db->inTransaction()) $this->db->rollBack();
                    $failed++; $errors[] = 'Line ' . $id . ': ' . $e->getMessage();
                }
            }
        }
        return ['removed' => $done, 'failed' => $failed, 'errors' => array_slice($errors, 0, 20)];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    public static function key(array $r): string
    {
        $d = substr(strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '', (string)$r['description'])), 0, 30);
        return substr((string)$r['transaction_date'], 0, 10) . '|' . number_format((float)$r['amount'], 2, '.', '') . '|' . $d;
    }

    /**
     * @param array $rows id, transaction_date, amount, description, import_session_id, linked
     * @return array{groups: array<int, array{keep: array, remove: array}>, extras: int, skipped: array}
     */
    public static function findExtras(array $rows): array
    {
        $by = [];
        foreach ($rows as $r) $by[self::key($r)][] = $r;
        $groups = []; $skipped = []; $extras = 0;
        foreach ($by as $k => $lines) {
            $perSession = array_count_values(array_map(fn($l) => (string)$l['import_session_id'], $lines));
            if (count($perSession) < 2) continue;                     // all in one import: could be real repeats
            $keepCount = max($perSession);
            if (count($lines) <= $keepCount) continue;
            usort($lines, fn($a, $b) => [(int)!$a['linked'], (int)$a['id']] <=> [(int)!$b['linked'], (int)$b['id']]);
            $keep = array_slice($lines, 0, $keepCount);
            $remove = array_slice($lines, $keepCount);
            if (array_filter($remove, fn($l) => (bool)$l['linked'])) { $skipped[] = $k; continue; }   // a human decides
            $groups[] = ['keep' => $keep, 'remove' => $remove];
            $extras += count($remove);
        }
        return ['groups' => $groups, 'extras' => $extras, 'skipped' => $skipped];
    }
}
