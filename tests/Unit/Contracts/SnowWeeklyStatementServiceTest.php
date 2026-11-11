<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Monday statement of last week's salt & snow runs, one per billing inbox. */
class SnowWeeklyStatementServiceTest extends TestCase
{
    private function row(string $email, string $date, string $addr, float $amt, string $inv): array
    {
        return ['email' => $email, 'invoice_id' => 1, 'invoice_number' => $inv, 'visit_date' => $date,
                'address' => $addr, 'service' => 'Salted', 'amount' => $amt, 'balance' => $amt, 'link' => null];
    }

    public function test_last_week_is_the_previous_monday_to_sunday(): void
    {
        $this->assertSame(['start' => '2026-11-09', 'end' => '2026-11-15'], SnowWeeklyStatementService::lastWeek('2026-11-16')); // Monday
        $this->assertSame(['start' => '2026-11-09', 'end' => '2026-11-15'], SnowWeeklyStatementService::lastWeek('2026-11-19')); // Thursday catch-up
        $this->assertSame(['start' => '2026-12-28', 'end' => '2027-01-03'], SnowWeeklyStatementService::lastWeek('2027-01-04')); // across the year end
    }

    public function test_runs_group_by_billing_inbox_with_totals(): void
    {
        $g = SnowWeeklyStatementService::group([
            $this->row('invoices@dorsetrealty.com', '2026-11-10', '880 West 71st Avenue', 105.84, 'INV-1'),
            $this->row('Invoices@DorsetRealty.com', '2026-11-09', '2464 Point Grey Road', 100.33, 'INV-2'),
            $this->row('langleyap@quaypacific.com', '2026-11-09', '1551 West 11th Avenue', 162.75, 'INV-3'),
            $this->row('', '2026-11-09', 'nowhere', 50.00, 'INV-4'),
        ]);
        $this->assertSame(['invoices@dorsetrealty.com', 'langleyap@quaypacific.com'], array_keys($g));
        $this->assertSame(206.17, $g['invoices@dorsetrealty.com']['total']);
        $this->assertSame('INV-2', $g['invoices@dorsetrealty.com']['lines'][0]['invoice_number']); // date order
    }

    public function test_subject_names_the_week_count_and_total(): void
    {
        $g = SnowWeeklyStatementService::group([
            $this->row('a@b.c', '2026-11-10', 'X', 105.84, 'INV-1'),
            $this->row('a@b.c', '2026-11-11', 'Y', 100.33, 'INV-2'),
        ]);
        $this->assertSame('Salt & snow runs, Nov 9-15: 2 runs, $206.17',
            SnowWeeklyStatementService::subject(['start' => '2026-11-09', 'end' => '2026-11-15'], $g['a@b.c']));
        $this->assertSame('Salt & snow runs, Nov 30-Dec 6: 2 runs, $206.17',
            SnowWeeklyStatementService::subject(['start' => '2026-11-30', 'end' => '2026-12-06'], $g['a@b.c']));
    }

    public function test_body_lists_each_run_and_escapes_text(): void
    {
        $g = SnowWeeklyStatementService::group([$this->row('a@b.c', '2026-11-10', '<b>1 Main</b>', 105.84, 'INV-1')]);
        $html = SnowWeeklyStatementService::bodyHtml(['start' => '2026-11-09', 'end' => '2026-11-15'], $g['a@b.c']);
        $this->assertStringContainsString('INV-1', $html);
        $this->assertStringContainsString('$105.84', $html);
        $this->assertStringContainsString('&lt;b&gt;1 Main', $html);
        $this->assertStringNotContainsString('Still to pay', $html);
    }
}
