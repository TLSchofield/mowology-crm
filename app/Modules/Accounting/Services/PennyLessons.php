<?php
/**
 * PennyLessons — Penny's adapter onto HeadLessons (migration 1348, plan 2026-10-13 Part A1).
 *
 * Every place a person overrides Penny calls one of these with the record it is about; the
 * subject is looked up here so callers stay one line:
 *   expense()  → subject = the receipt's vendor   (subject_kind 'vendor')
 *   bankLine() → subject = the line's bank wording (subject_kind 'bank_wording',
 *                BankImportService::descriptionKey — numbers and cities stripped)
 *   subject()  → any other subject (an invoice, an e-Transfer sender, a service type)
 *
 * All quiet: a failure is logged and never reaches the person's action. Recording only — no
 * learner reads these yet (A2 will), and nothing here touches the books.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 3) . '/Core/paths.php';
}
require_once APP_ROOT . '/Services/HeadLessons.php';

class PennyLessons
{
    public const HEAD = 'penny';

    /** A correction to a receipt (category, payment method, job, vendor, split, billable…). */
    public static function expense(PDO $db, int $expenseId, string $field, $said, $correct, string $source, ?int $by, ?string $reason = null): ?int
    {
        try {
            $s = $db->prepare("SELECT COALESCE(v.name, e.vendor_name_raw) FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id = ?");
            $s->execute([$expenseId]);
            $vendor = trim((string)$s->fetchColumn());
            return self::subject($db, 'vendor', $vendor !== '' ? $vendor : 'unknown vendor', $field, $said, $correct, $source, $by,
                                 'expense', $expenseId, $reason);
        } catch (Throwable $e) {
            error_log('Penny lesson (expense #' . $expenseId . ', ' . $source . '): ' . $e->getMessage());
            return null;
        }
    }

    /** A correction to a bank / card statement line (account, receipt link, invoices it paid…). */
    public static function bankLine(PDO $db, int $txId, string $field, $said, $correct, string $source, ?int $by, ?string $reason = null): ?int
    {
        try {
            $s = $db->prepare("SELECT description FROM accounting_transactions WHERE id = ?");
            $s->execute([$txId]);
            $desc = (string)$s->fetchColumn();
            return self::subject($db, 'bank_wording', self::wordingKey($desc), $field, $said, $correct, $source, $by, 'bank_line', $txId, $reason);
        } catch (Throwable $e) {
            error_log('Penny lesson (bank line #' . $txId . ', ' . $source . '): ' . $e->getMessage());
            return null;
        }
    }

    public static function subject(PDO $db, string $kind, string $key, string $field, $said, $correct, string $source, ?int $by,
                                   ?string $refType = null, ?int $refId = null, ?string $reason = null): ?int
    {
        return HeadLessons::recordQuietly($db, [
            'head' => self::HEAD, 'subject_kind' => $kind, 'subject_key' => $key, 'field' => $field,
            'said' => $said, 'correct' => $correct, 'source' => $source, 'ref_type' => $refType, 'ref_id' => $refId,
            'corrected_by' => $by, 'reason' => $reason,
        ]);
    }

    /** The bank wording as the bank rules see it; the raw text when that strips to nothing. Pure. */
    public static function wordingKey(string $description): string
    {
        if (!class_exists('BankImportService')) require_once __DIR__ . '/BankImportService.php';
        $k = BankImportService::descriptionKey($description);
        return $k !== '' ? $k : (trim($description) !== '' ? $description : 'unknown bank line');
    }

    /** "5200 Materials & Supplies" for a chart_of_accounts id; null when there is none. */
    public static function accountLabel(PDO $db, ?int $accountId): ?string
    {
        if (!$accountId) return null;
        try {
            $s = $db->prepare("SELECT code, name FROM chart_of_accounts WHERE id = ?");
            $s->execute([$accountId]);
            $a = $s->fetch(PDO::FETCH_ASSOC);
            return $a ? trim($a['code'] . ' ' . $a['name']) : '#' . $accountId;
        } catch (Throwable $e) {
            return '#' . $accountId;
        }
    }

    /** Invoice numbers for a list of invoice ids, comma-separated (for a lesson's "correct"). */
    public static function invoiceNumbers(PDO $db, array $invoiceIds): ?string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));
        if (!$ids) return null;
        try {
            $s = $db->prepare("SELECT invoice_number FROM invoices WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY invoice_number");
            $s->execute($ids);
            $n = $s->fetchAll(PDO::FETCH_COLUMN);
            return $n ? implode(', ', $n) : null;
        } catch (Throwable $e) {
            return implode(', ', array_map(fn($i) => '#' . $i, $ids));
        }
    }
}
