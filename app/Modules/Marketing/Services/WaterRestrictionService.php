<?php
/**
 * WaterRestrictionService — which Metro Vancouver lawn watering stage is in effect.
 *
 * There is no API. A daily cron fetches Metro Vancouver's water restrictions page and reads
 * "Stage N water restrictions in effect"; if that fails, Burnaby's page ("returned to stage N",
 * or the same heading). The result is kept in ops_settings `mia_water_restriction` as JSON:
 *   stage, checked_at, source, snippet, last_attempt_at, error, season_year, season_max.
 * A failed fetch or an unreadable page never overwrites the last good stage.
 *
 * Outside the restriction season (May 1 – Oct 15) the answer is 0 / none, whatever the page says.
 *
 * What it changes (rules from Metro Vancouver, 2026):
 *   Stage 1  lawns watered once a week; new seed/sod permits (21 days) are issued.
 *   Stage 2  no lawn watering; permits issued in Stage 1 stay valid; no new permits.
 *   Stage 3  no lawn watering; all permits cancelled.
 * So in Stage 2/3 Mia never suggests watering a lawn and holds lawn-seeding campaigns.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class WaterRestrictionService
{
    public const SETTING = 'mia_water_restriction';
    public const METRO_URL = 'https://metrovancouver.org/services/water/water-restrictions';
    public const FALLBACK_URL = 'https://www.burnaby.ca/services-and-payments/water-and-sewers/watering-restrictions';
    public const DORMANCY_URL = 'https://metrovancouver.org/services/water/seasonal-lawn-care';
    public const SEASON_FROM = '05-01';
    public const SEASON_TO = '10-15';

    private ?PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested with fixture HTML)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Read the stage from a page. Scripts, styles and tags are stripped first (Metro's page
     * carries "Stage 3" inside a search script), and zero-width spaces removed.
     * @return array{stage: ?int, snippet: string}
     */
    public static function parse(string $html): array
    {
        $html = (string)preg_replace('#<(script|style|noscript)\b.*?</\1>#is', ' ', $html);
        $html = (string)preg_replace('#<br\s*/?>|</(p|h\d|div|li)>#i', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string)preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', ' ', $text);
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        $patterns = [
            '/\bStage\s*(\d)\s+(?:water(?:ing)?\s+)?restrictions?\s+(?:are\s+)?(?:now\s+)?in\s+effect/i',
            '/\breturned\s+to\s+stage\s*(\d)\b/i',
            '/\bmoved?\s+to\s+stage\s*(\d)\b/i',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
                $stage = (int)$m[1][0];
                if ($stage < 1 || $stage > 4) continue;
                $at = max(0, $m[0][1] - 60);
                return ['stage' => $stage, 'snippet' => mb_strcut($text, $at, 220, 'UTF-8')];
            }
        }
        return ['stage' => null, 'snippet' => ''];
    }

    /** Is the date (Y-m-d) inside the restriction season, May 1 – Oct 15? */
    public static function inSeason(string $ymd): bool
    {
        $md = substr($ymd, 5, 5);
        return $md >= self::SEASON_FROM && $md <= self::SEASON_TO;
    }

    /** The stage that applies on a date: 0 out of season, else what the page said (null = unknown). */
    public static function effective(?int $parsed, string $ymd): ?int
    {
        return self::inSeason($ymd) ? $parsed : 0;
    }

    /** "Stage 1 · checked today" / "None (season ended Oct 15)" / "Unknown · not checked yet". */
    public static function label(?int $stage, ?string $checkedAt, DateTimeImmutable $today): string
    {
        $t = $today->format('Y-m-d');
        if (!self::inSeason($t)) {
            return 'None · season is May 1 to Oct 15';
        }
        $when = 'not checked yet';
        if ($checkedAt) {
            $d = substr($checkedAt, 0, 10);
            $days = (int)floor((strtotime($t) - strtotime($d)) / 86400);
            $when = $days <= 0 ? 'checked today' : ($days === 1 ? 'checked yesterday' : "checked $days days ago");
        }
        return ($stage === null ? 'Unknown' : "Stage $stage") . " · $when";
    }

    /**
     * Fold a new reading into the stored state. A null stage (fetch or parse failed) keeps the
     * last good value and only records the attempt.
     */
    public static function merge(array $prev, ?int $stage, string $source, string $snippet, string $now, ?string $error = null): array
    {
        $out = $prev;
        $out['last_attempt_at'] = $now;
        $out['error'] = $error;
        if ($stage === null) return $out;
        $year = (int)substr($now, 0, 4);
        if ((int)($out['season_year'] ?? 0) !== $year) {
            $out['season_year'] = $year;
            $out['season_max'] = 0;
        }
        if (self::inSeason(substr($now, 0, 10))) $out['season_max'] = max((int)($out['season_max'] ?? 0), $stage);
        $out['stage'] = $stage;
        $out['checked_at'] = $now;
        $out['source'] = $source;
        $out['snippet'] = mb_strcut($snippet, 0, 220, 'UTF-8');
        $out['error'] = null;
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Stored state
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * What Mia knows today.
     * @return array{stage: ?int, label: string, checked_at: ?string, source: ?string, drought_year: bool, in_season: bool}
     */
    public function current(?DateTimeImmutable $today = null): array
    {
        $today = $today ?? new DateTimeImmutable('today');
        $s = $this->stored();
        $t = $today->format('Y-m-d');
        $stage = isset($s['stage']) ? (int)$s['stage'] : null;
        $sameYear = (int)($s['season_year'] ?? 0) === (int)$today->format('Y');
        return [
            'stage'        => self::effective($stage, $t),
            'label'        => self::label($stage, $s['checked_at'] ?? null, $today),
            'checked_at'   => $s['checked_at'] ?? null,
            'source'       => $s['source'] ?? null,
            'drought_year' => $sameYear && (int)($s['season_max'] ?? 0) >= 2,
            'in_season'    => self::inSeason($t),
        ];
    }

    public function stored(): array
    {
        if (!$this->db) return [];
        try {
            $q = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ? LIMIT 1");
            $q->execute([self::SETTING]);
            $v = json_decode((string)$q->fetchColumn(), true);
            return is_array($v) ? $v : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Fetch, parse and store. Never throws for a network or parse failure. */
    public function refresh(?string $now = null): array
    {
        $now = $now ?? date('Y-m-d H:i:s');
        $stage = null;
        $source = '';
        $snippet = '';
        $errors = [];
        foreach ([self::METRO_URL, self::FALLBACK_URL] as $url) {
            $html = $this->fetch($url);
            if ($html === null) { $errors[] = 'fetch failed: ' . $url; continue; }
            $p = self::parse($html);
            if ($p['stage'] === null) { $errors[] = 'no stage found: ' . $url; continue; }
            $stage = $p['stage'];
            $source = $url;
            $snippet = $p['snippet'];
            break;
        }
        $state = self::merge($this->stored(), $stage, $source, $snippet, $now, $stage === null ? implode('; ', $errors) : null);
        $this->store($state);
        return ['ok' => $stage !== null, 'stage' => $stage, 'source' => $source, 'error' => $state['error'] ?? null];
    }

    private function store(array $state): void
    {
        if (!$this->db) return;
        try {
            $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, 'Mia: Metro Vancouver watering stage (daily check)')
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                ->execute([self::SETTING, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) {
            error_log('WaterRestrictionService store: ' . $e->getMessage());
        }
    }

    /** GET a page; null on any failure. Protected so tests can feed fixture HTML. */
    protected function fetch(string $url): ?string
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; Mowology-CRM/1.0; watering restriction check; +https://mowology.ca)',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false || $code !== 200 || $body === '') {
            error_log("WaterRestrictionService fetch $code " . curl_error($ch) . " $url");
            return null;
        }
        return (string)$body;
    }
}
