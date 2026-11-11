<?php
declare(strict_types=1);

/**
 * GbifNames — any plant name → its accepted species in GBIF (the global species register), cached.
 *
 * Why: the same plant arrives under different names. Pl@ntNet said "Dendranthema grandiflorum";
 * GBIF's match API (free, no key) says that is a SYNONYM of Chrysanthemum × morifolium (accepted
 * key 3150767) in genus Chrysanthemum (checked on production 2026-10-15). Care cards are keyed by
 * the accepted key, so either name finds the card; the genus finds a "general" card.
 * A failed or doubtful lookup caches NULL for a day and is asked again after that.
 */
class GbifNames
{
    public const API = 'https://api.gbif.org/v1/species/match';
    public const MIN_CONFIDENCE = 90;

    private PDO $db;
    /** @var callable|null fn(string $url): ?array */
    private $fetch;

    public function __construct(PDO $db, ?callable $fetch = null)
    {
        $this->db = $db;
        $this->fetch = $fetch;
    }

    /** @return array{gbif_key: ?int, accepted_name: ?string, genus: ?string, family: ?string} */
    public function resolve(string $name): array
    {
        $key = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        $none = ['gbif_key' => null, 'accepted_name' => null, 'genus' => self::genusOf($name), 'family' => null];
        if ($key === '') return $none;
        try {
            $s = $this->db->prepare('SELECT gbif_key, accepted_name, genus, family, looked_up_at FROM plant_name_keys WHERE name = ?');
            $s->execute([mb_substr($key, 0, 160)]);
            $c = $s->fetch(PDO::FETCH_ASSOC);
            if ($c && ($c['gbif_key'] !== null || strtotime((string)$c['looked_up_at']) > time() - 86400)) {
                return ['gbif_key' => $c['gbif_key'] !== null ? (int)$c['gbif_key'] : null, 'accepted_name' => $c['accepted_name'],
                        'genus' => $c['genus'] ?: $none['genus'], 'family' => $c['family']];
            }
        } catch (Throwable $e) {
            return $none;   // before migration 1368
        }
        $m = $this->fetch ? ($this->fetch)(self::API . '?kingdom=Plantae&name=' . rawurlencode($name)) : $this->get(self::API . '?kingdom=Plantae&name=' . rawurlencode($name));
        $r = self::fromMatch($m ?? []) ?? $none;
        try {
            $this->db->prepare('REPLACE INTO plant_name_keys (name, gbif_key, accepted_name, genus, family, looked_up_at) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([mb_substr($key, 0, 160), $r['gbif_key'], $r['accepted_name'] !== null ? mb_substr($r['accepted_name'], 0, 200) : null,
                          $r['genus'], $r['family'], date('Y-m-d H:i:s')]);
        } catch (Throwable $e) { /* cache is a bonus */ }
        return $r;
    }

    /** GBIF's match answer → the accepted species, or null when it isn't a confident species-level match. */
    public static function fromMatch(array $m): ?array
    {
        $type = (string)($m['matchType'] ?? '');
        $rank = strtoupper((string)($m['rank'] ?? ''));
        if (!in_array($type, ['EXACT', 'FUZZY'], true) || (int)($m['confidence'] ?? 0) < self::MIN_CONFIDENCE) return null;
        if (!in_array($rank, ['SPECIES', 'VARIETY', 'SUBSPECIES', 'FORM'], true)) return null;
        $synonym = strtoupper((string)($m['status'] ?? '')) === 'SYNONYM' || !empty($m['acceptedUsageKey']);
        $key = (int)($synonym ? ($m['acceptedUsageKey'] ?? 0) : ($m['speciesKey'] ?? $m['usageKey'] ?? 0));
        if ($key <= 0) return null;
        // For a synonym GBIF's `species` field names the accepted species ("Chrysanthemum × morifolium").
        return ['gbif_key' => $key, 'accepted_name' => $synonym ? ($m['species'] ?? null) : ($m['species'] ?? $m['canonicalName'] ?? null),
                'genus' => $m['genus'] ?? null, 'family' => $m['family'] ?? null];
    }

    /** "Lavandula angustifolia 'Hidcote'" → "Lavandula". */
    public static function genusOf(string $name): ?string
    {
        return preg_match('/^\s*([A-Z][a-z]+)/', $name, $m) ? $m[1] : null;
    }

    private function get(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_USERAGENT => 'MowologyCRM/1.0']);
        $b = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $b !== false && $code === 200 ? (json_decode((string)$b, true) ?: null) : null;
    }
}
