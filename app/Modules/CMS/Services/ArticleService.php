<?php
/**
 * ArticleService — blog articles as CMS pages.
 *
 * An article is a `cms_pages` row whose slug starts with `blog/`, rendered by
 * the `article` layout, with its body stored as a single `rich_text` block and
 * its article-specific metadata (author, FAQ, keyword, city…) in the page's
 * `generated_variables` JSON column. No schema change was needed: the CMS
 * catch-all already routes `/blog/<slug>` to `cms-render.php`.
 *
 * Used by:
 *   - app/Modules/Marketing/Api/publish-article.php  (Content Engine → publish)
 *   - public/blog.php, public/blog-feed.php           (index + RSS)
 *   - public/layouts/article.php                      (render)
 *   - public/crm/includes/cms-renderer.php            (BlogPosting schema)
 *
 * Global-namespace class; `require_once` it and `new ArticleService($db)`.
 */
declare(strict_types=1);

class ArticleService
{
    public const SLUG_PREFIX = 'blog/';
    public const LAYOUT      = 'article';
    public const SOURCE_KEY  = 'content_engine';

    private PDO $db;
    private int $siteId;

    public function __construct(PDO $db, ?int $siteId = null)
    {
        $this->db     = $db;
        $this->siteId = $siteId ?? (defined('CMS_SITE_ID') ? (int)CMS_SITE_ID : 1);
    }

    // ── Pure helpers (unit-tested, no DB) ────────────────────────────────────

    /** URL slug from a title: lowercase, ascii, hyphenated, ≤ 80 chars, no stop-word stripping. */
    public static function slugify(string $text): string
    {
        $slug = strtolower(trim($text));
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
            if ($ascii !== false) {
                $slug = $ascii;
            }
        }
        $slug = preg_replace('/&(amp;)?/', ' and ', $slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim(preg_replace('/-+/', '-', $slug), '-');
        if (strlen($slug) > 80) {
            $slug = rtrim(substr($slug, 0, 80), '-');
            $cut  = strrpos($slug, '-');
            if ($cut !== false && $cut > 40) {
                $slug = substr($slug, 0, $cut);
            }
        }
        return $slug;
    }

    /** Plain-text excerpt of the body: first paragraph(s), cut at a word boundary. */
    public static function excerpt(string $html, int $maxChars = 160): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        $cut = mb_substr($text, 0, $maxChars);
        $sp  = mb_strrpos($cut, ' ');
        if ($sp !== false && $sp > (int)($maxChars * 0.6)) {
            $cut = mb_substr($cut, 0, $sp);
        }
        return rtrim($cut, " ,;:.") . '…';
    }

    public static function wordCount(string $html): int
    {
        return str_word_count(strip_tags($html));
    }

    /** Reading time at ~220 wpm, never less than one minute. */
    public static function readingMinutes(string $html): int
    {
        return max(1, (int)ceil(self::wordCount($html) / 220));
    }

    /**
     * schema.org BlogPosting for an article page.
     *
     * @param array $page  cms_pages row (title, meta_description, slug, published_at, updated_at, og_image_path)
     * @param array $meta  decoded generated_variables (author, keyword, city, service_type, word_count)
     * @param array $site  ['name' => , 'url' => , 'logo' => ] — SITE_* values, passed in so this stays pure
     */
    public static function blogPostingSchema(array $page, array $meta, array $site): array
    {
        $url       = rtrim($site['url'], '/') . '/' . ltrim((string)$page['slug'], '/');
        $published = $page['published_at'] ?? $page['publish_at'] ?? $page['created_at'] ?? null;
        $modified  = $page['updated_at'] ?? $published;
        $author    = trim((string)($meta['author'] ?? ''));

        $schema = [
            '@context'         => 'https://schema.org',
            '@type'            => 'BlogPosting',
            'headline'         => (string)$page['title'],
            'description'      => (string)($page['meta_description'] ?? ''),
            'url'              => $url,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'author'           => $author !== ''
                ? ['@type' => 'Person', 'name' => $author, 'worksFor' => ['@id' => rtrim($site['url'], '/') . '/#business']]
                : ['@type' => 'Organization', 'name' => $site['name'], '@id' => rtrim($site['url'], '/') . '/#business'],
            'publisher'        => [
                '@type' => 'Organization',
                '@id'   => rtrim($site['url'], '/') . '/#business',
                'name'  => $site['name'],
                'logo'  => ['@type' => 'ImageObject', 'url' => $site['logo']],
            ],
            'inLanguage'       => 'en-CA',
        ];
        if ($published) {
            $schema['datePublished'] = date('c', strtotime((string)$published));
        }
        if ($modified) {
            $schema['dateModified'] = date('c', strtotime((string)$modified));
        }
        if (!empty($page['og_image_path'])) {
            $schema['image'] = rtrim($site['url'], '/') . $page['og_image_path'];
        }
        $keywords = array_values(array_filter([
            $meta['keyword'] ?? null, $meta['service_type'] ?? null, $meta['city'] ?? null,
        ]));
        if ($keywords) {
            $schema['keywords'] = implode(', ', $keywords);
        }
        if (!empty($meta['word_count'])) {
            $schema['wordCount'] = (int)$meta['word_count'];
        }
        if (!empty($meta['city'])) {
            $schema['contentLocation'] = ['@type' => 'City', 'name' => $meta['city'], 'addressRegion' => 'BC', 'addressCountry' => 'CA'];
        }
        return $schema;
    }

    /** schema.org FAQPage from [{question, answer}] pairs; null when there are none. */
    public static function faqSchema(array $faqItems): ?array
    {
        $entities = [];
        foreach ($faqItems as $item) {
            $q = trim((string)($item['question'] ?? $item['q'] ?? ''));
            $a = trim((string)($item['answer'] ?? $item['a'] ?? ''));
            if ($q === '' || $a === '') {
                continue;
            }
            $entities[] = [
                '@type'          => 'Question',
                'name'           => $q,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a],
            ];
        }
        if (!$entities) {
            return null;
        }
        return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities];
    }

    /** Decode the article metadata stored on the page row. */
    public static function meta(array $page): array
    {
        $raw = $page['generated_variables'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $d = json_decode($raw, true);
            return is_array($d) ? $d : [];
        }
        return [];
    }

    // ── Persistence ──────────────────────────────────────────────────────────

    /**
     * Create (or, with page_id, update) an article.
     *
     * @param array $draft {
     *   title, body_html (required)
     *   meta_description, slug, author, keyword, city, service_type, season,
     *   faq_items: [{question, answer}], og_image_path,
     *   status: 'published'|'draft' (default draft), page_id: int (update)
     * }
     * @return array {page_id, slug, url, status}
     */
    public function save(array $draft, int $userId): array
    {
        $title = trim((string)($draft['title'] ?? ''));
        $body  = (string)($draft['body_html'] ?? '');
        if ($title === '' || trim(strip_tags($body)) === '') {
            throw new InvalidArgumentException('An article needs a title and a body.');
        }
        $status = ($draft['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $pageId = (int)($draft['page_id'] ?? 0);

        $existing = $pageId ? $this->getById($pageId) : null;
        if ($pageId && !$existing) {
            throw new RuntimeException("Article page #{$pageId} not found.");
        }

        // Slug: explicit → derived from title; kept stable on update unless given.
        $requested = self::slugify((string)($draft['slug'] ?? ''));
        if ($existing && $requested === '') {
            $slug = $existing['slug'];
        } else {
            $base = $requested !== '' ? $requested : self::slugify($title);
            if ($base === '') {
                throw new InvalidArgumentException('Could not derive a slug from the title.');
            }
            $slug = $this->uniqueSlug(self::SLUG_PREFIX . $base, $pageId ?: null);
        }

        $metaDesc = trim((string)($draft['meta_description'] ?? ''));
        if ($metaDesc === '') {
            $metaDesc = self::excerpt($body, 155);
        }
        $siteName  = defined('SITE_NAME') ? SITE_NAME : 'Mowology';
        $siteUrl   = defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';
        $metaTitle = mb_strlen($title . ' | ' . $siteName) <= 60 ? $title . ' | ' . $siteName : $title;
        $now       = date('Y-m-d H:i:s');

        $prevMeta = $existing ? self::meta($existing) : [];
        $meta = array_merge($prevMeta, array_filter([
            'author'       => trim((string)($draft['author'] ?? ($prevMeta['author'] ?? ''))),
            'keyword'      => trim((string)($draft['keyword'] ?? '')),
            'city'         => trim((string)($draft['city'] ?? '')),
            'service_type' => trim((string)($draft['service_type'] ?? '')),
            'season'       => trim((string)($draft['season'] ?? '')),
        ], 'strlen'));
        $meta['faq_items']       = array_values(array_filter(array_map(function ($f) {
            $q = trim((string)($f['question'] ?? $f['q'] ?? ''));
            $a = trim((string)($f['answer'] ?? $f['a'] ?? ''));
            return ($q !== '' && $a !== '') ? ['question' => $q, 'answer' => $a] : null;
        }, (array)($draft['faq_items'] ?? $prevMeta['faq_items'] ?? []))));
        $meta['word_count']      = self::wordCount($body);
        $meta['reading_minutes'] = self::readingMinutes($body);
        $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $publishedAt = $existing['published_at'] ?? null;
        if ($status === 'published' && empty($publishedAt)) {
            $publishedAt = $now;
        }

        $this->db->beginTransaction();
        try {
            if ($existing) {
                $stmt = $this->db->prepare("
                    UPDATE cms_pages
                       SET slug = ?, title = ?, meta_title = ?, meta_description = ?,
                           canonical_url = ?, og_image_path = ?, status = ?,
                           published_at = ?, published_by = ?, updated_by = ?, updated_at = ?,
                           generated_variables = ?
                     WHERE id = ?
                ");
                $stmt->execute([
                    $slug, $title, $metaTitle, $metaDesc,
                    $siteUrl . '/' . $slug, $draft['og_image_path'] ?? $existing['og_image_path'] ?? null, $status,
                    $publishedAt, $status === 'published' ? $userId : ($existing['published_by'] ?? null), $userId, $now,
                    $metaJson, $pageId,
                ]);
                $blk = $this->db->prepare("SELECT id FROM cms_blocks WHERE page_id = ? AND block_type = 'rich_text' ORDER BY position ASC LIMIT 1");
                $blk->execute([$pageId]);
                $blockId = (int)($blk->fetchColumn() ?: 0);
                $config  = json_encode(['content' => $body], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($blockId) {
                    $this->db->prepare("UPDATE cms_blocks SET config_json = ?, updated_at = ? WHERE id = ?")
                             ->execute([$config, $now, $blockId]);
                } else {
                    $this->insertBlock($pageId, $config, $now);
                }
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO cms_pages
                        (site_id, slug, title, meta_title, meta_description, canonical_url, og_image_path,
                         page_type, layout_template, status, noindex, published_at, published_by,
                         created_by, created_at, updated_by, updated_at,
                         is_template_generated, template_source_key, generated_variables)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'custom', ?, ?, 0, ?, ?, ?, ?, ?, ?, 1, ?, ?)
                ");
                $stmt->execute([
                    $this->siteId, $slug, $title, $metaTitle, $metaDesc, $siteUrl . '/' . $slug,
                    $draft['og_image_path'] ?? null, self::LAYOUT, $status,
                    $publishedAt, $status === 'published' ? $userId : null,
                    $userId, $now, $userId, $now,
                    self::SOURCE_KEY, $metaJson,
                ]);
                $pageId = (int)$this->db->lastInsertId();
                $this->insertBlock($pageId, json_encode(['content' => $body], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Bust the CMS caches when the CMS function layer is loaded (it is on the web path).
        if (function_exists('cms_invalidatePageHtmlCache')) {
            cms_invalidatePageHtmlCache($pageId);
        }
        if (function_exists('cms_invalidateCache')) {
            cms_invalidateCache("blocks_page_{$pageId}");
            cms_invalidateCache("page_slug_{$this->siteId}_{$slug}");
        }

        return [
            'page_id' => $pageId,
            'slug'    => $slug,
            'url'     => $siteUrl . '/' . $slug,
            'status'  => $status,
        ];
    }

    private function insertBlock(int $pageId, string $configJson, string $now): void
    {
        $this->db->prepare("
            INSERT INTO cms_blocks (page_id, block_type, position, config_json, content_json, visibility_json, created_at, updated_at)
            VALUES (?, 'rich_text', 1, ?, NULL, NULL, ?, ?)
        ")->execute([$pageId, $configJson, $now, $now]);
    }

    /** First free slug among base, base-2, base-3… (ignoring the page being updated). */
    private function uniqueSlug(string $base, ?int $ignorePageId): string
    {
        $stmt = $this->db->prepare("SELECT id FROM cms_pages WHERE slug = ? AND site_id = ? LIMIT 1");
        $slug = $base;
        for ($i = 2; $i < 100; $i++) {
            $stmt->execute([$slug, $this->siteId]);
            $id = $stmt->fetchColumn();
            if (!$id || ($ignorePageId && (int)$id === $ignorePageId)) {
                return $slug;
            }
            $slug = $base . '-' . $i;
        }
        throw new RuntimeException('Could not find a free slug for ' . $base);
    }

    public function getById(int $pageId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM cms_pages WHERE id = ? AND site_id = ? LIMIT 1");
        $stmt->execute([$pageId, $this->siteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Body HTML of an article (its rich_text block). */
    public function body(int $pageId): string
    {
        $stmt = $this->db->prepare("SELECT config_json FROM cms_blocks WHERE page_id = ? AND block_type = 'rich_text' ORDER BY position ASC LIMIT 1");
        $stmt->execute([$pageId]);
        $cfg = json_decode((string)$stmt->fetchColumn(), true);
        return (string)($cfg['content'] ?? '');
    }

    /**
     * Published, live articles, newest first. Each row carries `meta` (decoded)
     * and `excerpt`.
     */
    public function listPublished(int $limit = 20, int $offset = 0): array
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            SELECT p.*, b.config_json AS body_config
              FROM cms_pages p
              LEFT JOIN cms_blocks b ON b.page_id = p.id AND b.block_type = 'rich_text' AND b.position = 1
             WHERE p.site_id = ?
               AND p.slug LIKE ?
               AND p.status = 'published'
               AND (p.publish_at IS NULL OR p.publish_at <= ?)
               AND (p.unpublish_at IS NULL OR p.unpublish_at > ?)
             ORDER BY COALESCE(p.published_at, p.publish_at, p.created_at) DESC, p.id DESC
             LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
        ");
        $stmt->execute([$this->siteId, self::SLUG_PREFIX . '%', $now, $now]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cfg = json_decode((string)($row['body_config'] ?? ''), true);
            $row['meta']    = self::meta($row);
            $row['excerpt'] = $row['meta_description'] ?: self::excerpt((string)($cfg['content'] ?? ''), 160);
            unset($row['body_config']);
            $rows[] = $row;
        }
        return $rows;
    }

    public function countPublished(): int
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM cms_pages
             WHERE site_id = ? AND slug LIKE ? AND status = 'published'
               AND (publish_at IS NULL OR publish_at <= ?)
               AND (unpublish_at IS NULL OR unpublish_at > ?)
        ");
        $stmt->execute([$this->siteId, self::SLUG_PREFIX . '%', $now, $now]);
        return (int)$stmt->fetchColumn();
    }

    /** Up to $limit other live articles, for a "More from the blog" block. */
    public function related(int $excludePageId, int $limit = 3): array
    {
        return array_values(array_filter(
            $this->listPublished($limit + 1),
            fn($r) => (int)$r['id'] !== $excludePageId
        ));
    }
}
