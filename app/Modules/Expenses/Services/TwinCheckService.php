<?php
declare(strict_types=1);

/** Turns TwinFinder's read-only findings into questions. It never changes the books. */
require_once __DIR__ . '/TwinFinder.php';          // no autoloader in production: the service loads what it uses (2026-11-03)
require_once __DIR__ . '/RepeatAmountFinder.php';

class TwinCheckService
{
    public const KIND_TWIN = 'possible_twin';
    public const KIND_BAD_LINK = 'bad_link';
    public const KIND_REPEAT = 'repeat_amount';   // same vendor + total 4–90 days apart (RepeatAmountFinder, 2026-11-03)
    public const SINCE = '2026-01-01';
    public const CACHE_KEY = 'penny_twin_check_last';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT subject FROM penny_questions LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function run(bool $dryRun = false): array
    {
        $receipts = $this->receipts();
        $byId = [];
        foreach ($receipts as &$receipt) {
            $receipt['id'] = (int)$receipt['id'];
            $receipt['line_id'] = $receipt['line_id'] === null ? null : (int)$receipt['line_id'];
            $byId[$receipt['id']] = $receipt;
        }
        unset($receipt);
        $facts = $this->facts();
        $lines = $this->lines();
        $dismissals = $this->dismissals();
        $finds = TwinFinder::find($receipts, $facts, $lines, $dismissals);
        $repeats = RepeatAmountFinder::find($receipts, $dismissals);
        $rows = self::uniqueRows(array_merge(self::rows($finds, $byId), self::repeatRows($repeats)));
        $plan = self::plan($this->existing(), $rows);
        if (!$dryRun) $this->apply($plan);

        $openRows = [];
        $existing = $this->existing();
        $personAnswered = [];
        foreach ($existing as $row) {
            if (($row['status'] ?? '') === 'answered' && ($row['answer'] ?? '') !== 'resolved') {
                $personAnswered[$row['kind'] . ':' . (int)$row['expense_id']] = true;
            }
        }
        foreach ($rows as $row) {
            if (!isset($personAnswered[$row['kind'] . ':' . $row['expense_id']])) $openRows[] = $row;
        }
        $dollars = 0.0;
        foreach ($openRows as $row) if ($row['kind'] === self::KIND_TWIN) $dollars += (float)$row['amount'];
        return [
            'finds' => count($finds), 'repeats' => count($repeats), 'new' => count($plan['insert']), 'updated' => count($plan['update']),
            'reopened' => count($plan['reopen']), 'closed' => count($plan['close']),
            'open' => count($openRows), 'dollars' => round($dollars, 2),
        ];
    }

    public function refreshDaily(bool $dryRun = false): array
    {
        $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
        $s->execute([self::CACHE_KEY]);
        if ((string)$s->fetchColumn() === date('Y-m-d')) return ['ran' => false];
        $result = $this->run($dryRun);
        if (!$dryRun) {
            $s = $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, 'Penny possible-twin check last run')
                                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $s->execute([self::CACHE_KEY, date('Y-m-d')]);
        }
        return ['ran' => true] + $result;
    }

    private function receipts(): array
    {
        $s = $this->db->prepare("SELECT e.id, e.expense_date, e.status, e.total, COALESCE(v.name, e.vendor_name_raw) AS vendor,
                (SELECT t.id FROM accounting_transactions t WHERE t.matched_expense_id = e.id AND t.reference_type = 'bank_import'
                 AND (t.status IS NULL OR t.status <> 'void') LIMIT 1) AS line_id
            FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id
            WHERE e.expense_date >= ? AND e.status IN ('approved','forwarded','rejected')");
        $s->execute([self::SINCE]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    private function facts(): array
    {
        try {
            $rows = $this->db->query("SELECT expense_id, printed_date, time_first, time_last, doc_number FROM receipt_facts")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
        $out = [];
        foreach ($rows as $row) $out[(int)$row['expense_id']] = $row;
        return $out;
    }

    private function lines(): array
    {
        $s = $this->db->prepare("SELECT id, transaction_date, amount, matched_expense_id FROM accounting_transactions
            WHERE reference_type='bank_import' AND transaction_date >= DATE_SUB(?, INTERVAL 12 DAY)
              AND (status IS NULL OR status <> 'void')");
        $s->execute([self::SINCE]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['matched_expense_id'] = $row['matched_expense_id'] === null ? null : (int)$row['matched_expense_id'];
        }
        unset($row);
        return $rows;
    }

    private function dismissals(): array
    {
        try {
            $rows = $this->db->query("SELECT expense_a, expense_b, dismissed_by FROM expense_duplicate_dismissals")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
        foreach ($rows as &$row) { $row['expense_a'] = (int)$row['expense_a']; $row['expense_b'] = (int)$row['expense_b']; }
        unset($row);
        return $rows;
    }

    private function existing(): array
    {
        return $this->db->query("SELECT id, kind, expense_id, status, answer, amount, question FROM penny_questions
            WHERE kind IN ('possible_twin','bad_link','repeat_amount')")->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function rows(array $finds, array $receiptsById): array
    {
        $rows = [];
        foreach ($finds as $find) {
            if (($find['kind'] ?? '') === 'twin') {
                $copy = $find['copy_id'] ?? null;
                $expenseId = $copy !== null ? (int)$copy : (int)$find['keep_id'];
                $other = (int)$find['pair_ids'][0] === $expenseId ? (int)$find['pair_ids'][1] : (int)$find['pair_ids'][0];
                $subject = $copy !== null ? 'keep:' . (int)$find['keep_id'] : 'with:' . $other;
                $kind = self::KIND_TWIN;
                $category = (string)$find['grade'];
                $vendor = (string)($receiptsById[$expenseId]['vendor'] ?? $find['vendor'] ?? '');
            } else {
                $expenseId = (int)$find['wrong_id'];
                $subject = 'line:' . (int)$find['line_id'] . ';right:' . (int)($find['right_id'] ?? 0);
                $kind = self::KIND_BAD_LINK;
                $category = 'bad_link';
                $vendor = (string)($receiptsById[$expenseId]['vendor'] ?? '');
            }
            $rows[] = ['kind' => $kind, 'expense_id' => $expenseId, 'amount' => (float)$find['amount'],
                'vendor_name' => $vendor, 'category' => $category, 'subject' => $subject,
                'question' => self::wording($find, $receiptsById)];
        }
        return $rows;
    }

    /** RepeatAmountFinder findings as question rows: the NEWER receipt carries the question, subject names the older. */
    public static function repeatRows(array $repeats): array
    {
        $rows = [];
        foreach ($repeats as $find) {
            $rows[] = ['kind' => self::KIND_REPEAT, 'expense_id' => (int)$find['newer_id'], 'amount' => (float)$find['amount'],
                'vendor_name' => (string)$find['vendor'], 'category' => 'repeat', 'subject' => 'with:' . (int)$find['older_id'],
                'question' => RepeatAmountFinder::wording($find)];
        }
        return $rows;
    }

    public static function wording(array $find, array $receiptsById): string
    {
        if (($find['kind'] ?? '') === 'bad_link') {
            $wrong = $receiptsById[(int)$find['wrong_id']] ?? [];
            $which = ($wrong['status'] ?? '') === 'rejected' ? 'which was rejected' : 'which reads $' . number_format((float)($wrong['total'] ?? 0), 2);
            $text = 'The bank line of $' . number_format((float)$find['amount'], 2) . ' on ' . date('j M', strtotime((string)$find['date']))
                . ' is linked to #' . (int)$find['wrong_id'] . ', ' . $which . '.';
            if (!empty($find['right_id'])) $text .= ' #' . (int)$find['right_id'] . ' looks like the right receipt.';
            return $text;
        }
        $id = $find['copy_id'] !== null ? (int)$find['copy_id'] : (int)$find['keep_id'];
        $receipt = $receiptsById[$id] ?? [];
        $text = 'Is #' . $id . ' (' . (string)($receipt['vendor'] ?? $find['vendor'] ?? 'Unknown') . ' $'
            . number_format((float)$find['amount'], 2) . ', ' . date('j M', strtotime((string)($receipt['expense_date'] ?? $find['date'])))
            . ') the same purchase as #' . ($find['copy_id'] !== null ? (int)$find['keep_id'] : (int)($find['pair_ids'][0] === $id ? $find['pair_ids'][1] : $find['pair_ids'][0])) . '? ';
        if (($find['grade'] ?? '') === 'D') {
            $e = (string)($find['evidence'][0] ?? '0 receipts vs 0 bank line(s)');
            preg_match('/(\d+) receipts vs (\d+) bank line/', $e, $m);
            $evidence = 'No usable printed time or number to compare; ' . (int)($m[1] ?? 0) . ' receipts but ' . (int)($m[2] ?? 0) . ' bank line(s)';
        } else {
            $parts = [];
            foreach ($find['evidence'] ?? [] as $e) {
                if (strpos($e, 'same time ') === 0) $parts[] = 'Same printed time ' . substr($e, 10);
                elseif (strpos($e, 'same number ') === 0) $parts[] = 'same number ' . substr($e, 12);
                else $parts[] = ucfirst($e);
            }
            $evidence = ucfirst(implode(', ', $parts));
        }
        $evidence .= ($find['state'] ?? '') === 'copy_unlinked' ? ', and the bank shows one payment' : '; neither receipt is linked to a bank line yet';
        return $text . $evidence . '. If it is, the books count it twice.';
    }

    public static function plan(array $existing, array $rows): array
    {
        $plan = ['insert' => [], 'update' => [], 'reopen' => [], 'close' => []];
        $old = [];
        foreach ($existing as $row) $old[$row['kind'] . ':' . (int)$row['expense_id']] = $row;
        $seen = [];
        foreach ($rows as $row) {
            $key = $row['kind'] . ':' . (int)$row['expense_id'];
            // TwinFinder can return more than one pair anchored on the same receipt.
            // penny_questions is unique by kind + expense_id, so keep its first (best)
            // finding and never schedule conflicting writes for the same question.
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            if (!isset($old[$key])) { $plan['insert'][] = $row; continue; }
            $before = $old[$key];
            if (($before['status'] ?? '') === 'open') {
                if (abs((float)$before['amount'] - (float)$row['amount']) > 0.004 || (string)$before['question'] !== (string)$row['question']) {
                    $row['id'] = (int)$before['id']; $plan['update'][] = $row;
                }
            } elseif (($before['answer'] ?? '') === 'resolved') {
                $row['id'] = (int)$before['id']; $plan['reopen'][] = $row;
            }
        }
        foreach ($existing as $row) {
            $key = $row['kind'] . ':' . (int)$row['expense_id'];
            if (($row['status'] ?? '') === 'open' && !isset($seen[$key])) $plan['close'][] = $row;
        }
        return $plan;
    }

    /** Keep run totals and writes aligned with penny_questions' unique key. */
    private static function uniqueRows(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $key = $row['kind'] . ':' . (int)$row['expense_id'];
            if (!isset($unique[$key])) $unique[$key] = $row;
        }
        return array_values($unique);
    }

    private function apply(array $plan): void
    {
        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare("INSERT INTO penny_questions
                (kind, expense_id, amount, vendor_name, category, subject, question, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'open')");
            foreach ($plan['insert'] as $row) $insert->execute([$row['kind'], $row['expense_id'], $row['amount'], $row['vendor_name'], $row['category'], $row['subject'], $row['question']]);
            $update = $this->db->prepare("UPDATE penny_questions SET amount=?, vendor_name=?, category=?, subject=?, question=? WHERE id=?");
            foreach ($plan['update'] as $row) $update->execute([$row['amount'], $row['vendor_name'], $row['category'], $row['subject'], $row['question'], $row['id']]);
            $reopen = $this->db->prepare("UPDATE penny_questions SET amount=?, vendor_name=?, category=?, subject=?, question=?, status='open', answer=NULL WHERE id=?");
            foreach ($plan['reopen'] as $row) $reopen->execute([$row['amount'], $row['vendor_name'], $row['category'], $row['subject'], $row['question'], $row['id']]);
            $close = $this->db->prepare("UPDATE penny_questions SET status='answered', answer='resolved' WHERE id=?");
            foreach ($plan['close'] as $row) $close->execute([(int)$row['id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
