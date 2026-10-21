<?php
/**
 * British Columbia Employment Standards Act rules used to turn hours into gross pay (Penny Payroll step 2,
 * 2026-10-21). Read on gov.bc.ca on 2026-10-21; each entry names its page. Province facts shared by every
 * BC tenant. Reviewed with the regions skill (`.claude/skills/regions/CA-BC.md`).
 */
return [
    'province' => 'BC',
    'overtime' => [
        // ESA s.40 and s.1 "week": daily over 8 h 1.5×, over 12 h 2×; weekly over 40 h 1.5× counting only the
        // first 8 h of each day; the overtime week begins on Sunday. Hours worked on a stat count toward the
        // week (policy notes); where daily/stat and weekly overlap the greater rate applies, never both.
        'daily_regular_minutes' => 480, 'daily_time_and_half_minutes' => 720, 'weekly_regular_minutes' => 2400,
        'week_starts' => 'sunday',
        'source' => 'https://www2.gov.bc.ca/gov/content/employment-business/employment-standards-advice/employment-standards/forms-resources/igm/esa-part-4-section-40',
    ],
    'statutory_holidays' => [
        // 11 in 2026. Eligible: employed 30 calendar days before the holiday and earned wages on 15 of those 30.
        // Pay = wages in the 30 calendar days before ÷ days wages were earned (overtime excluded). Working the
        // holiday: 1.5× (2× over 12 h) plus that average day's pay. A day off that falls on one: the average day's pay.
        'dates' => ['2026-01-01', '2026-02-16', '2026-04-03', '2026-05-18', '2026-07-01', '2026-08-03', '2026-09-07',
                    '2026-09-30', '2026-10-12', '2026-11-11', '2026-12-25'],
        'lookback_days' => 30, 'min_employed_days' => 30, 'min_days_earned' => 15,
        'worked_rate' => 1.5, 'worked_rate_over_12' => 2.0,
        'source' => 'https://www2.gov.bc.ca/gov/content/employment-business/employment-standards-advice/employment-standards/statutory-holidays/calculate-statutory-holiday-pay',
    ],
    'vacation' => [
        // At least 4% of all wages (overtime and stat pay included); 6% from the 5th anniversary of employment.
        'rate' => 0.04, 'rate_after_years' => 5, 'rate_after' => 0.06,
        'source' => 'https://www2.gov.bc.ca/gov/content/employment-business/employment-standards-advice/employment-standards/time-off/vacation',
    ],
    'minimum_wage' => [
        // Indexed each 1 June; never decreases.
        ['from' => '2025-06-01', 'cents' => 1785],
        ['from' => '2026-06-01', 'cents' => 1825],
        'source' => 'https://www2.gov.bc.ca/gov/content/employment-business/employment-standards-advice/employment-standards/wages/minimum-wage',
    ],
];
