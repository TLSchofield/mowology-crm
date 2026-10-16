<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A phone's receipt in one call (Android 1.7.0): crew fields win, a repeat never makes a second expense. */
class ReceiptCaptureServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE receipt_capture_keys (id INTEGER PRIMARY KEY, user_id INT, client_ref TEXT, media_id INT, expense_id INT,
                   source TEXT, created_at TEXT, UNIQUE (user_id, client_ref))");
        $db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, expense_date TEXT, total REAL, vendor_name_raw TEXT, status TEXT, receipt_media_id INT)");
        $db->exec("INSERT INTO expenses VALUES (900, '2026-10-16', 54.30, 'HOME DEPOT', 'pending_approval', NULL)");
        $db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, property_id INT)");
        $db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INT, scheduled_date TEXT)");
        $db->exec("INSERT INTO job_plans VALUES (31, 77)");
        $db->exec("INSERT INTO job_visits VALUES (500, 31, '2026-10-16'), (501, 31, '2026-08-01')");
        return $db;
    }

    private function intake(): array
    {
        return ['success' => true, 'media_id' => 4410, 'ocr_text' => 'HOME DEPOT ...',
                'parsed' => ['total' => '54.30', 'subtotal' => '48.48', 'gst' => '2.42', 'pst' => '3.40', 'date' => '2026-10-15',
                             'vendor_hint' => 'Home Depot', 'line_items' => [['name' => 'Rake', 'line_total' => 48.48]]],
                'suggestions' => ['vendor_id' => 12, 'vendor_name' => 'HOME DEPOT', 'accounting_category' => 'Tools']];
    }

    private function crew(array $post = []): array
    {
        return ReceiptCaptureService::crewFields($post);
    }

    public function test_reference_must_be_a_plain_token(): void
    {
        $this->assertSame('r_20261016-ab12', ReceiptCaptureService::cleanRef(' r_20261016-ab12 '));
        $this->assertNull(ReceiptCaptureService::cleanRef('short'));
        $this->assertNull(ReceiptCaptureService::cleanRef("abc'; DROP--x"));
    }

    public function test_crew_fields_read_a_comma_total_and_drop_nonsense(): void
    {
        $c = $this->crew(['total' => '12,50', 'note' => "  gas\n for  the mower ", 'visit_id' => '500', 'lat' => '49.2', 'lng' => '-123.1']);
        $this->assertSame([12.5, 'gas for the mower', 500, 49.2, -123.1], [$c['total'], $c['note'], $c['visit_id'], $c['lat'], $c['lng']]);
        $c = $this->crew(['total' => '-3', 'visit_id' => 'x', 'lat' => '0', 'lng' => '0']);
        $this->assertNull($c['total']); $this->assertNull($c['visit_id']); $this->assertNull($c['lat']);
        $this->assertNull($this->crew(['total' => '250000'])['total']);
    }

    public function test_what_was_read_is_kept_when_the_crew_typed_nothing_or_the_same_total(): void
    {
        foreach ([[], ['total' => '54.30']] as $post) {
            $in = ReceiptCaptureService::buildInput($this->intake(), $this->crew($post), null, '2026-10-16');
            $this->assertSame([54.3, '48.48', '2.42', '3.40', '2026-10-15', 12, 'Tools'],
                [$in['total'], $in['amount'], $in['gst_amount'], $in['pst_amount'], $in['expense_date'], $in['vendor_id'], $in['accounting_category']]);
            $this->assertCount(1, $in['line_items']);
            $this->assertFalse($in['typed_total']);
            $this->assertSame(ReceiptCaptureService::DEFAULT_DESCRIPTION, $in['description']);
        }
    }

    public function test_a_different_typed_total_wins_and_drops_the_read_tax_split(): void
    {
        $in = ReceiptCaptureService::buildInput($this->intake(), $this->crew(['total' => '60', 'note' => 'two rakes']), null, '2026-10-16');
        $this->assertSame([60.0, '60.00', '0.00', '0.00', 'two rakes'], [$in['total'], $in['amount'], $in['gst_amount'], $in['pst_amount'], $in['description']]);
        $this->assertSame([], $in['line_items']);
        $this->assertTrue($in['typed_total']);
    }

    public function test_a_future_or_unreadable_date_becomes_today(): void
    {
        $i = $this->intake();
        $i['parsed']['date'] = '2027-01-01';
        $this->assertSame('2026-10-16', ReceiptCaptureService::buildInput($i, $this->crew(), null, '2026-10-16')['expense_date']);
        unset($i['parsed']);
        $in = ReceiptCaptureService::buildInput($i, $this->crew(['total' => '9.99']), null, '2026-10-16');
        $this->assertSame(['2026-10-16', 9.99, '9.99'], [$in['expense_date'], $in['total'], $in['amount']]);
    }

    public function test_capture_saves_pending_through_the_gate_with_the_picked_visit(): void
    {
        $db = $this->db();
        $gate = new ExpenseGateSpy();
        $svc = new ReceiptCaptureService($db, fn() => $this->intake(), $gate, fn() => ['flags' => 'new_vendor', 'score' => 20], fn() => true);
        $out = $svc->capture(9, ['x'], $this->crew(['visit_id' => '500']), 'ref-00000001', '2026-10-16');

        $this->assertSame(200, $out['http']);
        $this->assertSame([true, false, 900, 54.3, 'HOME DEPOT'], [$out['body']['success'], $out['body']['duplicate'],
            $out['body']['expense_id'], $out['body']['total'], $out['body']['vendor']]);
        $this->assertCount(1, $gate->calls);
        $c = $gate->calls[0];
        $this->assertSame([null, ReceiptCaptureService::SOURCE, 9], [$c['id'], $c['source'], $c['actor']['id']]);
        $this->assertSame(['pending_approval', 31, 77, 4410, 'new_vendor'], [$c['changes']['status'], $c['changes']['job_id'],
            $c['changes']['property_id'], $c['changes']['receipt_media_id'], $c['changes']['anomaly_flags']]);
        $this->assertArrayNotHasKey('typed_total', $c['changes'], 'only expense columns reach the gate');
    }

    public function test_a_repeat_answers_with_the_same_expense_and_never_reads_or_saves_again(): void
    {
        $db = $this->db();
        $gate = new ExpenseGateSpy();
        $reads = 0;
        $svc = new ReceiptCaptureService($db, function () use (&$reads) { $reads++; return $this->intake(); }, $gate, fn() => []);
        $svc->capture(9, ['x'], $this->crew(), 'ref-00000002', '2026-10-16');
        $again = $svc->capture(9, ['x'], $this->crew(), 'ref-00000002', '2026-10-16');

        $this->assertSame([1, 1], [$reads, count($gate->calls)]);
        $this->assertTrue($again['body']['duplicate']);
        $this->assertSame(900, $again['body']['expense_id']);
        // Another person's same reference is their own receipt.
        $svc->capture(8, ['x'], $this->crew(), 'ref-00000002', '2026-10-16');
        $this->assertSame(2, $reads);
    }

    public function test_a_refused_photo_keeps_its_code_and_saves_nothing(): void
    {
        $db = $this->db();
        $gate = new ExpenseGateSpy();
        $svc = new ReceiptCaptureService($db, fn() => ['success' => false, 'error' => 'File too large (max 10MB)', 'http_code' => 413], $gate, fn() => []);
        $out = $svc->capture(9, ['x'], $this->crew(), 'ref-00000003', '2026-10-16');
        $this->assertSame([413, false], [$out['http'], $out['body']['success']]);
        $this->assertSame([], $gate->calls);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM receipt_capture_keys")->fetchColumn());
    }

    public function test_an_old_visit_gives_no_job(): void
    {
        $db = $this->db();
        $gate = new ExpenseGateSpy();
        (new ReceiptCaptureService($db, fn() => $this->intake(), $gate, fn() => [], fn() => true))
            ->capture(9, ['x'], $this->crew(['visit_id' => '501']), 'ref-00000004', '2026-10-16');
        $this->assertNull($gate->calls[0]['changes']['job_id'] ?? null);
    }

    public function test_a_save_that_died_after_the_expense_is_found_by_its_photo_not_made_again(): void
    {
        $db = $this->db();
        $reads = 0;
        $intake = function () use (&$reads) { $reads++; return $this->intake(); };
        $broken = new class extends ExpenseGateSpy {
            public function apply(?int $expenseId, array $changes, array $actor, string $source, array $opts = []): array
            { throw new RuntimeException('connection lost'); }
        };
        try {
            (new ReceiptCaptureService($db, $intake, $broken, fn() => []))->capture(9, ['x'], $this->crew(), 'ref-00000005', '2026-10-16');
            $this->fail('the gate threw');
        } catch (RuntimeException $e) {}
        $this->assertEquals([4410, null], array_values($db->query("SELECT media_id, expense_id FROM receipt_capture_keys")->fetch(PDO::FETCH_NUM)));

        // Nothing was saved → the resend reads and saves (once).
        $gate = new ExpenseGateSpy();
        (new ReceiptCaptureService($db, $intake, $gate, fn() => []))->capture(9, ['x'], $this->crew(), 'ref-00000005', '2026-10-16');
        $this->assertSame([2, 1], [$reads, count($gate->calls)]);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM receipt_capture_keys")->fetchColumn());

        // The expense was saved but its key wasn't → found by the photo, not made again.
        $db->exec("UPDATE receipt_capture_keys SET expense_id = NULL");
        $db->exec("UPDATE expenses SET receipt_media_id = 4410 WHERE id = 900");
        $gate2 = new ExpenseGateSpy();
        $out = (new ReceiptCaptureService($db, $intake, $gate2, fn() => []))->capture(9, ['x'], $this->crew(), 'ref-00000005', '2026-10-16');
        $this->assertSame([true, 900, 2], [$out['body']['duplicate'], $out['body']['expense_id'], $reads]);
        $this->assertSame([], $gate2->calls);
        $this->assertSame('900', (string)$db->query("SELECT expense_id FROM receipt_capture_keys")->fetchColumn());
    }

    public function test_a_visit_this_person_is_not_on_gives_no_job(): void
    {
        $db = $this->db();
        $gate = new ExpenseGateSpy();
        $asked = [];
        (new ReceiptCaptureService($db, fn() => $this->intake(), $gate, fn() => [], function (int $v, int $u) use (&$asked) { $asked[] = [$v, $u]; return false; }))
            ->capture(9, ['x'], $this->crew(['visit_id' => '500']), 'ref-00000006', '2026-10-16');
        $this->assertSame([[500, 9]], $asked);
        $this->assertNull($gate->calls[0]['changes']['job_id'] ?? null);
        $this->assertSame('pending_approval', $gate->calls[0]['changes']['status'], 'the receipt still saves');
    }
}
