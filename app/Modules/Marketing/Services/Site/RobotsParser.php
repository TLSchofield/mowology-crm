<?php
/**
 * RobotsParser — robots.txt rules for Mia's crawler (RFC 9309 semantics, kept small).
 *
 *   - picks the group for our agent token ("IttaiMiaBot"), else the "*" group; groups with the same
 *     agent merge;
 *   - the LONGEST matching rule wins, and Allow beats Disallow when they are the same length;
 *   - "*" matches any run of characters and a trailing "$" anchors the end of the path;
 *   - an empty "Disallow:" allows everything;
 *   - Crawl-delay is read (seconds) and capped by the caller; Sitemap lines are collected.
 *
 * How the file's availability is treated is decided by forStatus(): 2xx = parse it; 4xx = no rules
 * (allow all); 5xx or unreachable = disallow all for this run (we cannot know what the owner forbids).
 *
 * Pure. Global namespace, no autoloader. PHP 7.4-compatible.
 */
declare(strict_types=1);

final class RobotsParser
{
    public const AGENT = 'ittaimiabot';
    /** Only this much of the file is read (RFC 9309 asks crawlers to parse at least 500 KiB). */
    public const MAX_BYTES = 512000;

    /** @var array<int,array{allow:bool,pattern:string,len:int}> */
    private $rules = [];
    /** @var float|null */
    private $crawlDelay = null;
    /** @var string[] */
    private $sitemaps = [];
    /** @var bool */
    private $disallowAll = false;

    public static function allowAll(): self
    {
        return new self();
    }

    public static function disallowAll(): self
    {
        $r = new self();
        $r->disallowAll = true;
        return $r;
    }

    /** The parser to use for a robots.txt fetch result. */
    public static function forStatus(int $status, string $body): self
    {
        if ($status >= 200 && $status < 300) { return self::parse($body); }
        if ($status >= 400 && $status < 500) { return self::allowAll(); }
        return self::disallowAll();
    }

    public static function parse(string $body): self
    {
        $self = new self();
        $body = substr($body, 0, self::MAX_BYTES);
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;

        $groups = [];            // each: ['agents' => string[], 'rules' => [], 'delay' => ?float]
        $cur = null;
        $lastWasAgent = false;
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || strpos($line, ':') === false) { continue; }
            list($k, $v) = array_map('trim', explode(':', $line, 2));
            $k = strtolower($k);
            if ($k === 'user-agent') {
                if ($cur === null || !$lastWasAgent) { $groups[] = ['agents' => [], 'rules' => [], 'delay' => null]; $cur = count($groups) - 1; }
                $groups[$cur]['agents'][] = strtolower($v);
                $lastWasAgent = true;
                continue;
            }
            if ($k === 'sitemap') { if ($v !== '') { $self->sitemaps[] = $v; } continue; }      // does not end a group (RFC 9309 2.2.4)
            $lastWasAgent = false;
            if ($cur === null) { continue; }
            if ($k === 'allow' || $k === 'disallow') {
                if ($v === '') { continue; }              // empty Disallow = nothing is forbidden, empty Allow = no-op
                $pat = self::normalizePercent($v);
                // Specificity = the literal path characters; "*" and the closing "$" do not count (RFC 9309).
                $groups[$cur]['rules'][] = ['allow' => $k === 'allow', 'pattern' => $pat,
                                            'len' => strlen(str_replace('*', '', substr($pat, -1) === '$' ? substr($pat, 0, -1) : $pat))];
            } elseif ($k === 'crawl-delay' && is_numeric($v) && (float)$v >= 0) {
                $groups[$cur]['delay'] = (float)$v;
            }
        }

        // Our own group if there is one, otherwise "*".
        $pick = function (string $agent) use ($groups) {
            $rules = []; $delay = null; $found = false;
            foreach ($groups as $g) {
                if (in_array($agent, $g['agents'], true)) { $found = true; $rules = array_merge($rules, $g['rules']); $delay = $g['delay'] ?? $delay; }
            }
            return $found ? ['rules' => $rules, 'delay' => $delay] : null;
        };
        $mine = $pick(self::AGENT) ?: $pick('*');
        if ($mine) { $self->rules = $mine['rules']; $self->crawlDelay = $mine['delay']; }
        $self->sitemaps = array_values(array_unique($self->sitemaps));
        return $self;
    }

    /** May we fetch this path? $path includes the query string, e.g. "/a/b?x=1". */
    public function isAllowed(string $path): bool
    {
        if ($this->disallowAll) { return false; }
        $path = self::normalizePercent($path);
        if ($path === '' || $path[0] !== '/') { $path = '/' . $path; }
        if ($path === '/robots.txt') { return true; }
        $best = null;
        foreach ($this->rules as $r) {
            if (!self::matches($r['pattern'], $path)) { continue; }
            if ($best === null || $r['len'] > $best['len'] || ($r['len'] === $best['len'] && $r['allow'] && !$best['allow'])) { $best = $r; }
        }
        return $best === null ? true : $best['allow'];
    }

    public function crawlDelay(): ?float
    {
        return $this->crawlDelay;
    }

    /** @return string[] */
    public function sitemaps(): array
    {
        return $this->sitemaps;
    }

    /**
     * True when nothing can be crawled: robots.txt was unusable, or the whole site is disallowed and there is
     * no Allow rule that could open part of it ("Disallow: /" with "Allow: /public/" still permits /public/).
     */
    public function blocksEverything(): bool
    {
        if ($this->disallowAll) { return true; }
        if ($this->isAllowed('/')) { return false; }
        foreach ($this->rules as $r) { if ($r['allow']) { return false; } }
        return true;
    }

    /**
     * Decode percent-escapes of unreserved characters (A-Z a-z 0-9 - . _ ~) and upper-case the rest, so
     * "/%61dmin" and "/admin" are the same path (RFC 3986 / 9309). Reserved escapes such as %2F stay encoded.
     */
    public static function normalizePercent(string $s): string
    {
        return preg_replace_callback('/%([0-9a-fA-F]{2})/', function ($m) {
            $c = chr(hexdec($m[1]));
            return preg_match('/^[A-Za-z0-9\-._~]$/', $c) ? $c : '%' . strtoupper($m[1]);
        }, $s) ?? $s;
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = substr($pattern, -1) === '$';
        if ($anchored) { $pattern = substr($pattern, 0, -1); }
        $re = '';
        foreach (explode('*', $pattern) as $i => $part) {
            if ($i > 0) { $re .= '.*'; }
            $re .= preg_quote($part, '#');
        }
        return (bool)preg_match('#^' . $re . ($anchored ? '$' : '') . '#', $path);
    }
}
