<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Mia (D4, 2026-10-15): themes ranked by the work they brought around this time of year, not by likes. */
class MiaThemeOutcomesTest extends TestCase
{
    private function today(): DateTimeImmutable { return new DateTimeImmutable('2026-10-15'); }

    private static function win(string $type, string $at, bool $attributed = false): array
    {
        return ['service_type' => $type, 'at' => $at, 'attributed' => $attributed];
    }

    public function testSeasonAndAgeWeights(): void
    {
        $t = $this->today();
        $this->assertSame(1.0, MiaThemeOutcomes::weight('2026-10-01', $t));
        $this->assertSame(0.5, MiaThemeOutcomes::weight('2025-10-20', $t->modify('+1 year')), 'the year before counts half');
        $this->assertSame(0.0, MiaThemeOutcomes::weight('2026-06-01', $t), 'out of season');
        $this->assertSame(0.0, MiaThemeOutcomes::weight('2023-10-15', $t), 'too old');
        $this->assertSame(1.0, MiaThemeOutcomes::weight('2026-01-02', new DateTimeImmutable('2026-12-20')) , 'across new year');
    }

    public function testTheThemeThatSoldLeadsOnlyWithEnoughWins(): void
    {
        $themes = MiaSeasonThemes::all();
        $wins = [
            self::win('irrigation_winterization', '2026-10-05'), self::win('irrigation', '2026-10-06'), self::win('Irrigation blowout', '2026-10-07', true),
            self::win('leaf cleanup', '2026-10-08'),
        ];
        $s = MiaThemeOutcomes::scores($wins, $themes, $this->today());
        $this->assertSame(['irrigation_blowout' => 1.5], $s, 'irrigation has 4 weighted wins; leaves has 1 (below the minimum)');
        $this->assertSame([], MiaThemeOutcomes::scores(array_slice($wins, 0, 2), $themes, $this->today()), 'two wins are not enough');
    }

    public function testAWinIsSharedBetweenTheThemesItFits(): void
    {
        // "maintenance" fits several themes: three such wins give none of them three wins each.
        $wins = [self::win('maintenance', '2026-10-01'), self::win('maintenance', '2026-10-02'), self::win('maintenance', '2026-10-03')];
        $this->assertSame([], MiaThemeOutcomes::scores($wins, MiaSeasonThemes::all(), $this->today()));
    }

    public function testEquallyGoodThemesBothScore(): void
    {
        // Production, Oct 2026: 24 snow contracts accepted, shared by the two snow themes.
        $wins = array_fill(0, 24, self::win('snow_removal', '2026-10-10'));
        $s = MiaThemeOutcomes::scores($wins, MiaSeasonThemes::all(), $this->today());
        $this->assertSame(['snow_salt_contracts' => 1.5, 'winter_quiet' => 1.5], $s);
    }

    public function testLeapYearEdge(): void
    {
        // 2027-11-16 → 2028-01-01 is 46 days (2028 is a leap year only after Feb; the gap is real days).
        $this->assertSame(0.0, MiaThemeOutcomes::weight('2027-11-16', new DateTimeImmutable('2028-01-01')));
        $this->assertSame(1.0, MiaThemeOutcomes::weight('2027-11-17', new DateTimeImmutable('2028-01-01')));
        $this->assertSame(0.5, MiaThemeOutcomes::weight('2024-02-29', new DateTimeImmutable('2025-03-01')), 'Feb 29 maps to Feb 28; over a year old = half');
    }

    public function testEngagementOnlyNudges(): void
    {
        $b = MiaThemeOutcomes::blend(['irrigation_blowout' => 1.0], ['irrigation_blowout' => 1.5, 'leaf_cleanup' => 1.5]);
        $this->assertSame(['irrigation_blowout' => 1.0, 'leaf_cleanup' => 0.5], $b);
    }
}
