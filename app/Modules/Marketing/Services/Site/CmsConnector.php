<?php
/**
 * CmsConnector — applies Mia's approved title and description to a page of Mowology's own CMS (cms_pages).
 *
 * Only pages the CMS really renders can be changed. Several live pages are built from code files
 * (public/includes/service-data/*.php) and only have a placeholder row in cms_pages, so a change there would be
 * saved but never shown. locate() refuses those: the row must be published, hold no {{placeholders}}, and
 * MiaPublishService additionally checks that the live page shows the row's current values before and after.
 * The title tag is cms_pages.meta_title and the description is cms_pages.meta_description.
 */
require_once __DIR__ . '/WebsiteConnector.php';

class CmsConnector implements WebsiteConnector
{
    private PDO $db;
    private string $host;
    /** @var callable|null cache clearer, set in tests */
    private $invalidate;

    public function __construct(PDO $db, string $host, ?callable $invalidate = null)
    {
        $this->db = $db;
        $this->host = self::bareHost($host);
        $this->invalidate = $invalidate;
    }

    public function name(): string { return 'cms'; }

    public function capabilities(): array { return ['seo_meta' => true, 'rollback' => true, 'body' => false]; }

    public static function bareHost(string $host): string
    {
        return preg_replace('/^www\./', '', strtolower(trim($host)));
    }

    /** "https://mowology.ca/services/strata/" -> "services/strata"; the home page is "home". */
    public static function slugFor(string $pageUrl): ?string
    {
        $path = parse_url($pageUrl, PHP_URL_PATH);
        if (!is_string($path)) { return null; }
        $slug = rtrim(trim(strtolower($path), '/'), '/');
        $slug = preg_replace('/\.(php|html)$/', '', $slug);
        if ($slug === '' || $slug === 'index') { return 'home'; }
        return preg_match('/^[a-z0-9\-\/]+$/', $slug) ? $slug : null;
    }

    public function locate(string $pageUrl): array
    {
        $urlHost = parse_url($pageUrl, PHP_URL_HOST);
        if (!is_string($urlHost) || self::bareHost($urlHost) !== $this->host) {
            return ['ok' => false, 'reason' => 'That page is not on the connected website.'];
        }
        $slug = self::slugFor($pageUrl);
        if ($slug === null) { return ['ok' => false, 'reason' => 'That address is not a page Mia can change.']; }
        $s = $this->db->prepare("SELECT p.id, p.status, p.meta_title, p.meta_description
                                   FROM cms_pages p JOIN cms_sites s ON s.id = p.site_id
                                  WHERE p.slug = ? AND (s.domain = ? OR s.domain = ?) LIMIT 1");
        $s->execute([$slug, $this->host, 'www.' . $this->host]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) { return ['ok' => false, 'code' => 'manual', 'reason' => 'This page is built from a code file, not the website editor, so Mia cannot change it. Apply the wording yourself.']; }
        if ($row['status'] !== 'published') { return ['ok' => false, 'reason' => 'That page is not published.']; }
        $t = (string)$row['meta_title'];
        $d = (string)$row['meta_description'];
        if (strpos($t, '{{') !== false || strpos($d, '{{') !== false) {
            return ['ok' => false, 'code' => 'manual', 'reason' => 'This page fills its title from a template, so Mia cannot change it. Apply the wording yourself.'];
        }
        return ['ok' => true, 'ref' => (string)$row['id'],
                'current' => ['meta_title' => $row['meta_title'], 'meta_description' => $row['meta_description']]];
    }

    public function applySeoMeta(string $ref, array $expected, array $new, int $userId): array
    {
        foreach (['meta_title', 'meta_description'] as $k) {
            if (isset($new[$k]) && strpos((string)$new[$k], '{{') !== false) { return ['ok' => false, 'error' => 'Wording may not contain template placeholders.']; }
        }
        $u = $this->db->prepare("UPDATE cms_pages SET meta_title = ?, meta_description = ?, updated_by = ?, updated_at = ?
                                  WHERE id = ? AND status = 'published'
                                    AND (meta_title = ? OR (meta_title IS NULL AND ? IS NULL))
                                    AND (meta_description = ? OR (meta_description IS NULL AND ? IS NULL))");
        $u->execute([$new['meta_title'], $new['meta_description'], $userId, date('Y-m-d H:i:s'), (int)$ref,
                     $expected['meta_title'], $expected['meta_title'], $expected['meta_description'], $expected['meta_description']]);
        if ($u->rowCount() !== 1) {
            return ['ok' => false, 'error' => 'The page was edited since Mia read it. Nothing was changed.'];
        }
        return ['ok' => true];
    }

    public function afterChange(string $ref): void
    {
        try {
            if ($this->invalidate) { call_user_func($this->invalidate, (int)$ref); return; }
            if (function_exists('cms_invalidatePageHtmlCache')) { cms_invalidatePageHtmlCache((int)$ref); }
        } catch (Throwable $e) {
            error_log('CmsConnector afterChange: ' . $e->getMessage());
        }
    }
}
