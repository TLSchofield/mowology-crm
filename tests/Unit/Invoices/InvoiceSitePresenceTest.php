<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Modules/Invoices/Services/InvoiceSitePresence.php';

class InvoiceSitePresenceTest extends TestCase
{
    private function sample(): array
    {
        return ['count' => 5, 'first' => '2026-11-19 14:24:47', 'last' => '2026-11-19 14:25:31',
                'address' => '1965 West 4th Avenue <x>', 'best_accuracy_m' => 12];
    }

    public function testNoReadingsRendersNothing(): void
    {
        $this->assertSame('', InvoiceSitePresence::html(null));
    }

    public function testPanelStatesOnlyRecordedFacts(): void
    {
        $html = InvoiceSitePresence::html($this->sample());
        $this->assertStringContainsString('SITE PRESENCE', $html);
        $this->assertStringContainsString('5 within 200 m', $html);
        $this->assertStringContainsString('2:24:47 PM', $html);
        $this->assertStringContainsString('1965 West 4th Avenue &lt;x&gt;', $html);
        $this->assertStringContainsString('± 12 m', $html);
    }

    public function testSideBySideOnlyWhenBothExist(): void
    {
        $this->assertSame('W', InvoiceSitePresence::sideBySide('W', ''));
        $this->assertSame('P', InvoiceSitePresence::sideBySide('', 'P'));
        $this->assertSame('', InvoiceSitePresence::sideBySide('', ''));
        $this->assertStringContainsString('<td width="52%"', InvoiceSitePresence::sideBySide('W', 'P'));
    }
}
