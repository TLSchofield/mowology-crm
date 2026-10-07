<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BankLineMoveServiceTest.php';   // shared SQLite schema

/**
 * Bank rules learn only from the owner's decisions, and switch on after 2 (2026-10-07:
 * "at 50 confirmations this will take 2 years"). Imports never teach.
 */
class BankRuleLearningTest extends TestCase
{
    private const MISC = 4, WAGES = 6, CARD = 7;

    public function test_default_accounts_and_empty_keys_never_teach(): void
    {
        $this->assertFalse(BankRuleLearning::teaches('point sale shell', 69, [49, 69]), 'Miscellaneous');
        $this->assertFalse(BankRuleLearning::teaches('abc', 12, [49, 69]), 'key too short');
        $this->assertTrue(BankRuleLearning::teaches('point sale shell', 31, [49, 69]));
    }

    public function test_a_rule_acts_alone_after_two_owner_confirmations(): void
    {
        $this->assertSame(2, BankRuleLearning::CONFIRMATIONS);
        $this->assertFalse(BankRuleLearning::isTrusted(1));
        $this->assertTrue(BankRuleLearning::isTrusted(2));
    }

    public function test_the_same_line_twice_counts_once(): void
    {
        $this->assertSame(1, BankRuleLearning::nextCount(1, 100, 100));
        $this->assertSame(2, BankRuleLearning::nextCount(1, 100, 101));
        $this->assertSame(1, BankRuleLearning::nextCount(0, null, 100));
    }

    public function test_two_owner_decisions_on_two_lines_switch_the_rule_on(): void
    {
        $db = $this->db();
        $this->line($db, 1, 'WAVE PYRL 0012345');
        $this->line($db, 2, 'WAVE PYRL 0099887');
        $learner = new BankRuleLearning($db);

        $first = $learner->learnFromCorrection(1, self::WAGES, 7);
        $this->assertSame(['created', false, 1], [$first['action'], $first['active'], $first['count']]);
        $again = $learner->learnFromCorrection(1, self::WAGES, 7);   // recategorized the same line again
        $this->assertSame([false, 1], [$again['active'], $again['count']]);

        $second = $learner->learnFromCorrection(2, self::WAGES, 7);
        $this->assertSame([true, 2], [$second['active'], $second['count']]);
        $rule = $db->query("SELECT is_active, owner_confirmations, last_confirmed_tx_id FROM transaction_rules")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame([1, 2, 2], array_map('intval', array_values($rule)));
    }

    public function test_an_import_count_never_switches_a_rule_on(): void
    {
        // The wrong "Wave PYRL → 2400" rule grew to 50 from untouched imports; it has no owner decisions.
        $db = $this->db();
        $db->exec("INSERT INTO transaction_rules (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id,
                   transaction_type, is_active, source, learned_count, owner_confirmations)
                   VALUES ('Learned: WAVE PYRL', 9001, 'expense', 'description', 'contains', 'wave pyrl', 7, 'expense', 0, 'learned', 50, 0)");
        $this->line($db, 1, 'WAVE PYRL 0012345');
        $r = (new BankRuleLearning($db))->learnFromCorrection(1, self::CARD, 7);
        $this->assertSame([false, 1], [$r['active'], $r['count']], 'one owner decision, whatever learned_count says');
        $this->assertFalse(method_exists(BankImportService::class, 'learnFromCommit'), 'the import no longer teaches');
    }

    public function test_a_correction_resets_the_competing_rule(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO transaction_rules (name, priority, applies_to, condition_field, condition_operator, condition_value, account_id,
                   transaction_type, is_active, source, learned_count, owner_confirmations, last_confirmed_tx_id)
                   VALUES ('Learned: WAVE PYRL', 9001, 'expense', 'description', 'contains', 'wave pyrl', 7, 'expense', 1, 'learned', 50, 2, 9)");
        $this->line($db, 1, 'WAVE PYRL 0012345');
        (new BankRuleLearning($db))->learnFromCorrection(1, self::WAGES, 7);

        $old = $db->query("SELECT is_active, learned_count, owner_confirmations, last_confirmed_tx_id FROM transaction_rules WHERE account_id = 7")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['is_active' => 0, 'learned_count' => 0, 'owner_confirmations' => 0, 'last_confirmed_tx_id' => null],
                          array_map(fn($v) => $v === null ? null : (int)$v, $old));
        $this->assertSame(1, (int)$db->query("SELECT owner_confirmations FROM transaction_rules WHERE account_id = 6")->fetchColumn());
    }

    public function test_default_account_decisions_teach_nothing(): void
    {
        $db = $this->db();
        $this->line($db, 1, 'POINT OF SALE SHELL C01303');
        $this->assertSame('skipped', (new BankRuleLearning($db))->learnFromCorrection(1, self::MISC, 7)['action']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM transaction_rules")->fetchColumn());
    }

    public function test_before_migration_1220_nothing_switches_on(): void
    {
        $db = $this->db(false);
        $this->line($db, 1, 'WAVE PYRL 0012345');
        $this->line($db, 2, 'WAVE PYRL 0099887');
        $learner = new BankRuleLearning($db);
        $learner->learnFromCorrection(1, self::WAGES, 7);
        $this->assertFalse($learner->learnFromCorrection(2, self::WAGES, 7)['active']);
    }

    public function test_undo_switches_the_rule_off_and_counting_starts_again(): void
    {
        $db = $this->db();
        $this->line($db, 1, 'WAVE PYRL 0012345');
        $this->line($db, 2, 'WAVE PYRL 0099887');
        $learner = new BankRuleLearning($db);
        $learner->learnFromCorrection(1, self::WAGES, 7);
        $id = $learner->learnFromCorrection(2, self::WAGES, 7)['rule_id'];
        $this->assertTrue($learner->switchOff($id));
        $this->assertSame([0, 0], array_map('intval', array_values($db->query("SELECT is_active, owner_confirmations FROM transaction_rules")->fetch(PDO::FETCH_ASSOC))));
    }

    public function test_card_words(): void
    {
        $this->assertSame('Done — 1 of 2 — one more and I\'ll do these myself.',
            BankDeskService::learnedMessage(['action' => 'created', 'active' => false, 'count' => 1], 'Wave PYRL', '5100', 'Wages'));
        $this->assertSame('Done — I\'ll file Wave PYRL lines on 5100 Wages myself from now on.',
            BankDeskService::learnedMessage(['action' => 'confirmed', 'active' => true, 'count' => 2], 'Wave PYRL', '5100', 'Wages'));
        $this->assertSame('Done.', BankDeskService::learnedMessage(['action' => 'skipped', 'active' => false], 'x', '6900', 'Misc'));
    }

    public function test_description_key_is_shared_with_the_import(): void
    {
        $this->assertSame(BankImportService::descriptionKey('POINT OF SALE SHELL C12345 VANCOUVER BC'),
                          BankImportService::descriptionKey('Point of Sale - Shell C99881 Vancouver BC'));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function line(PDO $db, int $id, string $desc): void
    {
        $db->prepare("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, account_id, reference_type)
                      VALUES (?, '2026-09-18', 'expense', 500, ?, 4, 'bank_import')")->execute([$id, $desc]);
    }

    private function db(bool $migrated = true): PDO
    {
        $db = method_exists(PDO::class, 'connect') ? PDO::connect('sqlite::memory:') : new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        BankLineMoveServiceTest::schema($db);
        if (!$migrated) {
            $db->exec("DROP TABLE transaction_rules");
            $db->exec("CREATE TABLE transaction_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, priority INTEGER, applies_to TEXT, condition_field TEXT,
                       condition_operator TEXT, condition_value TEXT, account_id INTEGER, transaction_type TEXT, is_active INTEGER DEFAULT 1, source TEXT DEFAULT 'manual',
                       learned_count INTEGER DEFAULT 0, last_learned_at TEXT, created_by INTEGER, created_at TEXT)");
        }
        return $db;
    }
}
