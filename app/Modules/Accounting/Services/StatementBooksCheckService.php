<?php
/**
 * StatementBooksCheckService — for one bank or card account and one month, why the books differ from the
 * statement, line by line, adding up EXACTLY to the month's gap (Tim, 2026-10-14: "build the checker").
 *
 * Read only. Three lists, whose sum is (books' movement − statement's movement) for the month:
 *   1. lines   — each statement line in the books (accounting_transactions, reference_type 'bank_import'):
 *                what it should do to the account (its signed amount) vs what the journal actually did with
 *                it this month: its own bank entry, or — when linked to a receipt (matched_expense_id) — that
 *                receipt's entry. Only lines where the two differ are listed (booked to another account,
 *                the wrong way round, never posted, posted in another month…).
 *   2. others  — journal entries on the account this month that are not any line's posting: payments booked
 *                straight to the bank, receipts with no bank line, manual entries, Stripe, client credits.
 *   3. missing — the statement's movement vs the lines we hold: lines never imported (or imported twice).
 * The statement is bank_statement_sections (opening, closing, line count per account per month).
 * Stripe fee lines are ours, not the bank's (split out of a payout): expected to move the account by nothing.
 *
 * Uses: BankBalanceCheckService's sign rules (book sign: bank Dr − Cr, card Cr − Dr) and
 * LedgerSyncService::transferDirection for transfers. Tenant-neutral; no namespace (require_once + new).
 */
class StatementBooksCheckService
{
    private PDO $db;
    private ?array $facts = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** chart id → ['code','name','kind'] for accounts that have statements. */
    public function accounts(): array
    {
        $out = [];
        $rows = $this->db->query("SELECT DISTINCT c.id, c.code, c.name, c.type FROM bank_statement_sections s JOIN chart_of_accounts c ON c.id = s.bank_account_id ORDER BY c.code")
                         ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $out[(int)$r['id']] = ['code' => (string)$r['code'], 'name' => (string)$r['name'],
                                   'kind' => ($r['code'] === '2400' || $r['type'] === 'liability') ? 'card' : 'bank'];
        }
        return $out;
    }

    /** The statement sections for an account, oldest first. */
    public function months(int $accountId): array
    {
        $s = $this->db->prepare("SELECT period_from, period_to, opening_balance, closing_balance, line_count FROM bank_statement_sections
                                 WHERE bank_account_id = ? ORDER BY period_from");
        $s->execute([$accountId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array{account:array, from:string, to:string, statement:?array, book_move:float, gap:?float,
     *               lines:list<array>, others:list<array>, missing:?float, line_counts:array, adds_up:?bool}
     */
    public function check(int $accountId, string $from, string $to): array
    {
        $acct = $this->accounts()[$accountId] ?? ['code' => '?', 'name' => '?', 'kind' => 'bank'];
        $sign = $acct['kind'] === 'card' ? -1 : 1;                       // book sign of Dr − Cr

        $sec = $this->db->prepare("SELECT opening_balance, closing_balance, line_count FROM bank_statement_sections
                                   WHERE bank_account_id = ? AND period_from = ? AND period_to = ? LIMIT 1");
        $sec->execute([$accountId, $from, $to]);
        $statement = $sec->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmtMove = ($statement && $statement['closing_balance'] !== null)
            ? round((float)$statement['closing_balance'] - (float)$statement['opening_balance'], 2) : null;

        // Every journal line on the account this month, by entry.
        $e = $this->db->prepare("
            SELECT je.id, je.entry_date, je.source_type, je.source_id, je.memo, SUM(jl.debit) - SUM(jl.credit) AS dc
            FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id
            WHERE jl.account_id = ? AND je.status = 'posted' AND je.entry_date BETWEEN ? AND ?
            GROUP BY je.id, je.entry_date, je.source_type, je.source_id, je.memo");
        $e->execute([$accountId, $from, $to]);
        $entries = [];
        $bookMove = 0.0;
        foreach ($e->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $eff = round($sign * (float)$r['dc'], 2);
            if (abs($eff) < 0.005) continue;
            $bookMove += $eff;
            $entries[(int)$r['id']] = $r + ['effect' => $eff];
        }
        $bookMove = round($bookMove, 2);
        $bySource = [];
        foreach ($entries as $id => $r) $bySource[$r['source_type'] . ':' . $r['source_id']][] = $id;
        // A reversal belongs to the entry it reverses: a line whose posting was reversed (and booked again
        // elsewhere) keeps both on its own row, instead of one reading "counted twice" and the other "other".
        $reversalOf = [];
        try {
            $rv = $this->db->prepare("SELECT id, reversed_by_entry_id FROM journal_entries WHERE reversed_by_entry_id IS NOT NULL AND entry_date BETWEEN ? AND ?");
            $rv->execute([date('Y-m-d', strtotime($from . ' -400 days')), $to]);
            foreach ($rv->fetchAll(PDO::FETCH_ASSOC) as $x) $reversalOf[(int)$x['id']] = (int)$x['reversed_by_entry_id'];
        } catch (Throwable $e) { /* no reversals column */ }
        $take = function (int $eid) use (&$used, $entries, $reversalOf): float {
            if (isset($used[$eid])) return 0.0;
            $used[$eid] = true;
            $eff = $entries[$eid]['effect'];
            $r = $reversalOf[$eid] ?? null;
            if ($r !== null && isset($entries[$r]) && !isset($used[$r])) { $used[$r] = true; $eff += $entries[$r]['effect']; }
            return $eff;
        };

        // The statement lines we hold.
        $t = $this->db->prepare("
            SELECT id, transaction_date, type, amount, description, account_id, matched_expense_id, matched_invoice_id
            FROM accounting_transactions
            WHERE reference_type = 'bank_import' AND bank_account_id = ? AND transaction_date BETWEEN ? AND ?
              AND (status IS NULL OR status <> 'void')
            ORDER BY transaction_date, id");
        $t->execute([$accountId, $from, $to]);
        $lines = $t->fetchAll(PDO::FETCH_ASSOC);

        $facts = $this->directionFacts();
        $used = [];
        $listed = [];
        $expectedSum = 0.0;
        foreach ($lines as $l) {
            // A Stripe fee line is split out of a payout by us — the statement shows only the net payout — so it
            // should move this account by nothing (it belongs on 1015 Stripe clearing). 2026-10-15.
            $feeLine = LedgerSyncService::isStripeFeeLine((string)$l['description']);
            $expected = $feeLine ? 0.0 : round(self::lineSign($l, $facts[(int)$l['id']] ?? [], $acct['kind']) * (float)$l['amount'], 2);
            $expectedSum += $expected;
            $actual = 0.0;
            $how = [];
            foreach (['bank_import', 'bank_deposit'] as $st) {
                foreach ($bySource[$st . ':' . $l['id']] ?? [] as $eid) {
                    if (isset($used[$eid])) continue;
                    $actual += $take($eid); $how[] = $st . ' #' . $eid;
                }
            }
            if (!empty($l['matched_expense_id'])) {
                foreach ($bySource['expense:' . (int)$l['matched_expense_id']] ?? [] as $eid) {
                    if (isset($used[$eid])) continue;
                    $actual += $take($eid); $how[] = 'receipt #' . (int)$l['matched_expense_id'];
                }
            }
            $diff = round($actual - $expected, 2);
            if (abs($diff) >= 0.01) {
                $listed[] = [
                    'id' => (int)$l['id'], 'date' => (string)$l['transaction_date'], 'description' => (string)$l['description'],
                    'type' => (string)$l['type'], 'expected' => $expected, 'actual' => round($actual, 2), 'diff' => $diff,
                    'posted_as' => $how ? implode(', ', $how) : 'nothing posted this month',
                    'receipt' => !empty($l['matched_expense_id']) ? (int)$l['matched_expense_id'] : null,
                    'why' => $feeLine ? 'a Stripe fee booked to this account — it is not a statement line (Stripe takes it before the payout)'
                                      : self::why($expected, $actual, $how, $l, $acct['kind']),
                ];
            }
        }

        $others = [];
        $opening = 0.0;
        foreach ($entries as $id => $r) {
            if (isset($used[$id])) continue;
            if (in_array($r['source_type'], ['fy_opening', 'fy2026_opening_split', 'opening'], true)) { $opening += $r['effect']; continue; }   // the year's opening, not a month item
            $others[] = ['entry_id' => $id, 'date' => (string)$r['entry_date'], 'source' => (string)$r['source_type'],
                         'source_id' => $r['source_id'] !== null ? (int)$r['source_id'] : null,
                         'memo' => mb_substr((string)$r['memo'], 0, 160), 'effect' => $r['effect']];
        }
        usort($others, fn($a, $b) => abs($b['effect']) <=> abs($a['effect']));

        $missing = $stmtMove === null ? null : round($stmtMove - $expectedSum, 2);   // > 0: statement has more in than our lines
        $gap = $stmtMove === null ? null : round($bookMove - $stmtMove, 2);   // gap = lines_total + others_total + opening_entries − missing
        $sumLines = round(array_sum(array_column($listed, 'diff')), 2);
        $sumOthers = round(array_sum(array_column($others, 'effect')), 2);
        return [
            'account' => $acct + ['id' => $accountId], 'from' => $from, 'to' => $to,
            'statement' => $statement ? ['opening' => (float)$statement['opening_balance'],
                                         'closing' => $statement['closing_balance'] === null ? null : (float)$statement['closing_balance'],
                                         'lines' => (int)$statement['line_count'], 'move' => $stmtMove] : null,
            'book_move' => $bookMove, 'gap' => $gap,
            'lines' => $listed, 'lines_total' => $sumLines,
            'others' => $others, 'others_total' => $sumOthers,
            'missing' => $missing,                                           // statement move not covered by our lines
            'opening_entries' => round($opening, 2),                         // the year's opening entries dated in this month
            'line_counts' => ['statement' => $statement ? (int)$statement['line_count'] : null, 'books' => count($lines)],
            // gap = lines_total + others_total − missing, to the cent
            'adds_up' => $gap === null ? null : abs($gap - ($sumLines + $sumOthers + $opening - $missing)) < 0.015,
        ];
    }

    /** +1 / −1: the line's effect on the account in book sign. Pure. */
    public static function lineSign(array $line, array $facts, string $kind): int
    {
        $type = (string)($line['type'] ?? '');
        if ($type === 'income') $s = 1;
        elseif ($type === 'expense') $s = -1;
        else {
            if (!class_exists('LedgerSyncService')) require_once __DIR__ . '/LedgerSyncService.php';
            $s = LedgerSyncService::transferDirection($facts) === 'in' ? 1 : -1;
        }
        return $kind === 'card' ? -$s : $s;                                 // a card charge raises what we owe
    }

    /** A short reason a person can act on. Pure. */
    public static function why(float $expected, float $actual, array $how, array $line, string $kind): string
    {
        if (!$how) {
            if (!empty($line['matched_expense_id'])) return 'linked to receipt #' . (int)$line['matched_expense_id'] . ', which is not booked to this account this month (another account, another month, or not approved)';
            return 'nothing posted for this line this month';
        }
        if (abs($actual) < 0.01 && count($how) > 0) return 'posted, then reversed (moved to another account) — the bank still shows it here';
        if (abs($actual + $expected) < 0.01) return 'booked the wrong way round';
        if (abs($actual) < 0.01) return 'posted, but not to this account';
        if (abs($actual - 2 * $expected) < 0.01) return 'counted twice';
        return 'posted for a different amount';
    }

    private function directionFacts(): array
    {
        if ($this->facts === null) {
            require_once __DIR__ . '/LedgerService.php';
            require_once __DIR__ . '/LedgerSyncService.php';
            $this->facts = (new LedgerSyncService($this->db, new LedgerService($this->db)))->directionFacts();
        }
        return $this->facts;
    }
}
