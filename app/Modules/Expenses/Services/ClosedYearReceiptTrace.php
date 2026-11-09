<?php
/**
 * ClosedYearReceiptTrace — a receipt dated in a filed year is stored and traced to its bank line by itself (Tim,
 * 2026-11-06: "receipts from 2025 are meant to be stored and traced to 2025 bank lines" … "you shouldn't be doing these
 * by hand"). The closed books already carry the purchase from the bank feed, so the receipt is filed
 * (ExpenseApprovalService::fileAsBooked(): kept with its photo, nothing posted) and linked to that line
 * (BankImportService::attachExpenseMatch()). Only when exactly one unmatched bank line carries the receipt's exact
 * amount, dated in the closed year within three days of the receipt. Anything less stays waiting for a person.
 *
 * Filing takes a receipt out of the books with no one looking, so the evidence linkBank() trusts after an approval is not
 * enough here (Fable review 2026-11-08). ops_settings closed_year_autofile: 'propose' (default) asks Penny's one-tap
 * question for every certain line; 'auto' files on its own only when nothing casts doubt, and leaves a notice with Undo.
 *
 * Runs after every save of an open receipt (ExpenseGateHooks::closedYear). Never fails the save.
 * No namespace / no autoloader in production: require_once and `new`.
 */
declare(strict_types=1);

require_once __DIR__ . '/ExpenseGate.php';

final class ClosedYearReceiptTrace
{
    public const SETTING = 'closed_year_autofile';
    /** Real links: 246 of 253 sit within 3 days; a stranger at the same amount is twice as likely outside them (2026-11-08). */
    public const LINE_WINDOW_DAYS = 3;
    public const QUESTION_KIND = 'file_closed_year';

    /** @var PDO */
    private $db;
    /** @var object|null BankImportService, or a test double */
    private $bank;

    public function __construct(PDO $db, $bank = null)
    {
        $this->db = $db;
        $this->bank = $bank;
    }

    /** 'auto' only when set so; anything else asks first. Pure. */
    public static function mode($setting): string
    {
        return strtolower(trim((string)$setting)) === 'auto' ? 'auto' : 'propose';
    }

    /**
     * Pure: the one bank line this receipt is, or null — exactly one candidate at the exact amount, dated in the closed
     * year and close to the receipt. Two lines at the same amount can be two purchases at one shop: a person decides
     * (review 2026-11-06). A line in the open year is not "already in the filed books", whatever its amount.
     * @param list<array{transaction_id:int, amount:float, date:string}> $candidates
     */
    public static function pick(array $candidates, float $total, string $receiptDate): ?array
    {
        $exact = array_values(array_filter($candidates, static fn($c) => abs(abs((float)($c['amount'] ?? 0)) - round($total, 2)) < 0.005));
        if (count($exact) !== 1) return null;
        $day = substr((string)($exact[0]['date'] ?? ''), 0, 10);
        if ($day === '' || $day >= ExpenseGate::CLOSED_BEFORE) return null;
        $gap = abs(strtotime($day) - strtotime(substr($receiptDate, 0, 10))) / 86400;
        return $gap <= self::LINE_WINDOW_DAYS ? $exact[0] : null;
    }

    /** Pure: is this receipt one to trace — open, dated in a filed year, a real amount, paid from an account that has a bank line? */
    public static function applies(array $row): bool
    {
        $date = substr((string)($row['expense_date'] ?? ''), 0, 10);
        return in_array((string)($row['status'] ?? ''), ['draft', 'pending_approval'], true)
            && $date !== '' && $date < ExpenseGate::CLOSED_BEFORE
            && (float)($row['total'] ?? 0) > 0
            && !in_array((string)($row['payment_method'] ?? ''), ['owner_paid', 'cash'], true);
    }

    /**
     * Pure: why a person should look before this receipt is filed; empty = nothing casts doubt.
     * @param array{office:bool, vendor_stop_on_upload_day:bool, printed_date:?string, paper_years:list<int>} $facts
     * @return list<string> plain sentences for Penny's question
     */
    public static function doubts(string $expenseDate, array $facts): array
    {
        $out = [];
        $year = (int)substr($expenseDate, 0, 4);
        if (empty($facts['office'])) $out[] = 'A crew member took this photo, and crew receipts are usually from the same day.';
        if (!empty($facts['vendor_stop_on_upload_day'])) $out[] = 'The crew stopped at this supplier the day the photo was taken.';
        $printed = (string)($facts['printed_date'] ?? '');
        if ($printed !== '' && substr($printed, 0, 10) !== substr($expenseDate, 0, 10)) $out[] = 'The date saved differs from the date printed (' . substr($printed, 0, 10) . ').';
        foreach ((array)($facts['paper_years'] ?? []) as $y) {
            if ((int)$y !== $year) { $out[] = 'The paper also shows the year ' . (int)$y . '.'; break; }
        }
        return $out;
    }

    /**
     * File and link one receipt when its bank line is certain, or ask first.
     * @return array{action: string, transaction_id?: int}  traced | proposed | held | not_closed_year | already_linked | no_line
     */
    public function trace(int $expenseId): array
    {
        $s = $this->db->prepare("SELECT id, status, expense_date, total, payment_method, created_by, created_at, vendor_id, vendor_name_raw, raw_ocr_json FROM expenses WHERE id = ?");
        $s->execute([$expenseId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row || !self::applies($row)) return ['action' => 'not_closed_year'];

        $l = $this->db->prepare("SELECT 1 FROM accounting_transactions WHERE matched_expense_id = ? LIMIT 1");
        $l->execute([$expenseId]);
        if ($l->fetchColumn()) return ['action' => 'already_linked'];

        // Already asked: an open question waits for its answer, and a "keep waiting" or Undo is never overruled.
        $asked = $this->askedStatus($expenseId);
        if ($asked === 'open') return ['action' => 'proposed'];
        if ($asked !== null) return ['action' => 'held'];

        $date = substr((string)$row['expense_date'], 0, 10);
        $line = self::pick($this->bank()->candidateTransactionsForExpense($expenseId, 8), (float)$row['total'], $date);
        if ($line === null) return ['action' => 'no_line'];

        $doubts = self::doubts($date, $this->facts($row));
        if ($doubts || self::mode($this->setting()) === 'propose') {
            $this->ask($row, $line, 'question', $doubts);
            return ['action' => 'proposed', 'transaction_id' => (int)$line['transaction_id']];
        }

        if ($this->linkAndFile($expenseId, (int)$line['transaction_id'], 0) !== null) return ['action' => 'no_line'];
        $this->ask($row, $line, 'notice', []);
        return ['action' => 'traced', 'transaction_id' => (int)$line['transaction_id']];
    }

    /** A person said yes to Penny's question. @return array{ok: bool, message: string} */
    public function fileProposed(int $expenseId, int $lineId, int $userId): array
    {
        $why = $this->linkAndFile($expenseId, $lineId, $userId);
        return $why === null ? ['ok' => true, 'message' => 'Filed: kept with its photo and linked to its bank line, nothing booked again.']
                             : ['ok' => false, 'message' => $why];
    }

    /** Undo Penny's own filing: the line is free again and the receipt is back waiting. @return array{ok: bool, message: string} */
    public function undo(int $expenseId, int $lineId, int $userId): array
    {
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        else $this->db->exec('SAVEPOINT closed_year_undo');
        try {
            $this->bank()->detachExpenseMatch($lineId, $userId);
            (new ExpenseGate($this->db))->apply($expenseId, [
                'status' => 'pending_approval', 'approved_by' => null, 'approved_at' => null, 'rejection_reason' => null,
            ], ['id' => $userId, 'kind' => 'user'], 'file_undo', ['transition' => 'unmerge']);
            if ($own) $this->db->commit();
        } catch (Throwable $e) {
            if ($own) {
                if ($this->db->inTransaction()) $this->db->rollBack();
            } else {
                $this->db->exec('ROLLBACK TO SAVEPOINT closed_year_undo');
            }
            error_log('Closed-year undo #' . $expenseId . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => "I couldn't undo that one. Nothing changed."];
        }
        return ['ok' => true, 'message' => 'Undone: the receipt is waiting for you again and the bank line is free.'];
    }

    /** Linked and filed together or not at all: a receipt filed without its line would leave every queue unseen. @return ?string why not */
    private function linkAndFile(int $expenseId, int $lineId, int $userId): ?string
    {
        require_once __DIR__ . '/ExpenseApprovalService.php';
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        else $this->db->exec('SAVEPOINT closed_year_trace');   // inside a caller's transaction: undo only this step
        try {
            $this->bank()->attachExpenseMatch($lineId, $expenseId, $userId);
            $filed = (new ExpenseApprovalService($this->db))->fileAsBooked($expenseId, $userId > 0 ? ['id' => $userId, 'kind' => 'user'] : ['id' => 0, 'kind' => 'penny']);
            if (empty($filed['success']) || stripos((string)($filed['message'] ?? ''), 'nothing to change') !== false) {
                throw new RuntimeException('It was already dealt with.');   // someone else filed or posted it: leave it as they did
            }
            if ($own) $this->db->commit();
        } catch (Throwable $e) {
            if ($own) {
                if ($this->db->inTransaction()) $this->db->rollBack();
            } else {
                $this->db->exec('ROLLBACK TO SAVEPOINT closed_year_trace');
            }
            return $e->getMessage();
        }
        return null;
    }

    private function bank()
    {
        if ($this->bank === null) {
            require_once APP_ROOT . '/Modules/Accounting/Services/BankImportService.php';
            $this->bank = new BankImportService($this->db);
        }
        return $this->bank;
    }

    private function setting(): ?string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ? LIMIT 1");
            $s->execute([self::SETTING]);
            $v = $s->fetchColumn();
            return $v === false ? null : (string)$v;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** The open question's status for this receipt, 'answered', or null when never asked. */
    private function askedStatus(int $expenseId): ?string
    {
        try {
            $s = $this->db->prepare("SELECT status FROM penny_questions WHERE kind = ? AND expense_id = ? LIMIT 1");
            $s->execute([self::QUESTION_KIND, $expenseId]);
            $v = $s->fetchColumn();
            return $v === false ? null : (string)$v;
        } catch (Throwable $e) {
            return 'answered';   // no question table: never file on our own
        }
    }

    /** What the doubts are made of. A fact that can't be read counts against filing on its own. */
    private function facts(array $row): array
    {
        $role = '';
        try {
            $u = $this->db->prepare("SELECT role FROM users WHERE id = ?");
            $u->execute([(int)$row['created_by']]);
            $role = (string)$u->fetchColumn();
        } catch (Throwable $e) { /* unknown uploader: not office */ }
        require_once __DIR__ . '/ExpenseService.php';

        $stop = false;
        if (!empty($row['vendor_id']) && !empty($row['created_at'])) {
            try {
                $s = $this->db->prepare("SELECT 1 FROM supplier_stops WHERE vendor_id = ? AND stop_date = ? LIMIT 1");
                $s->execute([(int)$row['vendor_id'], substr((string)$row['created_at'], 0, 10)]);
                $stop = (bool)$s->fetchColumn();
            } catch (Throwable $e) { /* before migration 1359 */ }
        }

        $printed = null;
        try {
            require_once __DIR__ . '/ReceiptFactsService.php';
            $f = (new ReceiptFactsService($this->db))->forExpense((int)$row['id']);
            $d = is_array($f) ? (string)($f['printed_date'] ?? '') : '';
            $printed = preg_match('/^\d{4}-\d{2}-\d{2}/', $d) ? $d : null;
        } catch (Throwable $e) { /* no facts yet */ }

        require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
        require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
        $text = ocrTextFromStored($row['raw_ocr_json'] ?? null);

        return ['office' => ExpenseService::isOffice($role, false), 'vendor_stop_on_upload_day' => $stop,
                'printed_date' => $printed, 'paper_years' => $text !== '' ? receiptPaperYears($text) : []];
    }

    /** One question or notice per receipt, on Penny's card. */
    private function ask(array $row, array $line, string $category, array $doubts): void
    {
        $vendor = trim((string)$row['vendor_name_raw']) ?: 'A receipt';
        $money = '$' . number_format((float)$row['total'], 2);
        $receipt = $vendor . ' ' . $money . ' (' . date('M j, Y', strtotime((string)$row['expense_date'])) . ', #' . (int)$row['id'] . ')';
        $bank = date('M j, Y', strtotime((string)$line['date'])) . ' "' . trim((string)($line['description'] ?? '')) . '"';
        $text = $category === 'notice'
            ? "I filed {$receipt} against the bank line {$bank}. It is already in the closed books, so nothing was booked again."
            : "{$receipt} looks like the bank line {$bank}, already in the closed books. File it there?";
        if ($doubts) $text .= ' ' . implode(' ', $doubts);
        try {
            $this->db->prepare("INSERT INTO penny_questions (kind, subject, expense_id, vendor_name, category, amount, question) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([self::QUESTION_KIND, 'line:' . (int)$line['transaction_id'], (int)$row['id'], $vendor, $category, round((float)$row['total'], 2), mb_substr($text, 0, 490)]);
        } catch (Throwable $e) {
            error_log('Closed-year question #' . (int)$row['id'] . ': ' . $e->getMessage());
        }
    }
}
