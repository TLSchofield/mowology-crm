<?php
/**
 * ExpensePersonalService — the "Personal" button (owner, 2026-10-19: "there should be a button that
 * says personal that automatically posts the expense to shareholder"; Tim by default, Katrenna by choice).
 *
 * One click: the receipt becomes category Personal, charged to the chosen shareholder — 1300 Due from
 * Shareholder (Tim) or that shareholder's own account (1302 Katrenna), from payroll_shareholders
 * (WavePayrollImportService::shareholders, the one list of who the shareholders are). The books then
 * carry the WHOLE receipt on that account and claim no GST back (LedgerService::isShareholderAccount);
 * the GST return leaves it out (GstReportService::itcExpenses). A split by line is cleared: the whole
 * receipt is personal.
 *
 * Every change goes through ExpenseGate (audit row, a posted receipt is reversed and posted again,
 * a receipt sent to accounting is refused). Approving stays its own click in the modal.
 * Global-namespace service: require_once the file.
 */
require_once __DIR__ . '/ExpenseGate.php';
require_once dirname(__DIR__, 2) . '/Accounting/Services/LedgerService.php';
require_once dirname(__DIR__, 2) . '/Accounting/Services/WavePayrollImportService.php';

class ExpensePersonalService
{
    public const CATEGORY = 'Personal';

    private PDO $db;
    private ExpenseGate $gate;

    public function __construct(PDO $db, ?ExpenseGate $gate = null)
    {
        $this->db = $db;
        $this->gate = $gate ?? new ExpenseGate($db);
    }

    /** The shareholders a receipt can be charged to: [{name, account}], 1300 first. */
    public function shareholders(): array
    {
        try {
            $list = (new WavePayrollImportService($this->db))->shareholders();
        } catch (Throwable $e) {
            $list = [];
        }
        return self::choices($list);
    }

    /** [name => code] → [{name, account}] limited to shareholder accounts, 1300 first. PURE. */
    public static function choices(array $byName): array
    {
        $out = [];
        foreach ($byName as $name => $code) {
            $code = trim((string)$code);
            if (!LedgerService::isShareholderAccount($code)) continue;
            $out[] = ['name' => (string)$name, 'account' => $code];
        }
        usort($out, static fn($a, $b) => strcmp($a['account'], $b['account']));
        if (!$out) $out[] = ['name' => 'Shareholder', 'account' => LedgerService::ACC_DUE_FROM_SH];
        return $out;
    }

    /**
     * The changes that make a receipt personal to $account. NULL account = the Personal category's own
     * (1300). An account that isn't one of the shareholders' is refused. PURE.
     */
    public static function changesFor(?string $account, array $choices): array
    {
        $account = $account === null ? '' : trim($account);
        if ($account !== '' && !in_array($account, array_column($choices, 'account'), true)) {
            throw new InvalidArgumentException("That isn't a shareholder's account");
        }
        return [
            'accounting_category' => self::CATEGORY,
            // 1300 is what the Personal category already posts to: stored as NULL so a later change to
            // that mapping still applies; any other shareholder's account is stored.
            'shareholder_account' => ($account === '' || $account === LedgerService::ACC_DUE_FROM_SH) ? null : $account,
            'allocations'         => [],
        ];
    }

    /** @return array the gate's result + shareholder {name, account} */
    public function mark(int $expenseId, ?string $account, array $actor, string $source = 'desktop_personal'): array
    {
        if ($expenseId <= 0) throw new InvalidArgumentException('Expense ID required');   // never a new receipt
        $choices = $this->shareholders();
        $changes = self::changesFor($account, $choices);
        $res = $this->gate->apply($expenseId, $changes, $actor, $source);
        $code = $changes['shareholder_account'] ?? LedgerService::ACC_DUE_FROM_SH;
        $who = null;
        foreach ($choices as $c) if ($c['account'] === $code) { $who = $c; break; }
        return $res + ['shareholder' => $who ?? ['name' => 'Shareholder', 'account' => $code]];
    }
}
