<?php
/**
 * CMS Site Functions — Multi-Tenant Resolution & Helpers
 *
 * Provides site detection by domain, site CRUD, and tenant context
 * for the multi-tenant CMS platform.
 *
 * @package Mowology
 * @subpackage CMS
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 3) . '/Core/paths.php';
}

// ============================================================================
// SITE RESOLUTION
// ============================================================================

/**
 * Resolve current site from HTTP_HOST domain.
 *
 * Called once per request in bootstrap.php. Result is cached in a static var
 * so subsequent calls are free.
 *
 * @param string|null $domain Override domain (for testing). If null, uses HTTP_HOST.
 * @return array Site record from cms_sites. Returns fallback site_id=1 if no match.
 */
function cms_resolveSite(?string $domain = null): array
{
    static $resolved = null;
    if ($resolved !== null && $domain === null) {
        return $resolved;
    }

    // Detect domain from request
    if ($domain === null) {
        $domain = $_SERVER['HTTP_HOST'] ?? '';
        // Strip port if present
        $domain = strtolower(explode(':', $domain)[0]);
        // Strip www. prefix
        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT *
            FROM cms_sites
            WHERE domain = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$domain]);
        $site = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($site) {
            $resolved = $site;
            return $resolved;
        }
    } catch (\Throwable $e) {
        // Table may not exist yet (pre-migration). Fall through to default.
        error_log('cms_resolveSite: ' . $e->getMessage());
    }

    // Fallback: return Mowology defaults so existing code never breaks
    $resolved = cms_getDefaultSite();
    return $resolved;
}

/**
 * Get the default site record (Mowology — site_id=1).
 * Used as fallback when cms_sites table doesn't exist or domain not found.
 *
 * @return array Default site record matching cms_sites column shape
 */
function cms_getDefaultSite(): array
{
    return [
        'id'                  => 1,
        'slug'                => 'mowology',
        'domain'              => 'mowology.ca',
        'name'                => 'Mowology',
        'tagline'             => 'A HIGHER DEGREE OF SERVICE',
        'logo_path'           => '/assets/img/logo/mowology-logo.jpg',
        'favicon_dir'         => '/assets/favicon',
        'phone_display'       => '778-846-9273',
        'phone_tel'           => '7788469273',
        'email'               => 'office@mowology.ca',
        'locale'              => 'en_CA',
        'timezone'            => 'America/Vancouver',
        'theme_id'            => null,
        'plan_tier'           => 'enterprise',
        'onboarding_complete' => 1,
        'is_active'           => 1,
    ];
}

/**
 * Get the current site ID. Returns CMS_SITE_ID constant if defined,
 * otherwise resolves and returns it.
 *
 * @return int Site ID
 */
function cms_currentSiteId(): int
{
    if (defined('CMS_SITE_ID')) {
        return CMS_SITE_ID;
    }
    $site = cms_resolveSite();
    return (int)$site['id'];
}

// ============================================================================
// SITE CRUD
// ============================================================================

/**
 * Get a site by ID.
 *
 * @param int $siteId Site ID
 * @return array|null Site record
 */
function cms_getSiteById(int $siteId): ?array
{
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM cms_sites WHERE id = ? LIMIT 1");
    $stmt->execute([$siteId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Get all active sites.
 *
 * @return array Array of site records
 */
function cms_getAllSites(): array
{
    $db = getDB();
    $stmt = $db->query("
        SELECT *
        FROM cms_sites
        WHERE is_active = 1
        ORDER BY name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Create a new site.
 *
 * @param array $data Site data
 * @return int New site ID
 */
function cms_createSite(array $data): int
{
    $db = getDB();

    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($data['slug'] ?? ''));
    if (!$slug) {
        throw new \Exception('Invalid site slug');
    }

    $domain = strtolower(trim($data['domain'] ?? ''));
    if (!$domain) {
        throw new \Exception('Domain is required');
    }

    $stmt = $db->prepare("
        INSERT INTO cms_sites (slug, domain, name, tagline, logo_path, phone_display, phone_tel, email, locale, timezone)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $slug,
        $domain,
        $data['name'] ?? $slug,
        $data['tagline'] ?? '',
        $data['logo_path'] ?? '',
        $data['phone_display'] ?? '',
        $data['phone_tel'] ?? '',
        $data['email'] ?? '',
        $data['locale'] ?? 'en_CA',
        $data['timezone'] ?? 'America/Vancouver',
    ]);

    $siteId = (int)$db->lastInsertId();

    // Create default 'main' menu for the new site
    try {
        $menuStmt = $db->prepare("
            INSERT INTO cms_menus (site_id, menu_key, label, items)
            VALUES (?, 'main', 'Main Navigation', '[]')
        ");
        $menuStmt->execute([$siteId]);
    } catch (\Throwable $e) {
        // Non-critical — menu can be created later
    }

    return $siteId;
}

/**
 * Update a site.
 *
 * @param int   $siteId Site ID
 * @param array $data   Fields to update
 * @return bool Success
 */
function cms_updateSite(int $siteId, array $data): bool
{
    $db = getDB();

    $allowed = ['name', 'tagline', 'domain', 'logo_path', 'favicon_dir',
                'phone_display', 'phone_tel', 'email', 'locale', 'timezone',
                'theme_id', 'plan_tier', 'onboarding_complete', 'is_active'];

    $sets = [];
    $params = [];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $data)) {
            $sets[] = "{$col} = ?";
            $params[] = $data[$col];
        }
    }

    if (empty($sets)) {
        return false;
    }

    $params[] = $siteId;
    $sql = "UPDATE cms_sites SET " . implode(', ', $sets) . " WHERE id = ?";
    return $db->prepare($sql)->execute($params);
}

// ============================================================================
// SITE USER AUTH
// ============================================================================

/**
 * Authenticate a site user by email + password.
 *
 * @param int    $siteId  Site ID
 * @param string $email   Email
 * @param string $password Plain-text password
 * @return array|null User record on success, null on failure
 */
function cms_authenticateSiteUser(int $siteId, string $email, string $password): ?array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT *
        FROM cms_site_users
        WHERE site_id = ? AND email = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$siteId, strtolower(trim($email))]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return null;
    }

    // Update last login
    $db->prepare("UPDATE cms_site_users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);

    // Remove password hash from returned data
    unset($user['password_hash']);
    return $user;
}

/**
 * Create a site user.
 *
 * @param int    $siteId   Site ID
 * @param string $email    Email
 * @param string $password Plain-text password (will be hashed)
 * @param string $role     Role: owner, admin, editor, viewer
 * @param string $firstName First name
 * @param string $lastName  Last name
 * @return int New user ID
 */
function cms_createSiteUser(int $siteId, string $email, string $password, string $role = 'editor', string $firstName = '', string $lastName = ''): int
{
    $db = getDB();

    $email = strtolower(trim($email));
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $db->prepare("
        INSERT INTO cms_site_users (site_id, email, password_hash, first_name, last_name, role)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$siteId, $email, $hash, $firstName, $lastName, $role]);

    return (int)$db->lastInsertId();
}

/**
 * Get the current site user from session.
 *
 * @return array|null User record or null if not logged in
 */
function cms_getCurrentSiteUser(): ?array
{
    $userId = $_SESSION['cms_site_user_id'] ?? null;
    if (!$userId) {
        return null;
    }

    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, site_id, email, first_name, last_name, role, last_login_at, is_active
        FROM cms_site_users
        WHERE id = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Get menu items for a site (for public rendering).
 *
 * @param string $menuKey Menu key (e.g. 'main')
 * @param int    $siteId  Site ID
 * @return array|null Array of nav items or null if no menu found
 */
function cms_getSiteMenuItems(string $menuKey, int $siteId): ?array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT items
        FROM cms_menus
        WHERE menu_key = ? AND site_id = ?
        LIMIT 1
    ");
    $stmt->execute([$menuKey, $siteId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['items'])) {
        return null;
    }

    $items = json_decode($row['items'], true);
    return is_array($items) ? $items : null;
}
