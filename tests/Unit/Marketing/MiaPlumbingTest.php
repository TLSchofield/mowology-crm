<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MiaPlumbingTest extends TestCase
{
    public function testEveryProductionSpellingMapsToOneNeutralKey(): void
    {
        $seen = ['lawn_care' => 'recurring_upkeep', '7 Day Lawn Cut Service' => 'recurring_upkeep', '14 Day Lawn Cut Service' => 'recurring_upkeep',
            'Lawn Cut' => 'recurring_upkeep', 'Lawn Maintenance' => 'recurring_upkeep', 'maintenance' => 'recurring_upkeep',
            'GARDENING' => 'garden_care', 'Garden Care' => 'garden_care', 'garden_maintenance' => 'garden_care', 'Hedge Trimming' => 'garden_care', 'HEDGE TRIMMING' => 'garden_care',
            'AERATION' => 'seasonal_treatment', 'SUMMER FERTILIZER' => 'seasonal_treatment',
            'landscaping' => 'install_project', 'Black Composted Bark Mulch' => 'install_project', 'Premium Topsoil' => 'install_project',
            'snow_removal' => 'winter_service', 'salt_application' => 'winter_service', 'Cleanup' => 'cleanup',
            'Skilled Labor' => 'labor', 'Basic Labor' => 'labor'];
        foreach ($seen as $raw => $key) {
            $this->assertSame($key, MiaServiceKey::keyFor($raw), $raw);
        }
    }

    public function testSpacingCaseAndSeparatorsDoNotMatter(): void
    {
        $this->assertSame('winter_service', MiaServiceKey::keyFor('  Snow-Removal '));
        $this->assertSame('recurring_upkeep', MiaServiceKey::keyFor('LAWN__CARE'));
    }

    public function testAnUnknownSpellingIsOtherAndIsReportedNotGuessed(): void
    {
        $this->assertSame('other', MiaServiceKey::keyFor('ACELEPYN'));
        $this->assertSame('other', MiaServiceKey::keyFor(null));
        $this->assertSame(['ACELEPYN'], MiaServiceKey::unmapped(['lawn_care', 'ACELEPYN', '', null]));
        $this->assertContains('other', MiaServiceKey::keys());
    }

    public function testAcceptedAndConvertedAreWonAndDeclinedIsLost(): void
    {
        $this->assertTrue(MiaFunnelRules::isWon('accepted'));
        $this->assertTrue(MiaFunnelRules::isWon(' Converted '));
        $this->assertFalse(MiaFunnelRules::isWon('quoted'), 'quoted is not won even when converted_at is set');
        $this->assertFalse(MiaFunnelRules::isWon(null));
        $this->assertTrue(MiaFunnelRules::isLost('declined'));
        $this->assertSame("q.status IN ('accepted','converted')", MiaFunnelRules::wonSql('q.status'));
    }

    public function testTheFunnelSummaryMatchesTodaysProductionCounts(): void
    {
        $s = MiaFunnelRules::summarise(['new' => 4, 'reviewing' => 10, 'quoted' => 18, 'accepted' => 37, 'converted' => 2, 'declined' => 1]);
        $this->assertSame(['total' => 72, 'won' => 39, 'lost' => 1, 'open' => 32, 'win_rate' => 97.5], $s);
        $this->assertNull(MiaFunnelRules::summarise([])['win_rate']);
    }
}
