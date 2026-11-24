<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Expenses/ExpenseGateTestKit.php';
require_once __DIR__ . '/../Accounting/BankLineMoveServiceTest.php';

/**
 * head_lessons (migration 1348, plan 2026-10-13 Part A1): one learning record for every head.
 * The pure helpers, the reads A2 will use, and that each wired override path writes one row
 * (and that a lessons failure never blocks the person's action). Fixture data is invented.
 */
class HeadLessonsTest extends TestCase
{
    /** The table as migration 1348 makes it (SQLite). */
    public static function schema(PDO $db): void
    {
        $db->exec("CREATE TABLE head_lessons (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, head TEXT NOT NULL,
                   subject_kind TEXT NOT NULL, subject_key TEXT NOT NULL, field TEXT NOT NULL, said TEXT, correct TEXT, source TEXT NOT NULL,
                   ref_type TEXT, ref_id INT, corrected_by INT, reason TEXT, created_at TEXT NOT NULL)");
    }

    public static function rows(PDO $db, ?string $source = null): array
    {
        $sql = "SELECT head, subject_kind, subject_key, field, said, correct, source, ref_type, ref_id, corrected_by FROM head_lessons";
        if ($source !== null) {
            $s = $db->prepare($sql . " WHERE source = ? ORDER BY id");
            $s->execute([$source]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        }
        return $db->query($sql . " ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        self::schema($db);
        return $db;
    }

    // ── Pure ────────────────────────────────────────────────────────────

    public function test_keys_and_values_are_normalised(): void
    {
        $this->assertSame('acme supply co', HeadLessons::normaliseKey("  ACME \t Supply   Co "));
        $this->assertSame(120, strlen(HeadLessons::normaliseKey(str_repeat('a', 300))));
        $this->assertNull(HeadLessons::valueText(null));
        $this->assertNull(HeadLessons::valueText('   '));
        $this->assertSame('yes', HeadLessons::valueText(true));
        $this->assertSame('12.5', HeadLessons::valueText(12.5));
        $this->assertSame('{"job_id":7}', HeadLessons::valueText(['job_id' => 7]));
    }

    public function test_a_change_that_changes_nothing_is_not_a_lesson(): void
    {
        $this->assertTrue(HeadLessons::isNoChange('Fuel', ' Fuel '));
        $this->assertTrue(HeadLessons::isNoChange(12.5, '12.50'));
        $this->assertTrue(HeadLessons::isNoChange(null, ''));
        $this->assertFalse(HeadLessons::isNoChange(null, 'Materials'));
        $this->assertFalse(HeadLessons::isNoChange('credit_card', 'debit'));
        $base = ['head' => 'penny', 'subject_kind' => 'vendor', 'subject_key' => 'Acme', 'field' => 'payment_method', 'source' => 't'];
        $this->assertNull(HeadLessons::row($base + ['said' => 'debit', 'correct' => 'debit']));
        $this->assertNull(HeadLessons::row(['subject_key' => ''] + $base + ['said' => 'a', 'correct' => 'b']), 'no subject');
        $this->assertNull(HeadLessons::row(['head' => ''] + $base + ['said' => 'a', 'correct' => 'b']), 'no head');
        $r = HeadLessons::row($base + ['said' => 'credit_card', 'correct' => 'debit', 'corrected_by' => 0, 'ref_id' => '42'], 3);
        $this->assertSame(['penny', 'acme', 3, null, 42], [$r['head'], $r['subject_key'], $r['site_id'], $r['corrected_by'], $r['ref_id']]);
    }

    public function test_tally_counts_agreement_with_the_latest_answer(): void
    {
        $this->assertSame(['value' => null, 'count' => 0, 'contradictions' => 0, 'total' => 0], HeadLessons::tally([]));
        $this->assertSame(['value' => '5200', 'count' => 3, 'contradictions' => 0, 'total' => 3], HeadLessons::tally(['5200', '5200', '5200']));
        $this->assertSame(['value' => '6100', 'count' => 1, 'contradictions' => 2, 'total' => 3], HeadLessons::tally(['5200', '5200', '6100']));
    }

    // ── Store and read ──────────────────────────────────────────────────

    public function test_record_and_read_back(): void
    {
        $db = $this->db();
        $hl = new HeadLessons($db);
        $l = ['head' => 'penny', 'subject_kind' => 'bank_wording', 'subject_key' => 'Point of sale ACME FUEL', 'field' => 'account',
              'said' => '6900 Miscellaneous', 'correct' => '6100 Fuel', 'source' => 'bank_desk', 'ref_type' => 'bank_line', 'ref_id' => 5, 'corrected_by' => 9];
        $this->assertNotNull($hl->record($l));
        $hl->record(['said' => '6900 Miscellaneous', 'ref_id' => 6] + $l);
        $this->assertNull($hl->record(['said' => '6100 Fuel'] + $l), 'said == correct');
        $hl->record(['head' => 'sam', 'source' => 'x'] + $l);

        $this->assertCount(2, $hl->forSubject('penny', 'bank_wording', 'point of sale acme fuel'));
        $this->assertCount(2, $hl->forSubject('PENNY', 'bank_wording', '  Point of sale  ACME FUEL', 'account'));
        $this->assertCount(0, $hl->forSubject('penny', 'bank_wording', 'point of sale acme fuel', 'payer'));
        $this->assertCount(2, $hl->recentFor('penny', 1));
        $this->assertSame(['value' => '6100 Fuel', 'count' => 2, 'contradictions' => 0, 'total' => 2],
                          $hl->countConsistent('penny', 'bank_wording', 'point of sale acme fuel', 'account'));
        $this->assertCount(0, (new HeadLessons($db, 2))->forSubject('penny', 'bank_wording', 'point of sale acme fuel'), 'another tenant sees none');
    }

    public function test_a_missing_table_never_blocks_the_caller(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $prev = ini_set('error_log', '/dev/null');
        try {
            $this->assertNull(HeadLessons::recordQuietly($db, ['head' => 'penny', 'subject_kind' => 'vendor', 'subject_key' => 'x',
                                                               'field' => 'f', 'said' => 'a', 'correct' => 'b', 'source' => 's']));
        } finally {
            ini_set('error_log', (string)$prev);
        }
    }

    // ── Each override path writes one row ───────────────────────────────

    private function receiptDb(): PDO
    {
        $db = ExpenseGateTestDb::make();
        self::schema($db);
        $db->exec("INSERT INTO vendors (id, name) VALUES (70, 'ACME FAKE SUPPLY')");
        $db->exec("INSERT INTO expenses (id, expense_date, vendor_id, vendor_name_raw, amount, gst_amount, total, accounting_category, payment_method, status, created_by)
                   VALUES (900, '2026-09-10', 70, 'ACME FAKE SUPPLY', 100, 5, 105, 'Materials', 'credit_card', 'pending_approval', 6)");
        return $db;
    }

    public function test_a_person_changing_a_receipt_writes_one_lesson_per_field(): void
    {
        $db = $this->receiptDb();
        $gate = new ExpenseGate($db);
        $gate->apply(900, ['accounting_category' => 'Fuel', 'payment_method' => 'debit', 'notes' => 'gas can'], ['id' => 9, 'kind' => 'user'], 'desktop');

        $rows = self::rows($db);
        $this->assertCount(2, $rows, 'notes are not an override of Penny');
        $this->assertSame(['vendor', 'acme fake supply', 'accounting_category', 'Materials', 'Fuel', 'expense_gate:desktop', 'expense', 900, 9],
            [$rows[0]['subject_kind'], $rows[0]['subject_key'], $rows[0]['field'], $rows[0]['said'], $rows[0]['correct'], $rows[0]['source'],
             $rows[0]['ref_type'], (int)$rows[0]['ref_id'], (int)$rows[0]['corrected_by']]);
        $this->assertSame(['payment_method', 'credit_card', 'debit'], [$rows[1]['field'], $rows[1]['said'], $rows[1]['correct']]);
    }

    public function test_penny_and_her_card_do_not_record_through_the_gate(): void
    {
        $db = $this->receiptDb();
        $gate = new ExpenseGate($db);
        $gate->apply(900, ['accounting_category' => 'Fuel'], ['id' => null, 'kind' => 'penny'], 'bank_desk');
        $gate->apply(900, ['accounting_category' => 'Meals'], ['id' => 9, 'kind' => 'user'], 'penny_card');
        $this->assertSame([], self::rows($db), "Penny's own change is not a lesson; her card records against her suggestion");
    }

    public function test_a_lessons_failure_never_blocks_the_receipt_change(): void
    {
        $db = ExpenseGateTestDb::make();      // no head_lessons table
        $db->exec("INSERT INTO expenses (id, expense_date, vendor_name_raw, total, accounting_category, status) VALUES (901, '2026-09-10', 'X', 10, 'Materials', 'draft')");
        $prev = ini_set('error_log', '/dev/null');
        try {
            $r = (new ExpenseGate($db))->apply(901, ['accounting_category' => 'Fuel'], ['id' => 9, 'kind' => 'user'], 'desktop');
        } finally {
            ini_set('error_log', (string)$prev);
        }
        $this->assertSame(['accounting_category'], $r['changed']);
        $this->assertSame('Fuel', $db->query("SELECT accounting_category FROM expenses WHERE id = 901")->fetchColumn());
    }

    public function test_answers_to_pennys_questions_are_lessons(): void
    {
        $db = $this->receiptDb();
        $db->exec("CREATE TABLE penny_questions (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL DEFAULT 'unbilled_materials', subject TEXT,
                   expense_id INT, plan_id INT, property_id INT, contract_id INT, vendor_id INT, vendor_name TEXT, category TEXT,
                   amount REAL NOT NULL DEFAULT 0, question TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'open', answer TEXT, answered_by INT,
                   answered_at TEXT, created_at TEXT)");
        $db->exec("INSERT INTO penny_questions (id, kind, expense_id, vendor_name, amount, question) VALUES
                   (1, 'unbilled_materials', 900, 'ACME FAKE SUPPLY', 105, 'Bill it?'), (2, 'unbilled_materials', 900, 'ACME FAKE SUPPLY', 105, 'Bill it?')");
        $pq = new PennyQuestionService($db);
        $this->assertTrue($pq->answer(1, 'contract', 9)['ok']);
        $this->assertTrue($pq->answer(2, 'invoice', 9)['ok']);
        $rows = self::rows($db);
        $this->assertCount(1, $rows, '"invoice it" means Penny was right — not a lesson');
        $this->assertSame(['billable', 'not billed yet', 'contract', 'penny_question:unbilled_materials'],
                          [$rows[0]['field'], $rows[0]['said'], $rows[0]['correct'], $rows[0]['source']]);
    }

    private function mover(PDO $db): BankLineMoveService
    {
        return new BankLineMoveService($db, new class($db) extends LedgerService {
            public function canRepostSource(): bool { return true; }
        });
    }

    public function test_moving_a_bank_line_is_a_lesson_unless_penny_proposed_it(): void
    {
        $db = method_exists(PDO::class, 'connect') ? PDO::connect('sqlite::memory:') : new PDO('sqlite::memory:');   // Pdo\Sqlite on PHP 8.4+
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        BankLineMoveServiceTest::schema($db);
        self::schema($db);
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, account_id, reference_type, bank_account_id) VALUES
                   (10, '2026-09-18', 'expense', 80, 'Point of sale ACME FAKE FUEL 004411', 4, 'bank_import', 1),
                   (11, '2026-09-19', 'expense', 60, 'Point of sale ACME FAKE FUEL 004412', 4, 'bank_import', 1)");
        $this->assertTrue($this->mover($db)->move(10, 10, 9)['ok']);
        $this->assertTrue($this->mover($db)->move(11, 10, 9, 'penny')['ok']);
        $rows = self::rows($db);
        $this->assertCount(1, $rows);
        $this->assertSame(['bank_wording', 'point sale acme fake fuel', 'account', '6900 Miscellaneous', '6100 Fuel', 'bank_line_move'],
            [$rows[0]['subject_kind'], $rows[0]['subject_key'], $rows[0]['field'], $rows[0]['said'], $rows[0]['correct'], $rows[0]['source']]);
    }
}
