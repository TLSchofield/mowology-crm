<?php
declare(strict_types=1);

/**
 * Penny's third job on the Ittai job strip (2026-11-10): the emailed receipt waiting for review, one at a time —
 * "Approve this receipt?". A new face on existing logic only: the receipts are ReceiptInboxService::listPending()'s (the
 * rows of the Expenses page's "Receipts from email" card) and Approve carries the exact fields that card's Approve button
 * posts to /crm/api/receipt-inbox-confirm.php (action=approve, expense_id, vendor_id, expense_date, total, gst_amount,
 * pst_amount, accounting_category), so the endpoint's own rules (total above zero, amounts foot, the expense gate) decide.
 * Nothing here writes anything.
 *
 * Which receipts are offered: only ones Penny can propose in one press, because the endpoint would refuse or the books
 * would take a guess otherwise. Open year (a receipt in a filed year is filed and traced by ClosedYearReceiptTrace, never
 * approved), a file to look at, a known vendor, a total above zero that the endpoint accepts (GST and PST within it), a real date, and a category (the
 * receipt's own, or the vendor's default). Everything else stays on the card for a person. Dismiss stays on the card too.
 */
class PennyReceiptJobAdapter
{
    /** The strip's cap, like the e-Transfer queue's: each job costs a query. */
    public const MAX_JOBS = 10;
    /** Approved-unchanged run that earns the blue triangle, then the gold one (PennyBadgeService::VENDOR_RUN). */
    public const KNOWN_AT = 3;
    public const MASTERED_AT = 5;

    /** Cheap gate: is any emailed receipt waiting? Any failure means no. */
    public static function hasWork(PDO $db): bool
    {
        try {
            self::load();
            return (new ReceiptInboxService($db))->countPending() > 0;
        } catch (Throwable $e) {
            error_log('PennyReceiptJobAdapter::hasWork: ' . $e->getMessage());
        }
        return false;
    }

    /** @return list<array> the receipts Penny can propose, best-known vendor first (index/total set by the caller). [] on any failure. */
    public static function jobs(PDO $db): array
    {
        try {
            self::load();
            $rows = (new ReceiptInboxService($db))->listPending();
            if (!$rows) return [];
            $ids = array_values(array_unique(array_filter(array_map(fn($r) => (int)($r['vendor_id'] ?? 0), $rows))));
            $defaults = [];
            if ($ids) {
                $s = $db->query('SELECT id, default_accounting_category FROM vendors WHERE id IN (' . implode(',', $ids) . ')');
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $v) $defaults[(int)$v['id']] = trim((string)$v['default_accounting_category']);
            }
            $strength = [];
            try {
                require_once APP_ROOT . '/Modules/Expenses/Services/PennyBadgeService.php';
                foreach ((new PennyBadgeService($db))->vendors(500) as $v) $strength[strtolower(trim((string)$v['vendor']))] = $v;
            } catch (Throwable $e) { /* her record is a bonus: without it every vendor reads as first time */ }

            $ready = [];
            foreach ($rows as $r) {
                $vid = (int)($r['vendor_id'] ?? 0);
                if (!self::proposes($r, $defaults[$vid] ?? '')) continue;
                $rec = $strength[strtolower(trim((string)($r['vendor_name'] ?? '')))] ?? ['seen' => 0, 'run' => 0];
                $ready[] = ['row' => $r, 'default' => $defaults[$vid] ?? '', 'record' => $rec];
            }
            $ready = self::order($ready);
            $twin = $db->prepare("SELECT COUNT(*) FROM expenses WHERE id <> ? AND status IN ('approved', 'forwarded') AND vendor_id = ?
                                   AND ABS(total - ?) < 0.01 AND ABS(DATEDIFF(expense_date, ?)) <= 3");
            $out = [];
            foreach (array_slice($ready, 0, self::MAX_JOBS) as $c) {
                $r = $c['row'];
                $twin->execute([(int)$r['id'], (int)$r['vendor_id'], (float)$r['total'], (string)$r['expense_date']]);
                $out[] = self::fromReceipt($r, ['default' => $c['default'], 'seen' => (int)$c['record']['seen'], 'run' => (int)$c['record']['run'], 'twin' => (int)$twin->fetchColumn() > 0], 0, 0);
            }
            return $out;
        } catch (Throwable $e) {
            error_log('PennyReceiptJobAdapter::jobs: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Pure: may the strip offer Approve for this listPending() row? $vendorCategory is the vendor's default category.
     * Mirrors what the endpoint accepts, plus the filed-year rule (those are traced, not approved).
     */
    public static function proposes(array $row, string $vendorCategory = ''): bool
    {
        if ((int)($row['vendor_id'] ?? 0) <= 0) return false;
        if (empty($row['receipt_media_id'])) return false;
        $total = round((float)($row['total'] ?? 0), 2);
        if ($total <= 0) return false;
        if (!ReceiptInboxService::isValidDate($row['expense_date'] ?? null)) return false;
        if (substr((string)$row['expense_date'], 0, 10) < ExpenseGate::CLOSED_BEFORE) return false;
        $gst = (float)($row['gst_amount'] ?? 0); $pst = (float)($row['pst_amount'] ?? 0);
        if (ReceiptAmountRules::approvalError($total - $gst - $pst, $gst, $pst, $total) !== null) return false;
        return trim((string)($row['accounting_category'] ?? '')) !== '' || trim($vendorCategory) !== '';
    }

    /**
     * Pure: the vendors she knows best first (longest unchanged run, then most seen), the rest in the card's order. Stable.
     * @param list<array{row:array, default:string, record:array}> $cands
     */
    public static function order(array $cands): array
    {
        foreach ($cands as $i => $c) $cands[$i]['_at'] = $i;
        usort($cands, fn($a, $b) => [(int)$b['record']['run'], (int)$b['record']['seen'], $a['_at']] <=> [(int)$a['record']['run'], (int)$a['record']['seen'], $b['_at']]);
        foreach ($cands as $i => $c) unset($cands[$i]['_at']);
        return $cands;
    }

    /**
     * The knowledge triangle for a receipt (SPEC-v1.2 "Confidence"). Pure.
     * $seen: receipts of this vendor she suggested for that you approved; $run: her current run of those approved unchanged.
     * $differs: a ≠ disc is showing. One strikes it back to the broken triangle, however well known.
     * forming (three loose lines): nothing approved with this vendor yet;
     * held (broken triangle): seen, but not yet a run of three unchanged, or something differs;
     * known (solid blue): three unchanged in a row; mastered (gold): five or more with nothing differing.
     */
    public static function sureOfReceipt(int $seen, int $run, bool $differs): string
    {
        if ($seen <= 0) return 'forming';
        if ($differs) return 'held';
        if ($run >= self::MASTERED_AT) return 'mastered';
        return $run >= self::KNOWN_AT ? 'known' : 'held';
    }

    /**
     * One pending receipt → the generic job shape. Pure.
     * $ctx: default (the vendor's default category), seen/run (her record), twin (an approved receipt with this vendor,
     * total and a date within three days already exists).
     * The receipt is drawn ABOVE the rail, what Penny read BELOW it; the discs on the rail compare them.
     */
    public static function fromReceipt(array $r, array $ctx, int $index, int $total): array
    {
        $id = (int)($r['id'] ?? 0);
        $vendor = trim((string)($r['vendor_name'] ?? '')) !== '' ? trim((string)$r['vendor_name']) : trim((string)($r['vendor_name_raw'] ?? ''));
        $sum = round((float)($r['total'] ?? 0), 2);
        $gst = round((float)($r['gst_amount'] ?? 0), 2); $pst = round((float)($r['pst_amount'] ?? 0), 2);
        // the amount Penny read (the stored one), not the one the endpoint will work out: a gap between them is what the disc shows
        $net = isset($r['amount']) && $r['amount'] !== '' ? round((float)$r['amount'], 2) : round($sum - $gst - $pst, 2);
        $own = trim((string)($r['accounting_category'] ?? ''));
        $default = trim((string)($ctx['default'] ?? ''));
        $category = $own !== '' ? $own : $default;
        $date = substr((string)($r['expense_date'] ?? ''), 0, 10);
        $uploaded = substr((string)($r['created_at'] ?? ''), 0, 10);
        $seen = (int)($ctx['seen'] ?? 0); $run = (int)($ctx['run'] ?? 0);

        $d = static fn(string $v, string $f = 'M j'): string => strtotime($v) ? date($f, strtotime($v)) : '';
        $money = static fn(float $v): string => '$' . number_format($v, 2);

        $foots = abs(($net + $gst + $pst) - $sum) <= 0.02;
        $dateOdd = $uploaded !== '' && ReceiptDateCheck::suspect($date, $uploaded);
        $catState = $own !== '' && $default !== '' ? (strcasecmp($own, $default) === 0 ? 'same' : 'differs') : ($own === '' ? 'info' : 'info');
        $twin = !empty($ctx['twin']);
        $differs = !$foots || $dateOdd || $catState === 'differs' || $twin;

        // The frame names both sides (the receipt, what she read), so the columns carry only the comparison.
        $columns = [
            ['label' => 'Amounts', 'top' => $money($sum), 'bottom' => $money($net + $gst + $pst), 'state' => $foots ? 'same' : 'differs'],
            ['label' => 'Vendor', 'top' => '', 'bottom' => '', 'state' => $seen > 0 ? 'same' : 'info'],
            ['label' => 'Date', 'top' => $d($date), 'bottom' => $uploaded !== '' ? 'Sent ' . $d($uploaded) : '', 'state' => $dateOdd ? 'differs' : 'same'],
            ['label' => 'Category', 'top' => $own !== '' ? $own : '', 'bottom' => $default, 'state' => $catState],
            ['label' => 'Already filed', 'top' => '', 'bottom' => '', 'state' => $twin ? 'differs' : 'same'],
        ];

        $note = $seen > 0 ? "With {$vendor} you have approved {$seen} of her reads" . ($run > 0 ? "; the last {$run} unchanged." : '.') : '';
        $mediaId = (int)($r['receipt_media_id'] ?? 0);
        $from = trim((string)($r['sender_email'] ?? ''));

        return [
            'head'       => 'penny',
            'kind'       => 'receipt',
            'key'        => 'rc:' . $id,
            'title'      => 'Approve this receipt? Penny has read it',
            'confidence' => $note,
            'sure'       => self::sureOfReceipt($seen, $run, $differs),
            // above the rail: the receipt as it came in; below it: what she read from it
            'left'       => ['label' => 'Receipt', 'who' => $from !== '' ? $from : 'emailed in', 'when' => $uploaded !== '' ? 'Sent ' . $d($uploaded) : '',
                             'href' => $mediaId > 0 ? '/crm/api/serve-receipt.php?id=' . $mediaId : ''],
            'right'      => ['label' => $vendor, 'who' => $money($sum) . ' · GST ' . $money($gst), 'when' => $category],
            'columns'    => $columns,
            // the same fields the card's Approve button posts: the endpoint decides
            'primary'    => ['label' => 'Approve', 'sub' => $vendor . ' · ' . $money($sum),
                             'request' => ['action' => 'approve', 'expense_id' => $id, 'vendor_id' => (int)$r['vendor_id'], 'expense_date' => $date,
                                           'total' => number_format($sum, 2, '.', ''), 'gst_amount' => number_format($gst, 2, '.', ''),
                                           'pst_amount' => number_format($pst, 2, '.', ''), 'accounting_category' => $category]],
            // Not this opens the full editor (never dismisses: that stays a deliberate click on the card)
            'secondary'  => ['label' => 'Not this', 'sub' => 'open the editor', 'request' => ['mode' => 'edit', 'expense_id' => $id]],
            'undo'       => null,
            'api'        => '/crm/api/receipt-inbox-confirm.php',
            'transport'  => 'form',
            'start'      => ['label' => 'Receipt', 'href' => '/crm/expenses_appstack.php#receipt-inbox', 'icon' => 'mail'],
            'anchor'     => ['label' => 'Ledger', 'href' => '/crm/ledger/'],
            'index'      => $index,
            'total'      => $total,
        ];
    }

    private static function load(): void
    {
        if (!defined('APP_ROOT')) return;   // tests load the services through bootstrap.php
        foreach (['ReceiptInboxService', 'ReceiptAmountRules', 'ReceiptDateCheck', 'ExpenseGate'] as $c) {
            if (!class_exists($c, false)) require_once APP_ROOT . '/Modules/Expenses/Services/' . $c . '.php';
        }
    }
}
