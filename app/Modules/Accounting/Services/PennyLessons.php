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
 * All quiet: a failure is logged and never reaches the person's action. Nothing here touches the books.
 *
 * Read back (2026-10-14, plan penny-learning-and-crew-view A): forVendor() gives the receipt
 * bookkeeper the owner's one-at-a-time corrections for a vendor, and trackRecord() how often her
 * own suggestions for it were right. Bulk clean-ups and vendor misreads are left out (safeLesson).
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

    /**
     * Fields of a receipt lesson the bookkeeper may learn per VENDOR. Never the vendor name (a misread is
     * filed under the wrong vendor) and never the job (Tim 2026-10-14: the job is per purchase — which
     * property that day — not a fact about the vendor; it comes from where the truck/crew went).
     */
    public const LEARN_FIELDS = ['accounting_category', 'asset_tag', 'rejected'];

    /**
     * Is this lesson the owner's own one-at-a-time correction (not a bulk clean-up script)? Pure.
     * Receipt edits (expense_gate:<screen>), Penny's card, the owner's answers, the self-audit — but not
     * imports, reconciles or anything else run over many rows at once.
     */
    public static function safeLesson(array $l): bool
    {
        if (!in_array((string)($l['field'] ?? ''), self::LEARN_FIELDS, true)) return false;
        $src = (string)($l['source'] ?? '');
        if (preg_match('/import|reconcile|backfill|bulk|fix|_to_/i', $src)) return false;
        return strpos($src, 'expense_gate:') === 0 || in_array($src, ['penny_card', 'owner_answer', 'self_audit', 'penny_reject'], true);
    }

    /**
     * The owner's corrections for a vendor's receipts, newest first, for the bookkeeper's context:
     * [{field, she_or_reader_said, owner_set, on, why?}]. $exceptExpenseId's own lessons and (for a replay)
     * anything after $before are left out, so a backtest never sees the answer.
     */
    public static function forVendor(PDO $db, string $vendor, int $exceptExpenseId = 0, ?string $before = null, int $limit = 8, int $siteId = 1): array
    {
        if (trim($vendor) === '') return [];
        try {
            // One company's lessons only (site_id, as HeadLessons writes them): a tenant's corrections are private.
            $s = $db->prepare("SELECT field, said, correct, source, reason, ref_type, ref_id, created_at FROM head_lessons
                               WHERE site_id = ? AND head = 'penny' AND subject_kind = 'vendor' AND subject_key = ? ORDER BY id DESC LIMIT 60");
            $s->execute([$siteId, HeadLessons::normaliseKey($vendor)]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];   // migration 1348 not run
        }
        $out = [];
        foreach ($rows as $l) {
            if (!self::safeLesson($l)) continue;
            if ($exceptExpenseId > 0 && ($l['ref_type'] ?? '') === 'expense' && (int)$l['ref_id'] === $exceptExpenseId) continue;
            if ($before !== null && substr((string)$l['created_at'], 0, 10) >= $before) continue;   // strictly before the receipt's day
            $row = ['field' => $l['field'], 'said' => $l['said'], 'owner_set' => $l['correct'], 'on' => substr((string)$l['created_at'], 0, 10)];
            if (!empty($l['reason'])) $row['why'] = mb_substr((string)$l['reason'], 0, 200);
            if (($l['ref_type'] ?? '') === 'expense' && (int)$l['ref_id'] > 0) $row['_ref'] = (int)$l['ref_id'];
            $out[] = $row;
            if (count($out) >= $limit) break;
        }
        return self::withExamples($db, $out);
    }

    /**
     * Each lesson gets "on_receipt": the receipt it was made on, in brief (total, first items) — so a
     * correction made once is applied only to receipts like it (middle path, Tim 2026-10-14: the coffee
     * at Chevron is Meals; the fill-ups stay Fuel).
     */
    private static function withExamples(PDO $db, array $lessons): array
    {
        $refs = array_values(array_unique(array_filter(array_map(fn($l) => $l['_ref'] ?? 0, $lessons))));
        $brief = [];
        if ($refs) {
            try {
                $in = implode(',', array_map('intval', $refs));
                foreach ($db->query("SELECT id, total FROM expenses WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $e) {
                    $brief[(int)$e['id']] = ['total' => round((float)$e['total'], 2), 'items' => []];
                }
                foreach ($db->query("SELECT expense_id, name FROM expense_line_items WHERE expense_id IN ($in) ORDER BY expense_id, sort_order, id")->fetchAll(PDO::FETCH_ASSOC) as $li) {
                    $id = (int)$li['expense_id'];
                    if (isset($brief[$id]) && count($brief[$id]['items']) < 5) $brief[$id]['items'][] = mb_substr((string)$li['name'], 0, 60);
                }
            } catch (Throwable $e) { /* examples are a bonus */ }
        }
        foreach ($lessons as &$l) {
            if (isset($l['_ref'], $brief[$l['_ref']])) $l['on_receipt'] = $brief[$l['_ref']];
            unset($l['_ref']);
        }
        unset($l);
        return $lessons;
    }

    /**
     * How often the bookkeeper's suggestions for this vendor were kept, per field, from the owner's
     * decisions (expense_suggestions.outcome_json): ['accounting_category' => '5 of 6 right', …]. Fields
     * with no decisions are left out.
     */
    public static function trackRecord(PDO $db, int $vendorId, int $exceptExpenseId = 0, ?string $before = null): array
    {
        try {
            $sql = "SELECT s.outcome_json FROM expense_suggestions s JOIN expenses e ON e.id = s.expense_id
                    WHERE e.vendor_id = ? AND s.source = 'live' AND s.status IN ('accepted', 'edited') AND s.expense_id <> ?"
                 . ($before !== null ? " AND s.decided_at < ?" : '') . " ORDER BY s.id DESC LIMIT 40";
            $s = $db->prepare($sql);
            $s->execute($before !== null ? [$vendorId, $exceptExpenseId, $before] : [$vendorId, $exceptExpenseId]);
            return self::tallyOutcomes(array_map(fn($j) => json_decode((string)$j, true) ?: [], $s->fetchAll(PDO::FETCH_COLUMN)));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Pure: outcome_json rows → field => "n of m right". */
    public static function tallyOutcomes(array $outcomes): array
    {
        $right = []; $all = [];
        foreach ($outcomes as $o) {
            foreach (['vendor', 'accounting_category', 'asset_tag', 'job', 'total'] as $f) {
                if (!isset($o[$f]) || !is_array($o[$f]) || !array_key_exists('accepted', $o[$f])) continue;
                $all[$f] = ($all[$f] ?? 0) + 1;
                if (!empty($o[$f]['accepted'])) $right[$f] = ($right[$f] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($all as $f => $n) $out[$f] = ($right[$f] ?? 0) . ' of ' . $n . ' right';
        return $out;
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
