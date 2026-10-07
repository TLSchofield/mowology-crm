<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny's on-demand guidance on a bank line: the prompt carries Tim's rules and note,
 * answers are checked against the chart (an invented account is rejected), one payee is
 * one cache key whatever its reference number, and the daily cap stops calls. The HTTP
 * call is always a fake transport — the real API is never called here.
 */
class BankGuidanceServiceTest extends TestCase
{
    private const TLNK = 'Point of sale TLNK/0534703381';

    private function chart(): array
    {
        return [
            '2610' => ['id' => 26, 'code' => '2610', 'name' => 'Loan Payable — RAM', 'type' => 'liability'],
            '6100' => ['id' => 61, 'code' => '6100', 'name' => 'Fuel & Vehicle', 'type' => 'expense'],
            '6810' => ['id' => 68, 'code' => '6810', 'name' => 'Interest Expense', 'type' => 'expense'],
            '6850' => ['id' => 85, 'code' => '6850', 'name' => 'Meals & Entertainment', 'type' => 'expense'],
            '6900' => ['id' => 69, 'code' => '6900', 'name' => 'Miscellaneous', 'type' => 'expense'],
        ];
    }

    private function answer(array $over = []): array
    {
        return $over + [
            'merchant' => 'TransLink',
            'what_it_is' => 'TLNK is TransLink (bus/SkyTrain).',
            'account_code' => '6100',
            'confidence' => 0.85,
            'reason' => '$3.50 is one adult fare, probably getting to a job without the truck.',
            'gst' => ['chargeable' => false, 'note' => 'Transit fares are GST-exempt'],
            'split' => [],
            'question_if_unsure' => '',
        ];
    }

    // ── prompt ──────────────────────────────────────────────────────────────

    public function test_prompt_includes_the_line_rules_chart_and_note(): void
    {
        $tx = ['transaction_date' => '2026-09-30', 'type' => 'expense', 'amount' => 3.50, 'description' => self::TLNK];
        $p = BankGuidanceService::buildPrompt($tx, $this->chart(), BankGuidanceService::RULES, [], ['bank_lines' => [], 'receipts' => []],
                                              'bus when the truck broke down');
        $this->assertStringContainsString(self::TLNK, $p);
        $this->assertStringContainsString('3.5', $p);
        $this->assertStringContainsString('money out', $p);
        $this->assertStringContainsString('"bus when the truck broke down"', $p);
        $this->assertStringContainsString('Diesel is for the Dodge Ram', $p);
        $this->assertStringContainsString('Gas (petrol) under $50', $p);
        $this->assertStringContainsString('TD ON-LINE LOANS', $p);
        $this->assertStringContainsString('2610 Loan Payable — RAM', $p);
        $this->assertStringContainsString('6810', $p);
        $this->assertStringContainsString('6850', $p);
        $this->assertStringContainsString('50%', $p);
        $this->assertStringContainsString('6100 | Fuel & Vehicle | expense', $p);
    }

    public function test_prompt_without_a_note_says_none_and_carries_past_decisions(): void
    {
        $tx = ['transaction_date' => '2026-09-30', 'type' => 'expense', 'amount' => 3.50, 'description' => self::TLNK];
        $p = BankGuidanceService::buildPrompt($tx, $this->chart(), BankGuidanceService::RULES,
            [['date' => '2026-08-02', 'amount' => 3.15, 'description' => 'Point of sale TLNK/0411', 'filed_to' => '6100 Fuel & Vehicle']],
            ['bank_lines' => [['description' => 'KAL TIRE', 'amount' => '-412.00']], 'receipts' => []], '', 'TransLink');
        $this->assertStringContainsString("Tim's note: (none)", $p);
        $this->assertStringContainsString('6100 Fuel & Vehicle', $p);
        $this->assertStringContainsString('KAL TIRE', $p);
        $this->assertStringContainsString('"payee_known_as": "TransLink"', $p);
    }

    public function test_request_uses_sonnet_and_a_strict_schema(): void
    {
        $r = BankGuidanceService::buildRequest('x');
        $this->assertSame('claude-sonnet-5-5', $r['model']);
        $schema = $r['output_config']['format']['schema'];
        $this->assertSame('json_schema', $r['output_config']['format']['type']);
        $this->assertFalse($schema['additionalProperties']);
        foreach (['merchant', 'what_it_is', 'account_code', 'confidence', 'reason', 'gst', 'split', 'question_if_unsure'] as $k) {
            $this->assertContains($k, $schema['required']);
        }
    }

    // ── validation ──────────────────────────────────────────────────────────

    public function test_a_good_answer_validates_and_reads_in_pennys_voice(): void
    {
        $v = BankGuidanceService::validate($this->answer(), $this->chart(), 3.50);
        $this->assertTrue($v['ok'], implode('; ', $v['errors']));
        $say = BankGuidanceService::say($v['guidance'], $this->chart());
        $this->assertSame('TLNK is TransLink (bus/SkyTrain). $3.50 is one adult fare, probably getting to a job without the truck. '
            . 'I\'d file it to 6100 Fuel & Vehicle. No GST to claim: transit fares are GST-exempt.', $say);
    }

    public function test_an_invented_account_code_is_rejected(): void
    {
        $v = BankGuidanceService::validate($this->answer(['account_code' => '6155']), $this->chart(), 3.50);
        $this->assertFalse($v['ok']);
        $this->assertStringContainsString('6155 is not in the chart', $v['errors'][0]);
    }

    public function test_a_default_account_or_empty_code_is_rejected(): void
    {
        $this->assertFalse(BankGuidanceService::validate($this->answer(['account_code' => '6900']), $this->chart(), 3.50)['ok']);
        $this->assertFalse(BankGuidanceService::validate($this->answer(['account_code' => '']), $this->chart(), 3.50)['ok']);
        $this->assertFalse(BankGuidanceService::validate('nonsense', $this->chart(), 3.50)['ok']);
    }

    public function test_a_split_must_use_real_codes_and_add_up(): void
    {
        $loan = $this->answer(['account_code' => '2610', 'split' => [
            ['account_code' => '2610', 'amount' => 812.40, 'why' => 'principal'],
            ['account_code' => '6810', 'amount' => 187.60, 'why' => 'interest'],
        ]]);
        $this->assertTrue(BankGuidanceService::validate($loan, $this->chart(), -1000.00)['ok']);

        $bad = $loan;
        $bad['split'][1]['account_code'] = '6899';
        $this->assertFalse(BankGuidanceService::validate($bad, $this->chart(), 1000.00)['ok']);

        $short = $loan;
        $short['split'][1]['amount'] = 100.00;
        $v = BankGuidanceService::validate($short, $this->chart(), 1000.00);
        $this->assertFalse($v['ok']);
        $this->assertStringContainsString('adds up to 912.40', $v['errors'][0]);
    }

    public function test_confidence_is_clamped_and_unsure_answers_ask(): void
    {
        $v = BankGuidanceService::validate($this->answer(['confidence' => 7, 'question_if_unsure' => '']), $this->chart(), 3.5);
        $this->assertSame(1.0, $v['guidance']['confidence']);
        $v = BankGuidanceService::validate($this->answer(['confidence' => 0.3, 'question_if_unsure' => 'Was this for a job?']), $this->chart(), 3.5);
        $this->assertStringEndsWith('I\'m not sure, though — was this for a job?', BankGuidanceService::say($v['guidance'], $this->chart()));
    }

    // ── cache key ───────────────────────────────────────────────────────────

    public function test_one_payee_is_one_cache_key_whatever_the_reference(): void
    {
        $a = BankGuidanceService::payeeKey('Point of sale TLNK/0534703381');
        $b = BankGuidanceService::payeeKey('Point of sale TLNK/0598812234');
        $this->assertSame($a, $b);
        $this->assertStringContainsString('tlnk', $a);
        $this->assertNotSame($a, BankGuidanceService::payeeKey('Point of sale SHELL C12345'));
    }

    // ── cap ─────────────────────────────────────────────────────────────────

    public function test_cap_logic(): void
    {
        $this->assertSame(30, BankGuidanceService::capLeft(30, 0));
        $this->assertSame(1, BankGuidanceService::capLeft(30, 29));
        $this->assertSame(0, BankGuidanceService::capLeft(30, 30));
        $this->assertSame(0, BankGuidanceService::capLeft(30, 45));
        $this->assertTrue(BankGuidanceService::canCall(30, 29));
        $this->assertFalse(BankGuidanceService::canCall(30, 30));
        $this->assertFalse(BankGuidanceService::canCall(0, 0));
    }

    public function test_cost_uses_sonnet_prices(): void
    {
        $this->assertEqualsWithDelta(0.0105, BankGuidanceService::cost(2000, 300), 1e-9);   // 2000×$3/M + 300×$15/M
    }

    // ── the flow, on SQLite with a fake transport ──────────────────────────

    private function db(int $cap = 30): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, code TEXT, name TEXT, type TEXT, is_active INTEGER DEFAULT 1)");
        foreach ($this->chart() as $a) {
            $db->prepare("INSERT INTO chart_of_accounts (id, code, name, type) VALUES (?, ?, ?, ?)")->execute([$a['id'], $a['code'], $a['name'], $a['type']]);
        }
        $db->exec("CREATE TABLE accounting_transactions (id INTEGER PRIMARY KEY, transaction_date TEXT, type TEXT, amount REAL, description TEXT,
                   account_id INTEGER, reference_type TEXT)");
        $db->exec("INSERT INTO accounting_transactions VALUES (1, '2026-09-30', 'expense', 3.50, 'Point of sale TLNK/0534703381', 69, 'bank_import'),
                   (2, '2026-10-02', 'expense', 3.50, 'Point of sale TLNK/0598812234', 69, 'bank_import'),
                   (3, '2026-10-02', 'expense', 9.10, 'Point of sale BLENZ/0598812299', 69, 'bank_import')");
        $db->exec("CREATE TABLE bank_line_reviews (id INTEGER PRIMARY KEY, transaction_id INTEGER UNIQUE, final_account_id INTEGER,
                   decided_at TEXT, guidance_json TEXT)");
        $db->exec("CREATE TABLE bank_guidance (id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INTEGER, payee_key TEXT, source TEXT, note TEXT,
                   guidance_json TEXT, model TEXT, input_tokens INTEGER, output_tokens INTEGER, cost_usd REAL, error TEXT, requested_by INTEGER, created_at TEXT)");
        $db->exec("CREATE TABLE bank_payee_names (payee_key TEXT PRIMARY KEY, display_name TEXT, taught_by INTEGER, updated_at TEXT)");
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $db->prepare("INSERT INTO ops_settings VALUES (?, ?, '')")->execute([BankGuidanceService::CAP_KEY, (string)$cap]);
        return $db;
    }

    /** A fake API that answers $answer and counts its calls. */
    private function transport(array $answer, int &$calls, ?array &$lastBody = null): callable
    {
        return function (array $body) use ($answer, &$calls, &$lastBody) {
            $calls++;
            $lastBody = $body;
            return ['code' => 200, 'body' => json_encode([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => json_encode($answer)]],
                'usage' => ['input_tokens' => 2000, 'output_tokens' => 300],
            ])];
        };
    }

    public function test_ask_then_reuse_for_free_from_earlier(): void
    {
        $db = $this->db();
        $calls = 0;
        $body = null;
        $svc = new BankGuidanceService($db, $this->transport($this->answer(), $calls, $body), date('Y-m-d'));

        $r = $svc->guide(1, 'bus when the truck broke down', 7);
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['from_earlier']);
        $this->assertSame(61, $r['account_id']);
        $this->assertSame(29, $r['left']);
        $this->assertStringContainsString('bus when the truck broke down', $body['messages'][0]['content'][0]['text']);
        $row = $db->query("SELECT source, input_tokens, output_tokens, cost_usd FROM bank_guidance WHERE id = " . (int)$r['guidance_id'])->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['claude', 2000, 300], [$row['source'], (int)$row['input_tokens'], (int)$row['output_tokens']]);
        $this->assertEqualsWithDelta(0.0105, (float)$row['cost_usd'], 1e-9);

        // Same payee, another reference number, no note → no call.
        $r2 = $svc->guide(2, '', 7);
        $this->assertTrue($r2['ok']);
        $this->assertTrue($r2['from_earlier']);
        $this->assertSame(1, $calls);
        $this->assertSame(29, $r2['left']);
        $this->assertSame('cache', $db->query("SELECT source FROM bank_guidance WHERE id = " . (int)$r2['guidance_id'])->fetchColumn());
    }

    public function test_cap_reached_means_no_call(): void
    {
        $db = $this->db(1);
        $calls = 0;
        $svc = new BankGuidanceService($db, $this->transport($this->answer(), $calls), date('Y-m-d'));
        $this->assertTrue($svc->guide(1, '', 7)['ok']);
        $r = $svc->guide(3, '', 7);              // a different payee: would need a call
        $this->assertFalse($r['ok']);
        $this->assertTrue($r['capped']);
        $this->assertSame(0, $r['left']);
        $this->assertSame(1, $calls);
        // A cache hit is still free at the cap.
        $this->assertTrue($svc->guide(2, '', 7)['from_earlier']);
        $this->assertSame(1, $calls);
    }

    public function test_an_invented_account_from_the_api_is_not_shown_or_cached(): void
    {
        $db = $this->db();
        $calls = 0;
        $svc = new BankGuidanceService($db, $this->transport($this->answer(['account_code' => '6155']), $calls), date('Y-m-d'));
        $r = $svc->guide(1, '', 7);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('6155 is not in the chart', $r['message']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM bank_guidance WHERE guidance_json IS NOT NULL")->fetchColumn());
        $this->assertSame(29, $r['left']);      // the failed call still counts against the cap
    }

    public function test_approving_keeps_the_reason_and_learns_the_payee_name(): void
    {
        $db = $this->db();
        $calls = 0;
        $svc = new BankGuidanceService($db, $this->transport($this->answer(), $calls), date('Y-m-d'));
        $r = $svc->guide(1, '', 7);
        $db->exec("INSERT INTO bank_line_reviews (transaction_id, final_account_id, decided_at) VALUES (1, 61, '2026-10-07')");

        $this->assertTrue($svc->recordDecision(1, (int)$r['guidance_id'], 7));
        $kept = json_decode((string)$db->query("SELECT guidance_json FROM bank_line_reviews WHERE transaction_id = 1")->fetchColumn(), true);
        $this->assertSame('6100', $kept['account_code']);
        $this->assertStringContainsString('adult fare', $kept['reason']);

        $lines = $svc->decorate([['id' => 2, 'description' => 'Point of sale TLNK/0598812234'], ['id' => 3, 'description' => 'Point of sale BLENZ/0598812299']]);
        $this->assertSame('TransLink', $lines[0]['payee_name']);
        $this->assertNull($lines[1]['payee_name']);

        // A guidance id from another line teaches nothing.
        $this->assertFalse($svc->recordDecision(2, (int)$r['guidance_id'], 7));
    }
}
