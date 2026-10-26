<?php
declare(strict_types=1);

/**
 * PennyFocusService — the three things Penny's card should show (Tim, 2026-10-26: "what if she only showed me 3 things:
 * the receipt that teaches her most, and the one that is the problem for February — and why").
 *
 *   1. teach  — the receipt whose answer teaches her most: the vendor with the most receipts waiting, weighted by how little
 *               she trusts that vendor (one answer redoes the whole group — the vendor-batch review standard, 2026-10-14).
 *   2. month  — the receipt holding up the oldest month that cannot close yet: an approved receipt posted to chequing with no
 *               bank line (MonthDrilldownService), the biggest first, with Penny's best guess at the bank line when she has one.
 *   3. money  — money waiting on one tap: deposits matched to invoices, else GST claimable in unapproved receipts.
 *
 * READ ONLY. It ranks and words things; it books nothing and never calls an AI. Every row carries `why` in plain words.
 * Pure parts (teachScore, pickTeach, whyTeach, whyMonth, moneyRow) are tested; loaders are thin.
 *
 * No namespace / no autoloader: require_once and `new`.
 */
class PennyFocusService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Pure ────────────────────────────────────────────────────────────────

    /** How much one answer on this vendor is worth: receipts waiting × (1 for an unknown vendor, up to 1.25… down to 0.2 for a trusted one). */
    public static function teachScore(int $waiting, ?array $strength): float
    {
        if ($waiting <= 0) return 0.0;
        if ($strength === null) return $waiting * 1.25;               // never seen: she has everything to learn
        if (!empty($strength['trusted'])) return $waiting * 0.2;      // she already gets this one right
        $pct = max(0, min(100, (int)($strength['pct'] ?? 0)));
        return $waiting * (0.6 + (100 - $pct) / 100 * 0.6);           // 0.6 (nearly there) … 1.2 (mostly wrong)
    }

    /**
     * @param list<array{vendor:string,n:int,expense_id:int,suggestion_id:int}> $groups
     * @param array<string,array> $strengthByVendor lowercase vendor => vendorStrength() row
     */
    public static function pickTeach(array $groups, array $strengthByVendor): ?array
    {
        $best = null; $bestScore = 0.0;
        foreach ($groups as $g) {
            $s = self::teachScore((int)$g['n'], $strengthByVendor[strtolower(trim((string)$g['vendor']))] ?? null);
            if ($s > $bestScore) { $bestScore = $s; $best = $g + ['score' => round($s, 2)]; }
        }
        return $best;
    }

    /** Words that are never a vendor: a receipt "from" one of these is a reading mistake, not a shop. */
    public static function isJunkVendor(string $v): bool
    {
        return in_array(strtoupper(trim($v)), ['AMOUNT', 'TOTAL', 'SUBTOTAL', 'BALANCE', 'CASH', 'DEBIT', 'CREDIT', 'VISA', 'MASTERCARD', 'RECEIPT', 'INVOICE', 'CUSTOMER COPY', 'THANK YOU'], true);
    }

    public static function whyTeach(array $g, ?array $strength): string
    {
        $n = (int)$g['n'];
        if (self::isJunkVendor((string)$g['vendor'])) {
            return $n . ' receipts have "' . $g['vendor'] . '" read as the shop name. Tell me the real shop on this one and I\'ll fix how I read the rest.';
        }
        $what = $n === 1 ? 'This is the only ' . $g['vendor'] . ' receipt waiting' : $n . ' ' . $g['vendor'] . ' receipts are waiting, and one answer redoes them all';
        if ($strength === null) return $what . '. I haven\'t learned ' . $g['vendor'] . ' yet.';
        if (!empty($strength['trusted'])) return $what . '. I already know this vendor.';
        return $what . '. I\'m right ' . (int)$strength['pct'] . '% of the time on ' . $g['vendor'] . ' (' . (int)$strength['seen'] . ' seen).';
    }

    /**
     * @param array{month:string,amount_off:float,receipt_amount:float,vendor:string,date:string,hint:?string} $m
     */
    public static function whyMonth(array $m): string
    {
        $month = date('F', strtotime($m['month'] . '-01'));
        $dir = $m['amount_off'] < 0 ? 'below' : 'above';
        $s = $month . ' ends $' . number_format(abs($m['amount_off']), 2) . ' ' . $dir . ' the bank. This ' . $m['vendor'] . ' receipt ($'
           . number_format(abs($m['receipt_amount']), 2) . ') is booked as paid from chequing, but no bank line matches it.';
        if (!empty($m['hint'])) $s .= ' ' . $m['hint'];
        return $s;
    }

    /** Penny's guess at the bank line, in words; a line from a closed year says so. Pure. */
    public static function hintFor(array $c, string $expenseDate): string
    {
        $when = date('M j, Y', strtotime((string)$c['date']));
        $s = 'Could it be the $' . number_format((float)$c['amount'], 2) . ' bank line on ' . $when . ' (' . trim(substr((string)$c['description'], 0, 38)) . ')?';
        if ((string)$c['date'] < '2026-01-01' && $expenseDate >= '2026-01-01') {
            $s .= ' That is in the filed 2025 books, so this receipt may be a second copy of it.';
        }
        return $s;
    }

    /** A link on the card goes to a page inside the CRM, never to another scheme or host. Pure. */
    public static function internalUrl(string $url): string
    {
        return preg_match('#^/(?![/\\\\])[A-Za-z0-9_\\-./?=&%]*$#', $url) ? $url : '';
    }

    /** A cached row is only used when it has the three text fields the card prints. Pure. */
    public static function validRow($r): bool
    {
        return is_array($r) && in_array($r['kind'] ?? null, ['teach', 'month', 'money'], true)
            && is_string($r['title'] ?? null) && is_string($r['why'] ?? null);
    }

    /** One row of money waiting, from the card's deposit line or the GST figure. Pure. */
    public static function moneyRow(?array $pm, float $gstStuck): ?array
    {
        if ($pm && (int)($pm['waiting'] ?? 0) > 0) {
            $n = (int)$pm['waiting']; $high = (int)($pm['high'] ?? 0);
            return ['kind' => 'money', 'title' => $n . ' deposit' . ($n === 1 ? '' : 's') . ' matched to invoices',
                    'why' => 'Worth $' . number_format((float)$pm['waiting_total'], 2) . '. Nothing is booked until you approve it.' . ($high > 0 ? ' ' . $high . ' high-confidence.' : ''),
                    'url' => self::internalUrl((string)($pm['url'] ?? ''))];
        }
        if ($gstStuck >= 1) {
            return ['kind' => 'money', 'title' => '$' . number_format($gstStuck, 2) . ' of GST to claim back',
                    'why' => 'It is sitting in receipts nobody has approved yet.', 'url' => '/crm/expenses_appstack.php'];
        }
        return null;
    }

    // ── Loaders (read only) ─────────────────────────────────────────────────

    /** @return list<array{vendor:string,n:int,expense_id:int,suggestion_id:int}> pending receipts grouped by vendor, oldest first within it */
    public function groups(): array
    {
        $rows = $this->db->query("
            SELECT s.id AS suggestion_id, e.id AS expense_id, e.expense_date, COALESCE(v.name, e.vendor_name_raw, '') AS vendor
            FROM expense_suggestions s JOIN expenses e ON e.id = s.expense_id LEFT JOIN vendors v ON v.id = e.vendor_id
            WHERE s.source = 'live' AND s.status = 'pending' AND e.status IN ('draft', 'pending_approval')
            ORDER BY e.expense_date ASC, e.id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $by = [];
        foreach ($rows as $r) {
            $k = strtolower(trim((string)$r['vendor']));
            if ($k === '') continue;
            if (!isset($by[$k])) $by[$k] = ['vendor' => (string)$r['vendor'], 'n' => 0, 'expense_id' => (int)$r['expense_id'], 'suggestion_id' => (int)$r['suggestion_id']];
            $by[$k]['n']++;
        }
        return array_values($by);
    }

    public function teach(): ?array
    {
        require_once __DIR__ . '/PennyBadgeService.php';
        $strength = [];
        try {
            foreach ((new PennyBadgeService($this->db))->vendors(500) as $v) $strength[strtolower($v['vendor'])] = $v;
        } catch (Throwable $e) { /* strength is a bonus */ }
        $g = self::pickTeach($this->groups(), $strength);
        if (!$g) return null;
        $s = $strength[strtolower(trim($g['vendor']))] ?? null;
        $junk = self::isJunkVendor($g['vendor']);
        return ['kind' => 'teach', 'title' => $junk ? 'I can\'t read the shop on ' . $g['n'] . ' receipts' : 'Teach me ' . $g['vendor'], 'why' => self::whyTeach($g, $s),
                'expense_id' => $g['expense_id'], 'suggestion_id' => $g['suggestion_id'], 'score' => $g['score']];
    }

    /** The oldest month that is not ready, and the biggest approved receipt booked to chequing with no bank line in it. */
    public function month(): ?array
    {
        require_once __DIR__ . '/../../Accounting/Services/MonthCloseService.php';
        require_once __DIR__ . '/../../Accounting/Services/MonthDrilldownService.php';
        require_once __DIR__ . '/../../Accounting/Services/BankImportService.php';
        $drill = new MonthDrilldownService($this->db);
        $target = null; $best = null; $d = null; $tried = 0;
        foreach ((new MonthCloseService($this->db))->months() as $m) {
            if ($m['locked'] || $m['ready'] || empty($m['blockers'])) continue;
            if (++$tried > 6) break;
            $d = $drill->month('1010', $m['month']);
            if (isset($d['error'])) continue;
            // A receipt that moves the books away from the bank: approved, booked as paid from chequing, no bank line.
            foreach ($d['book_only'] as $b) {
                if (strpos((string)($b['source'] ?? ''), 'expense:') !== 0) continue;
                if ($best === null || abs((float)$b['amount']) > abs((float)$best['amount'])) $best = $b;
            }
            if ($best) { $target = $m; break; }
        }
        if (!$best || !$target) return null;
        $expenseId = (int)substr((string)$best['source'], 8);
        $e = $this->db->prepare("SELECT COALESCE(v.name, e.vendor_name_raw) AS vendor, e.expense_date FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id = ?");
        $e->execute([$expenseId]);
        $ex = $e->fetch(PDO::FETCH_ASSOC);
        if (!$ex) return null;
        $hint = null;
        try {
            foreach ((new BankImportService($this->db))->candidateTransactionsForExpense($expenseId, 3) as $c) {
                if (!empty($c['vendor_only'])) continue;
                $hint = self::hintFor($c, (string)$ex['expense_date']);
                break;
            }
        } catch (Throwable $e2) { /* a hint is a bonus */ }
        $off = !empty($target['drift']) ? (float)reset($target['drift']) : (float)($d['books_minus_statement'] ?? 0);   // month-end: books minus bank
        $row = ['month' => $target['month'], 'amount_off' => $off, 'receipt_amount' => (float)$best['amount'], 'vendor' => (string)$ex['vendor'],
                'date' => (string)$ex['expense_date'], 'hint' => $hint];
        return ['kind' => 'month', 'title' => date('F', strtotime($target['month'] . '-01')) . ' can\'t close: ' . $ex['vendor'] . ' $' . number_format(abs((float)$best['amount']), 2),
                'why' => self::whyMonth($row), 'expense_id' => $expenseId, 'month' => $target['month']];
    }

    /** Seconds the two books-heavy rows (teach, month) are kept: the month row runs the whole balance check. */
    public const CACHE_SECONDS = 180;

    /** @return list<array> up to three rows, in order: teach, month, money. The money row is never cached. */
    public function rows(?array $pm = null, float $gstStuck = 0.0, bool $fresh = false, bool $withMonth = true): array
    {
        $out = $this->heavyRows($fresh, $withMonth);
        if ($m = self::moneyRow($pm, $gstStuck)) $out[] = $m;
        return $out;
    }

    private function heavyRows(bool $fresh, bool $withMonth): array
    {
        // The month row shows books-against-bank figures and a bank line: administrators only (its own cache).
        $key = $withMonth ? 'penny_focus_cache' : 'penny_focus_cache_basic';
        try {
            if (!$fresh) {
                $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
                $s->execute([$key]);
                $c = json_decode((string)$s->fetchColumn(), true);
                if (is_array($c) && isset($c['at'], $c['rows']) && is_array($c['rows']) && time() - (int)$c['at'] < self::CACHE_SECONDS
                    && count(array_filter($c['rows'], [self::class, 'validRow'])) === count($c['rows'])) return $c['rows'];
            }
        } catch (Throwable $e) { /* no cache: compute */ }
        $out = [];
        foreach ($withMonth ? [fn() => $this->teach(), fn() => $this->month()] : [fn() => $this->teach()] as $f) {
            try { if ($r = $f()) $out[] = $r; } catch (Throwable $e) { error_log('[PennyFocus] ' . $e->getMessage()); }
        }
        try {
            $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                     ->execute([$key, json_encode(['at' => time(), 'rows' => $out])]);
        } catch (Throwable $e) { /* a cache miss next time is fine */ }
        return $out;
    }
}
