<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Fiscal years from the business's year end; the CRM's own address for links (review 2026-10-13). */
class FiscalYearAndSiteTest extends TestCase
{
    public function test_calendar_year_end(): void
    {
        $this->assertSame(['2026-01-01', '2026-12-31'], FiscalYear::bounds(2026));
        $this->assertSame(2026, FiscalYear::of('2026-12-31'));
        $this->assertSame(2027, FiscalYear::of('2027-01-01'));
        $this->assertCount(12, FiscalYear::months(2025));
        $this->assertSame('2025-01', FiscalYear::months(2025)[0]);
        $this->assertSame('December 31', FiscalYear::endLabel());
    }

    public function test_june_year_end_crosses_the_calendar(): void
    {
        $this->assertSame(['2025-07-01', '2026-06-30'], FiscalYear::bounds(2026, '06-30'));
        $this->assertSame(2026, FiscalYear::of('2025-07-01', '06-30'), 'inside the tail of the year');
        $this->assertSame(2026, FiscalYear::of('2026-06-30', '06-30'));
        $this->assertSame(2027, FiscalYear::of('2026-07-01', '06-30'));
        $this->assertSame(['2025-07', '2026-06'], [FiscalYear::months(2026, '06-30')[0], FiscalYear::months(2026, '06-30')[11]]);
        $this->assertSame(['2026-01-01', '2026-12-31'], FiscalYear::bounds(2026, '02-30'), 'an impossible year end falls back to 12-31');
        $this->assertFalse(FiscalYear::validEnd('02-29'));
    }

    public function test_filed_means_every_month_locked(): void
    {
        $locked = [];
        foreach (FiscalYear::months(2025) as $ym) $locked[$ym] = ['closed_at' => 'x'];
        $this->assertTrue(FiscalYear::lockState(2025, $locked)['closed']);
        unset($locked['2025-07']);
        $s = FiscalYear::lockState(2025, $locked);
        $this->assertFalse($s['closed']);
        $this->assertSame(11, $s['locked']);
        $this->assertFalse(FiscalYear::lockState(2026, $locked)['closed']);
    }

    public function test_dates_are_validated(): void
    {
        $this->assertSame('2026-10-06', FiscalYear::cleanDate('2026-10-06'));
        $this->assertSame('2026-01-01', FiscalYear::cleanDate('2026-02-30', '2026-01-01'), 'no Feb 30');
        $this->assertSame('2026-01-01', FiscalYear::cleanDate("2026-10-06' OR 1=1", '2026-01-01'));
        $this->assertSame('2026-01-01', FiscalYear::cleanDate(['x'], '2026-01-01'));
        $this->assertSame(date('Y-m-d'), FiscalYear::cleanDate('yesterday'));
    }

    public function test_site_address_never_trusts_a_foreign_host(): void
    {
        $this->assertSame('https://mowology.ca', LedgerSite::pick('https://mowology.ca/', null, 'evil.example', false), 'the setting wins');
        $this->assertSame('https://mowology.ca', LedgerSite::pick('mowology.ca', null, '', false));
        $this->assertNull(LedgerSite::pick(null, 'mowology.ca', 'evil.example', true), 'a forged host is refused');
        $this->assertSame('https://www.mowology.ca', LedgerSite::pick(null, 'mowology.ca', 'www.mowology.ca', true));
        $this->assertSame('https://mowology.ca', LedgerSite::pick(null, 'https://www.mowology.ca', 'mowology.ca', false), 'own domain is always https');
        $this->assertSame('http://localhost:8000', LedgerSite::pick(null, null, 'localhost:8000', false), 'local dev host');
        $this->assertNull(LedgerSite::pick(null, null, 'mowology.ca.evil.example', true));
        $this->assertNull(LedgerSite::pick('javascript:alert(1)', null, 'x', true));
    }

    public function test_gifi_active_is_one_rule(): void
    {
        $this->assertTrue(GifiRules::isActive(['type' => 'asset', 'lines' => 0, 'end' => 12.5, 'period' => 0]));
        $this->assertFalse(GifiRules::isActive(['type' => 'revenue', 'lines' => 0, 'end' => 500, 'period' => 0]),
            'an income account with only a prior-year balance and no activity this year needs no code');
        $this->assertTrue(GifiRules::isActive(['type' => 'expense', 'lines' => 3, 'end' => 0, 'period' => 0]));
        $this->assertFalse(GifiRules::isActive(['type' => 'liability', 'lines' => 0, 'end' => 0.004, 'period' => 9]));
    }
}
