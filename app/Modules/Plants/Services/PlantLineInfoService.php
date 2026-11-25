<?php
declare(strict_types=1);

require_once __DIR__ . '/PlantWorkService.php';

/**
 * PlantLineInfoService — what the crew sees beside a plant line on the Work Record (2026-11-11).
 *
 * Tim: "an image of New England Aster ... and if now is the time to propagate and/or move it, say that."
 * For every checklist line that carries plant ids, this adds read-only `plant` info to the OUTPUT of the visit-work API:
 *   name       the plant's common name (else scientific)
 *   photo_url  the crew's own photo of that plant (the first of its plant record's photos that has a thumbnail), else null
 *   now        one sentence when an APPROVED care card lists a propagation method whose months include the visit's month
 *              ("Now is a good time to divide or move it."), else null
 * Nothing here is stored: VisitWorkService::sanitizeChecklist drops unknown keys on save, so the checklist JSON is unchanged.
 * Only approved cards speak; a draft card never produces a note. Never throws: the checklist must still load without it.
 *
 * Global namespace, no autoloader: require_once this file, then `new PlantLineInfoService($db)`.
 */
class PlantLineInfoService
{
    private PDO $db;
    /** @var callable(string): ?array  scientific name => approved-or-not care card (PlantCareService::forPlant shape) */
    private $cardFor;

    public function __construct(PDO $db, ?callable $cardFor = null)
    {
        $this->db = $db;
        $this->cardFor = $cardFor ?? function (string $sci): ?array {
            require_once __DIR__ . '/PlantCareService.php';
            static $care = null;
            $care ??= new PlantCareService($this->db);
            return $care->forPlant($sci);
        };
    }

    // ── Pure ──────────────────────────────────────────────────────────────

    /**
     * The sentence for a month, from a card's propagation methods, or null.
     * A method named division / dividing / transplant / moving says "divide or move"; any other method in season says
     * "propagate it (cuttings, seed)". Methods out of season are never mentioned.
     */
    public static function nowNote(array $card, int $month): ?string
    {
        $divide = false; $others = [];
        foreach ((array)($card['card']['propagation'] ?? []) as $m) {
            if (!is_array($m) || !PlantWorkService::inWindow($month, (int)($m['from'] ?? 0), (int)($m['to'] ?? 0))) continue;
            $name = trim((string)($m['method'] ?? ''));
            if ($name === '') continue;
            if (preg_match('/\b(divi|transplant|moving|move|lift)/i', $name)) $divide = true;
            else $others[] = mb_strtolower($name);
        }
        if ($divide) return 'Now is a good time to divide or move it.';
        if ($others) return 'Now is a good time to propagate it: ' . implode(', ', array_slice(array_values(array_unique($others)), 0, 3)) . '.';
        return null;
    }

    // ── Reading ───────────────────────────────────────────────────────────

    /**
     * Add `plant` to each item that has plant ids. Items without plant ids, and every item when anything fails, come back unchanged.
     * Only plants on THIS property (and not removed) are used: a plant id on a line is client data, never trusted to reach another property's records.
     * @param array $items checklist items {item, checked, note, key?, plant_ids?}
     */
    public function decorate(array $items, int $month, int $propertyId): array
    {
        try {
            $ids = [];
            foreach ($items as $it) {
                foreach ((array)($it['plant_ids'] ?? []) as $pid) if ((int)$pid > 0) $ids[(int)$pid] = true;
            }
            if (!$ids || $propertyId < 1) return $items;
            $plants = $this->plants(array_keys($ids), $propertyId);
            $thumbs = $this->thumbs($plants);
            $notes = [];
            foreach ($items as &$it) {
                $pid = 0;
                foreach ((array)($it['plant_ids'] ?? []) as $p) if (isset($plants[(int)$p])) { $pid = (int)$p; break; }
                if ($pid === 0) continue;
                $p = $plants[$pid];
                $sci = (string)$p['scientific_name'];
                if (!array_key_exists($sci, $notes)) {
                    $card = $sci !== '' ? ($this->cardFor)($sci) : null;
                    $notes[$sci] = ($card && ($card['status'] ?? '') === 'approved') ? self::nowNote($card, $month) : null;
                }
                $it['plant'] = ['id' => $pid, 'name' => $p['name'], 'photo_url' => $thumbs[$pid] ?? null, 'now' => $notes[$sci]];
            }
            unset($it);
            return $items;
        } catch (Throwable $e) {
            error_log('PlantLineInfoService::decorate: ' . $e->getMessage());
            return $items;
        }
    }

    /** @return array<int, array{name: string, scientific_name: string, media_ids: int[]}> */
    private function plants(array $ids, int $propertyId): array
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $this->db->prepare("SELECT id, common_name, scientific_name, media_ids FROM plant_records WHERE property_id = ? AND status <> 'removed' AND id IN ({$in})");
        $s->execute(array_merge([$propertyId], array_values($ids)));
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $common = trim((string)($r['common_name'] ?? ''));
            $out[(int)$r['id']] = [
                'name' => $common !== '' ? $common : trim((string)($r['scientific_name'] ?? '')),
                'scientific_name' => trim((string)($r['scientific_name'] ?? '')),
                'media_ids' => array_values(array_filter(array_map('intval', explode(',', (string)($r['media_ids'] ?? ''))), fn($i) => $i > 0)),
            ];
        }
        return $out;
    }

    /** plant id => thumbnail path of its first photo that has one. */
    private function thumbs(array $plants): array
    {
        $media = [];
        foreach ($plants as $p) foreach ($p['media_ids'] as $m) $media[$m] = true;
        if (!$media) return [];
        $in = implode(',', array_fill(0, count($media), '?'));
        $s = $this->db->prepare("SELECT media_id, file_path FROM media_variants WHERE media_id IN ({$in}) AND variant_type = 'thumb_square' AND format = 'jpeg'");
        $s->execute(array_keys($media));
        $path = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $v) $path[(int)$v['media_id']] = (string)$v['file_path'];
        $out = [];
        foreach ($plants as $pid => $p) {
            foreach ($p['media_ids'] as $m) {
                if (isset($path[$m]) && $path[$m] !== '') { $out[$pid] = $path[$m]; break; }
            }
        }
        return $out;
    }
}
