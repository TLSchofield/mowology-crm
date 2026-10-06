<?php
/**
 * BankRuleLearning — the one place bank-line categorization rules are learned.
 *
 * Penny's bank work, step 1 (2026-10-05). Before this, every imported line with no
 * rule became a permanent, active 'learned' rule — even lines that only carried the
 * default Miscellaneous / Other Services account nobody had looked at (119 of 221
 * learned rules pointed at Miscellaneous, e.g. "point sale shell" → Miscellaneous ×53).
 * Now:
 *   - a line on a default account (4900, 6900) never teaches;
 *   - a learned rule switches on only after CONFIRMATIONS (2) confirmations: an import
 *     committed with the line unchanged counts as one, an owner's correction as one;
 *   - a correction overrides: learned rules sending the same description elsewhere are
 *     switched off (and their count reset), so a wrong rule can't keep winning.
 * Rules are keyed on BankImportService::descriptionKey() and applied by RulesEngine as
 * before (learned rules at priority 9000+, never above manual rules).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class BankRuleLearning
{
    public const DEFAULT_ACCOUNT_CODES = ['4900', '6900'];   // Other Services, Miscellaneous
    public const CONFIRMATIONS = 2;

    private PDO $db;
    private ?array $defaultIds = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Learn that a bank line with this description belongs on this account.
     * @param string $how 'confirmed' (left as is on import) | 'corrected' (the owner changed it)
     * @return array{action: string, rule_id: ?int, active: bool}
     */
    public function learn(string $description, int $accountId, string $type, int $userId, string $how = 'confirmed'): array
    {
        $key = BankImportService::descriptionKey($description);
        if (!self::teaches($key, $accountId, $this->defaultAccountIds())) {
            return ['action' => 'skipped', 'rule_id' => null, 'active' => false];
        }
        $type = $type === 'income' ? 'income' : 'expense';

        if ($how === 'corrected') {
            // The owner said otherwise: rules sending this description elsewhere stop.
            $this->db->prepare("
                UPDATE transaction_rules SET is_active = 0, learned_count = 0, last_learned_at = NOW()
                WHERE source = 'learned' AND condition_field = 'description' AND condition_value = ? AND account_id <> ?
            ")->execute([$key, $accountId]);
        }

        $s = $this->db->prepare("SELECT id, learned_count FROM transaction_rules
                                 WHERE source = 'learned' AND condition_field = 'description' AND condition_value = ? AND account_id = ?
                                 LIMIT 1");
        $s->execute([$key, $accountId]);
        $rule = $s->fetch(PDO::FETCH_ASSOC);

        if ($rule) {
            $count = (int)$rule['learned_count'] + 1;
            $active = self::isTrusted($count);
            $this->db->prepare("UPDATE transaction_rules SET learned_count = ?, is_active = ?, last_learned_at = NOW() WHERE id = ?")
               ->execute([$count, $active ? 1 : 0, (int)$rule['id']]);
            return ['action' => 'confirmed', 'rule_id' => (int)$rule['id'], 'active' => $active];
        }

        $priority = (int)$this->db->query("SELECT COALESCE(MAX(priority), 8999) FROM transaction_rules WHERE source = 'learned'")->fetchColumn() + 1;
        $this->db->prepare("
            INSERT INTO transaction_rules
                (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id, transaction_type,
                 is_active, source, learned_count, last_learned_at, created_by, created_at)
            VALUES (?, ?, ?, 'description', 'contains', ?, ?, ?, ?, 'learned', 1, NOW(), ?, NOW())
        ")->execute(['Learned: ' . mb_substr(trim($description), 0, 80), $priority, $type, $key, $accountId, $type,
                     self::isTrusted(1) ? 1 : 0, $userId]);
        return ['action' => 'created', 'rule_id' => (int)$this->db->lastInsertId(), 'active' => self::isTrusted(1)];
    }

    /** Learn from an owner's recategorization of a transaction already in the books. */
    public function learnFromCorrection(int $transactionId, int $accountId, int $userId): array
    {
        $s = $this->db->prepare("SELECT description, type FROM accounting_transactions WHERE id = ?");
        $s->execute([$transactionId]);
        $tx = $s->fetch(PDO::FETCH_ASSOC);
        if (!$tx || trim((string)$tx['description']) === '') {
            return ['action' => 'skipped', 'rule_id' => null, 'active' => false];
        }
        return $this->learn((string)$tx['description'], $accountId, (string)$tx['type'], $userId, 'corrected');
    }

    private function defaultAccountIds(): array
    {
        if ($this->defaultIds === null) {
            $in = implode(',', array_fill(0, count(self::DEFAULT_ACCOUNT_CODES), '?'));
            $s = $this->db->prepare("SELECT id FROM chart_of_accounts WHERE code IN ({$in})");
            $s->execute(self::DEFAULT_ACCOUNT_CODES);
            $this->defaultIds = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        }
        return $this->defaultIds;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** Does this line teach anything? Needs a real key and a non-default account. */
    public static function teaches(string $key, int $accountId, array $defaultAccountIds): bool
    {
        return strlen($key) >= 4 && $accountId > 0 && !in_array($accountId, $defaultAccountIds, true);
    }

    public static function isTrusted(int $confirmations): bool
    {
        return $confirmations >= self::CONFIRMATIONS;
    }
}
