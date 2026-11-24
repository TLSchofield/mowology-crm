<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Otto's GPS stop strengthens a receipt's bank match (Tim, 2026-10-15) — the Sep 1 Chevron receipt, Nigel 8:08. */
class OttoStopBonusTest extends TestCase
{
    private function stop(string $basis): array
    {
        return ['arrived_at' => '2026-09-01 08:08:00', 'place_name' => 'CHEVRON', 'people' => 'Nigel (on the clock)', 'basis' => $basis];
    }

    public function testPrintedTimeIsStrongest(): void
    {
        $b = BankImportService::ottoStopBonus($this->stop('printed'), '2026-09-01');
        $this->assertSame(20, $b['points']);
        $this->assertSame('GPS: Nigel at CHEVRON 8:08 — at the time printed on the receipt', $b['reason']);
        $this->assertSame(15, BankImportService::ottoStopBonus($this->stop('photo'), '2026-09-02')['points']);
        $this->assertSame(8, BankImportService::ottoStopBonus($this->stop('date'), '2026-09-04')['points']);
    }

    public function testOnlyTheStopDayOrAFewDaysAfter(): void
    {
        $this->assertNull(BankImportService::ottoStopBonus($this->stop('printed'), '2026-08-31'), 'a line before the visit');
        $this->assertNull(BankImportService::ottoStopBonus($this->stop('printed'), '2026-09-05'), 'more than 3 days later');
        $this->assertNull(BankImportService::ottoStopBonus(null, '2026-09-01'));
    }
}
