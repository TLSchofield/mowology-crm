<?php
/**
 * Service landing-page links — one list for the footer, the service-page
 * "Related services" block and article pages, so every landing page (file-based
 * under /services/*.php AND CMS-authored under slug services/*) is linked from
 * every page. Orphaned landing pages were the site's biggest SEO defect.
 *
 * Required: bootstrap.php loaded first (for getDB(), CMS_SITE_ID, h()).
 */

if (!function_exists('mw_serviceLinks')) {
    /**
     * @param string|null $excludeSlug bare slug (e.g. "hedge-trimming") to leave out
     * @return array<string, array{title:string, url:string, desc:string}> keyed by bare slug
     */
    function mw_serviceLinks(?string $excludeSlug = null): array
    {
        static $all = null;

        if ($all === null) {
            $all = [];

            // 1. File-based landing pages (includes/service-data/*.php return arrays)
            foreach (glob(__DIR__ . '/service-data/*.php') ?: [] as $file) {
                $data = include $file;
                if (!is_array($data) || empty($data['slug'])) {
                    continue;
                }
                $slug = basename((string)$data['slug']);
                $all[$slug] = [
                    'title' => (string)($data['title'] ?? $slug),
                    'url'   => '/services/' . $slug,
                    'desc'  => (string)($data['related_blurb'] ?? ($data['hero']['subheadline'] ?? '')),
                ];
            }

            // 2. CMS-authored landing pages (slug services/<x>), published and live
            if (function_exists('getDB')) {
                try {
                    $siteId = defined('CMS_SITE_ID') ? (int)CMS_SITE_ID : 1;
                    $now    = date('Y-m-d H:i:s');
                    // Pages authored by the CMS page generator store literal {{TOKENS}}
                    // (NEIGHBOURHOOD, CITY, PHONE...) in title / meta_description and
                    // only resolve them at render time. Reading the raw column here
                    // showed "{{NEIGHBOURHOOD}}, {{CITY}}" verbatim in every card that
                    // linked to such a page, so resolve them the way cms-renderer.php does.
                    $tokenFile = dirname(__DIR__) . '/crm/includes/cms-token-engine.php';
                    if (!function_exists('cms_buildTokenMap') && is_file($tokenFile)) {
                        require_once $tokenFile;
                    }
                    $stmt   = getDB()->prepare("
                        SELECT id, slug, title, meta_description
                          FROM cms_pages
                         WHERE site_id = ? AND slug LIKE 'services/%' AND status = 'published' AND noindex = 0
                           AND (publish_at IS NULL OR publish_at <= ?)
                           AND (unpublish_at IS NULL OR unpublish_at > ?)
                         ORDER BY title
                    ");
                    $stmt->execute([$siteId, $now, $now]);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $slug = basename((string)$row['slug']);
                        if (isset($all[$slug])) {
                            continue; // file-based page wins for the same URL
                        }
                        $title = (string)$row['title'];
                        $desc  = (string)($row['meta_description'] ?? '');
                        if (function_exists('cms_buildTokenMap') && function_exists('cms_resolveTokens')
                            && (strpos($title . $desc, '{{') !== false)) {
                            $tokenMap = cms_buildTokenMap((int)$row['id']);
                            $title = cms_resolveTokens($title, $tokenMap, false);
                            $desc  = cms_resolveTokens($desc, $tokenMap, false);
                        }
                        // A token the page never defined must never reach a visitor:
                        // drop the blurb rather than print "{{TRUST_STATEMENT}}".
                        if (strpos($desc, '{{') !== false) {
                            $desc = '';
                        }
                        if (strpos($title, '{{') !== false) {
                            $title = ucwords(str_replace('-', ' ', $slug));
                        }
                        $all[$slug] = [
                            'title' => $title,
                            'url'   => '/' . ltrim((string)$row['slug'], '/'),
                            'desc'  => $desc,
                        ];
                    }
                } catch (\Throwable $e) {
                    // CMS tables unavailable — the file-based list is still complete enough
                }
            }
            ksort($all);
        }

        if ($excludeSlug === null) {
            return $all;
        }
        $out = $all;
        unset($out[basename($excludeSlug)]);
        return $out;
    }
}
