<?php
/**
 * Social Hashtag Engine
 *
 * Pure PHP, no DB dependency. Provides:
 *  - Service × location × season hashtag clusters
 *  - Seasonal caption hooks
 *  - Optimal posting time suggestions by day of week
 *
 * @package Mowology\Social
 */

declare(strict_types=1);

class SocialHashtagEngine
{
    // ── Hashtag clusters per service type ─────────────────────────────────
    // Each cluster: [branded, service-specific, local]
    private static $serviceHashtags = [
        'lawn_care' => [
            '#LawnCare', '#LawnMowing', '#GrassCutting', '#LawnService',
            '#VancouverLawns', '#LawnCareVancouver', '#GreenLawn',
        ],
        'maintenance' => [
            '#GardenMaintenance', '#YardMaintenance', '#PropertyMaintenance',
            '#VancouverGarden', '#GardenCare', '#YardCare',
        ],
        'cleanup' => [
            '#YardCleanup', '#GardenCleanup', '#PropertyCleanup',
            '#VancouverCleanup', '#CleanGarden', '#YardWork',
        ],
        'snow_removal' => [
            '#SnowRemoval', '#SnowClearing', '#WinterLandscaping',
            '#VancouverSnow', '#SnowService', '#IceRemoval',
        ],
        'hedge_trimming' => [
            '#HedgeTrimming', '#HedgeTrim', '#GardenTrimming',
            '#VancouverGarden', '#HedgePruning', '#ShrubTrimming',
        ],
        'landscaping' => [
            '#Landscaping', '#LandscapeDesign', '#GardenDesign',
            '#VancouverLandscaping', '#HardScaping', '#SoftScaping',
        ],
        'garden_maintenance' => [
            '#GardenMaintenance', '#GardenCare', '#FlowerBeds',
            '#VancouverGarden', '#GardeningService', '#PlantCare',
        ],
        'seasonal_cleanup' => [
            '#SeasonalCleanup', '#YardCleanup', '#GardenRenewal',
            '#VancouverLandscaping', '#SeasonalService', '#PropertyCare',
        ],
    ];

    // Hashtags always appended regardless of service type
    private static $brandHashtags = [
        '#Mowology', '#VancouverLandscaping', '#ProfessionalLandscaping',
        '#LandscapingVancouver',
    ];

    // ── Seasonal caption hooks ─────────────────────────────────────────────
    // Month number (1-12) → season → caption prefix
    private static $seasonHooks = [
        1  => ['season' => 'Winter',  'hook' => 'Keeping it tidy through the winter — '],
        2  => ['season' => 'Winter',  'hook' => 'Winter grounds care done right — '],
        3  => ['season' => 'Spring',  'hook' => 'Spring is here — '],
        4  => ['season' => 'Spring',  'hook' => 'Spring transformation complete — '],
        5  => ['season' => 'Spring',  'hook' => 'Spring refresh — '],
        6  => ['season' => 'Summer',  'hook' => 'Summer-ready — '],
        7  => ['season' => 'Summer',  'hook' => 'Summer in full swing — '],
        8  => ['season' => 'Summer',  'hook' => 'Late summer, looking sharp — '],
        9  => ['season' => 'Fall',    'hook' => 'Fall prep season — '],
        10 => ['season' => 'Fall',    'hook' => 'Fall cleanup in action — '],
        11 => ['season' => 'Fall',    'hook' => 'Ready for winter — '],
        12 => ['season' => 'Winter',  'hook' => 'Holiday curb appeal — '],
    ];

    // ── Best posting times by day of week ─────────────────────────────────
    // PHP date('N'): 1=Mon, 2=Tue, 3=Wed, 4=Thu, 5=Fri, 6=Sat, 7=Sun
    private static $bestPostTimes = [
        1 => 'Mon 11:00 AM',
        2 => 'Tue 10:00 AM',
        3 => 'Wed 10:00 AM',
        4 => 'Thu 10:00 AM',
        5 => 'Fri 9:00 AM',
        6 => 'Sat 8:00 AM',
        7 => 'Sun 9:00 AM',
    ];

    /**
     * Build hashtags and caption hook for a service type + location + season.
     *
     * @param string $serviceType  Slug e.g. 'lawn_care'
     * @param string $neighborhood e.g. 'Kitsilano'
     * @param string $city         e.g. 'Vancouver'
     * @param int    $month        1–12
     * @return array ['hashtags' => string, 'caption_hook' => string, 'season' => string]
     */
    public static function forServiceAndLocation(
        string $serviceType,
        string $neighborhood,
        string $city,
        int $month
    ): array {
        // Service-specific cluster (4-5 tags)
        $serviceTags = isset(self::$serviceHashtags[$serviceType])
            ? array_slice(self::$serviceHashtags[$serviceType], 0, 5)
            : ['#LandscapingService', '#Landscaping', '#YardWork', '#VancouverLandscaping'];

        // Neighbourhood tag: CamelCase without spaces
        $nbhTag = '';
        if ($neighborhood && strtolower($neighborhood) !== strtolower($city)) {
            $nbhCamel = str_replace(' ', '', ucwords(strtolower($neighborhood)));
            $nbhTag   = '#Vancouver' . $nbhCamel;
        }

        // Combine: service + brand + neighbourhood
        $allTags = array_merge($serviceTags, self::$brandHashtags);
        if ($nbhTag) {
            $allTags[] = $nbhTag;
        }

        // De-dupe
        $allTags = array_unique($allTags);

        $seasonData = isset(self::$seasonHooks[$month])
            ? self::$seasonHooks[$month]
            : ['season' => 'Summer', 'hook' => ''];

        return [
            'hashtags'     => implode(' ', $allTags),
            'caption_hook' => $seasonData['hook'],
            'season'       => $seasonData['season'],
        ];
    }

    /**
     * Return the best posting time string for a given day of week.
     *
     * @param int $dayOfWeek PHP date('N') value: 1=Mon … 7=Sun
     * @return string e.g. "Tue 10:00 AM"
     */
    public static function bestPostTime(int $dayOfWeek): string
    {
        return isset(self::$bestPostTimes[$dayOfWeek])
            ? self::$bestPostTimes[$dayOfWeek]
            : 'Tue 10:00 AM';
    }
}
