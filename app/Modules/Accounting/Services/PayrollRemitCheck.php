<?php
declare(strict_types=1);

require_once __DIR__ . '/WavePayrollImportService.php';
require_once __DIR__ . '/PayrollRemitMatch.php';

/**
 * PayrollRemitCheck — loads what PayrollRemitMatch needs (read only, no writes): the month's payroll run, Wave's debits
 * in the bank lines (including ones a booked run already moved to 2310: the description stays), and how far the bank
 * lines reach. No autoloader: require_once and `new PayrollRemitCheck($db)`.
 */
final class PayrollRemitCheck
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'payroll_runs'")->rowCount() > 0
                && $this->db->query("SHOW TABLES LIKE 'accounting_transactions'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** The assessment for the remittance due on $due. A failed read is "na", never a guess. */
    public function forDue(string $due, string $today, int $depth = 0): array
    {
        $month = PayrollRemitMatch::monthFor($due);
        if (!$this->ready()) return PayrollRemitMatch::assess(null, [], $due, $today, null, false);
        try {
            $s = $this->db->prepare("SELECT id, period_month, remittance, gross_wages, status FROM payroll_runs WHERE period_month = ? ORDER BY id DESC LIMIT 1");
            $s->execute([$month]);
            $run = $s->fetch(PDO::FETCH_ASSOC) ?: null;

            [$from, $to] = PayrollRemitMatch::window($month, $due);
            $q = $this->db->prepare("SELECT id, transaction_date, amount, description FROM accounting_transactions
                WHERE reference_type = 'bank_import' AND transaction_date BETWEEN ? AND ? AND type <> 'income'
                  AND (status IS NULL OR status NOT IN ('void','deleted'))
                ORDER BY transaction_date, id");
            $q->execute([$from, $to]);
            $debits = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!PayrollRemitMatch::isWaveDebit((string)$r['description'])) continue;
                $debits[] = ['id' => (int)$r['id'], 'date' => substr((string)$r['transaction_date'], 0, 10), 'amount' => (float)$r['amount']];
            }
            // Lines another run already took as its remittance (booked runs keep their moves) are not this month's.
            $claimed = [];
            if ($this->db->query("SHOW TABLES LIKE 'payroll_bank_moves'")->rowCount() > 0) {
                $m = $this->db->prepare("SELECT transaction_id FROM payroll_bank_moves WHERE role = 'remittance' AND undone_at IS NULL AND (run_id IS NULL OR run_id <> ?)");
                $m->execute([(int)($run['id'] ?? 0)]);
                $claimed = array_map('intval', $m->fetchAll(PDO::FETCH_COLUMN));
            }
            // ...and what the month before this one matched (an unbooked run has no moves yet).
            if ($depth === 0) {
                $prevDue = date('Y-m-d', strtotime(substr($due, 0, 7) . '-01 -1 month +' . ((int)substr($due, 8, 2) - 1) . ' days'));
                $claimed = array_merge($claimed, $this->forDue($prevDue, $today, 1)['ids']);
            }
            $covered = $this->coveredTo();
            $inUse = $run !== null || (int)$this->db->query("SELECT COUNT(*) FROM payroll_runs")->fetchColumn() > 0 || $debits !== [];
            return PayrollRemitMatch::assess($run, $debits, $due, $today, $covered ? substr((string)$covered, 0, 10) : null, $inUse, $claimed);
        } catch (Throwable $e) {
            error_log('PayrollRemitCheck: ' . $e->getMessage());
            return PayrollRemitMatch::assess(null, [], $due, $today, null, false);
        }
    }

    /**
     * How far the bank lines reach FOR THE ACCOUNT THAT CARRIES THE WAVE DEBITS: the statement end of the newest import into the
     * bank account that last showed a Wave payroll line. Without that link, the newest imported line of any account (never later
     * than the truth would allow: a missing statement of the payroll account must read "unchecked", not "unpaid").
     */
    private function coveredTo(): ?string
    {
        try {
            $acct = $this->db->query("SELECT s.bank_account_id FROM bank_import_rows r JOIN bank_import_sessions s ON s.id = r.session_id
                                      WHERE r.is_duplicate = 0 AND r.description LIKE '%PYRL%' AND s.bank_account_id IS NOT NULL
                                      ORDER BY r.transaction_date DESC, r.id DESC LIMIT 1")->fetchColumn();
            if ($acct) {
                $q = $this->db->prepare("SELECT MAX(date_to) FROM bank_import_sessions WHERE bank_account_id = ? AND status = 'imported'");
                $q->execute([(int)$acct]);
                $to = $q->fetchColumn();
                return $to ? substr((string)$to, 0, 10) : null;
            }
        } catch (Throwable $e) {
            // fall through to the all-accounts reading
        }
        $c = $this->db->query("SELECT MAX(transaction_date) FROM accounting_transactions WHERE reference_type = 'bank_import' AND (status IS NULL OR status NOT IN ('void','deleted'))")->fetchColumn();
        return $c ? substr((string)$c, 0, 10) : null;
    }
}
