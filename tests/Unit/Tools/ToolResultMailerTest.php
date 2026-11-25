<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Tests calculator result email rendering. */
final class ToolResultMailerTest extends TestCase
{
    /** Verifies mail uses server results, labels, and working rather than raw keys. */
    public function testUsesServerResultLabelsAndWorking(): void
    {
        $mail = (new ToolResultMailer())->render('labour-cost', [
            'wage' => 24,
            'vacation' => 4,
            'stat' => 4.4,
            'cpp' => 5.95,
            'ei' => 2.282,
            'wcb' => 3.14,
            'billable' => 75,
            'truck' => 6,
            'rate' => 80,
            'cost_per_billed_hour' => 999,
            'evil' => '<script>alert(1)</script>',
        ]);

        $this->assertStringContainsString('With vacation and stat:', $mail['plain']);
        $this->assertStringContainsString('Wage per paid hour: $24.00', $mail['plain']);
        $this->assertStringContainsString('Billable share of paid hours: 75%', $mail['plain']);
        $this->assertStringContainsString('you keep', $mail['plain']);
        $this->assertStringNotContainsString('cost_per_billed_hour', $mail['plain']);
        $this->assertStringNotContainsString('<script>', $mail['html']);
        $this->assertArrayNotHasKey('evil', $mail['data']['inputs']);
    }
}
