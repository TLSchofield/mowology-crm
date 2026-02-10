<?php
/**
 * Page Generator Engine - Template-Driven Landing Page Generation
 *
 * Handles substitution of variables into block templates and page generation.
 * Supports service + neighbourhood substitution with smart defaults and validation.
 *
 * @package Mowology CRM
 * @subpackage CMS Phase 4
 */

declare(strict_types=1);

// ============================================================================
// PAGE GENERATION FUNCTIONS
// ============================================================================

/**
 * Generate a page from template configuration
 *
 * @param array $config Generator configuration from cms_page_generator_config
 * @param array $variables Substitution variables: ['service' => 'lawn-care', 'neighbourhood' => 'burnaby']
 * @param int $userId User ID creating the page
 * @return array Result: ['success' => bool, 'page_id' => int, 'message' => string, 'errors' => string[]]
 */
function pg_generatePage(array $config, array $variables, int $userId): array
{
    try {
        $db = getDB();

        // Validate variables match template requirements
        $requiredVars = $config['required_variables'] ?? [];
        $missingVars = array_diff($requiredVars, array_keys($variables));
        if (!empty($missingVars)) {
            return [
                'success' => false,
                'errors' => ['Missing required variables: ' . implode(', ', $missingVars)]
            ];
        }

        // Sanitize variables
        $cleanVars = [];
        foreach ($variables as $key => $value) {
            $cleanVars[$key] = preg_replace('/[^a-z0-9\-_]/', '', strtolower($value ?? ''));
        }

        // Generate page title and slug
        $titleTemplate = $config['title_template'] ?? 'Generated Page';
        $pageTitle = pg_substituteVariables($titleTemplate, $cleanVars);

        $slugTemplate = $config['slug_template'] ?? 'generated-page-{service}-{neighbourhood}';
        $pageSlug = pg_substituteVariables($slugTemplate, $cleanVars);
        $pageSlug = preg_replace('/[^a-z0-9\-]/', '-', $pageSlug);
        $pageSlug = preg_replace('/-+/', '-', $pageSlug);
        $pageSlug = trim($pageSlug, '-');

        // Check for slug collision
        $existing = $db->prepare("SELECT id FROM cms_pages WHERE slug = ?")->execute([$pageSlug])->fetch();
        if ($existing) {
            // Append timestamp to make unique
            $pageSlug .= '-' . time();
        }

        // Generate page description
        $descTemplate = $config['meta_description_template'] ?? 'Professional services in {neighbourhood}';
        $pageDescription = pg_substituteVariables($descTemplate, $cleanVars);

        // Create page record
        $stmt = $db->prepare("
            INSERT INTO cms_pages (
                slug, title, page_type, status, meta_title, meta_description,
                is_template_generated, template_source_key, generated_variables,
                auto_seo_enabled, created_by, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, NOW(), NOW()
            )
        ");

        $generatedVars = json_encode($cleanVars, JSON_UNESCAPED_SLASHES);
        $stmt->execute([
            $pageSlug,
            $pageTitle,
            $config['page_type'] ?? 'service_landing',
            'draft',
            $pageTitle,
            $pageDescription,
            true,
            $config['config_key'],
            $generatedVars,
            true,
            $userId
        ]);

        $pageId = (int)$db->lastInsertId();

        // Generate blocks from template
        $blocks = $config['blocks'] ?? [];
        $blockOrder = 1;

        foreach ($blocks as $blockConfig) {
            $blockType = $blockConfig['block_type'] ?? 'rich_text';
            $blockContent = pg_substituteBlockContent($blockConfig, $cleanVars);

            $blockStmt = $db->prepare("
                INSERT INTO cms_blocks (
                    page_id, block_type, config, block_order, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, NOW(), NOW()
                )
            ");

            $blockStmt->execute([
                $pageId,
                $blockType,
                json_encode($blockContent, JSON_UNESCAPED_SLASHES),
                $blockOrder
            ]);

            $blockOrder++;
        }

        // Log generation
        $logStmt = $db->prepare("
            INSERT INTO cms_page_generations_log (
                page_id, generator_config_id, variables, generated_by, generated_at
            ) VALUES (
                ?, ?, ?, ?, NOW()
            )
        ");

        $configIdResult = $db->prepare("SELECT id FROM cms_page_generator_config WHERE config_key = ?")
            ->execute([$config['config_key']])
            ->fetch();

        if ($configIdResult) {
            $logStmt->execute([
                $pageId,
                $configIdResult['id'],
                $generatedVars,
                $userId
            ]);
        }

        return [
            'success' => true,
            'page_id' => $pageId,
            'page_slug' => $pageSlug,
            'message' => "Page generated successfully: $pageTitle"
        ];

    } catch (Exception $e) {
        error_log("Page generation failed: " . $e->getMessage());
        return [
            'success' => false,
            'errors' => ['Generation failed: ' . $e->getMessage()]
        ];
    }
}

/**
 * Substitute variables in template string
 *
 * Template syntax: {variable_name}
 * Example: "Lawn Care in {neighbourhood}" → "Lawn Care in Burnaby"
 *
 * @param string $template Template string with {variable} placeholders
 * @param array $variables Associative array of variables
 * @return string Substituted string
 */
function pg_substituteVariables(string $template, array $variables): string
{
    $output = $template;

    foreach ($variables as $key => $value) {
        $output = str_replace('{' . $key . '}', $value, $output);
    }

    // Remove any unreplaced placeholders
    $output = preg_replace('/\{[^}]+\}/', '', $output);

    return $output;
}

/**
 * Substitute variables in block content (handle nested structures)
 *
 * @param array $blockConfig Block configuration with content/config keys
 * @param array $variables Variables to substitute
 * @return array Block content with substituted variables
 */
function pg_substituteBlockContent(array $blockConfig, array $variables): array
{
    $blockType = $blockConfig['block_type'] ?? 'rich_text';
    $config = $blockConfig['config'] ?? [];
    $content = $blockConfig['content'] ?? '';

    // Substitute in content
    if (!empty($content)) {
        $content = pg_substituteVariables($content, $variables);
    }

    // Substitute in config (handles nested arrays and objects)
    $config = pg_substituteInArray($config, $variables);

    return [
        'block_type' => $blockType,
        'content' => $content,
        'config' => $config
    ];
}

/**
 * Recursively substitute variables in array/object structure
 *
 * @param mixed $data Array or scalar to process
 * @param array $variables Variables to substitute
 * @return mixed Processed data with substitutions
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
 * Get all available generator configurations
 *
 * @param bool $enabledOnly Return only enabled configs
 * @return array Array of configurations
 */
function pg_getGeneratorConfigs(bool $enabledOnly = true): array
{
    $db = getDB();
    $where = $enabledOnly ? "WHERE enabled = TRUE" : "";
    $stmt = $db->query("
        SELECT id, config_key, config_label, config_data, enabled, created_at
        FROM cms_page_generator_config
        $where
        ORDER BY config_label ASC
    ");

    return array_map(function($row) {
        $row['config_data'] = json_decode($row['config_data'], true);
        return $row;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/**
 * Get specific generator configuration by key
 *
 * @param string $configKey Configuration key
 * @return ?array Configuration data or null
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

    $row['config_data'] = json_decode($row['config_data'], true);
    return $row;
}

/**
 * Save or update generator configuration
 *
 * @param array $config Configuration array with keys: config_key, config_label, config_data, enabled
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

        // Validate config_data structure
        if (!isset($configData['page_type'], $configData['blocks']) || !is_array($configData['blocks'])) {
            return ['success' => false, 'message' => 'Invalid config_data structure'];
        }

        $dataJson = json_encode($configData, JSON_UNESCAPED_SLASHES);

        // Check if exists
        $existing = $db->prepare("SELECT id FROM cms_page_generator_config WHERE config_key = ?")
            ->execute([$configKey])
            ->fetch();

        if ($existing) {
            // Update
            $stmt = $db->prepare("
                UPDATE cms_page_generator_config
                SET config_label = ?, config_data = ?, enabled = ?, updated_at = NOW()
                WHERE config_key = ?
            ");
            $stmt->execute([$configLabel, $dataJson, $enabled ? 1 : 0, $configKey]);
            $id = $existing['id'];
        } else {
            // Insert
            $stmt = $db->prepare("
                INSERT INTO cms_page_generator_config (
                    config_key, config_label, config_data, enabled, created_at, updated_at
                ) VALUES (?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$configKey, $configLabel, $dataJson, $enabled ? 1 : 0]);
            $id = (int)$db->lastInsertId();
        }

        return [
            'success' => true,
            'id' => $id,
            'message' => 'Generator configuration saved'
        ];

    } catch (Exception $e) {
        error_log("Failed to save generator config: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to save: ' . $e->getMessage()];
    }
}

/**
 * Get available neighbourhood choices (from jobs or config)
 *
 * @return array Array of neighbourhood options [key => label]
 */
function pg_getNeighbourhoods(): array
{
    try {
        $db = getDB();

        // Query completed jobs for real neighbourhoods
        $stmt = $db->query("
            SELECT DISTINCT neighbourhood
            FROM jobs
            WHERE status = 'completed' AND neighbourhood IS NOT NULL AND neighbourhood != ''
            ORDER BY neighbourhood ASC
        ");

        $neighbourhoods = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = strtolower(str_replace(' ', '-', $row['neighbourhood']));
            $neighbourhoods[$key] = $row['neighbourhood'];
        }

        return $neighbourhoods;

    } catch (Exception $e) {
        error_log("Failed to get neighbourhoods: " . $e->getMessage());
        return [];
    }
}

/**
 * Get available service choices (from config or database)
 *
 * @return array Array of service options [key => label]
 */
function pg_getServices(): array
{
    return [
        'lawn-care' => 'Lawn Care',
        'snow-removal' => 'Snow Removal',
        'landscaping' => 'Landscaping Design',
        'maintenance' => 'Property Maintenance',
        'tree-service' => 'Tree Service',
        'irrigation' => 'Irrigation Installation',
    ];
}

/**
 * Get generation history for analytics
 *
 * @param int $limit Maximum records to return
 * @param int $offset Offset for pagination
 * @return array Array of generation log records
 */
function pg_getGenerationHistory(int $limit = 50, int $offset = 0): array
{
    try {
        $db = getDB();

        $stmt = $db->prepare("
            SELECT
                l.id, l.page_id, l.generator_config_id, l.variables, l.generated_at, l.generated_by,
                p.title, p.slug, c.config_label
            FROM cms_page_generations_log l
            LEFT JOIN cms_pages p ON l.page_id = p.id
            LEFT JOIN cms_page_generator_config c ON l.generator_config_id = c.id
            ORDER BY l.generated_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);

        return array_map(function($row) {
            $row['variables'] = json_decode($row['variables'], true);
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

    } catch (Exception $e) {
        error_log("Failed to get generation history: " . $e->getMessage());
        return [];
    }
}
