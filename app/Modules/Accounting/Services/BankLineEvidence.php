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
     * @param list<array> $lines BankDeskService::queue() lines
     * @return array<int, array{earlier: list<array>, receipts: list<array>, recurring: int}> by line id
     */
    public function forLines(array $lines, string $firstMonth): array
    {
        require_once __DIR__ . '/BankImportService.php';
        $out = [];
        $bank = new BankImportService($this->db);
        foreach ($lines as $l) {
            $id = (int)$l['id'];
            $row = ['earlier' => [], 'receipts' => [], 'recurring' => 0];
            try {
                $key = BankImportService::descriptionKey((string)($l['description'] ?? ''));
                if (strlen($key) >= 4) {
                    $first = explode(' ', $key)[0];
                    $q = $this->db->prepare("SELECT t.id, t.transaction_date, t.amount, t.description, c.code, c.name
                                             FROM accounting_transactions t LEFT JOIN chart_of_accounts c ON c.id = t.account_id
                                             WHERE t.reference_type = 'bank_import' AND t.id <> ? AND t.description LIKE ?
                                               AND COALESCE(t.status, '') NOT IN ('void', 'deleted')
                                             ORDER BY t.transaction_date DESC LIMIT " . self::MAX_EARLIER);
                    $q->execute([$id, '%' . $first . '%']);
                    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        if (BankImportService::descriptionKey((string)$r['description']) !== $key) continue;
                        $row['earlier'][] = ['date' => substr((string)$r['transaction_date'], 0, 10), 'amount' => round((float)$r['amount'], 2),
                                             'account' => trim((string)$r['code'] . ' ' . (string)$r['name'])];
                        if (abs(abs((float)$r['amount']) - abs((float)$l['amount'])) < 0.005) $row['recurring']++;
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
     * @param array{earlier?: list<array>, receipts?: list<array>, recurring?: int} $e
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
        $out[] = 'No vendor of yours matches';
        return array_slice($out, 0, 4);
    }
}
