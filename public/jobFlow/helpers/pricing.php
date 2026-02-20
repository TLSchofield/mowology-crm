<?php
/**
 * jobFlow Pricing Helper
 *
 * All pricing logic lives here. View files MUST NOT contain price constants or
 * calculations. When pricing changes, only this file needs updating.
 *
 * Prices are estimates in CAD, suitable for display in the funnel.
 * Final quotes are produced by the CRM — these are for conversion optimisation only.
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// Base pricing table
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns the pricing configuration for the funnel.
 * All values in CAD. Ranges represent [min, max] estimate.
 */
function getPricingConfig(): array {
    return [
        // Base service prices by lawn size (per visit)
        'lawn_care' => [
            'small'  => ['min' => 45,  'max' => 65],
            'medium' => ['min' => 65,  'max' => 95],
            'large'  => ['min' => 95,  'max' => 145],
            'default'=> ['min' => 65,  'max' => 95],
        ],
        'maintenance' => [
            'small'  => ['min' => 55,  'max' => 80],
            'medium' => ['min' => 80,  'max' => 120],
            'large'  => ['min' => 120, 'max' => 175],
            'default'=> ['min' => 80,  'max' => 120],
        ],
        'hedge_trimming' => [
            'small'  => ['min' => 80,  'max' => 120],
            'medium' => ['min' => 120, 'max' => 200],
            'large'  => ['min' => 200, 'max' => 350],
            'default'=> ['min' => 120, 'max' => 200],
        ],
        'cleanup' => [
            'small'  => ['min' => 150, 'max' => 250],
            'medium' => ['min' => 250, 'max' => 400],
            'large'  => ['min' => 400, 'max' => 650],
            'default'=> ['min' => 250, 'max' => 400],
        ],
        'snow_removal' => [
            'small'  => ['min' => 40,  'max' => 65],
            'medium' => ['min' => 65,  'max' => 95],
            'large'  => ['min' => 95,  'max' => 140],
            'default'=> ['min' => 65,  'max' => 95],
        ],

        // Upsell add-on prices (flat additions per visit or per service)
        'upsells' => [
            'weekly_cut'       => ['label' => 'Weekly Lawn Cut', 'price' => 0,   'note' => 'Included in maintenance plan'],
            'fertilizer'       => ['label' => 'Fertilizer Program', 'price' => 45, 'note' => 'Per application (3–4×/season)'],
            'hedge_addon'      => ['label' => 'Hedge Trimming Add-on', 'price' => 65, 'note' => 'Per visit'],
        ],

        // Irrigation surcharge
        'irrigation_surcharge' => 15, // added per lawn visit if has_irrigation

        // Seasonal adjustments (months are 1-12)
        'peak_season_months' => [3, 4, 5, 6],   // March–June: high demand
        'off_season_months'  => [11, 12, 1, 2], // Nov–Feb: mostly snow removal

        // Frequency discount rates
        'frequency_discount' => [
            'weekly'    => 0.10, // 10% off per-visit when weekly
            'biweekly'  => 0.05, // 5% off per-visit when biweekly
        ],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Estimate calculator
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Calculate a rough price estimate for display purposes.
 *
 * @param array $serviceTypes  List of selected service type keys
 * @param string $lawnSize     'small' | 'medium' | 'large' | ''
 * @param bool $hasIrrigation  Whether property has irrigation
 * @param array $upsells       List of selected upsell keys
 * @return array ['min' => int, 'max' => int, 'note' => string] or [] if not calculable
 */
function calculateEstimate(array $serviceTypes, string $lawnSize = '', bool $hasIrrigation = false, array $upsells = []): array {
    if (empty($serviceTypes)) return [];

    $config = getPricingConfig();
    $totalMin = 0;
    $totalMax = 0;

    foreach ($serviceTypes as $service) {
        if (!isset($config[$service])) continue;
        $tier = ($lawnSize !== '' && isset($config[$service][$lawnSize]))
            ? $config[$service][$lawnSize]
            : $config[$service]['default'];
        $totalMin += $tier['min'];
        $totalMax += $tier['max'];
    }

    // Irrigation surcharge (only applies to lawn services)
    $lawnServices = array_intersect($serviceTypes, ['lawn_care', 'maintenance']);
    if ($hasIrrigation && !empty($lawnServices)) {
        $totalMin += $config['irrigation_surcharge'];
        $totalMax += $config['irrigation_surcharge'];
    }

    // Upsell additions
    foreach ($upsells as $upsell) {
        if (isset($config['upsells'][$upsell]['price'])) {
            $totalMin += $config['upsells'][$upsell]['price'];
            $totalMax += $config['upsells'][$upsell]['price'];
        }
    }

    if ($totalMin === 0) return [];

    $note = 'Estimated per visit. Final quote may vary based on property assessment.';
    $month = (int)date('n');
    if (in_array($month, $config['peak_season_months'], true)) {
        $note = 'Peak season — early booking recommended. ' . $note;
    }

    return [
        'min'  => $totalMin,
        'max'  => $totalMax,
        'note' => $note,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Upsell renderer
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Return the upsell options relevant to the selected services.
 *
 * Logic:
 *  - weekly_cut: shown when lawn_care or maintenance selected
 *  - fertilizer: shown when lawn_care or maintenance selected
 *  - hedge_addon: shown when hedge_trimming is NOT already selected
 *
 * @param array $serviceTypes  Already-selected services
 * @return array Array of upsell definition arrays with extra context
 */
function getRelevantUpsells(array $serviceTypes): array {
    $config = getPricingConfig();
    $upsells = [];

    $hasLawn = !empty(array_intersect($serviceTypes, ['lawn_care', 'maintenance']));
    $hasHedge = in_array('hedge_trimming', $serviceTypes, true);

    if ($hasLawn) {
        $upsells['weekly_cut'] = array_merge($config['upsells']['weekly_cut'], [
            'key'         => 'weekly_cut',
            'badge'       => 'Most Popular',
            'description' => 'Upgrade to a weekly lawn maintenance plan for a consistently perfect lawn all season.',
        ]);
        $upsells['fertilizer'] = array_merge($config['upsells']['fertilizer'], [
            'key'         => 'fertilizer',
            'badge'       => '',
            'description' => 'Professional fertilizer applications timed to Vancouver\'s growing season. Greener lawn, less weeding.',
        ]);
    }

    if (!$hasHedge) {
        $upsells['hedge_addon'] = array_merge($config['upsells']['hedge_addon'], [
            'key'         => 'hedge_addon',
            'badge'       => 'Bundle & Save',
            'description' => 'Add professional hedge trimming to your service. Tidy, shaped hedges every visit.',
        ]);
    }

    return $upsells;
}

// ─────────────────────────────────────────────────────────────────────────────
// Seasonal messaging
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Return an urgency/seasonal message to display in the funnel.
 * Returns null if no message is relevant this time of year.
 */
function getSeasonalMessage(): ?array {
    $month = (int)date('n');

    // April / May — spring rush
    if ($month === 4 || $month === 5) {
        return [
            'type'    => 'urgency',
            'icon'    => '🌱',
            'heading' => 'Spring is our busiest season',
            'body'    => 'April and May fill up fast. Secure your spot now — most new clients book within 48 hours of their quote.',
        ];
    }

    // March — early spring prep
    if ($month === 3) {
        return [
            'type'    => 'tip',
            'icon'    => '🌤',
            'heading' => 'Get ahead of spring',
            'body'    => 'Early March is the best time to lock in a lawn care plan before the rush hits. Spots are still available.',
        ];
    }

    // June — summer peak
    if ($month === 6) {
        return [
            'type'    => 'urgency',
            'icon'    => '☀️',
            'heading' => 'Limited summer availability',
            'body'    => 'We\'re booking into late June. Submit your request today for priority scheduling.',
        ];
    }

    // October — fall cleanup
    if ($month === 10) {
        return [
            'type'    => 'tip',
            'icon'    => '🍂',
            'heading' => 'Fall cleanup season is here',
            'body'    => 'Book your fall cleanup now before the leaves drop. We\'ll have your property looking great heading into winter.',
        ];
    }

    // November/December — snow season
    if ($month === 11 || $month === 12) {
        return [
            'type'    => 'tip',
            'icon'    => '❄️',
            'heading' => 'Snow removal season approaching',
            'body'    => 'Lock in your snow removal plan before the first snowfall. Pre-season pricing available.',
        ];
    }

    return null;
}

/**
 * Return a response-time trust message.
 */
function getResponseTimeBadge(): string {
    $hour = (int)date('G'); // 0-23
    $day  = (int)date('N'); // 1=Mon, 7=Sun

    if ($day >= 1 && $day <= 5 && $hour >= 8 && $hour <= 18) {
        return 'We typically respond within 2–4 hours during business hours.';
    }
    return 'We respond to all requests within 24 hours — usually same day on weekdays.';
}
