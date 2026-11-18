<?php
declare(strict_types=1);

/**
 * Real before/after pairs for a service landing page.
 *
 * Pulls the manager-approved pairs (BeforeAfterService — crew endorse → manager approves)
 * that match the page's categories and shapes them for the template's 'before_after'
 * section. Returns null when there is nothing to show, so the page simply omits the
 * section rather than rendering an empty grid. Never throws: a landing page must render
 * even if the CRM database is unavailable.
 *
 * Usage, before requiring service-template.php:
 *   $ba = serviceBeforeAfterSection(['lawn', 'garden'], 'Recent Lawn Work in Vancouver');
 *   if ($ba) { $service['proof_sections'][] = $ba; }
 *
 * @param string[] $categories  BeforeAfterService::CATEGORIES values, in preference order
 */
function serviceBeforeAfterSection(array $categories, string $heading = 'Real Results From Our Crews', int $limit = 4): ?array
{
    try {
        if (!defined('APP_ROOT') || !function_exists('getDB')) {
            return null;
        }
        require_once APP_ROOT . '/Modules/Portfolio/Services/BeforeAfterService.php';
        $pairs = (new BeforeAfterService(getDB()))->published(40);
    } catch (Throwable $e) {
        return null;
    }

    $wanted = array_values(array_intersect($categories, BeforeAfterService::CATEGORIES));
    $rank   = array_flip($wanted);
    $picked = [];
    foreach ($pairs as $p) {
        if (!isset($rank[$p['category']])) {
            continue;
        }
        $picked[] = $p;
    }
    usort($picked, static fn (array $a, array $b): int => $rank[$a['category']] <=> $rank[$b['category']]);
    $picked = array_slice($picked, 0, max(1, $limit));
    if (!$picked) {
        return null;
    }

    return [
        'type'    => 'before_after',
        'heading' => $heading,
        'pairs'   => array_map(static fn (array $p): array => [
            'before'  => $p['before_url'],
            'after'   => $p['after_url'],
            'caption' => trim($p['label'] . ($p['date'] ? ' · ' . $p['date'] : '')),
        ], $picked),
    ];
}
