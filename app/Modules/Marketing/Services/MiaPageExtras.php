<?php
/**
 * MiaPageExtras — the parts of a generated page that Mia adds from the business's own records, word for word, instead of writing them:
 * the real projects that match the search (as the page's case studies) and a call to action. Nothing here is generated, so nothing
 * here can be invented. The list is part of what the owner approves (signature()), so a project published or unpublished after the
 * preview voids the approval.
 *
 * Global namespace, no autoloader. PHP 7.4-compatible.
 */
declare(strict_types=1);

final class MiaPageExtras
{
    public const MAX_PROJECTS = 3;
    private const STOP = ['the','and','for','near','best','with','from','your','our','in','of','a','an','to','vancouver','bc','services','service'];

    /** @return string[] lowercase search words, a trailing s removed so "hedges" finds "hedge" */
    public static function tokens(string $s): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            if (mb_strlen($w, 'UTF-8') < 3 || in_array($w, self::STOP, true)) { continue; }
            $out[] = (mb_strlen($w, 'UTF-8') > 4 && substr($w, -1) === 's') ? substr($w, 0, -1) : $w;
        }
        return array_values(array_unique($out));
    }

    /** True when any word of the search appears in the text. */
    public static function overlaps(array $queryTokens, string $text): bool
    {
        $t = self::tokens($text);
        return (bool)array_intersect($queryTokens, $t);
    }

    /**
     * Published portfolio projects that match the search, best match first, at most MAX_PROJECTS.
     * @return array<int,array{id:int,name:string,location:string,description:string,image:?string}>
     */
    public static function projects(PDO $db, string $query): array
    {
        $qt = self::tokens($query);
        if (!$qt) { return []; }
        try {
            $rows = $db->query("SELECT id, project_name, location, description, categories, tags, after_image_path, gallery_images FROM portfolio_projects WHERE status = 'published' ORDER BY featured DESC, id DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
        $scored = [];
        foreach ($rows as $r) {
            $hay = $r['project_name'] . ' ' . $r['description'] . ' ' . $r['categories'] . ' ' . $r['tags'];
            $score = count(array_intersect($qt, self::tokens($hay)));
            if ($score > 0) { $scored[] = ['s' => $score, 'r' => $r]; }
        }
        usort($scored, function ($a, $b) { return $b['s'] <=> $a['s'] ?: (int)$b['r']['id'] <=> (int)$a['r']['id']; });
        $out = [];
        foreach (array_slice($scored, 0, self::MAX_PROJECTS) as $x) {
            $r = $x['r'];
            $img = null;
            $g = json_decode((string)$r['gallery_images'], true);
            foreach ([is_array($g) ? ($g[0] ?? null) : null, $r['after_image_path']] as $c) {
                if (is_string($c) && MiaStoryPublisher::safeImage($c)) { $img = $c; break; }
            }
            $out[] = ['id' => (int)$r['id'], 'name' => trim((string)$r['project_name']), 'location' => trim((string)$r['location']), 'description' => trim((string)$r['description']), 'image' => $img];
        }
        return $out;
    }

    /** What the owner's approval is bound to for these parts. */
    public static function signature(array $projects): string
    {
        $s = 'RECENT WORK:';
        foreach ($projects as $p) { $s .= "\n#" . $p['id'] . ' ' . $p['name'] . ' | ' . $p['location'] . ' | ' . $p['description']; }
        return $s;
    }

    /** Escaped HTML for the appended sections. $base is the verified https site address (no trailing slash) or ''. */
    public static function html(array $projects, string $base, string $businessName): string
    {
        $e = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $h = '';
        if ($projects) {
            $h .= "<h2>Recent work</h2>\n";
            foreach ($projects as $p) {
                $h .= '<h3>' . $e($p['name']) . "</h3>\n";
                if ($p['location'] !== '') { $h .= '<p><em>' . $e($p['location']) . "</em></p>\n"; }
                if ($p['description'] !== '') { $h .= '<p>' . $e($p['description']) . "</p>\n"; }
            }
        }
        if ($base !== '') {
            $h .= "<h2>Get a free quote</h2>\n<p><a href=\"" . $e($base . '/quote') . '">Ask ' . $e($businessName !== '' ? $businessName : 'us') . " for a free quote</a></p>\n";
        }
        return $h;
    }
}
