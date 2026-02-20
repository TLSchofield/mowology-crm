<?php
/**
 * jobFlow Lead Classification Helper
 *
 * Classifies leads by type, value tier, and priority.
 * Structured to allow future AI integration — the classifyJobType() function
 * is the hook point for an LLM call or ML model when ready.
 *
 * Current implementation: rule-based classification using deterministic logic.
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// Primary classification entry point (AI-extensible)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Classify the job type from submitted quote data.
 *
 * Return values (single label):
 *   'lawn'         — primarily lawn care / maintenance
 *   'hedge'        — primarily hedge trimming
 *   'fertilizer'   — fertilizer program
 *   'cleanup'      — one-time or seasonal cleanup
 *   'snow'         — snow removal
 *   'multi-service'— two or more distinct service categories
 *   'unknown'      — could not classify
 *
 * FUTURE INTEGRATION: Replace the body of this function with an API call to
 * an LLM (e.g., claude-haiku-4-5) using $data['description'] as context.
 * The return type (string) stays the same; callers need no changes.
 *
 * @param array $data  Validated quote data array from session
 * @return string      Job type label
 */
function classifyJobType(array $data): string {
    $services = (array)($data['service_types'] ?? []);

    if (empty($services)) return 'unknown';
    if (count($services) >= 2) return 'multi-service';

    $service = $services[0];
    $map = [
        'lawn_care'     => 'lawn',
        'maintenance'   => 'lawn',
        'hedge_trimming'=> 'hedge',
        'cleanup'       => 'cleanup',
        'snow_removal'  => 'snow',
    ];

    return $map[$service] ?? 'unknown';
}

// ─────────────────────────────────────────────────────────────────────────────
// Value tier
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Determine the estimated value tier of a lead.
 *
 * Tiers: 'high' | 'medium' | 'low'
 *
 * Factors:
 *  - Property type (commercial/strata > residential)
 *  - Lawn size (large > medium > small)
 *  - Irrigation (adds complexity = higher value)
 *  - Number of services selected
 *  - Urgency (asap = motivated buyer)
 *  - Upsell selections
 */
function classifyValueTier(array $data): string {
    $score = 0;

    // Property type
    $propType = $data['property_type'] ?? 'residential';
    if ($propType === 'commercial') $score += 3;
    elseif ($propType === 'strata')  $score += 2;
    else                             $score += 1;

    // Lawn size
    $lawnSize = $data['lawn_size'] ?? '';
    if ($lawnSize === 'large')       $score += 3;
    elseif ($lawnSize === 'medium')  $score += 2;
    elseif ($lawnSize === 'small')   $score += 1;

    // Irrigation
    if (!empty($data['has_irrigation'])) $score += 2;

    // Number of services
    $serviceCount = count((array)($data['service_types'] ?? []));
    $score += min($serviceCount, 3); // cap at 3 pts

    // Urgency
    if (($data['urgency'] ?? '') === 'asap') $score += 2;

    // Upsells selected
    $upsellCount = count((array)($data['upsells'] ?? []));
    $score += min($upsellCount, 2);

    if ($score >= 9)  return 'high';
    if ($score >= 5)  return 'medium';
    return 'low';
}

// ─────────────────────────────────────────────────────────────────────────────
// Priority flag
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Return true if this lead should be marked as priority for immediate follow-up.
 *
 * Priority criteria (any one triggers):
 *  - Value tier = 'high'
 *  - Urgency = 'asap'
 *  - Irrigated lawn (complexity = larger job)
 *  - Commercial or strata property
 */
function isHighPriorityLead(array $data): bool {
    if (classifyValueTier($data) === 'high') return true;
    if (($data['urgency'] ?? '') === 'asap') return true;
    if (!empty($data['has_irrigation'])) return true;
    $propType = $data['property_type'] ?? 'residential';
    if ($propType === 'commercial' || $propType === 'strata') return true;
    return false;
}

// ─────────────────────────────────────────────────────────────────────────────
// Suggested frequency
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Suggest an optimal service frequency based on lead attributes.
 *
 * Return values: 'weekly' | 'biweekly' | 'monthly' | 'one-time'
 */
function suggestFrequency(array $data): string {
    $services = (array)($data['service_types'] ?? []);
    $hasLawn  = !empty(array_intersect($services, ['lawn_care', 'maintenance']));

    if (!$hasLawn) {
        // Non-lawn services are typically one-time or seasonal
        return in_array('snow_removal', $services, true) ? 'seasonal' : 'one-time';
    }

    // Irrigated lawns grow faster
    if (!empty($data['has_irrigation'])) return 'weekly';

    $lawnSize = $data['lawn_size'] ?? '';
    if ($lawnSize === 'large') return 'weekly';

    return 'biweekly';
}

// ─────────────────────────────────────────────────────────────────────────────
// Full classification summary
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Return a complete classification summary for storing in the DB.
 *
 * @param array $data  Validated quote data
 * @return array {
 *   job_type: string,
 *   value_tier: string,
 *   is_priority: bool,
 *   suggested_frequency: string,
 *   tags: string[]
 * }
 */
function classifyLead(array $data): array {
    $jobType   = classifyJobType($data);
    $valueTier = classifyValueTier($data);
    $isPriority= isHighPriorityLead($data);
    $frequency = suggestFrequency($data);

    // Build tag list for CRM tagging
    $tags = [$jobType];
    $tags[] = $valueTier . '-value';
    if ($isPriority)  $tags[] = 'priority';
    if (!empty($data['has_irrigation'])) $tags[] = 'irrigated';
    if (!empty($data['upsells']))        $tags[] = 'upsell-interest';
    $propType = $data['property_type'] ?? 'residential';
    if ($propType !== 'residential')     $tags[] = $propType;

    return [
        'job_type'            => $jobType,
        'value_tier'          => $valueTier,
        'is_priority'         => $isPriority,
        'suggested_frequency' => $frequency,
        'tags'                => array_unique($tags),
    ];
}
