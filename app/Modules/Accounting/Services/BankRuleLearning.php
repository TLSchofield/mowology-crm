<?php
/**
 * BankRuleLearning — the one place bank-line categorization rules are learned.
 *
 * Penny's bank work, step 1 (2026-10-05); owner-only, 2 confirmations (2026-10-07).
 * Before 2026-10-05 every imported line with no rule became a permanent, active
 * 'learned' rule — even lines on the default Miscellaneous / Other Services account
 * nobody had looked at. Then a rule needed 50 confirmations, and an import committed
 * with the line untouched counted as one: wrong rules grew from lines nobody checked
 * (Wave PYRL → 2400 Credit Card Payable, TD loan → 2400), and right ones would have
 * taken two years. Now (migration 1220):
 *   - only the OWNER's decisions count: Approve / correct on Penny's bank card, "also
 *     put the other N lines on …" (bulk apply), recategorize on the transactions screen.
 *     An import never teaches;
 *   - a line on a default account (4900, 6900) never teaches;
 *   - a learned rule switches on at CONFIRMATIONS (2) owner confirmations, each from a
 *     different bank line (the same line recategorized twice counts once);
 *   - every decision overrides: learned rules sending the same description elsewhere
 *     are switched off and their counts reset, so a wrong rule can't keep winning.
 * Rules are keyed on BankImportService::descriptionKey() and applied by RulesEngine as
 * before (learned rules at priority 9000+, never above manual rules).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class BankRuleLearning
{
    public const DEFAULT_ACCOUNT_CODES = ['4900', '6900'];   // Other Services, Miscellaneous
    /** Earned autonomy: two owner decisions, on two different lines, before a rule acts alone. */
    public const CONFIRMATIONS = 2;

    private PDO $db;
    private ?array $defaultIds = null;
    private ?bool $ownerColumns = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * The owner put a bank line with this description on this account: count it.
     * @param int|null $transactionId the bank line decided (the same line twice counts once)
     * @return array{action: string, rule_id: ?int, active: bool, count?: int}
     */
    public function learn(string $description, int $accountId, string $type, int $userId, ?int $transactionId = null): array
    {
        $key = BankImportService::descriptionKey($description);
        if (!self::teaches($key, $accountId, $this->defaultAccountIds())) {
            return ['action' => 'skipped', 'rule_id' => null, 'active' => false];
        }
        $type = $type === 'income' ? 'income' : 'expense';
        $owner = $this->hasOwnerColumns();

        // The owner said this description goes HERE: learned rules sending it elsewhere stop.
        $this->db->prepare("
            UPDATE transaction_rules SET is_active = 0, learned_count = 0" . ($owner ? ', owner_confirmations = 0, last_confirmed_tx_id = NULL' : '') . ", last_learned_at = NOW()
            WHERE source = 'learned' AND condition_field = 'description' AND condition_value = ? AND account_id <> ?
        ")->execute([$key, $accountId]);

        $s = $this->db->prepare("SELECT id, learned_count" . ($owner ? ', owner_confirmations, last_confirmed_tx_id' : '') . " FROM transaction_rules
                                 WHERE source = 'learned' AND condition_field = 'description' AND condition_value = ? AND account_id = ?
                                 LIMIT 1");
        $s->execute([$key, $accountId]);
        $rule = $s->fetch(PDO::FETCH_ASSOC);

        if ($rule) {
            if (!$owner) {
                // Before migration 1220 nothing switches on: the old count mixed in import confirmations.
                $this->db->prepare("UPDATE transaction_rules SET learned_count = learned_count + 1, last_learned_at = NOW() WHERE id = ?")
                   ->execute([(int)$rule['id']]);
                return ['action' => 'confirmed', 'rule_id' => (int)$rule['id'], 'active' => false, 'count' => 1];
            }
            $before = (int)$rule['owner_confirmations'];
            $last = $rule['last_confirmed_tx_id'] !== null ? (int)$rule['last_confirmed_tx_id'] : null;
            $count = self::nextCount($before, $last, $transactionId);
            $active = self::isTrusted($count);
            $this->db->prepare("UPDATE transaction_rules SET learned_count = learned_count + ?, owner_confirmations = ?, last_confirmed_tx_id = ?,
                                       is_active = ?, last_learned_at = NOW() WHERE id = ?")
               ->execute([$count > $before ? 1 : 0, $count, $transactionId ?? $last, $active ? 1 : 0, (int)$rule['id']]);
            return ['action' => 'confirmed', 'rule_id' => (int)$rule['id'], 'active' => $active, 'count' => $count];
        }

        $priority = (int)$this->db->query("SELECT COALESCE(MAX(priority), 8999) FROM transaction_rules WHERE source = 'learned'")->fetchColumn() + 1;
        $active = $owner && self::isTrusted(1);
        $cols = 'name, priority, applies_to, condition_field, condition_operator, condition_value, account_id, transaction_type,
                 is_active, source, learned_count, last_learned_at, created_by, created_at' . ($owner ? ', owner_confirmations, last_confirmed_tx_id' : '');
        $vals = "?, ?, ?, 'description', 'contains', ?, ?, ?, ?, 'learned', 1, NOW(), ?, NOW()" . ($owner ? ', 1, ?' : '');
        $args = ['Learned: ' . mb_substr(trim($description), 0, 80), $priority, $type, $key, $accountId, $type, $active ? 1 : 0, $userId];
        if ($owner) $args[] = $transactionId;
        $this->db->prepare("INSERT INTO transaction_rules ({$cols}) VALUES ({$vals})")->execute($args);
        return ['action' => 'created', 'rule_id' => (int)$this->db->lastInsertId(), 'active' => $active, 'count' => 1];
    }

    /** The owner put a transaction already in the books on this account (card approve, bulk apply, recategorize). */
    public function learnFromCorrection(int $transactionId, int $accountId, int $userId): array
    {
        $s = $this->db->prepare("SELECT description, type FROM accounting_transactions WHERE id = ?");
        $s->execute([$transactionId]);
        $tx = $s->fetch(PDO::FETCH_ASSOC);
        if (!$tx || trim((string)$tx['description']) === '') {
            return ['action' => 'skipped', 'rule_id' => null, 'active' => false];
        }
        return $this->learn((string)$tx['description'], $accountId, (string)$tx['type'], $userId, $transactionId);
    }

    /** "Undo" on the card: the rule stops acting alone and starts counting again. */
    public function switchOff(int $ruleId): bool
    {
        $s = $this->db->prepare("UPDATE transaction_rules SET is_active = 0" . ($this->hasOwnerColumns() ? ', owner_confirmations = 0, last_confirmed_tx_id = NULL' : '') . "
                                 WHERE id = ? AND source = 'learned'");
        $s->execute([$ruleId]);
        return $s->rowCount() > 0;
    }

    /** Has migration 1220 run? */
    public function hasOwnerColumns(): bool
    {
        if ($this->ownerColumns === null) {
            try {
                $this->db->query("SELECT owner_confirmations, last_confirmed_tx_id FROM transaction_rules LIMIT 0");
                $this->ownerColumns = true;
            } catch (Throwable $e) {
                $this->ownerColumns = false;
            }
        }
        return $this->ownerColumns;
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

    /** The owner count after one more decision: the same bank line again doesn't count twice. */
    public static function nextCount(int $count, ?int $lastTxId, ?int $txId): int
    {
        return ($txId !== null && $lastTxId !== null && $txId === $lastTxId) ? $count : $count + 1;
    }
}
