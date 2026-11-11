<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sam's approval summary: every line accounted for, so a missing line never looks like a bug
 * (owner, 2026-10-10). The real case: council approved QUO-2026-0073 minus the sprinkler line.
 */
class QuoteApprovalServiceTest extends TestCase
{
    private function quote(): array
    {
        return ['quote_number' => 'QUO-2026-0073',
                'accepted_by_name' => 'Monica Nicule, Macdonald PM, for council (email Oct 9) (verbal approval by Tim Schofield)'];
    }

    private function lines(bool $declineSprinkler = true): array
    {
        return [
            ['service_type' => 'Black Composted Bark Mulch', 'line_total' => '700.00'],
            ['service_type' => 'Bulbs', 'line_total' => '250.00'],
            ['service_type' => 'Flowerbed Soil', 'line_total' => '175.00'],
            ['service_type' => 'Extend Sprinkler and convert bed sprinklers to drip lines', 'line_total' => '550.00',
             'client_declined' => $declineSprinkler ? 1 : 0],
        ];
    }

    public function test_a_partial_approval_names_what_is_not_included(): void
    {
        $s = QuoteApprovalService::summary($this->quote(), $this->lines());
        $this->assertTrue($s['partial']);
        $this->assertSame(3, $s['approved_count']);
        $this->assertSame(4, $s['line_count']);
        $this->assertSame(1181.25, $s['approved_total']);
        $this->assertSame(1758.75, $s['quoted_total']);
        $this->assertStringContainsString('3 of 4 lines', $s['headline']);
        $this->assertStringContainsString('(quoted $1,758.75)', $s['headline']);
        $this->assertStringContainsString('Not included: Extend Sprinkler and convert bed sprinklers to drip lines $550.00', $s['push_body']);
        $this->assertStringContainsString('Not included', $s['job_note']);
        $this->assertSame([true, true, true, false], array_column($s['lines'], 'approved'));
    }

    public function test_a_full_approval_says_so_and_never_mentions_missing_lines(): void
    {
        $s = QuoteApprovalService::summary($this->quote(), $this->lines(false));
        $this->assertFalse($s['partial']);
        $this->assertStringContainsString('All 4 lines', $s['headline']);
        $this->assertStringNotContainsString('Not included', $s['push_body'] . $s['job_note'] . $s['headline']);
        $this->assertStringNotContainsString('quoted', $s['headline']);
        $this->assertSame(1758.75, $s['approved_total']);
    }

    public function test_optional_lines_are_not_counted_as_declined(): void
    {
        $lines = $this->lines(false);
        $lines[] = ['service_type' => 'Optional edging', 'line_total' => '90.00', 'is_optional' => 1];
        $s = QuoteApprovalService::summary($this->quote(), $lines);
        $this->assertFalse($s['partial']);
        $this->assertSame(4, $s['line_count']);
    }

    public function test_who_and_how(): void
    {
        $this->assertSame('by email', QuoteApprovalService::via($this->quote()));
        $this->assertSame('Monica Nicule, Macdonald PM, for council (email Oct 9)', QuoteApprovalService::approver($this->quote()));
        $this->assertSame('verbal', QuoteApprovalService::via(['accepted_by_name' => 'Bob (verbal approval by Tim)']));
        $this->assertSame('signed online', QuoteApprovalService::via(['accepted_by_name' => 'Joann', 'signature_data' => 'data:image/png;base64,x']));
        $s = QuoteApprovalService::summary($this->quote(), $this->lines());
        $this->assertStringContainsString('Approved by Monica Nicule, Macdonald PM, for council (email Oct 9) (by email).', $s['job_note']);
    }

    public function test_push_title_is_sams(): void
    {
        $this->assertSame('Sam: QUO-2026-0073 approved', QuoteApprovalService::summary($this->quote(), $this->lines())['push_title']);
    }

    public function test_ottos_schedule_line(): void
    {
        $this->assertSame('Daily salt & snow route PLN-2026-0150 set up: Nov 1 – Mar 31, crew Nigel Casey.',
            QuoteApprovalService::scheduleNote(['route_number' => 'PLN-2026-0150', 'route_start' => '2026-11-01', 'route_end' => '2027-03-31', 'crew' => 'Nigel Casey', 'is_contract' => 1]));
        $this->assertSame("Job PLN-2026-0145 is in the Unscheduled tray — place it when you're ready.",
            QuoteApprovalService::scheduleNote(['plan_number' => 'PLN-2026-0145']));
        $this->assertStringStartsWith('Route not set up: no rates', QuoteApprovalService::scheduleNote(['setup_status' => 'failed', 'setup_detail' => 'no rates', 'is_contract' => 1]));
        $this->assertSame('Daily salt & snow route PLN-1 set up: Nov 1 – Mar 31, no crew yet.',
            QuoteApprovalService::scheduleNote(['route_number' => 'PLN-1', 'route_start' => '2026-11-01', 'route_end' => '2027-03-31', 'crew' => '']));
    }
}
