<?php
/**
 * Page Generator Engine - Template-Driven Landing Page Generation
 *
 * Handles substitution of variables into block templates and page generation.
 * Supports service + neighbourhood substitution with smart defaults and validation.
 *
 * Uses cms_savePage() and cms_saveBlock() from cms-functions.php for all DB writes.
 *
 * Migrated from: public/crm/includes/page-generator.php
 *
 * @package Mowology CRM
 * @subpackage CMS Phase 4
 */

declare(strict_types=1);

// ============================================================================
// GENERATOR CONFIG QUERIES
// ============================================================================

/**
 * Get all available generator configurations.
 *
 * @param bool $enabledOnly Return only enabled configs
 * @return array Array of configurations with decoded config_data
 */
function pg_getGeneratorConfigs(bool $enabledOnly = true): array
{
    $db = getDB();
    $where = $enabledOnly ? "WHERE enabled = 1" : "";
    $stmt = $db->query("
        SELECT id, config_key, config_label, config_data, enabled, created_at
        FROM cms_page_generator_config
        $where
        ORDER BY config_label ASC
    ");

    return array_map(function ($row) {
        $row['config_data'] = json_decode($row['config_data'], true) ?: [];
        return $row;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/**
 * Get specific generator configuration by key.
 *
 * @param string $configKey Configuration key
 * @return array|null Configuration data or null
 */
function pg_getGeneratorConfig(string $configKey): ?array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, config_key, config_label, config_data, enabled
        FROM cms_page_generator_config
        WHERE config_key = ?
    ");
    $stmt->execute([$configKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $row['config_data'] = json_decode($row['config_data'], true) ?: [];
    return $row;
}

// ============================================================================
// SERVICE & NEIGHBOURHOOD MAPS
// ============================================================================

/**
 * Get available service choices for page generation.
 *
 * @return array<string, string> service_key => display label
 */
function pg_getServices(): array
{
    return [
        'lawn-care'           => 'Lawn Care',
        'lawn-mowing'         => 'Lawn Mowing',
        'garden-maintenance'  => 'Garden Maintenance',
        'hedge-trimming'      => 'Hedge Trimming',
        'spring-cleanup'      => 'Spring Cleanup',
        'fall-cleanup'        => 'Fall Cleanup',
        'seasonal-cleanup'    => 'Seasonal Cleanup',
        'strata-landscaping'  => 'Strata Landscaping',
        'irrigation'          => 'Irrigation & Drainage',
        'hardscaping'         => 'Hardscaping',
        'garden-design'       => 'Garden Design',
        'landscape-design'    => 'Landscape Design',
        'turf-installation'   => 'Turf Installation',
        'tree-service'        => 'Tree & Shrub Care',
        'pressure-washing'    => 'Pressure Washing',
        'snow-removal'        => 'Snow Removal',
        'landscaping'         => 'Landscaping',
        'maintenance'         => 'Property Maintenance',
    ];
}

/**
 * Get available neighbourhood choices.
 *
 * Tries to load from properties database first,
 * falls back to a hardcoded list of Greater Vancouver neighbourhoods.
 *
 * @return array<string, string> slug => display label
 */
function pg_getNeighbourhoods(): array
{
    // Try to load from properties database
    try {
        $db = getDB();
        $stmt = $db->query("
            SELECT DISTINCT p.neighbourhood
            FROM properties p
            WHERE p.neighbourhood IS NOT NULL AND p.neighbourhood != ''
            ORDER BY p.neighbourhood ASC
        ");

        $fromDb = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = strtolower(str_replace(' ', '-', trim($row['neighbourhood'])));
            if ($key !== '') {
                $fromDb[$key] = $row['neighbourhood'];
            }
        }

        if (!empty($fromDb)) {
            // Merge DB entries with hardcoded list (DB entries take priority)
            return array_merge(pg_getDefaultNeighbourhoods(), $fromDb);
        }
    } catch (Exception $e) {
        // DB query failed — use hardcoded fallback
    }

    return pg_getDefaultNeighbourhoods();
}

/**
 * Hardcoded Greater Vancouver neighbourhood list.
 *
 * @return array<string, string>
 */
function pg_getDefaultNeighbourhoods(): array
{
    return [
        // Vancouver
        'kitsilano'           => 'Kitsilano',
        'kerrisdale'          => 'Kerrisdale',
        'dunbar'              => 'Dunbar',
        'point-grey'          => 'Point Grey',
        'shaughnessy'         => 'Shaughnessy',
        'south-granville'     => 'South Granville',
        'east-vancouver'      => 'East Vancouver',
        'mount-pleasant'      => 'Mount Pleasant',
        'hastings-sunrise'    => 'Hastings-Sunrise',
        'renfrew-collingwood' => 'Renfrew-Collingwood',
        'riley-park'          => 'Riley Park',
        'south-cambie'        => 'South Cambie',
        'marpole'             => 'Marpole',
        'oakridge'            => 'Oakridge',
        'sunset'              => 'Sunset',
        // Greater Vancouver
        'north-vancouver'     => 'North Vancouver',
        'west-vancouver'      => 'West Vancouver',
        'burnaby'             => 'Burnaby',
        'new-westminster'     => 'New Westminster',
        'richmond'            => 'Richmond',
        'coquitlam'           => 'Coquitlam',
        'port-coquitlam'      => 'Port Coquitlam',
        'port-moody'          => 'Port Moody',
        'surrey'              => 'Surrey',
        'delta'               => 'Delta',
        'langley'             => 'Langley',
        'white-rock'          => 'White Rock',
    ];
}

// ============================================================================
// PAGE GENERATION
// ============================================================================

/**
 * Generate a page from template configuration.
 *
 * Uses cms_savePage() and cms_saveBlock() for all database writes.
 *
 * @param array $config   Generator config_data (decoded JSON from cms_page_generator_config)
 * @param array $variables Substitution variables: ['service' => 'lawn-care', 'neighbourhood' => 'burnaby']
 * @param int   $userId   User ID creating the page
 * @return array Result with keys: success, page_id, page_slug, edit_url, message, errors
 */
function pg_generatePage(array $config, array $variables, int $userId): array
{
    try {
        // Validate variables match template requirements
        $requiredVars = $config['required_variables'] ?? [];
        $missingVars = array_diff($requiredVars, array_keys(array_filter($variables)));
        if (!empty($missingVars)) {
            return [
                'success' => false,
                'errors' => ['Missing required variables: ' . implode(', ', $missingVars)],
            ];
        }

        // Build human-readable variable labels for substitution
        $services = pg_getServices();
        $neighbourhoods = pg_getNeighbourhoods();
        $serviceLabel = $services[$variables['service'] ?? ''] ?? ucwords(str_replace('-', ' ', $variables['service'] ?? ''));
        $neighbourhoodLabel = $neighbourhoods[$variables['neighbourhood'] ?? ''] ?? ucwords(str_replace('-', ' ', $variables['neighbourhood'] ?? ''));

        // Replacement map: {variable} => Human-readable label
        $labelReplacements = [
            'service'       => $serviceLabel,
            'neighbourhood' => $neighbourhoodLabel,
        ];

        // Generate page title
        $titleTemplate = $config['title_template'] ?? '{service} in {neighbourhood}';
        $pageTitle = pg_substituteVariables($titleTemplate, $labelReplacements);

        // Generate slug (from the slug template, using slug-safe values)
        $slugTemplate = $config['slug_template'] ?? '{service}-{neighbourhood}';
        $slugReplacements = [
            'service'       => $variables['service'] ?? 'service',
            'neighbourhood' => $variables['neighbourhood'] ?? 'area',
        ];
        $pageSlug = pg_substituteVariables($slugTemplate, $slugReplacements);
        $pageSlug = preg_replace('/[^a-z0-9\-\/]/', '-', strtolower($pageSlug));
        $pageSlug = preg_replace('/-+/', '-', $pageSlug);
        $pageSlug = trim($pageSlug, '-');

        // Prepend services/ for service landing pages
        $pageType = $config['page_type'] ?? 'service_landing';
        if ($pageType === 'service_landing' && strpos($pageSlug, 'services/') !== 0) {
            $pageSlug = 'services/' . $pageSlug;
        }

        // Generate meta description
        $descTemplate = $config['meta_description_template'] ?? '';
        $metaDescription = pg_substituteVariables($descTemplate, $labelReplacements);

        // Create the page using cms_savePage()
        $pageData = [
            'slug'             => $pageSlug,
            'title'            => $pageTitle,
            'meta_title'       => $pageTitle,
            'meta_description' => $metaDescription,
            'page_type'        => $pageType,
            'layout_template'  => 'service_landing',
            'status'           => 'draft',
            'noindex'          => 0,
        ];

        $pageId = cms_savePage($pageData, null, $userId);

        // Create blocks from template using cms_saveBlock()
        $blocks = $config['blocks'] ?? [];
        foreach ($blocks as $position => $blockDef) {
            $blockType = $blockDef['block_type'] ?? 'rich_text';
            $blockConfig = $blockDef['config'] ?? [];
            $blockContent = $blockDef['content'] ?? '';

            // Substitute variables in config recursively
            $blockConfig = pg_substituteInArray($blockConfig, $labelReplacements);

            // Handle rich_text content → goes into config['content']
            if (is_string($blockContent) && $blockContent !== '') {
                $blockContent = pg_substituteVariables($blockContent, $labelReplacements);
                if ($blockType === 'rich_text') {
                    $blockConfig['content'] = $blockContent;
                }
            }

            cms_saveBlock($pageId, $blockType, $position, $blockConfig);
        }

        // Set page-level token overrides (if token engine is available)
        if (function_exists('cms_savePageTokens')) {
            $pageTokens = [];
            if (!empty($serviceLabel)) {
                $pageTokens['SERVICE'] = $serviceLabel;
                $pageTokens['SERVICE_SLUG'] = strtolower(str_replace(' ', '-', $serviceLabel));
                $pageTokens['PRIMARY_KEYWORD'] = strtolower($serviceLabel);
                $pageTokens['CTA_URL'] = '/quote?service=' . urlencode($variables['service'] ?? '')
                    . '&area=' . urlencode($variables['neighbourhood'] ?? '')
                    . '&src=' . urlencode($pageSlug);
            }
            if (!empty($neighbourhoodLabel)) {
                $pageTokens['NEIGHBOURHOOD'] = $neighbourhoodLabel;
            }
            if (!empty($pageTokens)) {
                cms_savePageTokens($pageId, $pageTokens);
            }
        }

        // Mark page as template-generated (if columns exist)
        try {
            $db = getDB();
            $db->prepare("
                UPDATE cms_pages
                SET is_template_generated = 1,
                    template_source_key = ?,
                    generated_variables = ?
                WHERE id = ?
            ")->execute([
                $config['config_key'] ?? '',
                json_encode($variables),
                $pageId,
            ]);
        } catch (PDOException $e) {
            // Columns may not exist — non-critical
        }

        // Log generation
        pg_logGeneration($pageId, $config, $variables, $userId);

        return [
            'success'   => true,
            'page_id'   => $pageId,
            'page_slug' => $pageSlug,
            'edit_url'  => '/crm/cms/cms-page-edit.php?id=' . $pageId,
            'message'   => "Page generated successfully: {$pageTitle}",
        ];

    } catch (Exception $e) {
        error_log('pg_generatePage failed: ' . $e->getMessage());
        return [
            'success' => false,
            'errors'  => ['Generation failed: ' . $e->getMessage()],
        ];
    }
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Substitute {variable} placeholders in a template string.
 *
 * @param string $template Template string with {variable} placeholders
 * @param array  $variables Associative array of variable => value
 * @return string Substituted string
 */
function pg_substituteVariables(string $template, array $variables): string
{
    $output = $template;
    foreach ($variables as $key => $value) {
        $output = str_replace('{' . $key . '}', (string)$value, $output);
    }
    // Remove any unreplaced placeholders
    $output = preg_replace('/\{[^}]+\}/', '', $output);
    return trim($output);
}

/**
 * Recursively substitute {variable} placeholders in array structures.
 *
 * @param mixed $data      Array or scalar to process
 * @param array $variables Variables to substitute
 * @return mixed Processed data
 */
function pg_substituteInArray($data, array $variables)
{
    if (is_array($data)) {
        return array_map(fn($item) => pg_substituteInArray($item, $variables), $data);
    }
    if (is_string($data)) {
        return pg_substituteVariables($data, $variables);
    }
    return $data;
}

/**
 * Substitute variables in block content for backward compatibility.
 *
 * @param array $blockConfig Block configuration with content/config keys
 * @param array $variables   Variables to substitute
 * @return array Block content with substituted variables
 */
function pg_substituteBlockContent(array $blockConfig, array $variables): array
{
    $config = $blockConfig['config'] ?? [];
    $content = $blockConfig['content'] ?? '';

    if (!empty($content) && is_string($content)) {
        $content = pg_substituteVariables($content, $variables);
    }
    $config = pg_substituteInArray($config, $variables);

    return [
        'block_type' => $blockConfig['block_type'] ?? 'rich_text',
        'content'    => $content,
        'config'     => $config,
    ];
}

/**
 * Log a page generation event to cms_page_generations_log.
 *
 * @param int   $pageId    Generated page ID
 * @param array $config    Generator config_data
 * @param array $variables Variables used for generation
 * @param int   $userId    User who triggered generation
 */
function pg_logGeneration(int $pageId, array $config, array $variables, int $userId): void
{
    try {
        $db = getDB();

        // Look up the generator config ID
        $configKey = $config['config_key'] ?? '';
        $configId = null;
        if ($configKey) {
            $stmt = $db->prepare("SELECT id FROM cms_page_generator_config WHERE config_key = ? LIMIT 1");
            $stmt->execute([$configKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $configId = $row ? (int)$row['id'] : null;
        }

        if ($configId) {
            $db->prepare("
                INSERT INTO cms_page_generations_log
                (page_id, generator_config_id, variables, generated_at, generated_by)
                VALUES (?, ?, ?, NOW(), ?)
            ")->execute([
                $pageId,
                $configId,
                json_encode($variables),
                $userId,
            ]);
        }
    } catch (PDOException $e) {
        // Table may not exist — non-critical
        error_log('pg_logGeneration: ' . $e->getMessage());
    }
}

/**
 * Save or update generator configuration.
 *
 * @param array $config Config with keys: config_key, config_label, config_data, enabled
 * @return array Result: ['success' => bool, 'id' => int, 'message' => string]
 */
function pg_saveGeneratorConfig(array $config): array
{
    try {
        $db = getDB();

        $configKey = $config['config_key'] ?? '';
        $configLabel = $config['config_label'] ?? '';
        $configData = $config['config_data'] ?? [];
        $enabled = $config['enabled'] ?? true;

        if (empty($configKey) || empty($configLabel)) {
            return ['success' => false, 'message' => 'Missing config_key or config_label'];
        }

        if (!isset($configData['page_type'], $configData['blocks']) || !is_array($configData['blocks'])) {
            return ['success' => false, 'message' => 'Invalid config_data structure'];
        }

        $dataJson = json_encode($configData, JSON_UNESCAPED_SLASHES);

        // Check if exists
        $stmt = $db->prepare("SELECT id FROM cms_page_generator_config WHERE config_key = ? LIMIT 1");
        $stmt->execute([$configKey]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $db->prepare("
                UPDATE cms_page_generator_config
                SET config_label = ?, config_data = ?, enabled = ?, updated_at = NOW()
                WHERE config_key = ?
            ")->execute([$configLabel, $dataJson, $enabled ? 1 : 0, $configKey]);
            $id = (int)$existing['id'];
        } else {
            $db->prepare("
                INSERT INTO cms_page_generator_config
                (config_key, config_label, config_data, enabled, created_at, updated_at)
                VALUES (?, ?, ?, ?, NOW(), NOW())
            ")->execute([$configKey, $configLabel, $dataJson, $enabled ? 1 : 0]);
            $id = (int)$db->lastInsertId();
        }

        return ['success' => true, 'id' => $id, 'message' => 'Generator configuration saved'];

    } catch (Exception $e) {
        error_log('pg_saveGeneratorConfig: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to save: ' . $e->getMessage()];
    }
}

/**
 * Get generation history for analytics.
 *
 * @param int $limit  Maximum records
 * @param int $offset Pagination offset
 * @return array Generation log records
 */
function pg_getGenerationHistory(int $limit = 50, int $offset = 0): array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT
                l.id, l.page_id, l.generator_config_id, l.variables,
                l.generated_at, l.generated_by,
                p.title, p.slug, c.config_label
            FROM cms_page_generations_log l
            LEFT JOIN cms_pages p ON l.page_id = p.id
            LEFT JOIN cms_page_generator_config c ON l.generator_config_id = c.id
            ORDER BY l.generated_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);

        return array_map(function ($row) {
            $row['variables'] = json_decode($row['variables'], true);
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

    } catch (Exception $e) {
        error_log('pg_getGenerationHistory: ' . $e->getMessage());
        return [];
    }
}
