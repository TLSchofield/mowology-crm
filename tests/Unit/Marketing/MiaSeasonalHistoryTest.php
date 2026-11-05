<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

class MiaSeasonalHistoryTest extends TestCase
{
    public function test_jobber_lines_keep_unicode_commas_and_zero_amounts(): void
    {
        $lines=MiaSeasonalHistory::parseJobberLines('Lawn Cut (1, $60.00), Gardening 60 X (3, $1,253.50), 秋 clean-up (2, $0.00)');
        $this->assertSame(['Lawn Cut','Gardening 60 X','秋 clean-up'],array_column($lines,'name'));
        $this->assertSame(1253.5,$lines[1]['amount']);
        $this->assertSame(0.0,$lines[2]['amount']);
    }
    public function test_names_are_conservative(): void
    {
        foreach(['fall clean up','MAINTENANCE FALL CLEAN UP','Leaf Clean Up','leaves cleanup','Seasonal Clean Up','Leaf Fall Program','autumn clean-up'] as $n)$this->assertTrue(MiaSeasonalHistory::isFallCleanupName($n),$n);
        foreach(['Lawn Cut','Spring Clean Up','Gardening','Hedges Trim'] as $n)$this->assertFalse(MiaSeasonalHistory::isFallCleanupName($n),$n);
    }
    public function test_window_season_and_phrases(): void
    {
        $today=new DateTimeImmutable('2026-01-05');
        $this->assertTrue(MiaSeasonalHistory::inWindow('2025-12-25',$today));
        $this->assertFalse(MiaSeasonalHistory::inWindow('2026-01-06',$today));
        $this->assertTrue(MiaSeasonalHistory::askSeason(new DateTimeImmutable('2026-10-15')));
        $this->assertTrue(MiaSeasonalHistory::askSeason(new DateTimeImmutable('2026-12-15')));
        $this->assertFalse(MiaSeasonalHistory::askSeason(new DateTimeImmutable('2026-12-16')));
        $this->assertSame(['year'=>'Last year','month'=>'at the start of December'],MiaSeasonalHistory::whenPhrase('2025-12-05',$today));
        $this->assertSame(['year'=>'Two years ago','month'=>'in mid-December'],MiaSeasonalHistory::whenPhrase('2024-12-15',$today));
        $this->assertSame(['year'=>'In 2023','month'=>'at the end of December'],MiaSeasonalHistory::whenPhrase('2023-12-25',$today));
    }

    /** The first real case (2026-11-05): a priced fall cleanup in early December last year counts; a $0 contract line never does. */
    public function test_last_fall_cleanup_from_jobber_ignores_contract_lines(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->sqliteCreateFunction('MONTH', fn($d) => (int)substr((string)$d, 5, 2), 1);
        $db->exec("CREATE TABLE jobber_invoices (id INTEGER PRIMARY KEY, jobber_number TEXT, contact_id INT, property_id INT, issued_date TEXT, line_items TEXT)");
        $db->exec("INSERT INTO jobber_invoices VALUES (1, '11740', 71, 79, '2025-12-02', 'MAINTENANCE FALL CLEAN UP (1, \$1190.00)')");
        $db->exec("INSERT INTO jobber_invoices VALUES (2, '11691', 71, 79, '2025-10-27', 'Lawn Cut (1, \$60.00)')");
        $db->exec("INSERT INTO jobber_invoices VALUES (3, '11744', 26, 26, '2025-12-01', 'Leaf Clean Up (3, \$0.00), MAINTENANCE MONTHLY CHARGE (1, \$380.00)')");
        $h = new MiaSeasonalHistory($db);
        $today = new DateTimeImmutable('2026-11-05');
        $this->assertSame(['date' => '2025-12-02', 'source' => 'jobber', 'ref' => '11740', 'amount' => 1190.0], $h->lastFallCleanup(71, 79, $today));
        $this->assertNull($h->lastFallCleanup(26, 26, $today), 'included in the monthly contract: not an extra to offer');
        $this->assertNull($h->lastFallCleanup(71, 79, new DateTimeImmutable('2026-08-01')), 'not around this date');
    }
}
