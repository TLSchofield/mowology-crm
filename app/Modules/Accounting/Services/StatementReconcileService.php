<?php
/** Read-only, line-by-line comparison of a statement with the posted journal. */
class StatementReconcileService
{
    public const WINDOW_DAYS = 40;
    public const GROUP_WINDOW_DAYS = 14;
    public const GROUP_MAX = 6;
    public const GROUP_CANDIDATE_MAX = 200;
    public const GROUP_COMBINATION_MAX = 100000;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function dedupeStatementLines(array $lines): array
    {
        // Lines tagged with the file/export they came from: the same transaction can appear in several exports with different running
        // balances (the bank orders same-day items differently), so (date, amount, balance) is not safe across exports. The true number of
        // identical lines on a day is the most any ONE export shows; two identical same-day charges in one export are kept.
        $sourced = []; $plain = [];
        foreach ($lines as $l) { if (!empty($l['source'])) $sourced[] = $l; else $plain[] = $l; }
        if ($sourced) {
            $a = self::dedupeBySource($sourced);
            if (!$plain) return $a;
            $b = self::dedupeNoSource($plain);
            $merged = array_merge($a['lines'], $b['lines']);
            usort($merged, function (array $x, array $y): int { return strcmp((string)$x['date'], (string)$y['date']); });
            return ['lines' => $merged, 'balance_available' => $a['balance_available'] && $b['balance_available']];
        }
        return self::dedupeNoSource($lines);
    }

    private static function dedupeNoSource(array $lines): array
    {
        $out = [];
        $seen = [];
        $balanceAvailable = true;
        foreach (array_values($lines) as $i => $line) {
            $line['amount'] = round((float)$line['amount'], 2);
            $line['balance'] = $line['balance'] === null ? null : round((float)$line['balance'], 2);
            $line['_input_order'] = $i;
            if ($line['balance'] === null) {
                $balanceAvailable = false;
                // Deliberately do not deduplicate these: a genuine repeat is indistinguishable.
                $out[] = $line;
                continue;
            }
            $key = (string)$line['date'] . '|' . number_format($line['amount'], 2, '.', '') . '|' . number_format($line['balance'], 2, '.', '');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $line;
        }
        usort($out, function (array $a, array $b): int {
            $date = strcmp((string)$a['date'], (string)$b['date']);
            return $date !== 0 ? $date : ($a['_input_order'] <=> $b['_input_order']);
        });
        foreach ($out as &$line) unset($line['_input_order']);
        unset($line);
        return ['lines' => $out, 'balance_available' => $balanceAvailable && count($out) > 0];
    }

    private static function dedupeBySource(array $lines): array
    {
        $groups = [];
        foreach (array_values($lines) as $i => $line) {
            $line['amount'] = round((float)$line['amount'], 2);
            $line['balance'] = !isset($line['balance']) || $line['balance'] === null ? null : round((float)$line['balance'], 2);
            // Description text differs between exports ("Cheque deposit-branch" vs the same with the payer's name), so it is not part of the key.
            $key = (string)$line['date'] . '|' . number_format($line['amount'], 2, '.', '');
            $groups[$key][(string)$line['source']][] = [$i, $line];
        }
        $out = [];
        foreach ($groups as $bySource) {
            $best = null;
            foreach ($bySource as $items) if ($best === null || count($items) > count($best)) $best = $items;
            foreach ($best as $pair) $out[] = $pair;
        }
        usort($out, function (array $a, array $b): int {
            $d = strcmp((string)$a[1]['date'], (string)$b[1]['date']);
            return $d !== 0 ? $d : ($a[0] <=> $b[0]);
        });
        $balanceAvailable = count($out) > 0;
        $result = [];
        foreach ($out as $pair) { if ($pair[1]['balance'] === null) $balanceAvailable = false; $result[] = $pair[1]; }
        return ['lines' => $result, 'balance_available' => $balanceAvailable];
    }

    public static function collapseReversals(array $entries): array
    {
        $byId = [];
        foreach ($entries as $entry) $byId[(int)$entry['id']] = $entry;
        $next = []; $hasPrev = [];   // next[id] = the entry that reverses id
        foreach ($entries as $entry) {
            $id = (int)$entry['id'];
            $t = $entry['reversed_by_entry_id'] ?? null;
            if ($t !== null && isset($byId[(int)$t]) && (int)$t !== $id) { $next[$id] = (int)$t; $hasPrev[(int)$t] = true; }
        }
        // memo fallback, only for links the column did not give
        foreach ($entries as $entry) {
            $id = (int)$entry['id'];
            if (isset($hasPrev[$id]) || ($entry['reversed_by_entry_id'] ?? null) !== null) continue;
            if (preg_match('/^Reversal of #(\d+)\b/i', trim((string)($entry['memo'] ?? '')), $m)) {
                $x = (int)$m[1];
                if (isset($byId[$x]) && $x !== $id && !isset($next[$x])) { $next[$x] = $id; $hasPrev[$id] = true; }
            }
        }
        // A reversal negates the entry before it, so a chain of n entries leaves nothing when n is even and only the LAST entry when n is odd
        // (entry -> reversal -> reversal of the reversal restores the entry).
        $cancelled = [];
        foreach ($byId as $id => $_) {
            if (isset($hasPrev[$id])) continue;
            $chain = [$id]; $seen = [$id];
            while (isset($next[end($chain)]) && !isset($seen[$next[end($chain)]])) { $n = $next[end($chain)]; $chain[] = $n; $seen[$n] = true; }
            if (count($chain) === 1) continue;
            $keep = count($chain) % 2 === 1 ? end($chain) : null;
            foreach ($chain as $c) if ($c !== $keep) $cancelled[$c] = true;
        }
        return array_values(array_filter($entries, function (array $entry) use ($cancelled): bool {
            return !isset($cancelled[(int)$entry['id']]);
        }));
    }

    public static function match(array $statement, array $books, array $opts = []): array
    {
        $sUsed = $bUsed = [];
        $pairs = [];
        $capped = false;
        $combinations = 0;
        $add = function (string $tier, array $si, array $bi, int $gap = 0) use (&$pairs, &$sUsed, &$bUsed, $statement, $books): void {
            $ss = $bb = [];
            $amount = 0.0;
            foreach ($si as $i) { $sUsed[$i] = true; $ss[] = $statement[$i]; $amount += (float)$statement[$i]['amount']; }
            foreach ($bi as $i) { $bUsed[$i] = true; $bb[] = $books[$i]; }
            $pairs[] = ['tier' => $tier, 'gap_days' => $gap, 'statement' => $ss, 'books' => $bb, 'amount' => round($amount, 2)];
        };

        foreach ($statement as $si => $s) {
            if (empty($s['source_key'])) continue;
            foreach ($books as $bi => $b) {
                if (isset($bUsed[$bi]) || ($b['source_key'] ?? null) !== $s['source_key']) continue;
                if (self::cents($b['net']) === self::cents($s['amount'])) { $add('source', [$si], [$bi]); break; }
            }
        }

        $possible = [];
        foreach ($statement as $si => $s) if (!isset($sUsed[$si])) {
            foreach ($books as $bi => $b) if (!isset($bUsed[$bi]) && self::cents($s['amount']) === self::cents($b['net'])) {
                $gap = self::dayGap((string)$s['date'], (string)$b['date']);
                if ($gap <= self::WINDOW_DAYS) $possible[] = [$gap, self::stableId($b, $bi), $si, $bi];
            }
        }
        usort($possible, function ($a, $b) { return $a[0] <=> $b[0] ?: ($a[1] <=> $b[1]) ?: ($a[2] <=> $b[2]); });
        foreach ($possible as $p) if (!isset($sUsed[$p[2]]) && !isset($bUsed[$p[3]])) $add('amount_date', [$p[2]], [$p[3]], $p[0]);

        self::groupDirection($statement, $books, $sUsed, $bUsed, false, $add, $capped, $combinations);
        self::groupDirection($books, $statement, $bUsed, $sUsed, true, $add, $capped, $combinations);

        $su = $bu = [];
        foreach ($statement as $i => $v) if (!isset($sUsed[$i])) $su[] = $v;
        foreach ($books as $i => $v) if (!isset($bUsed[$i])) $bu[] = $v;
        $byTier = [];
        foreach ($pairs as $pair) $byTier[$pair['tier']] = ($byTier[$pair['tier']] ?? 0) + 1;
        return ['pairs' => $pairs, 'statement_unmatched' => $su, 'books_unmatched' => $bu, 'by_tier' => $byTier, 'capped' => $capped];
    }

    private static function groupDirection(array $one, array $many, array &$oneUsed, array &$manyUsed, bool $mirror, callable $add, bool &$capped, int &$combinations): void
    {
        foreach ($one as $oi => $target) {
            if ($capped) return;   // once the shared budget is spent, nothing else is matched by group sums
            if (isset($oneUsed[$oi])) continue;
            $targetAmount = (float)($mirror ? $target['net'] : $target['amount']);
            $candidates = [];
            foreach ($many as $mi => $item) {
                if (isset($manyUsed[$mi])) continue;
                $amount = (float)($mirror ? $item['amount'] : $item['net']);
                if (($amount < 0) !== ($targetAmount < 0) || abs($amount) < 0.005) continue;
                if (self::dayGap((string)$target['date'], (string)$item['date']) <= self::GROUP_WINDOW_DAYS) $candidates[] = $mi;
            }
            if (count($candidates) > self::GROUP_CANDIDATE_MAX) { $capped = true; continue; }
            $found = null;
            for ($size = 2; $size <= self::GROUP_MAX && $size <= count($candidates); $size++) {
                $found = self::combination($many, $candidates, $size, self::cents($targetAmount), $mirror, $combinations, $capped);
                if ($found !== null || $capped) break;
            }
            if ($capped && $found === null) continue;
            if ($found !== null) {
                if ($mirror) $add('group_sum', $found, [$oi]);
                else $add('group_sum', [$oi], $found);
            }
        }
    }

    private static function combination(array $items, array $indexes, int $size, int $target, bool $statementItems, int &$count, bool &$capped): ?array
    {
        if ($capped) return null;
        $walk = function (int $at, array $chosen, int $sum) use (&$walk, $items, $indexes, $size, $target, $statementItems, &$count, &$capped): ?array {
            if (++$count > self::GROUP_COMBINATION_MAX) { $capped = true; return null; }
            if (count($chosen) === $size) return $sum === $target ? $chosen : null;
            $need = $size - count($chosen);
            for ($i = $at; $i <= count($indexes) - $need; $i++) {
                $idx = $indexes[$i];
                $key = $statementItems ? 'amount' : 'net';
                $r = $walk($i + 1, array_merge($chosen, [$idx]), $sum + self::cents($items[$idx][$key]));
                if ($r !== null || $capped) return $r;
            }
            return null;
        };
        return $walk(0, [], 0);
    }

    public static function monthly(array $statement, array $books): array
    {
        $out = [];
        foreach ($statement as $line) {
            $ym = substr((string)$line['date'], 0, 7);
            if (!isset($out[$ym])) $out[$ym] = ['statement_net' => 0.0, 'books_net' => 0.0, 'difference' => 0.0, 'statement_count' => 0, 'books_count' => 0];
            $out[$ym]['statement_net'] += (float)$line['amount']; $out[$ym]['statement_count']++;
        }
        foreach ($books as $line) {
            $ym = substr((string)$line['date'], 0, 7);
            if (!isset($out[$ym])) $out[$ym] = ['statement_net' => 0.0, 'books_net' => 0.0, 'difference' => 0.0, 'statement_count' => 0, 'books_count' => 0];
            $out[$ym]['books_net'] += (float)$line['net']; $out[$ym]['books_count']++;
        }
        ksort($out);
        foreach ($out as &$row) { $row['statement_net'] = round($row['statement_net'], 2); $row['books_net'] = round($row['books_net'], 2); $row['difference'] = round($row['books_net'] - $row['statement_net'], 2); }
        unset($row);
        return $out;
    }

    public static function balanceChainCheck(array $lines): array
    {
        $months = [];
        foreach ($lines as $line) if ($line['balance'] !== null) $months[substr((string)$line['date'], 0, 7)][] = $line;
        ksort($months); $out = [];
        foreach ($months as $ym => $group) {
            $remaining = array_values($group); $pred = [];
            foreach ($remaining as $i => $line) $pred[$i] = round((float)$line['balance'] - (float)$line['amount'], 2);
            $balanceSet = [];
            foreach ($remaining as $line) $balanceSet[number_format((float)$line['balance'], 2, '.', '')] = true;
            $start = 0;
            foreach ($pred as $i => $p) if (!isset($balanceSet[number_format($p, 2, '.', '')])) { $start = $i; break; }
            $ordered = []; $used = []; $current = $pred[$start];
            while (count($ordered) < count($remaining)) {
                $next = null;
                foreach ($remaining as $i => $line) if (!isset($used[$i]) && abs($pred[$i] - $current) <= 0.01) { $next = $i; break; }
                if ($next === null) break;
                $used[$next] = true; $ordered[] = $remaining[$next]; $current = (float)$remaining[$next]['balance'];
            }
            $ok = count($ordered) === count($remaining);
            $opening = $pred[$start];
            $closing = $ok ? (float)$ordered[count($ordered) - 1]['balance'] : null;
            $sum = array_sum(array_column($remaining, 'amount'));
            if ($ok && abs(($closing - $opening) - $sum) > 0.01) $ok = false;
            $out[$ym] = ['ok' => $ok, 'opening' => round($opening, 2), 'closing' => $closing === null ? null : round($closing, 2), 'broken_at' => $ok ? null : (string)($remaining[count($ordered)]['date'] ?? $remaining[0]['date'])];
        }
        return $out;
    }

    public static function parseVancityCsv(string $csvText, string $source = ''): array
    {
        $csvText = preg_replace('/^\xEF\xBB\xBF/', '', $csvText);
        $fh = fopen('php://temp', 'r+'); fwrite($fh, $csvText); rewind($fh);
        $header = fgetcsv($fh, 0, ',', '"', ''); $out = [];
        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if (!array_filter($row, 'strlen')) continue;
            $date = DateTime::createFromFormat('d-M-Y', trim((string)($row[0] ?? '')));
            if (!$date) continue;
            $debit = abs(self::money($row[2] ?? '')); $credit = abs(self::money($row[3] ?? ''));
            $balance = trim((string)($row[4] ?? '')) === '' ? null : self::money($row[4]);
            $out[] = ['date' => $date->format('Y-m-d'), 'amount' => round($credit - $debit, 2), 'balance' => $balance, 'description' => trim((string)($row[1] ?? '')), 'source' => $source];
        }
        fclose($fh); return $out;
    }

    public function report(int $bankAccountChartId, string $from, string $to, ?array $csvLines = null, array $opts = []): array
    {
        $statement = $csvLines === null ? $this->loadStatement($bankAccountChartId, $from, $to) : array_values(array_filter($csvLines, function ($l) use ($from, $to) { return $l['date'] >= $from && $l['date'] <= $to; }));
        $deduped = self::dedupeStatementLines($statement); $statement = $deduped['lines'];
        $books = $this->loadBooks($bankAccountChartId, $from, $to, $opts);
        $books = self::collapseReversals($books);
        $base = ['months' => self::monthly($statement, $books), 'matching' => self::match($statement, $books), 'chain' => self::balanceChainCheck($statement), 'balance_available' => $deduped['balance_available'], 'from' => $from, 'to' => $to,
                 'receipts_without_line' => $this->receiptsWithoutLine($bankAccountChartId, $from, $to)];
        if (!$statement) return ['status' => 'nothing_to_check'] + $base;
        $different = self::hasDifferences($base);
        return ['status' => $different ? 'differences' : 'ok'] + $base;
    }

    /** True when anything needs a look: an unmatched line either side, a month that does not tie, or a broken balance chain. Pure. */
    public static function hasDifferences(array $base): bool
    {
        if (!empty($base['matching']['statement_unmatched']) || !empty($base['matching']['books_unmatched'])) return true;
        foreach ($base['months'] as $m) if (abs((float)$m['difference']) >= 0.005) return true;
        foreach ($base['chain'] as $c) if (empty($c['ok'])) return true;
        if (!empty($base['receipts_without_line'])) return true;
        return false;
    }

    /**
     * Approved debit receipts in the window with no bank line linked (read-only). The nightly receipt/statement check reverses
     * these and sends them back to draft, so the books will change tomorrow even if they tie today (Lawnboy #221, 2026-11-25).
     * Only the chequing account (code 1010) is checked; any other account returns [].
     */
    public function receiptsWithoutLine(int $bankAccountChartId, string $from, string $to): array
    {
        $c = $this->db->prepare("SELECT code FROM chart_of_accounts WHERE id = ?");
        $c->execute([$bankAccountChartId]);
        if ((string)$c->fetchColumn() !== '1010') return [];
        $linked = "EXISTS (SELECT 1 FROM accounting_transactions t WHERE t.matched_expense_id = e.id)";
        try {
            $this->db->query("SELECT 1 FROM bank_line_receipts LIMIT 1");
            $linked = "($linked OR EXISTS (SELECT 1 FROM bank_line_receipts r WHERE r.expense_id = e.id))";
        } catch (\Throwable $e) { /* older tenants have no table */ }
        $s = $this->db->prepare("SELECT e.id, e.expense_date, e.vendor_name_raw, e.total FROM expenses e
            WHERE e.status = 'approved' AND COALESCE(e.forwarded_to_accounting, 0) = 0 AND e.total > 0
              AND (COALESCE(e.payment_method, '') = '' OR e.payment_method LIKE '%debit%')
              AND e.expense_date BETWEEN ? AND ? AND NOT $linked ORDER BY e.expense_date, e.id");
        $s->execute([$from, $to]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ['id' => (int)$r['id'], 'date' => (string)$r['expense_date'], 'vendor' => (string)$r['vendor_name_raw'], 'total' => round((float)$r['total'], 2)];
        return $out;
    }

    /**
     * Sign imported lines from the running balance, not from the stored type (a card payment can be stored positive although money went
     * out). Each item: ['amount' => abs float, 'type' => income|expense|transfer|..., 'balance' => ?float]. A line is a credit when
     * (balance − amount) is another line's balance and (balance + amount) is not, and a debit the other way round; otherwise the stored type
     * decides. Pure.
     * @return list<float> signed amounts in the same order
     */
    public static function signFromBalances(array $items): array
    {
        $set = [];
        foreach ($items as $it) if ($it['balance'] !== null) $set[self::cents($it['balance'])] = true;
        $out = [];
        foreach ($items as $it) {
            $a = abs((float)$it['amount']); $sign = null;
            if ($it['balance'] !== null) {
                $credit = isset($set[self::cents($it['balance'] - $a)]); $debit = isset($set[self::cents($it['balance'] + $a)]);
                if ($credit && !$debit) $sign = 1; elseif ($debit && !$credit) $sign = -1;
            }
            if ($sign === null) $sign = ((string)$it['type'] === 'income') ? 1 : -1;
            $out[] = round($sign * $a, 2);
        }
        return $out;
    }

    private function loadStatement(int $accountId, string $from, string $to): array
    {
        $s = $this->db->prepare("SELECT r.id, r.transaction_id, r.transaction_date, r.type, r.amount, r.description, r.raw_row FROM bank_import_rows r JOIN bank_import_sessions s ON s.id = r.session_id WHERE s.bank_account_id = ? AND s.status = 'imported' AND r.is_duplicate = 0 AND r.transaction_date BETWEEN ? AND ? ORDER BY r.session_id, r.id");
        $s->execute([$accountId, $from, $to]); $items = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $raw = json_decode((string)$r['raw_row'], true) ?: [];
            $amount = abs((float)($raw['amount'] ?? $r['amount'])); $type = (string)($raw['type'] ?? $r['type']);
            $items[] = ['row' => $r, 'raw' => $raw, 'amount' => $amount, 'type' => $type, 'balance' => self::rawBalance((string)($raw['raw_line'] ?? ''), $amount)];
        }
        $signed = self::signFromBalances($items); $out = [];
        foreach ($items as $i => $it) {
            $r = $it['row']; $raw = $it['raw'];
            $out[] = ['id' => (int)$r['id'], 'date' => (string)$r['transaction_date'], 'amount' => $signed[$i], 'balance' => $it['balance'], 'description' => (string)($raw['description'] ?? $r['description'] ?? ''), 'source_key' => $r['transaction_id'] === null ? null : ('bank_import:' . (int)$r['transaction_id'])];
        }
        return $out;
    }

    private function loadBooks(int $accountId, string $from, string $to, array $opts): array
    {
        $sql = "SELECT je.id, je.entry_date, je.memo, je.reversed_by_entry_id, je.source_type, je.source_id, SUM(jl.debit) - SUM(jl.credit) AS net FROM journal_entries je JOIN journal_lines jl ON jl.entry_id = je.id WHERE jl.account_id = ? AND je.status = 'posted' AND je.entry_date BETWEEN ? AND ?";
        $args = [$accountId, $from, $to];
        $ids = array_values(array_map('intval', $opts['exclude_entry_ids'] ?? []));
        if ($ids) { $sql .= ' AND je.id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; $args = array_merge($args, $ids); }
        if (!empty($opts['exclude_memo_like'])) { $sql .= ' AND je.memo NOT LIKE ?'; $args[] = (string)$opts['exclude_memo_like']; }
        $sql .= ' GROUP BY je.id, je.entry_date, je.memo, je.reversed_by_entry_id, je.source_type, je.source_id ORDER BY je.entry_date, je.id';
        $s = $this->db->prepare($sql); $s->execute($args); $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ['id' => (int)$r['id'], 'date' => (string)$r['entry_date'], 'memo' => (string)$r['memo'], 'net' => round((float)$r['net'], 2), 'reversed_by_entry_id' => $r['reversed_by_entry_id'] === null ? null : (int)$r['reversed_by_entry_id'], 'source_type' => $r['source_type'], 'source_id' => $r['source_id'] === null ? null : (int)$r['source_id'], 'source_key' => $r['source_id'] === null ? null : ((string)$r['source_type'] . ':' . (int)$r['source_id'])];
        return $out;
    }

    private static function rawBalance(string $rawLine, float $amount): ?float
    {
        $cols = str_getcsv($rawLine, ',', '"', '');
        $vals = [];
        foreach ($cols as $i => $c) {
            $v = trim((string)$c);
            if ($i > 0 && $v !== '' && preg_match('/^-?\(?[\d,]+\.\d{2}\)?-?$/', $v)) $vals[] = self::money($v);
        }
        // a statement row is [.. amount (debit or credit) .. balance]: the balance is the LAST money column, even when it equals the amount
        return count($vals) >= 2 ? end($vals) : null;
    }

    private static function cents($amount): int { return (int)round((float)$amount * 100); }
    private static function dayGap(string $a, string $b): int { return (int)abs((strtotime($a) - strtotime($b)) / 86400); }
    private static function stableId(array $item, int $fallback): int { return isset($item['id']) ? (int)$item['id'] : $fallback; }
    private static function money($value): float { $v = trim((string)$value); $negative = strpos($v, '(') !== false || substr($v, 0, 1) === '-' || substr($v, -1) === '-'; return round((float)str_replace([',', '$', '(', ')', '-'], '', $v) * ($negative ? -1 : 1), 2); }
}
