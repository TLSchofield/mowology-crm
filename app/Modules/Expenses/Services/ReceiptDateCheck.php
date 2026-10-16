<?php
/**
 * ReceiptDateCheck — a receipt date the reader can't have read right (Tim, 2026-10-15).
 *
 * Lawnboy writes its dates by hand: Oct 15 was read as Apr 2, two Oct 7 receipts as Apr 2 / Apr 8, so
 * each landed in April and "didn't show". A waiting receipt whose date is more than SUSPECT_DAYS before
 * the day it was uploaded (or in the future) is doubted:
 *   - a bank / card line for the same vendor and amount, dated from BEFORE_DAYS before the upload to
 *     AFTER_DAYS after it, unmatched → the receipt takes that line's date (it IS that purchase);
 *   - none (yet) → the date is left alone and the receipt is flagged DATE_UNREADABLE for the person
 *     approving it. A backlog receipt scanned months later stays as printed: it has no fresh bank line,
 *     and the flag is only a note. The nightly sync re-checks flagged receipts as bank lines arrive.
 * Writes go through the expense gate (audited), only while the receipt is still waiting.
 *
 * Global-namespace service: require_once the file.
 */
declare(strict_types=1);

class ReceiptDateCheck
{
    public const SUSPECT_DAYS = 30;
    public const BEFORE_DAYS = 3;
    public const AFTER_DAYS = 6;
    public const FLAG = 'DATE_UNREADABLE';
    public const FLAG_SCORE = 20;
    public const SOURCE = 'date_check';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Is this date implausible for a receipt uploaded on $uploadDay? Pure. */
    public static function suspect(string $date, string $uploadDay): bool
    {
        $d = strtotime($date); $u = strtotime(substr($uploadDay, 0, 10));
        if (!$d || !$u) return false;
        return $d < $u - self::SUSPECT_DAYS * 86400 || $d > $u;   // after the upload day = can't be true
    }

    /** "ROUND_NUMBER" + DATE_UNREADABLE → "ROUND_NUMBER,DATE_UNREADABLE" (once). Pure. */
    public static function withFlag(?string $flags, bool $on): string
    {
        $list = array_values(array_filter(array_map('trim', explode(',', strtoupper((string)$flags)))));
        $list = array_values(array_filter($list, fn($f) => $f !== self::FLAG));
        if ($on) $list[] = self::FLAG;
        return implode(',', $list);
    }

    /**
     * Check one receipt. @return array{action: string, date?: string, line?: int}
     *   action: none (fine / not waiting) | dated (took the bank line's date) | flagged | cleared
     */
    public function check(int $expenseId): array
    {
        $s = $this->db->prepare("SELECT e.id, e.expense_date, e.total, e.status, e.created_at, e.anomaly_flags, e.anomaly_score, e.vendor_name_raw,
                                        v.name AS vendor_name
                                 FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id = ?");
        $s->execute([$expenseId]);
        $e = $s->fetch(PDO::FETCH_ASSOC);
        if (!$e || !in_array($e['status'], ['draft', 'pending_approval'], true) || (float)$e['total'] <= 0) return ['action' => 'none'];
        $flagged = self::hasFlag((string)$e['anomaly_flags']);
        if (!self::suspect((string)$e['expense_date'], (string)$e['created_at'])) {
            return $flagged ? $this->setFlag($e, false) : ['action' => 'none'];
        }
        $line = $this->bankLine($e);
        if ($line) {
            require_once __DIR__ . '/ExpenseGate.php';
            $was = (string)$e['expense_date'];
            $res = (new ExpenseGate($this->db))->apply($expenseId,
                ['expense_date' => $line['date'], 'anomaly_flags' => self::withFlag($e['anomaly_flags'], false),
                 'anomaly_score' => max(0, (int)$e['anomaly_score'] - ($flagged ? self::FLAG_SCORE : 0))],
                ['id' => null, 'kind' => 'system'], self::SOURCE,
                ['only_if' => fn(array $b) => in_array((string)($b['status'] ?? ''), ['draft', 'pending_approval'], true) && (string)$b['expense_date'] === $was,
                 'note' => 'Date read as ' . $was . '; the bank line #' . $line['id'] . ' (same vendor and amount) is dated ' . $line['date']]);
            return !empty($res['noop']) ? ['action' => 'none'] : ['action' => 'dated', 'date' => $line['date'], 'line' => $line['id']];
        }
        return $flagged ? ['action' => 'none'] : $this->setFlag($e, true);
    }

    public static function hasFlag(string $flags): bool
    {
        return in_array(self::FLAG, array_map('trim', explode(',', strtoupper($flags))), true);
    }

    /**
     * The ONE unmatched bank / card purchase for this receipt near the upload day, naming the vendor — and only
     * when no other waiting receipt of that vendor has the same amount: otherwise it's ambiguous and we only flag.
     */
    private function bankLine(array $e): ?array
    {
        $names = array_values(array_filter([trim((string)$e['vendor_name']), trim((string)$e['vendor_name_raw'])]));
        if (!$names) return null;
        $up = substr((string)$e['created_at'], 0, 10);
        $s = $this->db->prepare("
            SELECT id, transaction_date, description FROM accounting_transactions
            WHERE reference_type = 'bank_import' AND type = 'expense' AND matched_expense_id IS NULL
              AND ABS(amount - ?) < 0.01
              AND transaction_date BETWEEN DATE_SUB(?, INTERVAL " . self::BEFORE_DAYS . " DAY) AND DATE_ADD(?, INTERVAL " . self::AFTER_DAYS . " DAY)
            ORDER BY ABS(DATEDIFF(transaction_date, ?)), id LIMIT 20");
        $s->execute([(float)$e['total'], $up, $up, $up]);
        require_once APP_ROOT . '/Modules/Accounting/Services/BankImportService.php';
        $hits = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $t) {
            foreach ($names as $n) {
                if (BankImportService::vendorInDescription($n, (string)$t['description'])) { $hits[] = ['id' => (int)$t['id'], 'date' => substr((string)$t['transaction_date'], 0, 10)]; break; }
            }
        }
        if (count($hits) !== 1) return null;   // none, or more than one: which one is ours?
        $o = $this->db->prepare("SELECT COUNT(*) FROM expenses x LEFT JOIN vendors v ON v.id = x.vendor_id
                                 WHERE x.id <> ? AND x.status IN ('draft', 'pending_approval') AND ABS(x.total - ?) < 0.01
                                   AND x.created_at >= DATE_SUB(?, INTERVAL 14 DAY)
                                   AND (LOWER(COALESCE(v.name, '')) = LOWER(?) OR LOWER(COALESCE(x.vendor_name_raw, '')) = LOWER(?))");
        $o->execute([(int)$e['id'], (float)$e['total'], (string)$e['created_at'], $names[0], $names[0]]);
        return (int)$o->fetchColumn() === 0 ? $hits[0] : null;
    }

    private function setFlag(array $e, bool $on): array
    {
        require_once __DIR__ . '/ExpenseGate.php';
        $score = max(0, (int)$e['anomaly_score'] + ($on ? self::FLAG_SCORE : -self::FLAG_SCORE));
        $res = (new ExpenseGate($this->db))->apply((int)$e['id'], ['anomaly_flags' => self::withFlag($e['anomaly_flags'], $on), 'anomaly_score' => $score],
            ['id' => null, 'kind' => 'system'], self::SOURCE,
            ['only_if' => fn(array $b) => in_array((string)($b['status'] ?? ''), ['draft', 'pending_approval'], true)
                                          && self::hasFlag((string)($b['anomaly_flags'] ?? '')) !== $on]);
        return !empty($res['noop']) ? ['action' => 'none'] : ['action' => $on ? 'flagged' : 'cleared'];
    }

    /** Nightly: re-check every waiting receipt still flagged (their bank lines may have arrived). */
    public function sweep(int $max = 200): array
    {
        // Exact flag (FIND_IN_SET on the comma list); newest first — fresh uploads are the ones whose bank lines arrive.
        $s = $this->db->prepare("SELECT id FROM expenses WHERE status IN ('draft', 'pending_approval')
                                 AND FIND_IN_SET(?, REPLACE(UPPER(COALESCE(anomaly_flags, '')), ' ', '')) > 0 ORDER BY id DESC LIMIT " . (int)$max);
        $s->execute([self::FLAG]);
        $ids = $s->fetchAll(PDO::FETCH_COLUMN);
        $out = ['checked' => 0, 'dated' => 0];
        foreach ($ids as $id) {
            try {
                $r = $this->check((int)$id);
                $out['checked']++;
                if ($r['action'] === 'dated') $out['dated']++;
            } catch (Throwable $ex) {
                error_log('Receipt date check #' . $id . ': ' . $ex->getMessage());
            }
        }
        return $out;
    }
}
