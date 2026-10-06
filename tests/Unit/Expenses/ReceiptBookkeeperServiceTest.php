<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ReceiptBookkeeperService — the parts the model can't be trusted with (hard checks),
 * the backtest scoring, and the request shape. The Claude call itself is injected.
 */
class ReceiptBookkeeperServiceTest extends TestCase
{
    private function f($value, string $conf = 'high'): array
    {
        return ['value' => $value, 'reason' => 'r', 'confidence' => $conf];
    }

    private function suggestion(array $over = []): array
    {
        return array_merge([
            'accounting_category' => $this->f('Materials'),
            'asset_tag'           => $this->f('none'),
            'job'                 => $this->f(null),
            'subtotal'            => $this->f(100.00),
            'gst'                 => $this->f(5.00),
            'pst'                 => $this->f(7.00),
            'total'               => $this->f(112.00),
            'line_items'          => [['name' => 'MULCH', 'amount' => 60.0], ['name' => 'SOIL', 'amount' => 40.0]],
            'notes'               => '',
        ], $over);
    }

    private function ctx(array $ruleHits = [], array $jobs = []): array
    {
        return ['prompt' => ['rule_hits' => $ruleHits, 'job_candidates' => $jobs]];
    }

    private function checksByName(array $checks): array
    {
        $out = [];
        foreach ($checks as $c) $out[$c['check']][] = $c['ok'];
        return $out;
    }

    public function test_clean_receipt_passes_every_check(): void
    {
        $r = ReceiptBookkeeperService::check($this->suggestion(), $this->ctx());
        foreach ($r['checks'] as $c) {
            $this->assertTrue($c['ok'], $c['check'] . ': ' . $c['message']);
        }
    }

    public function test_amounts_that_dont_add_up_are_flagged(): void
    {
        $r = ReceiptBookkeeperService::check($this->suggestion(['total' => $this->f(120.00)]), $this->ctx());
        $this->assertSame([false], $this->checksByName($r['checks'])['sum']);
    }

    public function test_gst_that_isnt_5_percent_is_flagged(): void
    {
        $r = ReceiptBookkeeperService::check($this->suggestion(['gst' => $this->f(12.00), 'total' => $this->f(119.00)]), $this->ctx());
        $this->assertSame([false], $this->checksByName($r['checks'])['gst_rate']);
    }

    public function test_firm_owner_rule_overrides_the_model(): void
    {
        $hits = [['field' => 'asset_tag', 'value' => 'truck', 'reason' => 'Diesel is for the Dodge Ram truck', 'rule' => 'fuel_diesel_truck', 'strength' => 'firm']];
        $r = ReceiptBookkeeperService::check($this->suggestion([
            'accounting_category' => $this->f('Fuel'), 'asset_tag' => $this->f('equipment'),
        ]), $this->ctx($hits));
        $this->assertSame('truck', $r['suggestion']['asset_tag']['value']);
        $this->assertStringContainsString("owner's rule", $r['suggestion']['asset_tag']['reason']);
    }

    public function test_soft_rule_does_not_override(): void
    {
        $hits = [['field' => 'accounting_category', 'value' => 'Tools/Equipment', 'reason' => 'EGO', 'rule' => 'ego_equipment', 'strength' => 'soft']];
        $r = ReceiptBookkeeperService::check($this->suggestion(), $this->ctx($hits));
        $this->assertSame('Materials', $r['suggestion']['accounting_category']['value']);
    }

    public function test_untagged_fuel_is_flagged(): void
    {
        $r = ReceiptBookkeeperService::check($this->suggestion(['accounting_category' => $this->f('Fuel')]), $this->ctx());
        $this->assertSame([false], $this->checksByName($r['checks'])['fuel_tagged']);
    }

    public function test_job_not_on_the_schedule_is_cleared(): void
    {
        $r = ReceiptBookkeeperService::check($this->suggestion(['job' => $this->f(999)]), $this->ctx([], [['plan_id' => 12]]));
        $this->assertNull($r['suggestion']['job']['value']);
        $r2 = ReceiptBookkeeperService::check($this->suggestion(['job' => $this->f(12)]), $this->ctx([], [['plan_id' => 12]]));
        $this->assertSame(12, $r2['suggestion']['job']['value']);
    }

    public function test_unknown_category_fails_the_check(): void
    {
        $r = ReceiptBookkeeperService::check($this->suggestion(['accounting_category' => $this->f('Snacks')]), $this->ctx());
        $this->assertSame([false], $this->checksByName($r['checks'])['category_known']);
    }

    // ── backtest scoring ──────────────────────────────────────────────

    public function test_score_against_the_owners_final_values(): void
    {
        $final = ['accounting_category' => 'Materials', 'asset_tag' => null, 'job_id' => null,
                  'total' => '112.00', 'gst_amount' => '5.00', 'pst_amount' => '7.00', 'amount' => '100.00'];
        $s = ReceiptBookkeeperService::score($this->suggestion(), $final, ['Mulch black 2cf', 'SOIL']);
        $this->assertTrue($s['accounting_category']);
        $this->assertSame('n/a', $s['asset_tag']);
        $this->assertSame('n/a', $s['job']);
        $this->assertTrue($s['total']);
        $this->assertTrue($s['gst']);
        $this->assertTrue($s['line_items']);
    }

    public function test_score_counts_a_wrong_category_and_tax(): void
    {
        $final = ['accounting_category' => 'Fuel', 'total' => '112.00', 'gst_amount' => '5.33', 'pst_amount' => '7.00', 'amount' => '100.00'];
        $s = ReceiptBookkeeperService::score($this->suggestion(), $final, []);
        $this->assertFalse($s['accounting_category']);
        $this->assertFalse($s['gst']);
        $this->assertSame('n/a', $s['line_items']);
    }

    // ── request shape ─────────────────────────────────────────────────

    public function test_request_uses_structured_output_effort_fallbacks_and_cached_system(): void
    {
        $b = ReceiptBookkeeperService::buildRequest(['vendor' => 'SHELL'], ['media_type' => 'image/jpeg', 'data' => 'AAAA'], 'claude-opus-5-5', 'medium');
        $this->assertSame('claude-opus-5-5', $b['model']);
        $this->assertSame('default', $b['fallbacks']);
        $this->assertSame('json_schema', $b['output_config']['format']['type']);
        $this->assertSame('medium', $b['output_config']['effort']);
        $this->assertSame(['type' => 'ephemeral'], $b['system'][0]['cache_control']);
        $this->assertSame('image', $b['messages'][0]['content'][0]['type']);
        $this->assertArrayNotHasKey('thinking', $b, 'Opus 5.5 thinking is always on — never send it');
    }

    public function test_schema_objects_all_forbid_extra_properties(): void
    {
        $walk = function ($node) use (&$walk) {
            if (!is_array($node)) return;
            if (($node['type'] ?? null) === 'object') {
                $this->assertFalse($node['additionalProperties'] ?? true);
                $this->assertSame(array_keys($node['properties']), $node['required']);
            }
            foreach ($node as $v) $walk($v);
        };
        $walk(ReceiptBookkeeperService::schema());
    }

    public function test_an_amount_must_be_printed_on_the_receipt(): void
    {
        $text = "CITY OF VANCOUVER\nGREEN WASTE 100 KG @ 124/T\nAMOUNT 12.40\nGST 0.00";
        $this->assertTrue(ReceiptBookkeeperService::printed(12.40, $text));
        $this->assertTrue(ReceiptBookkeeperService::printed(12.4, "TOTAL $12.4"));
        $this->assertFalse(ReceiptBookkeeperService::printed(12.00, $text), 'the reader\'s 12.00 is not on the ticket');
        $this->assertFalse(ReceiptBookkeeperService::printed(2.40, "TOTAL 12.40"), 'part of a bigger number does not count');
        $c = ReceiptBookkeeperService::check(['total' => ['value' => 15.00]], ['prompt' => ['receipt_text' => $text]]);
        $this->assertContains('printed', array_column(array_filter($c['checks'], fn($x) => !$x['ok']), 'check'));
    }
}
