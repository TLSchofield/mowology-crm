<?php
/**
 * CMS Token Replacement Engine
 *
 * Replaces {{TOKEN}} placeholders in block content with resolved values.
 * Token priority: page_tokens overrides > token_dictionary defaults > site constants.
 *
 * @package Mowology
 * @subpackage CMS
 */

declare(strict_types=1);

// ============================================================================
// TOKEN RESOLUTION
// ============================================================================

/**
 * Get site-wide token defaults from constants + token_dictionary table
 *
 * @return array Associative array of token => value
 */
function cms_getTokenDefaults(): array
{
    static $defaults = null;
    if ($defaults !== null) {
        return $defaults;
    }

    // Start with hardcoded constants (always available, even if DB is down)
    $defaults = [
        'BRAND'       => defined('SITE_NAME') ? SITE_NAME : 'Mowology',
        'PHONE'       => defined('SITE_PHONE_DISPLAY') ? SITE_PHONE_DISPLAY : '778-846-9273',
        'PHONE_TEL'   => defined('SITE_PHONE_TEL') ? SITE_PHONE_TEL : '7788469273',
        'EMAIL'       => defined('SITE_EMAIL') ? SITE_EMAIL : 'office@mowology.ca',
        'YEAR'        => defined('SITE_YEAR') ? SITE_YEAR : date('Y'),
        'CTA_URL'     => '/quote',
        'CTA_TEXT'    => 'Get Your Free Quote',
    ];

    // Merge in token_dictionary values (DB overrides constants)
    try {
        $db = getDB();
        $stmt = $db->query("SELECT token, default_value FROM token_dictionary");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            if ($row['default_value'] !== '') {
                $defaults[$row['token']] = $row['default_value'];
            }
        }
    } catch (Exception $e) {
        // Silently fall back to constants-only — don't break rendering
        error_log("CMS Token Engine: Failed to load token_dictionary: " . $e->getMessage());
    }

    return $defaults;
}

/**
 * Get per-page token overrides
 *
 * @param int $pageId Page ID
 * @return array Associative array of token => value
 */
function cms_getPageTokens(int $pageId): array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT token, value FROM page_tokens WHERE page_id = ?");
        $stmt->execute([$pageId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tokens = [];
        foreach ($rows as $row) {
            $tokens[$row['token']] = $row['value'];
        }
        return $tokens;
    } catch (Exception $e) {
        error_log("CMS Token Engine: Failed to load page_tokens for page {$pageId}: " . $e->getMessage());
        return [];
    }
}

/**
 * Build complete token map for a page (defaults + page overrides)
 *
 * @param int $pageId Page ID
 * @return array Merged token => value map
 */
function cms_buildTokenMap(int $pageId): array
{
    $defaults = cms_getTokenDefaults();
    $overrides = cms_getPageTokens($pageId);

    // Page overrides win over defaults
    return array_merge($defaults, $overrides);
}

/**
 * Replace {{TOKEN}} placeholders in a string
 *
 * @param string $text Text containing {{TOKEN}} placeholders
 * @param array $tokens Token => value map
 * @param bool $escapeHtml Whether to HTML-escape replacement values (default: true)
 * @return string Text with tokens replaced
 */
function cms_resolveTokens(string $text, array $tokens, bool $escapeHtml = true): string
{
    if (strpos($text, '{{') === false) {
        return $text;
    }

    return preg_replace_callback('/\{\{([A-Z_]+)\}\}/', function ($matches) use ($tokens, $escapeHtml) {
        $tokenName = $matches[1];

        if (!isset($tokens[$tokenName])) {
            // Leave unresolved tokens as-is (visible in admin for debugging)
            return $matches[0];
        }

        $value = $tokens[$tokenName];
        return $escapeHtml ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
    }, $text);
}

/**
 * Recursively resolve tokens in a block config array
 *
 * Walks all string values in the config and replaces {{TOKEN}} placeholders.
 * Arrays and nested arrays are processed recursively.
 *
 * @param array $config Block config array
 * @param array $tokens Token => value map
 * @param bool $escapeHtml Whether to HTML-escape (default: true)
 * @return array Config with tokens replaced in all string values
 */
function cms_processBlockConfig(array $config, array $tokens, bool $escapeHtml = true): array
{
    foreach ($config as $key => &$value) {
        if (is_string($value)) {
            $value = cms_resolveTokens($value, $tokens, $escapeHtml);
        } elseif (is_array($value)) {
            $value = cms_processBlockConfig($value, $tokens, $escapeHtml);
        }
    }
    unset($value);

    return $config;
}

// ============================================================================
// TOKEN CRUD (for admin UI)
// ============================================================================

/**
 * Get all tokens from dictionary
 *
 * @param string|null $category Filter by category
 * @return array Token records
 */
function cms_getAllTokens(?string $category = null): array
{
    $db = getDB();

    $sql = "SELECT id, token, default_value, description, category, example_value, created_at, updated_at
            FROM token_dictionary";
    $params = [];

    if ($category) {
        $sql .= " WHERE category = ?";
        $params[] = $category;
    }

    $sql .= " ORDER BY category ASC, token ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Save a token to dictionary (create or update)
 *
 * @param array $data Token data
 * @param int|null $tokenId If provided, update
 * @return int Token ID
 */
function cms_saveToken(array $data, ?int $tokenId = null): int
{
    $db = getDB();

    $token = strtoupper(preg_replace('/[^A-Z0-9_]/', '', strtoupper($data['token'] ?? '')));
    if (!$token) {
        throw new Exception('Invalid token name (uppercase letters, numbers, underscores only)');
    }

    if ($tokenId) {
        $stmt = $db->prepare("
            UPDATE token_dictionary
            SET token = ?, default_value = ?, description = ?, category = ?, example_value = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $token,
            $data['default_value'] ?? '',
            $data['description'] ?? null,
            $data['category'] ?? 'custom',
            $data['example_value'] ?? null,
            $tokenId,
        ]);
        return $tokenId;
    } else {
        $stmt = $db->prepare("
            INSERT INTO token_dictionary (token, default_value, description, category, example_value, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $token,
            $data['default_value'] ?? '',
            $data['description'] ?? null,
            $data['category'] ?? 'custom',
            $data['example_value'] ?? null,
        ]);
        return (int)$db->lastInsertId();
    }
}

/**
 * Delete a token from dictionary
 *
 * @param int $tokenId Token ID
 * @return bool Success
 */
function cms_deleteToken(int $tokenId): bool
{
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM token_dictionary WHERE id = ?");
    return $stmt->execute([$tokenId]);
}

/**
 * Save page token overrides
 *
 * @param int $pageId Page ID
 * @param array $tokens Array of ['token' => 'VALUE', ...]
 * @return void
 */
function cms_savePageTokens(int $pageId, array $tokens): void
{
    $db = getDB();

    // Delete existing overrides for this page
    $db->prepare("DELETE FROM page_tokens WHERE page_id = ?")->execute([$pageId]);

    // Insert new overrides
    $stmt = $db->prepare("
        INSERT INTO page_tokens (page_id, token, value, created_at)
        VALUES (?, ?, ?, NOW())
    ");

    foreach ($tokens as $token => $value) {
        $token = strtoupper(preg_replace('/[^A-Z0-9_]/', '', strtoupper($token)));
        if ($token && $value !== '') {
            $stmt->execute([$pageId, $token, $value]);
        }
    }
}

// ============================================================================
// CONTENT SNIPPET OPERATIONS
// ============================================================================

/**
 * Get all content snippets
 *
 * @param string|null $blockType Filter by block type
 * @param string|null $variant Filter by variant
 * @return array Snippet records with content_json decoded
 */
function cms_getSnippets(?string $blockType = null, ?string $variant = null): array
{
    $db = getDB();

    $sql = "SELECT id, snippet_key, variant, block_type, content_json, notes, is_active, created_at, updated_at
            FROM content_snippets WHERE is_active = 1";
    $params = [];

    if ($blockType) {
        $sql .= " AND block_type = ?";
        $params[] = $blockType;
    }
    if ($variant) {
        $sql .= " AND variant = ?";
        $params[] = $variant;
    }

    $sql .= " ORDER BY block_type ASC, variant ASC, snippet_key ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $snippets = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($snippets as &$s) {
        $s['content'] = json_decode($s['content_json'] ?? '{}', true);
    }

    return $snippets;
}

/**
 * Get a single snippet by key
 *
 * @param string $key Snippet key
 * @return array|null Snippet with decoded content
 */
function cms_getSnippetByKey(string $key): ?array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, snippet_key, variant, block_type, content_json, notes, is_active
        FROM content_snippets
        WHERE snippet_key = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$key]);
    $snippet = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($snippet) {
        $snippet['content'] = json_decode($snippet['content_json'] ?? '{}', true);
    }

    return $snippet ?: null;
}

/**
 * Save a content snippet (create or update)
 *
 * @param array $data Snippet data
 * @param int|null $snippetId If provided, update
 * @return int Snippet ID
 */
function cms_saveSnippet(array $data, ?int $snippetId = null): int
{
    $db = getDB();

    $key = $data['snippet_key'] ?? '';
    if (!$key || !preg_match('/^[a-z0-9_\.]+$/', $key)) {
        throw new Exception('Invalid snippet key (lowercase, numbers, underscores, dots only)');
    }

    $contentJson = is_array($data['content'] ?? null)
        ? json_encode($data['content'], JSON_UNESCAPED_SLASHES)
        : ($data['content_json'] ?? '{}');

    if ($snippetId) {
        $stmt = $db->prepare("
            UPDATE content_snippets
            SET snippet_key = ?, variant = ?, block_type = ?, content_json = ?, notes = ?, is_active = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $key,
            $data['variant'] ?? 'default',
            $data['block_type'] ?? '',
            $contentJson,
            $data['notes'] ?? null,
            isset($data['is_active']) ? (int)$data['is_active'] : 1,
            $snippetId,
        ]);
        return $snippetId;
    } else {
        $stmt = $db->prepare("
            INSERT INTO content_snippets (snippet_key, variant, block_type, content_json, notes, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $key,
            $data['variant'] ?? 'default',
            $data['block_type'] ?? '',
            $contentJson,
            $data['notes'] ?? null,
            isset($data['is_active']) ? (int)$data['is_active'] : 1,
        ]);
        return (int)$db->lastInsertId();
    }
}

/**
 * Delete a content snippet
 *
 * @param int $snippetId Snippet ID
 * @return bool Success
 */
function cms_deleteSnippet(int $snippetId): bool
{
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM content_snippets WHERE id = ?");
    return $stmt->execute([$snippetId]);
}

/**
 * Find best matching snippet for a block type + variant
 *
 * Searches for: exact match → variant fallback → default fallback
 *
 * @param string $blockType Block type
 * @param string $variant Variant name
 * @param string $version Version (v1, v2)
 * @return array|null Best matching snippet
 */
function cms_findSnippet(string $blockType, string $variant, string $version = 'v1'): ?array
{
    // Try exact match: hero.residential_lawn_care.v1
    $key = "{$blockType}.{$variant}.{$version}";
    $snippet = cms_getSnippetByKey($key);
    if ($snippet) {
        return $snippet;
    }

    // Try without version: hero.residential_lawn_care
    $key = "{$blockType}.{$variant}";
    $snippet = cms_getSnippetByKey($key);
    if ($snippet) {
        return $snippet;
    }

    // Try default variant: hero.default.v1
    $key = "{$blockType}.default.{$version}";
    $snippet = cms_getSnippetByKey($key);
    if ($snippet) {
        return $snippet;
    }

    return null;
}
