<?php
/**
 * CatalogTaxonService — a common name and a reference photo for each catalogue plant, from iNaturalist
 * (Tim, 2026-10-20: "how do we get common names and photos?" — for his own quote screens, not clients).
 *
 * One free call per SPECIES (not per cultivar): `GET api.inaturalist.org/v1/taxa?q=Acer palmatum` answers
 * "Japanese maple" and a photo with its licence in one response. All cultivars of a species share it; a
 * line with no species ("Hosta x 'Blue Angel'") falls back to its genus ("hostas"). Checked on live
 * names 2026-10-20: the FIRST hit can be the wrong rank ("Acer ser. Palmata"), so a result counts only
 * when its name equals the key exactly and its rank is right.
 *
 * SHARED (Ittai): public botanical facts, no site_id. Writes catalog_taxa (cache, one row per key) and, on
 * catalog_products, species_key + common_names (appended, never overwritten: a name the supplier printed wins).
 * Photos: only licences that allow a resized copy shown with credit (CC0, BY, BY-SA, BY-NC, BY-NC-SA — not
 * ND, not all-rights-reserved). The ~500 px image is COPIED to uploads/taxa/ and served from our own host,
 * so the CSP's img-src never has to widen and a photo cannot vanish from under a quote screen.
 * Internal screens only: if this ever reaches a client page, NC licences must go.
 */
declare(strict_types=1);

require_once __DIR__ . '/PlantNameNormalizer.php';

class CatalogTaxonService
{
    public const API = 'https://api.inaturalist.org/v1/taxa';
    public const UA = 'MowologyCRM/1.0 (office@mowology.ca)';
    public const LICENSES = ['cc0', 'cc-by', 'cc-by-sa', 'cc-by-nc', 'cc-by-nc-sa'];
    public const MAX_PHOTO_BYTES = 2 * 1024 * 1024;
    public const RETRY_NONE_DAYS = 30;
    public const RETRY_ERROR_DAYS = 1;

    private PDO $db;
    /** @var callable fn(string $url): array{code: int, body: string} */
    private $http;
    /** @var callable fn(int $microseconds): void */
    private $sleep;
    private string $photoDir;
    private bool $called = false;

    public function __construct(PDO $db, ?callable $http = null, ?string $photoDir = null, ?callable $sleep = null)
    {
        $this->db = $db;
        $this->http = $http ?? [self::class, 'curl'];
        $this->photoDir = $photoDir ?? ((defined('PUBLIC_ROOT') ? PUBLIC_ROOT : dirname(__DIR__, 4) . '/public') . '/uploads/taxa');
        $this->sleep = $sleep ?? static function (int $us): void { usleep($us); };
    }

    // ---- pure -------------------------------------------------------------------------------

    /**
     * A catalogue plant's match_key ("acer|palmatum dissectum|firecracker|") → lookup keys, best first:
     * "acer palmatum" (species) then "acer" (genus). A line with no species has only the genus.
     * @return string[]
     */
    public static function keysFor(string $matchKey): array
    {
        $p = PlantNameNormalizer::fromKey($matchKey);
        $genus = trim((string)($p['genus'] ?? ''));
        if ($genus === '' || !preg_match('/^[a-z]{3,}$/', $genus) || in_array($genus, ['assorted', 'mixed', 'misc', 'various'], true)) return [];
        $species = trim((string)strtok(trim((string)($p['species'] ?? '')), ' '));
        return $species !== '' && preg_match('/^[a-z-]{3,}$/', $species) && $species !== 'var' ? [$genus . ' ' . $species, $genus] : [$genus];
    }

    /** iNaturalist's list → the one result that IS this key (exact name, right rank), or null. */
    public static function pick(array $results, string $key): ?array
    {
        $isGenus = strpos($key, ' ') === false;
        $ranks = $isGenus ? ['genus', 'genushybrid'] : ['species', 'hybrid'];
        $best = null;
        foreach ($results as $r) {
            $name = mb_strtolower(trim((string)preg_replace('/\s+/', ' ', str_replace(["\u{00D7}", ' x '], ' ', (string)($r['name'] ?? '')))));
            $rank = strtolower((string)($r['rank'] ?? ''));
            if ($name !== $key || !in_array($rank, $ranks, true)) continue;
            // species before hybrid when both exist
            if ($best === null || array_search($rank, $ranks, true) < array_search(strtolower((string)$best['rank']), $ranks, true)) $best = $r;
        }
        return $best;
    }

    /** @return array{common_name: ?string, photo_url: ?string, photo_credit: ?string, photo_license: ?string, taxon_id: ?int, rank: ?string} */
    public static function describe(array $taxon): array
    {
        $photo = (array)($taxon['default_photo'] ?? []);
        $lic = strtolower(trim((string)($photo['license_code'] ?? '')));
        $url = trim((string)($photo['medium_url'] ?? ''));
        $ok = $url !== '' && in_array($lic, self::LICENSES, true) && strncmp($url, 'https://', 8) === 0;
        $common = trim((string)($taxon['preferred_common_name'] ?? ''));
        return [
            'common_name' => $common !== '' ? mb_substr(mb_convert_case($common, MB_CASE_TITLE, 'UTF-8'), 0, 160) : null,
            'photo_url' => $ok ? $url : null,
            'photo_credit' => $ok ? mb_substr(trim((string)($photo['attribution'] ?? '')) ?: 'iNaturalist', 0, 255) : null,
            'photo_license' => $ok ? $lic : null,
            'taxon_id' => isset($taxon['id']) ? (int)$taxon['id'] : null,
            'rank' => isset($taxon['rank']) ? (string)$taxon['rank'] : null,
        ];
    }

    /** Add a common name to a "|"-separated list unless it is already there (any case). PURE. */
    public static function addName(?string $list, string $name): string
    {
        $names = array_values(array_filter(array_map('trim', explode('|', (string)$list)), fn($n) => $n !== ''));
        foreach ($names as $n) if (mb_strtolower($n) === mb_strtolower($name)) return implode('|', $names);
        $names[] = $name;
        return mb_substr(implode('|', $names), 0, 255);
    }

    // ---- lookup -------------------------------------------------------------------------------

    /**
     * The cached row for a key, asking iNaturalist when there is none or its retry time has come.
     * @return array|null the catalog_taxa row (status found | none | error)
     * @throws RuntimeException on HTTP 429 so a batch stops at once
     */
    public function lookup(string $key): ?array
    {
        $s = $this->db->prepare("SELECT * FROM catalog_taxa WHERE species_key = ?");
        $s->execute([$key]);
        $row = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row && !$this->due($row)) return $row;

        if ($this->called) ($this->sleep)(1000000);   // one call a second: iNaturalist asks for under 60 a minute
        $this->called = true;
        $res = ($this->http)(self::API . '?' . http_build_query(['q' => $key, 'per_page' => 10, 'is_active' => 'true', 'locale' => 'en']));
        if ((int)$res['code'] === 429) throw new RuntimeException('iNaturalist rate limit');
        $new = ['status' => 'error', 'rank' => null, 'taxon_id' => null, 'common_name' => null, 'photo_file' => null, 'photo_credit' => null, 'photo_license' => null];
        if ((int)$res['code'] === 200) {
            $json = json_decode((string)$res['body'], true);
            if (is_array($json) && isset($json['results'])) {
                $hit = self::pick((array)$json['results'], $key);
                $new['status'] = 'none';
                if ($hit) {
                    $d = self::describe($hit);
                    $new = ['status' => 'found', 'rank' => $d['rank'], 'taxon_id' => $d['taxon_id'], 'common_name' => $d['common_name'],
                            'photo_file' => null, 'photo_credit' => $d['photo_credit'], 'photo_license' => $d['photo_license']];
                    if ($d['photo_url']) {
                        $new['photo_file'] = $this->savePhoto($key, $d['photo_url']);
                        if ($new['photo_file'] === null) { $new['photo_credit'] = null; $new['photo_license'] = null; }
                    }
                }
            }
        }
        $now = date('Y-m-d H:i:s');
        if ($row) {
            $this->db->prepare("UPDATE catalog_taxa SET taxon_rank = ?, inat_taxon_id = ?, common_name = ?, photo_file = COALESCE(?, photo_file), photo_credit = ?, photo_license = ?,
                                       status = ?, looked_up_at = ? WHERE species_key = ?")
                     ->execute([$new['rank'], $new['taxon_id'], $new['common_name'], $new['photo_file'], $new['photo_credit'], $new['photo_license'], $new['status'], $now, $key]);
        } else {
            $this->db->prepare("INSERT INTO catalog_taxa (species_key, taxon_rank, inat_taxon_id, common_name, photo_file, photo_credit, photo_license, status, looked_up_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                     ->execute([$key, $new['rank'], $new['taxon_id'], $new['common_name'], $new['photo_file'], $new['photo_credit'], $new['photo_license'], $new['status'], $now]);
        }
        $s->execute([$key]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function due(array $row): bool
    {
        if ($row['status'] === 'found') return false;
        $days = $row['status'] === 'none' ? self::RETRY_NONE_DAYS : self::RETRY_ERROR_DAYS;
        return strtotime((string)$row['looked_up_at']) < time() - $days * 86400;
    }

    private function savePhoto(string $key, string $url): ?string
    {
        try {
            $res = ($this->http)($url);
            $bytes = (string)$res['body'];
            if ((int)$res['code'] !== 200 || $bytes === '' || strlen($bytes) > self::MAX_PHOTO_BYTES) return null;
            $info = @getimagesizeFromString($bytes);
            $ext = $info ? [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? null : null;
            if ($ext === null) return null;
            if (!is_dir($this->photoDir) && !@mkdir($this->photoDir, 0755, true) && !is_dir($this->photoDir)) return null;
            $name = substr(sha1($key), 0, 16) . '.' . $ext;
            return @file_put_contents($this->photoDir . '/' . $name, $bytes) === false ? null : $name;
        } catch (Throwable $e) {
            error_log('[taxa] photo: ' . $e->getMessage());
            return null;
        }
    }

    // ---- batch --------------------------------------------------------------------------------

    /**
     * Name and photo for up to $limit distinct keys not yet looked up. Stops on a rate limit.
     * @return array{keys: int, found: int, none: int, error: int, products: int, stopped: ?string}
     */
    public function enrichBatch(int $limit = 40): array
    {
        $out = ['keys' => 0, 'found' => 0, 'none' => 0, 'error' => 0, 'products' => 0, 'stopped' => null];
        $byKey = [];
        foreach ($this->db->query("SELECT id, match_key FROM catalog_products WHERE family = 'plants' AND species_key IS NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $chain = self::keysFor((string)$p['match_key']);
            if (!$chain) { $this->setKey((int)$p['id'], ''); continue; }       // not a plant name: never asked again
            $byKey[$chain[0]]['ids'][] = (int)$p['id'];
            $byKey[$chain[0]]['chain'] = $chain;
        }
        foreach ($byKey as $first => $g) {
            if ($out['keys'] >= $limit) break;
            $out['keys']++;
            try {
                $use = null;
                foreach ($g['chain'] as $key) {
                    $row = $this->lookup($key);
                    $out[$row['status'] ?? 'error']++;
                    if ($row && $row['status'] === 'found') { $use = $row; break; }
                    if ($row && $row['status'] === 'error') break;
                }
            } catch (RuntimeException $e) {
                $out['stopped'] = $e->getMessage();
                break;
            }
            $key = $use ? (string)$use['species_key'] : $first;
            foreach ($g['ids'] as $id) {
                $this->setKey($id, $key);
                $out['products']++;
            }
            if ($use) $this->applyName($key);
        }
        return $out;
    }

    /** Plants still waiting for a name lookup (0 before migration 1391). */
    public function pending(): int
    {
        try {
            return (int)$this->db->query("SELECT COUNT(*) FROM catalog_products WHERE family = 'plants' AND species_key IS NULL")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function setKey(int $productId, string $key): void
    {
        $this->db->prepare("UPDATE catalog_products SET species_key = ? WHERE id = ?")->execute([$key, $productId]);
    }

    /** Append the key's common name to every product on it. */
    public function applyName(string $key): int
    {
        $s = $this->db->prepare("SELECT common_name FROM catalog_taxa WHERE species_key = ? AND status = 'found'");
        $s->execute([$key]);
        $name = (string)$s->fetchColumn();
        if ($name === '') return 0;
        $n = 0;
        $q = $this->db->prepare("SELECT id, common_names FROM catalog_products WHERE species_key = ?");
        $q->execute([$key]);
        $u = $this->db->prepare("UPDATE catalog_products SET common_names = ? WHERE id = ?");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $new = self::addName($p['common_names'], $name);
            if ($new !== (string)$p['common_names']) { $u->execute([$new, (int)$p['id']]); $n++; }
        }
        return $n;
    }

    /** @return array{code: int, body: string} */
    public static function curl(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 20,
                                CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => self::UA, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => is_string($body) ? $body : ''];
    }
}
