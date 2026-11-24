<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Products/ProductsTestDb.php';

/**
 * "Ask Otto to read the manual": Claude is always a fake transport here. Intervals must quote
 * the manual; nothing reaches equipment_service_intervals until Tim confirms each line; the
 * daily cap stops calls and every call is logged with tokens and cost.
 */
class OttoManualServiceTest extends TestCase
{
    private function db(int $cap = 10): PDO
    {
        $db = ProductsTestDb::make();
        $db->prepare("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('otto_manual_daily_cap', ?)")->execute([(string)$cap]);
        $db->exec("INSERT INTO equipment (id, name, equipment_class, make, model, power_source) VALUES (3, 'EGO mower', 'mower', 'EGO', 'LM2135SP', 'battery')");
        return $db;
    }

    private function claude(array $answer, array &$sent = null): callable
    {
        return function (array $body) use ($answer, &$sent): array {
            $sent = $body;
            return ['code' => 200, 'body' => json_encode([
                'content' => [['type' => 'text', 'text' => json_encode($answer)]],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 12000, 'output_tokens' => 400],
            ])];
        };
    }

    private function manual(): array
    {
        return ['intervals' => [
            ['task' => 'Sharpen blade', 'every_hours' => 25, 'every_days' => 0, 'page' => '18', 'quote' => 'Sharpen the blade every 25 hours of operation.'],
            ['task' => 'Clean underside of deck', 'every_hours' => 0, 'every_days' => 1, 'page' => '17', 'quote' => 'Clean the deck after each use.'],
            ['task' => 'Check tyre pressure', 'every_hours' => 10, 'every_days' => 0, 'page' => '', 'quote' => ''],   // no quote → dropped
        ], 'note' => ''];
    }

    public function test_request_sends_a_pdf_as_a_document_to_sonnet_with_a_strict_schema(): void
    {
        $r = OttoManualService::buildRequest(['name' => 'EGO mower', 'make' => 'EGO', 'model' => 'LM2135SP', 'equipment_class' => 'mower', 'power_source' => 'battery'], 'QUJD', 'application/pdf');
        $this->assertSame('claude-sonnet-5-5', $r['model']);
        $this->assertSame('document', $r['messages'][0]['content'][0]['type']);
        $this->assertSame('application/pdf', $r['messages'][0]['content'][0]['source']['media_type']);
        $this->assertStringContainsString('EGO LM2135SP (mower)', $r['messages'][0]['content'][1]['text']);
        $this->assertSame('json_schema', $r['output_config']['format']['type']);
        $this->assertFalse(OttoManualService::schema()['additionalProperties']);
        $this->assertStringContainsString('Do not add tasks', OttoManualService::SYSTEM_PROMPT);
        $img = OttoManualService::buildRequest(['name' => 'x'], 'QUJD', 'image/jpeg');
        $this->assertSame('image', $img['messages'][0]['content'][0]['type']);
    }

    public function test_validate_keeps_only_quoted_intervals_with_hours_or_days(): void
    {
        $v = OttoManualService::validate($this->manual());
        $this->assertCount(2, $v['intervals']);
        $this->assertSame(1, $v['dropped']);
        $this->assertSame(['task' => 'Sharpen blade', 'every_hours' => 25.0, 'every_days' => null, 'page' => '18',
                           'quote' => 'Sharpen the blade every 25 hours of operation.', 'status' => 'pending'], $v['intervals'][0]);
        $this->assertSame('every 25 h or a year', OttoManualService::every(25.0, 365));
    }

    public function test_reading_logs_cost_and_writes_nothing_until_confirmed(): void
    {
        $db = $this->db();
        $svc = new OttoManualService($db, $this->claude($this->manual(), $sent), '2026-10-07');
        $r = $svc->read(3, '%PDF-1.4 fake', 'application/pdf', 'LM2135SP-manual.pdf', 77, 1);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertCount(2, $r['intervals']);
        $this->assertSame(9, $r['left']);
        $row = $db->query("SELECT * FROM equipment_manual_reads")->fetch();
        $this->assertSame([12000, 400, 0.042, 77], [(int)$row['input_tokens'], (int)$row['output_tokens'], round((float)$row['cost_usd'], 5), (int)$row['media_id']]);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM equipment_service_intervals")->fetchColumn(), 'Tim confirms first');
        $this->assertSame(base64_encode('%PDF-1.4 fake'), $sent['messages'][0]['content'][0]['source']['data']);

        $pending = $svc->pending();
        $this->assertCount(2, $pending[3]);
        // Tim changes 25 h to 20 h before saving; skips the other.
        $c = $svc->confirm((int)$row['id'], 0, ['every_hours' => 20], 1);
        $this->assertTrue($c['ok'], $c['message']);
        $svc->skip((int)$row['id'], 1);
        $iv = $db->query("SELECT equipment_id, equipment_class, task, every_hours, every_days FROM equipment_service_intervals")->fetchAll();
        $this->assertSame([[3, null, 'Sharpen blade', 20.0, null]],
                          array_map(fn($r) => [(int)$r['equipment_id'], $r['equipment_class'], $r['task'], (float)$r['every_hours'], $r['every_days']], $iv));
        $this->assertSame([], $svc->pending());
        $this->assertFalse($svc->confirm((int)$row['id'], 0, [], 1)['ok'], 'already decided');
    }

    public function test_daily_cap_stops_calls(): void
    {
        $db = $this->db(1);
        $calls = 0;
        $t = function () use (&$calls) { $calls++; return ['code' => 200, 'body' => json_encode(['content' => [['type' => 'text', 'text' => '{"intervals":[],"note":"No schedule printed."}']], 'usage' => []])]; };
        $svc = new OttoManualService($db, $t, date('Y-m-d'));
        $first = $svc->read(3, 'x', 'image/jpeg', 'p.jpg', null, 1);
        $this->assertTrue($first['ok']);
        $this->assertStringContainsString('No schedule printed', $first['message']);
        $second = $svc->read(3, 'x', 'image/jpeg', 'p.jpg', null, 1);
        $this->assertFalse($second['ok']);
        $this->assertTrue($second['capped']);
        $this->assertSame(1, $calls);
        $this->assertSame(['ready' => true, 'cap' => 1, 'used' => 1, 'left' => 0], $svc->status());
    }

    public function test_api_errors_are_logged_and_reported(): void
    {
        $db = $this->db();
        $svc = new OttoManualService($db, fn() => ['code' => 529, 'body' => '{}'], '2026-10-07');
        $r = $svc->read(3, 'x', 'image/png', 'p.png', null, 1);
        $this->assertFalse($r['ok']);
        $this->assertSame('HTTP 529', $db->query("SELECT error FROM equipment_manual_reads")->fetchColumn());
    }

    public function test_wrong_file_type_never_calls_claude(): void
    {
        $svc = new OttoManualService($this->db(), function () { throw new RuntimeException('should not be called'); });
        $this->assertFalse($svc->read(3, 'x', 'text/plain', 'a.txt', null, 1)['ok']);
        $this->assertFalse($svc->read(99, 'x', 'application/pdf', 'a.pdf', null, 1)['ok']);
    }
}
