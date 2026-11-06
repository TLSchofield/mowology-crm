<?php
/**
 * ClosedYearReceiptTrace — a receipt dated in a filed year is stored and traced to its bank line by itself (Tim,
 * 2026-11-06: "receipts from 2025 are meant to be stored and traced to 2025 bank lines" … "you shouldn't be doing these
 * by hand"). The closed books already carry the purchase from the bank feed, so the receipt is filed
 * (ExpenseApprovalService::fileAsBooked(): kept with its photo, nothing posted) and linked to that line
 * (BankImportService::attachExpenseMatch()). Only when exactly one unmatched bank line carries the receipt's exact
 * amount near its date — the same certainty linkBank() uses for an approval. Anything less stays waiting for a person.
 *
 * Runs after every save of an open receipt (ExpenseGateHooks::closedYear). Never fails the save.
 * No namespace / no autoloader in production: require_once and `new`.
 */
declare(strict_types=1);

require_once __DIR__ . '/ExpenseGate.php';

final class ClosedYearReceiptTrace
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Pure: the one bank line this receipt is, or null. Exactly one candidate at the exact amount; when several share the
     * amount, one whose confidence is clearly ahead (≥ 20 points) — otherwise a person decides.
     * @param list<array{transaction_id:int, amount:float, confidence?:int|float}> $candidates
     */
    public static function pick(array $candidates, float $total): ?array
    {
        $exact = array_values(array_filter($candidates, static fn($c) => abs(abs((float)($c['amount'] ?? 0)) - round($total, 2)) < 0.005));
        if (count($exact) === 1) return $exact[0];
        if (count($exact) < 2) return null;
        usort($exact, static fn($a, $b) => (float)($b['confidence'] ?? 0) <=> (float)($a['confidence'] ?? 0));
        return (float)($exact[0]['confidence'] ?? 0) - (float)($exact[1]['confidence'] ?? 0) >= 20 ? $exact[0] : null;
    }

    /** Pure: is this receipt one to trace — open, dated in a filed year, a real amount, not paid personally? */
    public static function applies(array $row): bool
    {
        $date = substr((string)($row['expense_date'] ?? ''), 0, 10);
        return in_array((string)($row['status'] ?? ''), ['draft', 'pending_approval'], true)
            && $date !== '' && $date < ExpenseGate::CLOSED_BEFORE
            && (float)($row['total'] ?? 0) > 0
            && ($row['payment_method'] ?? '') !== 'owner_paid';
    }

    /**
     * File and link one receipt when its bank line is certain.
     * @return array{action: string, transaction_id?: int}  traced | not_closed_year | already_linked | no_line
     */
    public function trace(int $expenseId): array
    {
        $s = $this->db->prepare("SELECT id, status, expense_date, total, payment_method FROM expenses WHERE id = ?");
        $s->execute([$expenseId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row || !self::applies($row)) return ['action' => 'not_closed_year'];

        $l = $this->db->prepare("SELECT 1 FROM accounting_transactions WHERE matched_expense_id = ? LIMIT 1");
        $l->execute([$expenseId]);
        if ($l->fetchColumn()) return ['action' => 'already_linked'];

        require_once APP_ROOT . '/Modules/Accounting/Services/BankImportService.php';
        $bank = new BankImportService($this->db);
        $line = self::pick($bank->candidateTransactionsForExpense($expenseId, 8), (float)$row['total']);
        if ($line === null) return ['action' => 'no_line'];

        require_once __DIR__ . '/ExpenseApprovalService.php';
        $filed = (new ExpenseApprovalService($this->db))->fileAsBooked($expenseId, ['id' => 0, 'kind' => 'penny']);
        if (empty($filed['success'])) return ['action' => 'no_line'];
        $bank->attachExpenseMatch((int)$line['transaction_id'], $expenseId, 0);
        return ['action' => 'traced', 'transaction_id' => (int)$line['transaction_id']];
    }
}
