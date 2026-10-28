<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Charlie answers like a person, and the answer is built on facts worked out in code. The first real answer was a
 * wall of invoice numbers and dates, and it undercounted ($302.40 across 5 invoices for an account owing $453.60
 * across 8, because only the 8 newest invoice rows were sent). Fixture data is invented.
 */
class CharlieAnswerStyleTest extends TestCase
{
    /** The answer Charlie actually gave (names changed) — exactly what the owner called "very texty and overwhelming". */
    private const OLD_ANSWER = "No, Pat Rivera (12 Example Avenue) still owes \$302.40 across 5 invoices. Two are overdue: INV-2026-0397 for \$50.40 (due Oct 17) and INV-2026-0408 for \$50.40 (due Oct 24). The other three aren't due yet: \$50.40 due Nov 7, \$100.80 due Nov 15, and \$50.40 due Nov 21. They have paid three others, including two bigger ones (\$1,890 and \$1,853.25) on Oct 23.\n— from the CRM";

    public function testTheOldWallOfTextFailsEveryRule(): void
    {
        $bad = CharlieAnswerStyle::check(self::OLD_ANSWER);
        $this->assertContains('reads out invoice numbers', $bad);
        $this->assertContains('reads out dates', $bad);
        $this->assertContains('reads out too many amounts', $bad);
        $this->assertContains('has a source tag', $bad);
    }

    public function testTheExampleAnswersInThePromptPass(): void
    {
        preg_match_all('/^A: (.+)$/m', CharlieAnswerStyle::PROMPT, $m);
        $this->assertCount(2, $m[1]);
        foreach ($m[1] as $a) {
            $this->assertSame([], CharlieAnswerStyle::check($a), $a);
        }
    }

    public function testMarkdownListsAndLeadInsAreFlagged(): void
    {
        $this->assertContains('markdown or a list', CharlieAnswerStyle::check("Not quite.\n- two are late\n- one is not due"));
        $this->assertContains('markdown or a list', CharlieAnswerStyle::check('It is **overdue**.'));
        $this->assertContains('starts with a lead-in', CharlieAnswerStyle::check('Based on the records, Pat owes money.'));
        $this->assertContains('empty', CharlieAnswerStyle::check('  '));
    }

    public function testDetailRequestsMayBeLonger(): void
    {
        $this->assertTrue(CharlieAnswerStyle::wantsDetail('list all the invoices for Pat'));
        $this->assertTrue(CharlieAnswerStyle::wantsDetail('which invoices are overdue'));
        $this->assertFalse(CharlieAnswerStyle::wantsDetail('has Pat paid all their bills'));
        $this->assertSame([], CharlieAnswerStyle::check(self::OLD_ANSWER, true) === [] ? [] : array_diff(CharlieAnswerStyle::check(self::OLD_ANSWER, true), ['has a source tag']), 'a detail answer may be long and name invoices');
    }

    public function testNoGuessedPronounsInThePromptExamples(): void
    {
        $this->assertDoesNotMatchRegularExpression('/^A: .*\b(he|she|his|her)\b/mi', CharlieAnswerStyle::PROMPT);
    }

    // ── the request carries the story and the style ────────────────────────

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, phone TEXT, stripe_card_brand TEXT, stripe_card_last4 TEXT, stripe_payment_method_id TEXT, autopay_enabled INT DEFAULT 0)");
        $db->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT)");
        $db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, city TEXT)");
        $db->exec("CREATE TABLE quotes (id INTEGER PRIMARY KEY, quote_number TEXT, title TEXT, status TEXT, total_amount REAL, amount REAL, created_at TEXT, sent_at TEXT, viewed_at TEXT, accepted_at TEXT, valid_until TEXT, contact_id INT, company_id INT, property_id INT)");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, status TEXT, total REAL, total_amount REAL, balance_due REAL, issue_date TEXT, due_date TEXT, paid_at TEXT, payment_method TEXT,
                   contact_id INT, company_id INT, property_id INT, reminder_count INT DEFAULT 0, first_reminder_sent_at TEXT, last_reminder_sent_at TEXT, view_count INT DEFAULT 0, email_opened_at TEXT, dispute_status TEXT)");
        $db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, property_id INT, service_type TEXT)");
        $db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INT, scheduled_date TEXT, status TEXT)");
        $db->exec("CREATE TABLE charlie_asks (id INTEGER PRIMARY KEY AUTOINCREMENT, question TEXT, terms TEXT, matched TEXT, source TEXT, answer TEXT, model TEXT, input_tokens INT, output_tokens INT, cost_usd REAL, error TEXT, requested_by INT, created_at TEXT)");
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $db->prepare("INSERT INTO ops_settings VALUES (?, '30', '')")->execute([CharlieAskService::CAP_KEY]);
        $db->exec("INSERT INTO contacts VALUES (1, 'Pat', 'Rivera', 'pat@example.test', '', 'visa', '5760', 'pm_x', 0)");
        // Ten unpaid invoices: more than the 8 rows the old context could carry.
        for ($i = 1; $i <= 10; $i++) {
            $db->exec("INSERT INTO invoices (id, invoice_number, status, total, balance_due, due_date, contact_id, reminder_count) VALUES ($i, 'INV-2026-" . (1000 + $i) . "', 'overdue', 50, 50, '2026-10-" . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . "', 1, " . ($i % 4) . ")");
        }
        return $db;
    }

    public function testAskSendsTheStoryTheStyleAndDropsTheCrmTag(): void
    {
        $body = null;
        $transport = function (array $b) use (&$body) {
            $body = $b;
            return ['code' => 200, 'body' => json_encode(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Not quite. Pat owes about $500, all of it small.']], 'usage' => ['input_tokens' => 900, 'output_tokens' => 40]])];
        };
        $r = (new CharlieAskService($this->db(), $transport, '2026-10-28'))->ask('Has Pat paid all their bills', 1);

        $this->assertTrue($r['ok']);
        $this->assertStringNotContainsString('from the CRM', $r['answer']);
        $this->assertCount(2, $body['system'], 'the style rules are a second system block');
        $this->assertStringContainsString('HOW TO ANSWER', $body['system'][1]['text']);
        $this->assertSame(400, $body['max_tokens']);
        $prompt = $body['messages'][0]['content'][0]['text'];
        $this->assertStringContainsString('payment_story', $prompt);
        $this->assertStringContainsString('"total": 500', $prompt, 'the true total across all 10 invoices, not the 8 rows that fit');
        $this->assertStringContainsString('Visa ending 5760 is on file, but Pat is not enrolled in autopay', $prompt);
    }
}
