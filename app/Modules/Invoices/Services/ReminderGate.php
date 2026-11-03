<?php
declare(strict_types=1);

require_once __DIR__ . '/../../Clients/Services/BillToResolver.php';
require_once __DIR__ . '/../../Accounting/Services/StatementCoverageService.php';
require_once __DIR__ . '/AutomatedInvoiceInbox.php';
require_once __DIR__ . '/InvoiceReminderRules.php';

/**
 * ReminderGate — payment-certainty and payer-specific grace rules for invoice reminders (Tim, 2026-11-02).
 *
 * Why: on 2026-11-01 the cron emailed "1 days past due" to two property managers' accounts-payable inboxes
 * (Dorset, VCH); one invoice had been paid by a cheque that cleared nine days earlier. The only certainty
 * check was PaymentOnTheWay (pending e-Transfers). Rules, in order, in decide():
 *   ap_inbox          every resolved recipient is an accounts-payable inbox → no chasing email at all
 *   before_grace      first overdue reminder waits for the payer's habit (p75 lag + 3, min 7; defaults 14 company / 7 individual)
 *   deposit_candidate an unlinked income bank line equal to the balance (or total) since the issue date → ask, don't chase
 *   statement_stale   company payers only: no statement covers the days since the due date → "can't tell yet"
 * Mode (ops_settings.reminder_grace_mode): off = legacy; shadow = send but record what would be held; on = enforce.
 * The one "is this an AP inbox" rule is isApInbox(): InvoiceReminderRules::needsPdf() (ap@/invoices@/AP platforms,
 * role accounting) + AutomatedInvoiceInbox (LeapAP/Condoworks + ops_settings domains) + a local part ending in "invoice(s)".
 */
class ReminderGate
{
    private PDO $db;
    private string $today;
    private ?string $coveredThrough = null;
    private bool $coverageLoaded = false;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today ?? date('Y-m-d');
    }

    /** @return array{key:string,is_company:bool} */
    public function payerKeyFor(int $invoiceId): array
    {
        $all = $this->payerKeysFor([$invoiceId]);
        return $all[$invoiceId] ?? ['key' => 'contact:0', 'is_company' => false];
    }

    /** @return array<int,array{key:string,is_company:bool}> */
    public function payerKeysFor(array $invoiceIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $invoiceIds), static function ($id) {
            return $id > 0;
        })));
        if (!$ids) return [];

        $marks = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT i.id, i.contact_id,
                       COALESCE(i.company_id, bt_p.billing_company_id, bt_p.property_manager_id,
                                bt_p.company_id, bt_dc.company_id) AS payer_company_id
                  FROM invoices i " . BillToResolver::INVOICE_COMPANY_JOINS . "
                 WHERE i.id IN ($marks)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $invoiceId = (int)$row['id'];
            $companyId = $row['payer_company_id'] === null ? 0 : (int)$row['payer_company_id'];
            $out[$invoiceId] = $companyId > 0
                ? ['key' => 'company:' . $companyId, 'is_company' => true]
                : ['key' => 'contact:' . (int)($row['contact_id'] ?? 0), 'is_company' => false];
        }
        return $out;
    }

    /** @return array{lags:int[],p75:int,n:int}|null */
    public function habitFor(string $payerKey): ?array
    {
        $parts = explode(':', $payerKey, 2);
        $kind = $parts[0] ?? '';
        $id = (int)($parts[1] ?? 0);
        if ($id <= 0 || !in_array($kind, ['company', 'contact'], true)) return null;

        $payerExpr = "COALESCE(i.company_id, bt_p.billing_company_id, bt_p.property_manager_id,
                               bt_p.company_id, bt_dc.company_id)";
        // COALESCE has no numeric affinity in SQLite, where PDO binds execute-array values as
        // strings. Cast the ID so the unit-test query and MySQL compare the payer numerically.
        $where = $kind === 'company' ? "$payerExpr = CAST(? AS SIGNED)" : "$payerExpr IS NULL AND i.contact_id = ?";
        $stmt = $this->db->prepare(
            "SELECT i.due_date, i.paid_at, MAX(a.payment_date) AS allocation_date
               FROM invoices i " . BillToResolver::INVOICE_COMPANY_JOINS . "
               LEFT JOIN invoice_payment_allocations a ON a.invoice_id = i.id
              WHERE i.status = 'paid' AND i.balance_due <= 0.005 AND $where
              GROUP BY i.id, i.due_date, i.paid_at
              ORDER BY i.due_date DESC
              LIMIT 6"
        );
        $stmt->execute([$id]);
        $lags = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $paid = $row['allocation_date'] ?: $row['paid_at'];
            if (empty($row['due_date']) || empty($paid)) continue;
            $lags[] = max(0, $this->daysBetween((string)$row['due_date'], substr((string)$paid, 0, 10)));
        }
        if (count($lags) < 3) return null;
        return ['lags' => $lags, 'p75' => self::p75($lags), 'n' => count($lags)];
    }

    public static function p75(array $lags): int
    {
        if (!$lags) return 0;
        $values = array_map(static function ($lag) { return max(0, (int)$lag); }, $lags);
        sort($values, SORT_NUMERIC);
        $index = (int)ceil(0.75 * count($values)) - 1;
        return $values[$index];
    }

    /** @return array{days:int,basis:string} */
    public function graceDaysFor(string $payerKey, bool $isCompany): array
    {
        $habit = $this->habitFor($payerKey);
        if ($habit !== null) {
            return ['days' => max(7, $habit['p75'] + 3), 'basis' => 'habit'];
        }
        return $isCompany
            ? ['days' => 14, 'basis' => 'default_company']
            : ['days' => 7, 'basis' => 'default_individual'];
    }

    public function firstOverdueDate(string $dueDate, int $graceDays): string
    {
        return date('Y-m-d', strtotime($dueDate . ' +' . $graceDays . ' days'));
    }

    /** @param string[] $extraDomains AutomatedInvoiceInbox::extraDomains($db) when a DB is at hand */
    public static function isApInbox(string $email, string $role = '', array $extraDomains = []): bool
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) return false;
        if (AutomatedInvoiceInbox::isAutomated($email, $extraDomains)) return true;
        if (InvoiceReminderRules::needsPdf($email, $role)) return true;
        $local = substr($email, 0, $at);
        return (bool)preg_match('/invoices?$/', $local);   // vcfmoinvoices@vch.ca
    }

    public function unlinkedDepositFor(array $invoice): ?array
    {
        $invoiceId = (int)($invoice['id'] ?? 0);
        if ((!isset($invoice['issue_date']) || !isset($invoice['total'])) && $invoiceId > 0) {
            $s = $this->db->prepare("SELECT issue_date, created_at, total, balance_due FROM invoices WHERE id = ?");
            $s->execute([$invoiceId]);
            $invoice = array_merge($invoice, $s->fetch(PDO::FETCH_ASSOC) ?: []);
        }
        // invoices.issue_date is the issue column (007_job_system.sql); created_at is the fallback.
        $issueDate = substr((string)(($invoice['issue_date'] ?? '') ?: ($invoice['created_at'] ?? '')), 0, 10);
        if ($issueDate === '') return null;
        $stmt = $this->db->prepare(
            "SELECT t.id AS transaction_id, t.transaction_date, t.amount, t.description
               FROM accounting_transactions t
              WHERE t.type = 'income' AND t.reference_type = 'bank_import'
                AND t.transaction_date >= ?
                AND (ABS(t.amount - ?) <= 0.01 OR ABS(t.amount - ?) <= 0.01)
                AND t.amount - COALESCE((SELECT SUM(a.amount) FROM invoice_payment_allocations a WHERE a.transaction_id = t.id), 0) > 0.005
              ORDER BY t.transaction_date DESC, t.id DESC LIMIT 1"
        );
        $stmt->execute([$issueDate, (float)($invoice['balance_due'] ?? 0), (float)($invoice['total'] ?? 0)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function statementCoveredThrough(): ?string
    {
        if ($this->coverageLoaded) return $this->coveredThrough;
        $this->coverageLoaded = true;
        $report = (new StatementCoverageService($this->db, $this->today))->cached();
        foreach (($report['accounts'] ?? []) as $account) {
            $last = $account['last_month'] ?? [];
            if (empty($account['expected']) || !in_array($last['status'] ?? '', ['ok', 'partial', 'quiet'], true)) continue;
            $through = $last['through'] ?? null;
            if ($through && ($this->coveredThrough === null || $through > $this->coveredThrough)) {
                $this->coveredThrough = (string)$through;
            }
        }
        return $this->coveredThrough;
    }

    public function statementFreshFor(string $dueDate): bool
    {
        $through = $this->statementCoveredThrough();
        return $through !== null && $through >= $dueDate;
    }

    public function mode(): string
    {
        try {
            $stmt = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute(['reminder_grace_mode']);
            $mode = (string)($stmt->fetchColumn() ?: 'shadow');
            return in_array($mode, ['off', 'shadow', 'on'], true) ? $mode : 'shadow';
        } catch (Throwable $e) {
            return 'shadow';
        }
    }

    public function decide(array $invoice, array $recipients): array
    {
        $mode = $this->mode();
        $dueDate = (string)($invoice['due_date'] ?? '');
        $type = $dueDate > $this->today ? 'approaching' : ($dueDate === $this->today ? 'due_today' : 'overdue');
        $payer = $this->payerKeyFor((int)($invoice['id'] ?? 0));
        $habit = $this->habitFor($payer['key']);
        $grace = $habit !== null
            ? ['days' => max(7, $habit['p75'] + 3), 'basis' => 'habit']
            : ($payer['is_company'] ? ['days' => 14, 'basis' => 'default_company'] : ['days' => 7, 'basis' => 'default_individual']);
        $first = $this->firstOverdueDate($dueDate, $grace['days']);
        $extra = AutomatedInvoiceInbox::extraDomains($this->db);
        $chaseable = array_values(array_filter($recipients, static function ($recipient) use ($extra) {
            return !ReminderGate::isApInbox((string)($recipient['email_address'] ?? $recipient['email'] ?? ''),
                                            (string)($recipient['contact_role'] ?? ''), $extra);
        }));

        $reason = 'ok';
        $detail = 'Reminder may be sent';
        if ($recipients && !$chaseable) {
            $reason = 'ap_inbox';
            $detail = 'all resolved recipients are accounts-payable inboxes';
        } elseif ($type === 'approaching') {
            $reason = 'approaching';
            $detail = 'invoice is approaching its due date';
        } elseif ($type === 'due_today') {
            $reason = 'due_today';
            $detail = 'invoice is due today';
        } elseif ($this->today < $first) {
            $reason = 'before_grace';
            $detail = 'first reminder ' . $first . ' (' . $grace['basis'] . ', '
                . ($habit['n'] ?? 0) . ' paid invoices, p75 ' . ($habit['p75'] ?? 0) . ' d)';
        } elseif (($deposit = $this->unlinkedDepositFor($invoice)) !== null) {
            $reason = 'deposit_candidate';
            $detail = 'transaction ' . $deposit['transaction_id'] . ' on ' . $deposit['transaction_date']
                . ' for $' . number_format((float)$deposit['amount'], 2);
        } elseif ($payer['is_company'] && !$this->statementFreshFor($dueDate)) {
            $reason = 'statement_stale';
            $detail = 'statements cover through ' . ($this->statementCoveredThrough() ?? 'no reported date');
        }

        $allowed = in_array($reason, ['ok', 'approaching', 'due_today'], true);
        return [
            'send' => $allowed || $mode === 'off' || $mode === 'shadow',
            'reason' => $reason,
            'detail' => $detail,
            'type' => $type,
            'grace_days' => $grace['days'],
            'first_overdue_date' => $first,
            'payer_key' => $payer['key'],
            'mode' => $mode,
            'recipients' => $chaseable,
        ];
    }

    public function recordHold(array $decision, int $invoiceId): void
    {
        if (($decision['mode'] ?? '') === 'off') return;
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO invoice_reminder_holds
                    (invoice_id, reason, detail, mode, would_send_type, held_on, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE detail = VALUES(detail)"
            );
            $stmt->execute([$invoiceId, $decision['reason'], $decision['detail'], $decision['mode'], $decision['type'], $this->today]);
        } catch (Throwable $e) {
            error_log('ReminderGate hold log unavailable: ' . $e->getMessage());
        }
    }

    private function daysBetween(string $from, string $to): int
    {
        return (int)floor((strtotime($to) - strtotime($from)) / 86400);
    }
}
