<?php
declare(strict_types=1);

/**
 * AccountantQuestionService — the questions that wait for the accountant, kept in the CRM (Tim, 2026-11-10).
 *
 * Penny adds one when a month is off the bank by an amount she cannot match to any line (the flow line's "Add to list"
 * press); the Ledger overview shows the open ones to the owner. NOTHING is sent: the batch email to the accountant
 * (Sit Lim) stays the owner's own yes, as when Tim held everything to Ricole on 2026-10-13.
 *
 * The question's words are written HERE from the books (MonthCloseService's drift, and for the first month the filed
 * FY2025 cash against the Dec 31 statements), never taken from the browser: the press only names the month.
 * One row per gap (qkey 'drift:YYYY-MM'): adding twice, or after the owner dropped it, does nothing.
 *
 * No namespace / no autoloader in production: require_once and `new`. Guarded: does nothing until migration 1451 ran.
 */
class AccountantQuestionService
{
    public const CENT = 0.005;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'accountant_questions'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function keyFor(string $month): string
    {
        return 'drift:' . $month;
    }

    /** Is there a row for this gap, in any state? (A dropped one is not asked again.) */
    public function has(string $key): bool
    {
        if (!$this->ready()) return true;   // before the migration the job is simply not offered
        $s = $this->db->prepare("SELECT 1 FROM accountant_questions WHERE qkey = ?");
        $s->execute([$key]);
        return (bool)$s->fetchColumn();
    }

    /** @return list<array> the open questions, oldest first. [] before the migration. */
    public function open(): array
    {
        if (!$this->ready()) return [];
        return $this->db->query("SELECT id, qkey, kind, month, account, amount, question, evidence, created_at FROM accountant_questions
                                 WHERE status = 'open' ORDER BY created_at, id")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{ok:bool, message:string, already?:bool} */
    public function add(string $key, string $month, string $account, float $amount, string $question, ?string $evidence, int $userId): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Needs migration 1451'];
        $s = $this->db->prepare("INSERT IGNORE INTO accountant_questions (qkey, kind, month, account, amount, question, evidence, status, created_by, created_at)
                                 VALUES (?, 'drift', ?, ?, ?, ?, ?, 'open', ?, NOW())");
        $s->execute([$key, $month, $account, round($amount, 2), $question, $evidence, $userId]);
        if ($s->rowCount() === 0) return ['ok' => true, 'already' => true, 'message' => 'Already on the accountant\'s list.'];
        return ['ok' => true, 'message' => 'Added to the accountant\'s list — nothing is sent.'];
    }

    /** "Leave it": the gap is recorded as not to be asked about (kept, so it is not offered again). */
    public function drop(string $key, string $month, string $account, float $amount, int $userId): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Needs migration 1451'];
        $s = $this->db->prepare("INSERT IGNORE INTO accountant_questions (qkey, kind, month, account, amount, question, evidence, status, created_by, created_at, resolved_at)
                                 VALUES (?, 'drift', ?, ?, ?, 'Left alone by the owner.', NULL, 'dropped', ?, NOW(), NOW())");
        $s->execute([$key, $month, $account, round($amount, 2), $userId]);
        return ['ok' => true, 'message' => 'Left alone — I won\'t ask again for this gap.'];
    }

    /**
     * The question for one month's gap, from the books. Null when the month is not off the bank (or is locked / unknown),
     * so a stale press cannot invent a question. Reads MonthCloseService; for the first month adds the opening-cash evidence.
     * @return array{month:string, account:string, amount:float, question:string, evidence:?string}|null
     */
    public function draftFor(string $ym, ?array $months = null): ?array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) return null;
        require_once __DIR__ . '/MonthCloseService.php';
        $month = null;
        // $months: a summary the caller already holds (the flow line's cached one); the press itself always reads fresh
        foreach ($months ?? (new MonthCloseService($this->db))->months() as $m) if ($m['month'] === $ym) $month = $m;
        if ($month === null || !empty($month['locked']) || !empty($month['ready'])) return null;
        $account = ''; $amount = 0.0;
        foreach ((array)$month['drift'] as $acct => $d) {
            if (abs((float)$d) > abs($amount)) { $account = (string)$acct; $amount = (float)$d; }
        }
        if (abs($amount) < self::CENT) return null;
        $opening = null;
        if ($ym === MonthCloseService::FIRST) {
            try {
                require_once __DIR__ . '/LedgerService.php';
                require_once __DIR__ . '/Fy2026OpeningService.php';
                $st = (new Fy2026OpeningService($this->db, new LedgerService($this->db)))->statementBalances();
                $opening = self::openingEvidence((float)Fy2026OpeningService::FILED_2025['cash'], $st);
            } catch (Throwable $e) {
                error_log('AccountantQuestionService::draftFor opening: ' . $e->getMessage());
            }
        }
        return ['month' => $ym, 'account' => $account, 'amount' => round($amount, 2)] + self::draft($ym, $account, $amount, $opening);
    }

    /**
     * Pure: filed cash against the Dec 31 statements of the bank accounts.
     * @param array $statements code => balance at Dec 31 (Fy2026OpeningService::statementBalances())
     * @return array{filed:float, statements:float, gap:float}|null null when a bank statement balance is unknown
     */
    public static function openingEvidence(float $filed, array $statements): ?array
    {
        $sum = 0.0;
        foreach (['1010', '1020', '1025'] as $code) {
            if (!isset($statements[$code]) || $statements[$code] === null) return null;
            $sum += (float)$statements[$code];
        }
        return ['filed' => round($filed, 2), 'statements' => round($sum, 2), 'gap' => round($filed - $sum, 2)];
    }

    /**
     * Pure: the words of the question and its evidence. $opening: openingEvidence() for the first month, else null.
     * @return array{question:string, evidence:?string, evidence_short:?string}
     */
    public static function draft(string $ym, string $account, float $amount, ?array $opening): array
    {
        $money = static fn(float $v): string => '$' . number_format(abs($v), 2);
        $when = date('F Y', strtotime($ym . '-01'));
        $q = $when . ': the books are ' . $money($amount) . ($amount > 0 ? ' above' : ' below') . ' the bank for ' . $account
           . ' at month end, and I cannot match the difference to any bank line or receipt.';
        $evidence = null; $short = null;
        if ($opening !== null && abs((float)$opening['gap']) >= self::CENT) {
            $evidence = 'Filed FY2025 cash is ' . $money((float)$opening['filed']) . '; the Dec 31 statements add up to '
                      . $money((float)$opening['statements']) . ' (' . $money((float)$opening['gap']) . ($opening['gap'] > 0 ? ' less' : ' more') . ').';
            $short = 'Filed ' . $money((float)$opening['filed']) . ' · statements ' . $money((float)$opening['statements']);
            $q .= ' ' . $evidence . ' May we post a Jan 1 correcting entry (cash '
                . ($opening['gap'] > 0 ? '−' : '+') . $money((float)$opening['gap']) . ' against retained earnings)?';
        } else {
            $q .= ' How should we clear it?';
        }
        return ['question' => $q, 'evidence' => $evidence, 'evidence_short' => $short];
    }
}
