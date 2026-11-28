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
    public const KINDS = [self::ACCOUNTING_SOFTWARE];

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
            if ($this->due($key)) $out[] = self::shape($key);
        }
        return $out;
    }

    /** Pure: the question as the apps draw it. */
    public static function shape(string $key): array
    {
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
        if ($key !== self::ACCOUNTING_SOFTWARE) return false;
        return $this->setting('onboarding_uses_qbo') === null && $this->hasInvoice();
    }

    /** @param list<string> $choices @return array{ok:bool,message:string,next?:string,error?:string} */
    public function answer(string $key, array $choices, int $userId): array
    {
        if (!in_array($key, self::KINDS, true)) return ['ok' => false, 'error' => 'unknown_question', 'message' => 'That question is not open.'];
        $shape = self::shape($key);
        $valid = array_column($shape['choices'], 'key');
        $picked = array_values(array_unique(array_map('strval', $choices)));
        if (!$picked || array_diff($picked, $valid) || (!$shape['multi'] && count($picked) !== 1)) {
            return ['ok' => false, 'error' => 'bad_choice', 'message' => 'Pick one of the choices.'];
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
        $st = $this->db->prepare('SELECT COUNT(*) FROM ops_settings WHERE setting_key = ?');
        $st->execute([$key]);
        if ((int)$st->fetchColumn() > 0) {
            $this->db->prepare('UPDATE ops_settings SET setting_value = ? WHERE setting_key = ?')->execute([$value, $key]);
        } else {
            $this->db->prepare('INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)')->execute([$key, $value, $why]);
        }
    }
}
