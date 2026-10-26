<?php
declare(strict_types=1);

/**
 * MonthDrilldownService — for one account and one month, the statement's lines beside the books' entries,
 * with what has no partner on the other side (Tim, 2026-10-25: "are any of the months balancing" — the balance check
 * says a month is off by $X but cannot say which lines).
 *
 * READ ONLY: it changes nothing and proposes nothing; it names lines. Reuses BankBalanceCheckService's tracked accounts,
 * statement lines (merged, de-duplicated) and book movements, so it sees exactly what the check sees.
 *
 * Matching (pure, tested): a statement line and a book entry pair when the signed amounts agree to the cent, same
 * date first, then the same amount on a different date in the month ("date differs"). What is left is the story:
 * book entries with no statement line (books higher/lower), statement lines with no book entry.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/LedgerService.php';
require_once __DIR__ . '/BankBalanceCheckService.php';

class MonthDrilldownService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Pure. $stmt rows: ['id','date','amount' (signed, book sign), 'description'?]; $book rows: ['id','date','amount','memo'?,'source'?].
     * @return array{matched:int, cancelled_pairs:int, date_differs:list<array>, statement_only:list<array>, book_only:list<array>,
     *               statement_only_total:float, book_only_total:float, explains:float}
     */
    public static function compare(array $stmt, array $book): array
    {
        $cent = fn(float $v) => (int)round($v * 100);
        $usedBook = [];
        $unmatchedStmt = [];
        $matched = 0;
        // Pass 1: same date, same amount.
        $byKey = [];
        foreach ($book as $i => $b) $byKey[substr((string)$b['date'], 0, 10) . '|' . $cent((float)$b['amount'])][] = $i;
        foreach ($stmt as $s) {
            $k = substr((string)$s['date'], 0, 10) . '|' . $cent((float)$s['amount']);
            if (!empty($byKey[$k])) { $usedBook[array_shift($byKey[$k])] = true; $matched++; }
            else $unmatchedStmt[] = $s;
        }
        // Pass 2: same amount, another date in the month.
        $freeByAmt = [];
        foreach ($book as $i => $b) if (!isset($usedBook[$i])) $freeByAmt[$cent((float)$b['amount'])][] = $i;
        $dateDiffers = [];
        $statementOnly = [];
        foreach ($unmatchedStmt as $s) {
            $a = $cent((float)$s['amount']);
            if (!empty($freeByAmt[$a])) {
                $i = array_shift($freeByAmt[$a]);
                $usedBook[$i] = true;
                $dateDiffers[] = ['statement' => $s, 'book' => $book[$i]];
            } else {
                $statementOnly[] = $s;
            }
        }
        $bookOnly = [];
        foreach ($book as $i => $b) if (!isset($usedBook[$i])) $bookOnly[] = $b;
        // An entry and its exact opposite (a reversal and what it reversed, or a repost after a move) net to nothing:
        // set them aside so the list shows only what actually moves the balance.
        $cancelled = 0;
        $open = [];
        $byOpp = [];
        foreach ($bookOnly as $i => $b) $byOpp[-$cent((float)$b['amount'])][] = $i;
        $drop = [];
        foreach ($bookOnly as $i => $b) {
            if (isset($drop[$i])) continue;
            $a = $cent((float)$b['amount']);
            if ($a === 0 || empty($byOpp[$a])) continue;
            while (!empty($byOpp[$a])) {
                $j = array_shift($byOpp[$a]);
                if ($j !== $i && !isset($drop[$j])) { $drop[$i] = $drop[$j] = true; $cancelled++; break; }
            }
        }
        foreach ($bookOnly as $i => $b) if (!isset($drop[$i])) $open[] = $b;
        $bookOnly = $open;

        // Sum cents, the unit the pairing used, so a total never disagrees with its own lines.
        $sTotal = array_sum(array_map(fn($r) => $cent((float)$r['amount']), $statementOnly)) / 100;
        $bTotal = array_sum(array_map(fn($r) => $cent((float)$r['amount']), $bookOnly)) / 100;
        return [
            'matched' => $matched + count($dateDiffers),
            'cancelled_pairs' => $cancelled,
            'date_differs' => $dateDiffers,
            'statement_only' => $statementOnly,
            'book_only' => $bookOnly,
            'statement_only_total' => round($sTotal, 2),
            'book_only_total' => round($bTotal, 2),
            'explains' => round($bTotal - $sTotal, 2),   // books minus statement, from unpaired lines alone
        ];
    }

    /** Statement lines of the account dated in the month, in book sign. @return list<array> */
    public function statementLines(array $account, array $statement, string $ym): array
    {
        $out = [];
        $ids = [];
        foreach (($statement['ordered'] ?? []) as $l) {
            if (substr((string)$l['date'], 0, 7) !== $ym || $l['signed'] === null) continue;
            $out[] = ['id' => (int)$l['id'], 'date' => substr((string)$l['date'], 0, 10), 'amount' => (float)$l['signed'], 'balance' => $l['balance']];
            $ids[] = (int)$l['id'];
        }
        // What each line is: the bank row's description, via its CRM transaction.
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $s = $this->db->prepare("SELECT r.id, t.description FROM bank_import_rows r
                                     LEFT JOIN accounting_transactions t ON t.id = r.transaction_id WHERE r.id IN ($in)");
            $s->execute($ids);
            $desc = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $desc[(int)$r['id']] = (string)$r['description'];
            foreach ($out as &$o) $o['description'] = $desc[$o['id']] ?? '';
            unset($o);
        }
        return $out;
    }

    /** Posted journal entries touching the account in the month, one row per entry, in book sign. @return list<array> */
    public function bookEntries(array $account, string $ym): array
    {
        $s = $this->db->prepare("
            SELECT je.id, je.entry_date, je.memo, je.source_type, je.source_id, SUM(jl.debit) AS d, SUM(jl.credit) AS c
            FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id
            WHERE jl.account_id = ? AND je.status = 'posted' AND SUBSTR(je.entry_date, 1, 7) = ?
            GROUP BY je.id, je.entry_date, je.memo, je.source_type, je.source_id
            ORDER BY je.entry_date, je.id");
        $s->execute([(int)$account['id'], $ym]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mv = (float)$r['d'] - (float)$r['c'];
            $out[] = ['id' => (int)$r['id'], 'date' => substr((string)$r['entry_date'], 0, 10),
                      'amount' => round($account['kind'] === 'card' ? -$mv : $mv, 2),
                      'memo' => (string)$r['memo'], 'source' => (string)$r['source_type'] . ':' . (string)$r['source_id']];
        }
        return $out;
    }

    /**
     * @return array<string,mixed> report, or ['error' => …]
     */
    public function month(string $code, string $ym): array
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $mm) || !checkdate((int)$mm[2], 1, (int)$mm[1])) return ['error' => 'month must be YYYY-MM'];
        $check = new BankBalanceCheckService($this->db);
        $accounts = $check->trackedAccounts();
        $acct = null;
        foreach ($accounts as $a) if ($a['code'] === $code) { $acct = $a; break; }
        if (!$acct) return ['error' => 'unknown account code'];
        $statements = $check->statements($accounts);
        $st = $statements[$acct['id']] ?? null;
        if (!$st) return ['error' => 'no statement lines for this account'];

        $stmtLines = $this->statementLines($acct, $st, $ym);
        $bookLines = $this->bookEntries($acct, $ym);
        $cmp = self::compare($stmtLines, $bookLines);

        $prev = date('Y-m', strtotime($ym . '-01 -1 month'));
        $closing = $st['closing'] ?? [];
        $stmtNet = (isset($closing[$ym], $closing[$prev])) ? round((float)$closing[$ym] - (float)$closing[$prev], 2) : null;
        $bookNet = round(array_sum(array_map(fn($r) => $r['amount'], $bookLines)), 2);
        return [
            'account' => $acct['code'] . ' ' . $acct['name'], 'month' => $ym,
            'statement_lines' => count($stmtLines), 'book_entries' => count($bookLines),
            'statement_closing' => $closing[$ym] ?? null, 'statement_net_movement' => $stmtNet, 'book_net_movement' => $bookNet,
            'books_minus_statement' => $stmtNet === null ? null : round($bookNet - $stmtNet, 2),
        ] + $cmp;
    }
}
