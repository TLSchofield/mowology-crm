<?php
/**
 * OwnerQuestionService — "Penny asks you a question" (Tim, 2026-11-28).
 *
 * A generic kind for the Penny decision queue: a question with a title, a line of body text, a few choices,
 * an optional multi-select, and ONE answer action. Each question is a definition below (key, when it is due,
 * what answering does). A new question is one entry in definitions(); the apps render the shape unchanged:
 *
 *   {key, title, body, multi, choices: [{key, label}], answer: {mode: 'question_answer', key}}
 *
 * Answered by POST bookkeeper-mobile {mode:'question_answer', key, choices:[choiceKey, ...]} (admins) →
 * {ok, message, next?: 'connect_quickbooks'}. Nothing is stored beyond the setting the question owns.
 * No financial advice in the copy; no promise about syncing.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class OwnerQuestionService
{
    public const ACCOUNTING_SOFTWARE = 'accounting_software';
    public const PAYMENT_METHODS = 'payment_methods';
    public const PAYMENT_USUAL = 'payment_usual';
    public const COMPANY_DETAILS = 'company_details';
    public const KINDS = [self::ACCOUNTING_SOFTWARE, self::PAYMENT_METHODS, self::PAYMENT_USUAL, self::COMPANY_DETAILS];

    /** The choices for "how do you pay", in the order shown (codes from EXPENSE_PAYMENT_METHODS). */
    private const PAYMENT_LABELS = [
        'company_card' => 'Business card', 'credit_card' => 'Credit card', 'debit' => 'Debit', 'cash' => 'Cash',
        'etransfer' => 'e-Transfer', 'cheque' => 'Cheque', 'owner_paid' => 'I paid it myself',
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return list<array> the questions due now for the owner, in order. Read only. */
    public function open(): array
    {
        $out = [];
        foreach (self::KINDS as $key) {
            if ($this->due($key)) $out[] = self::shape($key, $this->context());
        }
        return $out;
    }

    /** Pure: the question as the apps draw it. */
    public static function shape(string $key, array $ctx = []): array
    {
        if ($key === self::COMPANY_DETAILS) {
            // Form-style kind: no choices; the app draws form.rows (value, source, edit) and form.fields, and sends
            // {mode:'question_answer', key, values:{fieldKey: text, …}} with only what changed. Button: confirm_label.
            return [
                'key' => $key,
                'title' => 'Check your company details',
                'body' => 'Penny uses these on your invoices and for your accountant. Change anything that is not right.',
                'multi' => false,
                'choices' => [],
                'form' => $ctx['company'] ?? ['rows' => [], 'fields' => []],
                'confirm_label' => 'Looks right',
                'answer' => ['mode' => 'question_answer', 'key' => $key, 'values' => true],
            ];
        }
        if ($key === self::PAYMENT_METHODS) {
            $choices = [];
            foreach (self::PAYMENT_LABELS as $k => $label) $choices[] = ['key' => $k, 'label' => $label];
            return [
                'key' => $key,
                'title' => 'How do you pay for the business?',
                'body' => 'Pick every way you pay. Penny uses this to fill in how a receipt was paid and to ask fewer questions.',
                'multi' => true,
                'choices' => $choices,
                'answer' => ['mode' => 'question_answer', 'key' => $key],
            ];
        }
        if ($key === self::PAYMENT_USUAL) {
            $choices = [];
            foreach ((array)($ctx['used'] ?? []) as $k) {
                if (isset(self::PAYMENT_LABELS[$k])) $choices[] = ['key' => $k, 'label' => self::PAYMENT_LABELS[$k]];
            }
            return [
                'key' => $key,
                'title' => 'Which one do you use most?',
                'body' => 'Penny fills this in when a receipt does not say how it was paid. You can always change it on a receipt.',
                'multi' => false,
                'choices' => $choices,
                'answer' => ['mode' => 'question_answer', 'key' => $key],
            ];
        }
        if ($key === self::ACCOUNTING_SOFTWARE) {
            return [
                'key' => $key,
                'title' => 'Which accounting software do you use?',
                'body' => 'Penny keeps your books in Ittai. You can connect QuickBooks later.',
                'multi' => false,
                'choices' => [['key' => 'ittai', 'label' => 'I want to use Ittai'], ['key' => 'quickbooks', 'label' => 'QuickBooks']],
                'answer' => ['mode' => 'question_answer', 'key' => $key],
            ];
        }
        return [];
    }

    /** Due when nothing is answered yet and the first invoice exists. */
    public function due(string $key): bool
    {
        if ($key === self::PAYMENT_METHODS) {
            return $this->paymentHabits()['used'] === [] && $this->hasFiledReceipt();
        }
        if ($key === self::PAYMENT_USUAL) {
            $h = $this->paymentHabits();
            return count($h['used']) > 1 && $h['usual'] === null;
        }
        if ($key === self::COMPANY_DETAILS) {
            // After the 3rd filed receipt; backstop: the first invoice exists. GST report and accountant export ask checked() too.
            require_once dirname(__DIR__, 2) . '/Settings/Services/CompanyDetailsService.php';
            $c = new CompanyDetailsService($this->db);
            return !$c->checked() && ($c->filedReceipts() >= 3 || $this->hasInvoice());
        }
        if ($key !== self::ACCOUNTING_SOFTWARE) return false;
        return $this->setting('onboarding_uses_qbo') === null && $this->hasInvoice();
    }

    /** @param list<string> $choices @return array{ok:bool,message:string,next?:string,error?:string} */
    public function answer(string $key, array $choices, int $userId, array $values = []): array
    {
        if (!in_array($key, self::KINDS, true) || !$this->due($key)) return ['ok' => false, 'error' => 'unknown_question', 'message' => 'That question is not open.'];
        if ($key === self::COMPANY_DETAILS) {
            require_once dirname(__DIR__, 2) . '/Settings/Services/CompanyDetailsService.php';
            return (new CompanyDetailsService($this->db))->confirm($values, $userId);
        }
        $shape = self::shape($key, $this->context());
        $valid = array_column($shape['choices'], 'key');
        $picked = array_values(array_unique(array_map('strval', $choices)));
        if (!$picked || array_diff($picked, $valid) || (!$shape['multi'] && count($picked) !== 1)) {
            return ['ok' => false, 'error' => 'bad_choice', 'message' => 'Pick one of the choices.'];
        }
        if ($key === self::PAYMENT_METHODS) {
            ReceiptPaymentHabits::saveUsed($this->db, $picked);
            return ['ok' => true, 'message' => 'Thanks. Penny will use this.'];
        }
        if ($key === self::PAYMENT_USUAL) {
            ReceiptPaymentHabits::saveUsual($this->db, $picked[0]);
            return ['ok' => true, 'message' => 'Thanks. Penny will use this.'];
        }
        if ($key === self::ACCOUNTING_SOFTWARE) {
            $uses = $picked[0] === 'quickbooks';
            $this->save('onboarding_uses_qbo', $uses ? 'yes' : 'no', 'Onboarding: does this business use QuickBooks (OwnerQuestionService)');
            return $uses
                ? ['ok' => true, 'message' => 'Next, connect QuickBooks.', 'next' => 'connect_quickbooks']
                : ['ok' => true, 'message' => 'Penny keeps your books in Ittai.'];
        }
        return ['ok' => false, 'error' => 'unknown_question', 'message' => 'That question is not open.'];
    }

    /** What a question's shape may depend on. */
    private function context(): array
    {
        $ctx = ['used' => $this->paymentHabits()['used']];
        if ($this->due(self::COMPANY_DETAILS)) {
            require_once dirname(__DIR__, 2) . '/Settings/Services/CompanyDetailsService.php';
            $ctx['company'] = (new CompanyDetailsService($this->db))->form();
        }
        return $ctx;
    }

    private function paymentHabits(): array
    {
        require_once __DIR__ . '/ReceiptPaymentHabits.php';
        return ReceiptPaymentHabits::load($this->db);
    }

    /** "Filed" = approved or forwarded (ExpenseGate::POSTED). */
    private function hasFiledReceipt(): bool
    {
        try {
            return $this->db->query("SELECT 1 FROM expenses WHERE status IN ('approved', 'forwarded') LIMIT 1")->fetchColumn() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function hasInvoice(): bool
    {
        try {
            return $this->db->query('SELECT 1 FROM invoices LIMIT 1')->fetchColumn() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function setting(string $key): ?string
    {
        try {
            $st = $this->db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ?');
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return in_array($v, ['yes', 'no'], true) ? (string)$v : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function save(string $key, string $value, string $why): void
    {
        $u = $this->db->prepare('UPDATE ops_settings SET setting_value = ? WHERE setting_key = ?');
        $u->execute([$value, $key]);
        if ($u->rowCount() === 0) {
            $st = $this->db->prepare('SELECT COUNT(*) FROM ops_settings WHERE setting_key = ?');
            $st->execute([$key]);
            if ((int)$st->fetchColumn() === 0) {
                $this->db->prepare('INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)')->execute([$key, $value, $why]);
            }
        }
    }
}
