<?php
/**
 * CmsHtmlSanitizer — allow-list cleaning for HTML that CMS editors type into blocks/sections.
 *
 * Fable review 2026-10-12: rich_text / custom / text-section HTML was stored and rendered raw on
 * the public site, so anyone who could save a block could put a <script> or onerror= handler on
 * mowology.ca. Now: known formatting tags and attributes only; script/style/iframe/form/etc are
 * removed with their content; on* handlers and javascript:/data: URLs are dropped. Applied on
 * save (save-block.php) and again at render (defence for rows saved before this).
 *
 * Pure (DOMDocument only). No namespace / no autoloader.
 */
final class CmsHtmlSanitizer
{
    private const ALLOWED = [
        'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'sup', 'sub', 'mark', 'code', 'pre',
        'span', 'div', 'section', 'blockquote', 'a', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img', 'figure', 'figcaption', 'picture', 'source',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
    ];

    /** Removed together with everything inside them. */
    private const DROP = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input',
        'button', 'textarea', 'select', 'option', 'link', 'meta', 'base', 'svg', 'math', 'template',
        'noscript', 'title', 'head',
    ];

    private const ATTRS = [
        '*'      => ['class', 'id', 'title', 'style', 'lang', 'dir', 'aria-label', 'aria-hidden', 'role'],
        'a'      => ['href', 'target', 'rel'],
        'img'    => ['src', 'alt', 'width', 'height', 'loading', 'srcset', 'sizes'],
        'source' => ['srcset', 'type', 'media', 'sizes'],
        'td'     => ['colspan', 'rowspan'],
        'th'     => ['colspan', 'rowspan', 'scope'],
        'ol'     => ['start', 'type'],
    ];

    private const URL_ATTRS = ['href', 'src'];

    public static function clean(string $html): string
    {
        if ($html === '' || strpbrk($html, '<&') === false) return $html;

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__cms_root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('__cms_root');
        if (!$root) {
            foreach ($doc->getElementsByTagName('div') as $d) { $root = $d; break; }
        }
        if (!$root) return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');

        self::walk($root);

        $out = '';
        foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
        // saveHTML() percent-encodes braces in href/src; CMS {{tokens}} must survive.
        return preg_replace_callback('/%7B(%7B)?([A-Za-z0-9_.:\-]+)%7D(%7D)?/i', static function (array $m): string {
            return '{' . ($m[1] ? '{' : '') . $m[2] . '}' . (($m[3] ?? '') ? '}' : '');
        }, $out) ?? $out;
    }

    /**
     * Config keys that hold a link or an image address. Their values go through safeUrl() whatever
     * they contain (Fable #7, 2026-10-12: "java\tscript:" in cta_url passed the old '<'/'javascript:'
     * probe). Keys like link_text / image_alt are NOT matched.
     */
    private const URL_KEY = '/(^|_)(url|href|link|src)$|^(image|image_tablet|image_mobile|background_image|bg_image)$/i';

    public static function isUrlKey($key): bool
    {
        return is_string($key) && preg_match(self::URL_KEY, $key) === 1;
    }

    /** Clean every string in a (nested) block config that could hold markup or a link. */
    public static function cleanConfig(array $config): array
    {
        foreach ($config as $k => $v) {
            if (is_array($v)) {
                $config[$k] = self::cleanConfig($v);
            } elseif (is_string($v) && self::isUrlKey($k)) {
                $config[$k] = self::safeUrl($v) ?? '';
            } elseif (is_string($v) && (strpos($v, '<') !== false || stripos($v, 'javascript:') !== false)) {
                $config[$k] = self::clean($v);
                if (stripos($config[$k], 'javascript:') !== false && strpos($config[$k], '<') === false) {
                    $config[$k] = self::safeUrl($config[$k]) ?? '';
                }
            }
        }
        return $config;
    }

    /**
     * Render-side pass: only the URL-keyed fields, unsafe ones become $fallback. Leaves every
     * other field alone (block templates escape text themselves; HTML fields are clean()ed there).
     */
    public static function cleanConfigUrls(array $config, string $fallback = '#'): array
    {
        foreach ($config as $k => $v) {
            if (is_array($v)) {
                $config[$k] = self::cleanConfigUrls($v, $fallback);
            } elseif (is_string($v) && self::isUrlKey($k)) {
                $config[$k] = self::safeUrl($v) ?? $fallback;
            }
        }
        return $config;
    }

    /** safeUrl() with a fallback, for templates: href="<?= h(CmsHtmlSanitizer::href($u)) ?>". */
    public static function href($url, string $fallback = '#'): string
    {
        if (!is_string($url)) return $fallback;
        return self::safeUrl($url) ?? $fallback;
    }

    /** A URL that may go into href/src, or null. Relative, http(s), mailto and tel only. */
    public static function safeUrl(string $url): ?string
    {
        // Decode until stable (browsers also accept numeric references without the ';'),
        // then drop every control/space character browsers ignore inside a scheme.
        $probe = $url;
        for ($i = 0; $i < 5; $i++) {
            $next = preg_replace_callback('/&#(x[0-9a-f]+|[0-9]+);?/i', static function (array $m): string {
                $n = ($m[1][0] === 'x' || $m[1][0] === 'X') ? hexdec(substr($m[1], 1)) : (int)$m[1];
                return ($n > 0 && $n < 0x110000) ? mb_chr((int)$n, 'UTF-8') : '';
            }, $probe) ?? $probe;
            $next = html_entity_decode($next, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $probe) break;
            $probe = $next;
        }
        $probe = strtolower(preg_replace('/[\x00-\x20\x7f]+|\xc2\xa0/', '', $probe) ?? '');
        if ($probe === '') return $url;
        if (strpos($probe, '\\') === 0 || strpos($probe, '/\\') === 0) return null; // "\\evil" = protocol-relative in browsers
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $probe, $m)) {
            return in_array($m[1], ['http', 'https', 'mailto', 'tel'], true) ? $url : null;
        }
        // No scheme before the first : / ? # — but a colon earlier than any of those means one.
        $colon = strpos($probe, ':');
        if ($colon !== false) {
            $stop = strcspn($probe, '/?#');
            if ($colon < $stop) return null;
        }
        return $url;
    }

    private static function walk(DOMNode $node): void
    {
        // Copy first: the live list changes as we remove/unwrap.
        $children = [];
        foreach ($node->childNodes as $c) $children[] = $c;

        foreach ($children as $child) {
            if ($child instanceof DOMComment) { $node->removeChild($child); continue; }
            if (!$child instanceof DOMElement) continue;

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) { $node->removeChild($child); continue; }

            self::walk($child);

            if (!in_array($tag, self::ALLOWED, true)) {
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            self::cleanAttributes($child, $tag);
        }
    }

    private static function cleanAttributes(DOMElement $el, string $tag): void
    {
        $allowed = array_merge(self::ATTRS['*'], self::ATTRS[$tag] ?? []);
        $names = [];
        foreach ($el->attributes as $a) $names[] = $a->nodeName;

        foreach ($names as $name) {
            $lname = strtolower($name);
            $value = $el->getAttribute($name);
            $keep = in_array($lname, $allowed, true) || strpos($lname, 'data-') === 0;
            if (strpos($lname, 'on') === 0) $keep = false;
            if ($keep && in_array($lname, self::URL_ATTRS, true) && self::safeUrl($value) === null) $keep = false;
            if ($keep && in_array($lname, ['srcset'], true) && preg_match('/(javascript|data|vbscript)\s*:/i', $value)) $keep = false;
            if ($keep && $lname === 'style' && preg_match('/expression\s*\(|javascript\s*:|url\s*\(|behavior\s*:|@import/i', $value)) $keep = false;
            if (!$keep) $el->removeAttribute($name);
        }
        if ($tag === 'a' && strtolower($el->getAttribute('target')) === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }
}
