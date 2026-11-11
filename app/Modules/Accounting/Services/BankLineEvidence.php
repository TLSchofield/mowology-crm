<?php
declare(strict_types=1);

/**
 * BankLineEvidence — what the books themselves can say about a bank line Penny has no account for (Tim, 2026-11-10: "it needs good
 * evidence", for any tenant). Read only, and only from the business's OWN data: nothing here knows any supplier by name.
 *
 *   earlier   other bank lines with the same payee (BankImportService::descriptionKey) and where each was filed
 *   receipts  receipts that could belong to it within ±14 days (BankImportService::candidateExpensesForTransaction, drafts included);
 *             one dated before $firstMonth is shown but is NEVER offered as a link (its books are in the closed year)
 *   recurring how many other lines carry the same payee AND the same amount
 *
 * summarise() turns that into at most four plain sentences for the strip's frame; it says "no receipt" and "first time" outright,
 * because an empty answer is evidence too. No namespace / no autoloader in production: require_once and `new`.
 */
class BankLineEvidence
{
    public const MAX_EARLIER = 40;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Pure: the merchant of a bank line as a short letters-only stem, so one shop matches across store numbers, spacing and formats
     * ("POINTOFSALE(THEHOMEDEPOT7259VANCOUVERBCCA)" and "Point of sale THE HOME DEPOT 7042" both → THEHOMEDEPO). The payee of an
     * e-Transfer is the name after its reference. '' when there is not enough to go on.
     * (BankImportService::descriptionKey drops a whole merchant when its store number is glued to it: "DAIRYQUEEN27339…" became
     * "pointofsale", and every card purchase matched every other — measured 2026-11-10.)
     */
    public static function stem(string $description): string
    {
        $d = strtoupper(trim(preg_replace('/\s+/', ' ', $description) ?? ''));
        if (preg_match('/^E-?TRANSFER\s+(?:DEBIT|CREDIT)\s+REF\s*\S+\s*(.*)$/', $d, $m)) {
            $d = $m[1];
        } else {
            $d = preg_replace('/^(POINT ?OF ?SALE|PURCHASE|INTERAC|DEBIT)\b/', '', $d) ?? $d;
            $d = str_replace(['(', ')'], ' ', $d);
            $cut = preg_match('/\d/', $d, $x, PREG_OFFSET_CAPTURE) ? $x[0][1] : null;   // the store number follows the merchant
            if ($cut !== null && preg_match('/[A-Z]{4}/', substr($d, 0, $cut))) $d = substr($d, 0, $cut);
        }
        $letters = preg_replace('/[^A-Z]/', '', $d) ?? '';
        return strlen($letters) >= 4 ? substr($letters, 0, 10) : '';
    }

    /** An alias this short is a fragment, not a name ("city of", "push", "pnty" are on real vendors, read off receipts): names count, aliases only from this length. */
    public const MIN_ALIAS_LETTERS = 8;

    /**
     * Pure: the first vendor on file whose NAME (letters only, 4+) or a long alias (MIN_ALIAS_LETTERS+) sits inside the line's text; null when none.
     * @param list<array{name:string, aliases?:string, default_accounting_category?:string}> $vendors
     * @return array{name:string, category:string}|null
     */
    public static function vendorFor(string $description, array $vendors): ?array
    {
        $hay = preg_replace('/[^A-Z]/', '', strtoupper($description)) ?? '';
        foreach ($vendors as $v) {
            $names = [[(string)$v['name'], 4]];
            foreach (explode(',', (string)($v['aliases'] ?? '')) as $al) $names[] = [trim($al), self::MIN_ALIAS_LETTERS];
            foreach ($names as [$n, $min]) {
                $k = preg_replace('/[^A-Z]/', '', strtoupper($n)) ?? '';
                if (strlen($k) >= $min && strpos($hay, $k) !== false) return ['name' => (string)$v['name'], 'category' => (string)($v['default_accounting_category'] ?? '')];
            }
        }
        return null;
    }

    /**
     * @param list<array> $lines BankDeskService::queue() lines
     * @return array<int, array{earlier: list<array>, receipts: list<array>, recurring: int, vendor: ?array}> by line id
     */
    public function forLines(array $lines, string $firstMonth): array
    {
        require_once __DIR__ . '/BankImportService.php';
        $out = [];
        $bank = new BankImportService($this->db);
        $vendors = [];
        try {
            $vendors = $this->db->query("SELECT name, aliases, default_accounting_category FROM vendors WHERE name <> '' LIMIT 3000")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* no vendors table: none match */ }
        foreach ($lines as $l) {
            $id = (int)$l['id'];
            $row = ['earlier' => [], 'receipts' => [], 'recurring' => 0, 'vendor' => self::vendorFor((string)($l['description'] ?? ''), $vendors)];
            try {
                $stem = self::stem((string)($l['description'] ?? ''));
                if ($stem !== '') {
                    // narrowed in SQL on the stem's start with spaces ignored, then compared exactly in PHP
                    $q = $this->db->prepare("SELECT t.id, t.transaction_date, t.amount, t.description, c.code, c.name
                                             FROM accounting_transactions t LEFT JOIN chart_of_accounts c ON c.id = t.account_id
                                             WHERE t.reference_type = 'bank_import' AND t.id <> ?
                                               AND REPLACE(UPPER(t.description), ' ', '') LIKE ?
                                               AND COALESCE(t.status, '') NOT IN ('void', 'deleted')
                                             ORDER BY t.transaction_date DESC LIMIT " . (self::MAX_EARLIER * 3));
                    $q->execute([$id, '%' . substr($stem, 0, 6) . '%']);
                    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        if (self::stem((string)$r['description']) !== $stem) continue;
                        $row['earlier'][] = ['date' => substr((string)$r['transaction_date'], 0, 10), 'amount' => round((float)$r['amount'], 2),
                                             'account' => trim((string)$r['code'] . ' ' . (string)$r['name'])];
                        if (abs(abs((float)$r['amount']) - abs((float)$l['amount'])) < 0.005) $row['recurring']++;
                        if (count($row['earlier']) >= self::MAX_EARLIER) break;
                    }
                }
                foreach ($bank->candidateExpensesForTransaction($id, 3) as $c) {
                    $row['receipts'][] = ['id' => (int)$c['expense_id'], 'vendor' => (string)$c['vendor'], 'date' => (string)$c['date'],
                                          'amount' => (float)$c['amount'], 'status' => (string)$c['status'],
                                          'closed_year' => substr((string)$c['date'], 0, 7) < $firstMonth];
                }
            } catch (Throwable $e) {
                error_log('BankLineEvidence line ' . $id . ': ' . $e->getMessage());
            }
            $out[$id] = $row;
        }
        return $out;
    }

    /**
     * Pure: the evidence as short sentences, most useful first, at most four. A default account (4900 / 6900) is "not sorted".
     * @param array{earlier?: list<array>, receipts?: list<array>, recurring?: int, vendor?: ?array} $e
     * @return list<string>
     */
    public static function summarise(array $e, array $defaultCodes = ['4900', '6900']): array
    {
        $out = [];
        $earlier = (array)($e['earlier'] ?? []);
        if (!$earlier) {
            $out[] = 'First time this payee is on your bank lines';
        } else {
            $by = [];
            foreach ($earlier as $r) {
                $code = explode(' ', trim((string)$r['account']))[0] ?? '';
                $label = ($code === '' || in_array($code, $defaultCodes, true)) ? 'not sorted' : trim((string)$r['account']);
                $by[$label] = ($by[$label] ?? 0) + 1;
            }
            arsort($by);
            $parts = [];
            foreach (array_slice($by, 0, 2, true) as $label => $n) $parts[] = $n . ' on ' . $label;
            $out[] = 'Seen ' . count($earlier) . ' time' . (count($earlier) === 1 ? '' : 's') . ' before: ' . implode(', ', $parts);
        }
        $rc = (array)($e['receipts'] ?? []);
        if (!$rc) {
            $out[] = 'No receipt within 14 days';
        } else {
            $r = $rc[0];
            $out[] = 'Receipt: ' . ($r['vendor'] !== '' ? $r['vendor'] : 'no name') . ' $' . number_format((float)$r['amount'], 2) . ' · ' . $r['date']
                   . ' · ' . $r['status'] . (!empty($r['closed_year']) ? ' (closed year: not linkable)' : '');
        }
        $rec = (int)($e['recurring'] ?? 0);
        if ($rec >= 2) $out[] = 'Same amount ' . ($rec + 1) . ' times — it repeats';
        $v = $e['vendor'] ?? null;
        $out[] = is_array($v) ? 'Vendor on file: ' . $v['name'] . ($v['category'] !== '' ? ' (usually ' . $v['category'] . ')' : '') : 'No vendor of yours matches';
        return array_slice($out, 0, 4);
    }
}
