<?php
/**
 * CmsSectionFunctions.php
 * Section Module Library — CRUD, reorder, and HTML renderers.
 *
 * Sections are content blocks stored in cms_page_sections.
 * Each block_type maps to a renderer that outputs ready-to-use HTML.
 *
 * Public API:
 *   cms_getSectionBlockTypes(): array
 *   cms_getPageSections(int $pageId, int $siteId): array
 *   cms_getSectionById(int $id, int $siteId): ?array
 *   cms_createSection(int $pageId, int $siteId, string $blockType, array $data, int $sortOrder): int
 *   cms_updateSection(int $id, int $siteId, array $data): bool
 *   cms_deleteSection(int $id, int $siteId): bool
 *   cms_reorderSections(array $orderedIds, int $siteId): bool
 *   cms_renderSection(array $section): string
 *   cms_renderPageSections(int $pageId, int $siteId): string
 */

declare(strict_types=1);

// ─── Block type registry ──────────────────────────────────────────────────────

function cms_getSectionBlockTypes(): array {
    static $types = null;
    if ($types === null) {
        $configFile = dirname(__DIR__) . '/Config/block-types.php';
        $types = file_exists($configFile) ? require $configFile : [];
    }
    return $types;
}

// ─── CRUD ─────────────────────────────────────────────────────────────────────

function cms_getPageSections(int $pageId, int $siteId): array {
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            'SELECT * FROM cms_page_sections
             WHERE page_id = ? AND site_id = ? AND is_active = 1
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$pageId, $siteId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Decode JSON data field
        foreach ($rows as &$row) {
            $row['data'] = json_decode($row['data'] ?? '{}', true) ?: [];
        }
        return $rows;
    } catch (\Throwable $e) {
        return [];
    }
}

function cms_getSectionById(int $id, int $siteId): ?array {
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            'SELECT * FROM cms_page_sections WHERE id = ? AND site_id = ?'
        );
        $stmt->execute([$id, $siteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['data'] = json_decode($row['data'] ?? '{}', true) ?: [];
        return $row;
    } catch (\Throwable $e) {
        return null;
    }
}

function cms_createSection(int $pageId, int $siteId, string $blockType, array $data, int $sortOrder = 0): int {
    $db   = getDB();
    $stmt = $db->prepare(
        'INSERT INTO cms_page_sections (page_id, site_id, block_type, sort_order, data)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$pageId, $siteId, $blockType, $sortOrder, json_encode($data)]);
    return (int)$db->lastInsertId();
}

function cms_updateSection(int $id, int $siteId, array $data): bool {
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            'UPDATE cms_page_sections SET data = ?, updated_at = NOW()
             WHERE id = ? AND site_id = ?'
        );
        $stmt->execute([json_encode($data), $id, $siteId]);
        return $stmt->rowCount() > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

function cms_deleteSection(int $id, int $siteId): bool {
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            'UPDATE cms_page_sections SET is_active = 0 WHERE id = ? AND site_id = ?'
        );
        $stmt->execute([$id, $siteId]);
        return $stmt->rowCount() > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Reorder sections. $orderedIds = [id, id, id...] in desired order.
 */
function cms_reorderSections(array $orderedIds, int $siteId): bool {
    try {
        $db = getDB();
        $db->beginTransaction();
        $stmt = $db->prepare(
            'UPDATE cms_page_sections SET sort_order = ? WHERE id = ? AND site_id = ?'
        );
        foreach ($orderedIds as $i => $id) {
            $stmt->execute([$i * 10, (int)$id, $siteId]);
        }
        $db->commit();
        return true;
    } catch (\Throwable $e) {
        $db->rollBack();
        return false;
    }
}

// ─── Renderer dispatcher ──────────────────────────────────────────────────────

function cms_renderSection(array $section): string {
    $type = $section['block_type'] ?? '';
    $data = is_array($section['data']) ? $section['data'] : (json_decode($section['data'] ?? '{}', true) ?: []);

    switch ($type) {
        case 'hero':         return cms_renderHero($data);
        case 'features':     return cms_renderFeatures($data);
        case 'text_block':   return cms_renderTextBlock($data);
        case 'cta_banner':   return cms_renderCtaBanner($data);
        case 'image_text':   return cms_renderImageText($data);
        case 'testimonials': return cms_renderTestimonials($data);
        default:             return "<!-- Unknown block type: " . htmlspecialchars($type) . " -->";
    }
}

function cms_renderPageSections(int $pageId, int $siteId): string {
    $sections = cms_getPageSections($pageId, $siteId);
    if (empty($sections)) return '';
    $html = '';
    foreach ($sections as $section) {
        $html .= cms_renderSection($section);
    }
    return $html;
}

// ─── Block renderers ──────────────────────────────────────────────────────────

function cms_renderHero(array $d): string {
    $bg      = $d['background'] ?? 'dark';
    $align   = $d['align']      ?? 'center';
    $badge   = $d['badge_text'] ?? '';
    $headline   = $d['headline']    ?? '';
    $sub        = $d['subheadline'] ?? '';
    $ctaText    = $d['cta_text']    ?? '';
    $ctaUrl     = $d['cta_url']     ?? '#';

    $bgClass = match($bg) {
        'primary' => 'cms-sec-hero--primary',
        'light'   => 'cms-sec-hero--light',
        'white'   => 'cms-sec-hero--white',
        default   => 'cms-sec-hero--dark',
    };
    $alignClass = $align === 'left' ? 'cms-sec-hero--left' : 'cms-sec-hero--center';

    $html = '<section class="cms-sec-hero ' . $bgClass . ' ' . $alignClass . '">';
    $html .= '<div class="cms-sec-container">';
    if ($badge) {
        $html .= '<span class="cms-sec-badge">' . htmlspecialchars($badge) . '</span>';
    }
    if ($headline) {
        $html .= '<h1 class="cms-sec-hero__heading">' . htmlspecialchars($headline) . '</h1>';
    }
    if ($sub) {
        $html .= '<p class="cms-sec-hero__sub">' . htmlspecialchars($sub) . '</p>';
    }
    if ($ctaText) {
        $html .= '<a href="' . htmlspecialchars($ctaUrl) . '" class="cms-sec-btn cms-sec-btn--accent">' . htmlspecialchars($ctaText) . '</a>';
    }
    $html .= '</div></section>';
    return $html;
}

function cms_renderFeatures(array $d): string {
    $headline = $d['headline'] ?? '';
    $subtext  = $d['subtext']  ?? '';
    $cols     = (int)($d['columns'] ?? 3);
    $items    = $d['items']    ?? [];
    $bg       = $d['background'] ?? 'white';

    $bgClass  = match($bg) {
        'light' => 'cms-sec-bg--light',
        'tint'  => 'cms-sec-bg--tint',
        default => '',
    };

    $html = '<section class="cms-sec-features ' . $bgClass . '">';
    $html .= '<div class="cms-sec-container">';
    if ($headline || $subtext) {
        $html .= '<div class="cms-sec-section-head">';
        if ($headline) $html .= '<h2 class="cms-sec-section-title">' . htmlspecialchars($headline) . '</h2>';
        if ($subtext)  $html .= '<p class="cms-sec-section-sub">'   . htmlspecialchars($subtext)  . '</p>';
        $html .= '</div>';
    }
    if (!empty($items)) {
        $html .= '<div class="cms-sec-features__grid cms-sec-features__grid--' . $cols . '">';
        foreach ($items as $item) {
            $icon  = htmlspecialchars($item['icon']  ?? 'check-circle');
            $title = htmlspecialchars($item['title'] ?? '');
            $body  = htmlspecialchars($item['body']  ?? '');
            if (!$title) continue;
            $html .= '<div class="cms-sec-feature-card">';
            $html .= '<div class="cms-sec-feature-card__icon"><i data-feather="' . $icon . '"></i></div>';
            $html .= '<h3 class="cms-sec-feature-card__title">' . $title . '</h3>';
            if ($body) $html .= '<p class="cms-sec-feature-card__body">' . $body . '</p>';
            $html .= '</div>';
        }
        $html .= '</div>';
    }
    $html .= '</div></section>';
    return $html;
}

function cms_renderTextBlock(array $d): string {
    $layout  = $d['layout']     ?? 'full';
    $bg      = $d['background'] ?? 'white';
    $content = $d['content']    ?? '';
    $content2= $d['content2']   ?? '';

    $bgClass = match($bg) {
        'light' => 'cms-sec-bg--light',
        'tint'  => 'cms-sec-bg--tint',
        default => '',
    };

    $html = '<section class="cms-sec-text ' . $bgClass . '">';
    $html .= '<div class="cms-sec-container">';
    if ($layout === 'two-col') {
        $html .= '<div class="cms-sec-two-col">';
        $html .= '<div class="cms-sec-prose">' . $content . '</div>';
        $html .= '<div class="cms-sec-prose">' . $content2 . '</div>';
        $html .= '</div>';
    } else {
        $html .= '<div class="cms-sec-prose cms-sec-prose--wide">' . $content . '</div>';
    }
    $html .= '</div></section>';
    return $html;
}

function cms_renderCtaBanner(array $d): string {
    $headline = $d['headline'] ?? '';
    $subtext  = $d['subtext']  ?? '';
    $ctaText  = $d['cta_text'] ?? 'Get a Quote';
    $ctaUrl   = $d['cta_url']  ?? '/contact';
    $style    = $d['style']    ?? 'primary';

    $styleClass = match($style) {
        'dark'   => 'cms-sec-cta--dark',
        'accent' => 'cms-sec-cta--accent',
        default  => 'cms-sec-cta--primary',
    };
    $btnClass = ($style === 'primary') ? 'cms-sec-btn--white' : 'cms-sec-btn--white';

    $html = '<section class="cms-sec-cta ' . $styleClass . '">';
    $html .= '<div class="cms-sec-container cms-sec-cta__inner">';
    $html .= '<div class="cms-sec-cta__text">';
    if ($headline) $html .= '<h2 class="cms-sec-cta__heading">' . htmlspecialchars($headline) . '</h2>';
    if ($subtext)  $html .= '<p class="cms-sec-cta__sub">'     . htmlspecialchars($subtext)  . '</p>';
    $html .= '</div>';
    if ($ctaText) {
        $html .= '<a href="' . htmlspecialchars($ctaUrl) . '" class="cms-sec-btn ' . $btnClass . '">' . htmlspecialchars($ctaText) . '</a>';
    }
    $html .= '</div></section>';
    return $html;
}

function cms_renderImageText(array $d): string {
    $imageUrl  = $d['image_url']      ?? '';
    $imageAlt  = $d['image_alt']      ?? '';
    $pos       = $d['image_position'] ?? 'left';
    $badge     = $d['badge_text']     ?? '';
    $headline  = $d['headline']       ?? '';
    $body      = $d['body']           ?? '';
    $ctaText   = $d['cta_text']       ?? '';
    $ctaUrl    = $d['cta_url']        ?? '#';
    $bg        = $d['background']     ?? 'white';

    $bgClass   = match($bg) {
        'light' => 'cms-sec-bg--light',
        'tint'  => 'cms-sec-bg--tint',
        default => '',
    };
    $reverseClass = ($pos === 'right') ? ' cms-sec-imgtext--reverse' : '';

    $html = '<section class="cms-sec-imgtext ' . $bgClass . $reverseClass . '">';
    $html .= '<div class="cms-sec-container cms-sec-imgtext__inner">';

    // Image column
    $html .= '<div class="cms-sec-imgtext__img">';
    if ($imageUrl) {
        $html .= '<img src="' . htmlspecialchars($imageUrl) . '" alt="' . htmlspecialchars($imageAlt) . '" loading="lazy">';
    } else {
        $html .= '<div class="cms-sec-imgtext__placeholder"></div>';
    }
    $html .= '</div>';

    // Text column
    $html .= '<div class="cms-sec-imgtext__text">';
    if ($badge)    $html .= '<span class="cms-sec-badge cms-sec-badge--muted">' . htmlspecialchars($badge) . '</span>';
    if ($headline) $html .= '<h2 class="cms-sec-imgtext__heading">' . htmlspecialchars($headline) . '</h2>';
    if ($body)     $html .= '<div class="cms-sec-prose">' . $body . '</div>';
    if ($ctaText)  $html .= '<a href="' . htmlspecialchars($ctaUrl) . '" class="cms-sec-btn cms-sec-btn--outline">' . htmlspecialchars($ctaText) . '</a>';
    $html .= '</div>';

    $html .= '</div></section>';
    return $html;
}

function cms_renderTestimonials(array $d): string {
    $headline = $d['headline']   ?? '';
    $items    = $d['items']      ?? [];
    $bg       = $d['background'] ?? 'light';

    $bgClass = match($bg) {
        'tint'  => 'cms-sec-bg--tint',
        'dark'  => 'cms-sec-bg--dark',
        'white' => '',
        default => 'cms-sec-bg--light',
    };
    $darkMode = ($bg === 'dark') ? ' cms-sec-testimonials--dark' : '';

    $html = '<section class="cms-sec-testimonials ' . $bgClass . $darkMode . '">';
    $html .= '<div class="cms-sec-container">';
    if ($headline) {
        $html .= '<h2 class="cms-sec-section-title cms-sec-section-title--center">' . htmlspecialchars($headline) . '</h2>';
    }
    if (!empty($items)) {
        $html .= '<div class="cms-sec-testimonials__grid">';
        foreach ($items as $item) {
            $quote  = htmlspecialchars($item['quote']  ?? '');
            $name   = htmlspecialchars($item['name']   ?? '');
            $title  = htmlspecialchars($item['title']  ?? '');
            $rating = (int)($item['rating'] ?? 5);
            if (!$quote || !$name) continue;
            $stars = str_repeat('★', min(5, max(1, $rating)));
            $html .= '<div class="cms-sec-testimonial-card">';
            $html .= '<div class="cms-sec-testimonial-card__stars">' . $stars . '</div>';
            $html .= '<p class="cms-sec-testimonial-card__quote">"' . $quote . '"</p>';
            $html .= '<div class="cms-sec-testimonial-card__author">';
            $html .= '<strong>' . $name . '</strong>';
            if ($title) $html .= '<span> — ' . $title . '</span>';
            $html .= '</div></div>';
        }
        $html .= '</div>';
    }
    $html .= '</div></section>';
    return $html;
}
