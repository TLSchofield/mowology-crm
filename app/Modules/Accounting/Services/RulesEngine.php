<?php
/**
 * RulesEngine — Auto-categorization for accounting transactions.
 *
 * Evaluates transaction_rules (ordered by priority ASC) against
 * accounting_transactions that are using default/uncategorized accounts.
 *
 * When a rule matches, the transaction's account_id is updated and
 * is_auto_categorized is set to 1.
 *
 * Rules can match on:
 *  - vendor_name   (via vendors table join)
 *  - description   (transaction description text)
 *  - merchant_keyword (any text field)
 *  - amount_gt / amount_lt (numeric thresholds)
 */
declare(strict_types=1);

class RulesEngine
{
    private PDO $db;

    // These are the "default" fallback accounts — transactions on these accounts
    // that aren't manually categorized are eligible for rule application.
    private const DEFAULT_ACCOUNTS = ['4900', '6900']; // Other Services, Miscellaneous

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Apply all active rules to eligible uncategorized transactions.
     * Returns total count of transactions recategorized.
     */
    public function applyAll(): int
    {
        $rules        = $this->getActiveRules();
        $transactions = $this->getEligibleTransactions();
        $updated      = 0;

        // Index vendor names for quick lookup
        $vendorNames = $this->loadVendorNames();

        foreach ($transactions as $tx) {
            foreach ($rules as $rule) {
                // Skip rules that don't match the transaction type
                if ($rule['applies_to'] !== 'both' && $rule['applies_to'] !== $tx['type']) {
                    continue;
                }

                if ($this->ruleMatches($rule, $tx, $vendorNames)) {
                    $this->applyRule($tx['id'], (int)$rule['id'], (int)$rule['account_id']);
                    $updated++;
                    break; // First matching rule wins (priority order)
                }
            }
        }

        return $updated;
    }

    /**
     * Apply rules to a single transaction (used after manual entry or bank import).
     * Returns the matched rule id, or null if no rule matched.
     */
    public function applyToTransaction(int $transactionId): ?int
    {
        $tx = $this->db->prepare(
            "SELECT t.*, v.name AS vendor_name_lookup
             FROM accounting_transactions t
             LEFT JOIN vendors v ON v.id = t.vendor_id
             WHERE t.id = ?"
        );
        $tx->execute([$transactionId]);
        $txRow = $tx->fetch(PDO::FETCH_ASSOC);

        if (!$txRow) return null;

        $rules = $this->getActiveRules();
        foreach ($rules as $rule) {
            if ($rule['applies_to'] !== 'both' && $rule['applies_to'] !== $txRow['type']) continue;

            $vendorNames = [$txRow['vendor_id'] => $txRow['vendor_name_lookup'] ?? ''];
            if ($this->ruleMatches($rule, $txRow, $vendorNames)) {
                $this->applyRule($transactionId, (int)$rule['id'], (int)$rule['account_id']);
                return (int)$rule['id'];
            }
        }

        return null;
    }

    /**
     * Test all rules against a description + vendor_name string.
     * Used by the UI "preview categorization" feature.
     */
    public function previewMatch(string $description, string $vendorName, string $type = 'expense'): ?array
    {
        $rules = $this->getActiveRules();
        $fakeTx = [
            'id'          => 0,
            'type'        => $type,
            'description' => $description,
            'amount'      => 0,
            'vendor_id'   => 0,
        ];
        $fakeVendors = [0 => $vendorName];

        foreach ($rules as $rule) {
            if ($rule['applies_to'] !== 'both' && $rule['applies_to'] !== $type) continue;
            if ($this->ruleMatches($rule, $fakeTx, $fakeVendors)) {
                return [
                    'rule_id'      => (int)$rule['id'],
                    'rule_name'    => $rule['name'],
                    'account_id'   => (int)$rule['account_id'],
                    'account_name' => $rule['account_name'],
                    'account_code' => $rule['account_code'],
                ];
            }
        }

        return null;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // RULE MANAGEMENT
    // ══════════════════════════════════════════════════════════════════════════

    public function getActiveRules(): array
    {
        return $this->db->query("
            SELECT r.*, coa.name AS account_name, coa.code AS account_code
            FROM transaction_rules r
            JOIN chart_of_accounts coa ON coa.id = r.account_id
            WHERE r.is_active = 1
            ORDER BY r.priority ASC, r.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllRules(): array
    {
        return $this->db->query("
            SELECT r.*, coa.name AS account_name, coa.code AS account_code
            FROM transaction_rules r
            JOIN chart_of_accounts coa ON coa.id = r.account_id
            ORDER BY r.priority ASC, r.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createRule(array $data, int $userId): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO transaction_rules
                (name, priority, applies_to, condition_field, condition_operator,
                 condition_value, account_id, transaction_type, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
        ");
        $stmt->execute([
            $data['name'],
            (int)($data['priority'] ?? 10),
            $data['applies_to'] ?? 'expense',
            $data['condition_field'],
            $data['condition_operator'] ?? 'contains',
            strtolower(trim($data['condition_value'])),
            (int)$data['account_id'],
            $data['transaction_type'] ?? 'expense',
            $userId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateRule(int $id, array $data): bool
    {
        $stmt = $this->db->prepare("
            UPDATE transaction_rules
            SET name               = ?,
                priority           = ?,
                applies_to         = ?,
                condition_field    = ?,
                condition_operator = ?,
                condition_value    = ?,
                account_id         = ?,
                transaction_type   = ?,
                is_active          = ?
            WHERE id = ?
        ");
        return $stmt->execute([
            $data['name'],
            (int)($data['priority'] ?? 10),
            $data['applies_to'] ?? 'expense',
            $data['condition_field'],
            $data['condition_operator'] ?? 'contains',
            strtolower(trim($data['condition_value'])),
            (int)$data['account_id'],
            $data['transaction_type'] ?? 'expense',
            (int)($data['is_active'] ?? 1),
            $id,
        ]);
    }

    public function deleteRule(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM transaction_rules WHERE id = ?");
        return $stmt->execute([$id]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Fetch transactions using default/fallback accounts that haven't been
     * manually categorized.
     */
    private function getEligibleTransactions(): array
    {
        // Build placeholders for default account codes
        $codes       = self::DEFAULT_ACCOUNTS;
        $placeholders = implode(',', array_fill(0, count($codes), '?'));

        $stmt = $this->db->prepare("
            SELECT t.id, t.type, t.description, t.amount, t.vendor_id
            FROM accounting_transactions t
            JOIN chart_of_accounts coa ON coa.id = t.account_id
            WHERE coa.code IN ($placeholders)
              AND t.is_auto_categorized = 0
              AND t.reference_type != 'manual'
            ORDER BY t.transaction_date DESC
            LIMIT 5000
        ");
        $stmt->execute($codes);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Load all vendor names into a vendorId → name map.
     */
    private function loadVendorNames(): array
    {
        $rows = $this->db->query("SELECT id, name FROM vendors")->fetchAll(PDO::FETCH_ASSOC);
        $map  = [];
        foreach ($rows as $r) {
            $map[(int)$r['id']] = strtolower($r['name']);
        }
        return $map;
    }

    /**
     * Check if a rule matches a transaction.
     */
    private function ruleMatches(array $rule, array $tx, array $vendorNames): bool
    {
        $field    = $rule['condition_field'];
        $operator = $rule['condition_operator'];
        $value    = strtolower($rule['condition_value']);

        // Determine the haystack based on condition field
        $haystack = '';
        switch ($field) {
            case 'vendor_name':
            case 'merchant_keyword':
                $haystack = $vendorNames[(int)$tx['vendor_id']] ?? strtolower($tx['description'] ?? '');
                break;
            case 'description':
                $haystack = strtolower($tx['description'] ?? '');
                break;
            case 'amount_gt':
                return (float)$tx['amount'] > (float)$rule['condition_value'];
            case 'amount_lt':
                return (float)$tx['amount'] < (float)$rule['condition_value'];
        }

        // String matching — PHP 7.4 compatible
        switch ($operator) {
            case 'contains':
                // Empty condition_value = match all (default fallback rule)
                return $value === '' || strpos($haystack, $value) !== false;
            case 'equals':
                return $haystack === $value;
            case 'starts_with':
                return substr($haystack, 0, strlen($value)) === $value;
            case 'ends_with':
                return $value === '' || substr($haystack, -strlen($value)) === $value;
        }

        return false;
    }

    /**
     * Update a transaction's account and mark as auto-categorized.
     */
    private function applyRule(int $txId, int $ruleId, int $accountId): void
    {
        $this->db->prepare("
            UPDATE accounting_transactions
            SET account_id = ?, rule_id = ?, is_auto_categorized = 1
            WHERE id = ?
        ")->execute([$accountId, $ruleId, $txId]);

        // Increment rule match stats
        $this->db->prepare("
            UPDATE transaction_rules
            SET match_count = match_count + 1, last_matched_at = NOW()
            WHERE id = ?
        ")->execute([$ruleId]);
    }
}
