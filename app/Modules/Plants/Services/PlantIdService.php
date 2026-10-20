<?php
declare(strict_types=1);

require_once __DIR__ . '/BedLocator.php';

/**
 * PlantIdService — "What is this?" on site (2026-10-15, plan docs/plans/2026-10-15-plant-id-to-bed-care.md).
 *
 * Three photos of one plant (whole plant, closer, close-up) go to Pl@ntNet in ONE identification
 * (up to 5 images per call, organs 'auto'). Its top matches are kept; a name is only suggested when
 * the best score reaches MIN_SCORE — otherwise the answer is "not sure, take a closer photo".
 * Where: the point / accuracy / compass heading the phone sent, else the photos' own GPS; the bed
 * comes from BedLocator on the property's drawn outlines. A person confirms the plant and the bed.
 *
 * Pl@ntNet terms (checked 2026-10-15): free to 500 identifications a day, attribution required
 * (ATTRIBUTION, shown wherever results are), photos not stored by them. DAILY_CAP stays under 500.
 * Each tenant needs its own key before Ittai sells this (the terms don't cover resale).
 */
class PlantIdService
{
    public const API = 'https://my-api.plantnet.org/v2/identify/all';
    public const DAILY_CAP = 450;
    public const MIN_SCORE = 0.20;
    public const MAX_PHOTOS = 5;
    public const ATTRIBUTION = 'The image-based plant species identification service used, is based on the Pl@ntNet recognition API, regularly updated and accessible through the site https://my.plantnet.org/';

    private PDO $db;
    /** @var callable|null fn(string $url, list<array{path: string, mime: string}> $images): array{code: int, body: string} */
    private $transport;
    private ?bool $ready = null;
    private string $root;

    public function __construct(PDO $db, ?callable $transport = null, ?string $root = null)
    {
        $this->db = $db;
        $this->transport = $transport;
        $this->root = $root ?? PUBLIC_ROOT;   // media file_path is web-root relative (/_media/…)
    }

    public function ready(): bool
    {
        if ($this->ready !== null) return $this->ready;
        try {
            $this->db->query('SELECT 1 FROM plant_records LIMIT 0');
            return $this->ready = true;
        } catch (Throwable $e) {
            return $this->ready = false;
        }
    }

    public function hasKey(): bool
    {
        return $this->transport !== null || (defined('PLANTNET_API_KEY') && PLANTNET_API_KEY !== '');
    }

    public function usedToday(): int
    {
        $s = $this->db->prepare('SELECT COUNT(*) FROM plant_records WHERE created_at >= ?');
        $s->execute([date('Y-m-d 00:00:00')]);
        return (int)$s->fetchColumn();
    }

    /**
     * Identify one plant from its photos.
     * @param array{lat?: mixed, lng?: mixed, accuracy?: mixed, heading?: mixed} $where what the phone knew when it took them
     */
    public function identify(int $propertyId, array $mediaIds, array $where, int $userId): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Plant ID needs migration 1367.'];
        if (!$this->hasKey()) return ['ok' => false, 'message' => 'No Pl@ntNet key set up yet.'];
        $mediaIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $mediaIds), fn($i) => $i > 0))), 0, self::MAX_PHOTOS);
        if (!$mediaIds) return ['ok' => false, 'message' => 'Take a photo of the plant first.'];
        if (!$this->propertyExists($propertyId)) return ['ok' => false, 'message' => 'That property is gone.'];
        // Only this property's photos (its own, or a visit's there): never another client's photo to a third party.
        $mediaIds = $this->ofProperty($propertyId, $mediaIds);
        if (!$mediaIds) return ['ok' => false, 'message' => "Those photos aren't from this property."];
        // One identification at a time, so the daily cap can't be overrun by taps at once.
        if (!$this->lock()) return ['ok' => false, 'retry' => true, 'message' => 'Another plant is being identified — try again in a moment.'];
        try {
            // A phone sending on its own may send the same photos again after a lost answer (Android 1.7.1, 2026-10-16):
            // the record already made from them is the answer — no second record, no second Pl@ntNet call.
            // The same SET of photos, in any order (Codex review 2026-10-16).
            $want = $mediaIds; sort($want);
            $again = $this->db->prepare('SELECT id, media_ids FROM plant_records WHERE property_id = ? AND created_at >= ? ORDER BY id DESC LIMIT 200');
            $again->execute([$propertyId, date('Y-m-d H:i:s', time() - 7 * 86400)]);
            $had = 0;
            foreach ($again->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $ids = array_map('intval', array_filter(explode(',', (string)$row['media_ids']), 'strlen')); sort($ids);
                if ($ids === $want) { $had = (int)$row['id']; break; }
            }
            if ($had > 0) {
                $rec = $this->one($had);
                return ['ok' => true, 'repeat' => true, 'record' => $rec, 'bed' => $rec['bed'] ?? null, 'message' => 'Already identified.'];
            }
            return $this->identifyLocked($propertyId, $mediaIds, $where, $userId);
        } finally {
            $this->unlock();
        }
    }

    private function identifyLocked(int $propertyId, array $mediaIds, array $where, int $userId): array
    {
        if ($this->usedToday() >= self::DAILY_CAP) return ['ok' => false, 'retry' => true, 'message' => "That's today's plant identifications used up — try again tomorrow."];

        $media = $this->media($mediaIds);
        $images = [];
        foreach ($mediaIds as $id) {
            $m = $media[$id] ?? null;
            if (!$m || !in_array($m['mime_type'], ['image/jpeg', 'image/png'], true)) continue;
            $path = $this->root . '/' . ltrim((string)$m['file_path'], '/');
            if (is_file($path)) $images[] = ['path' => $path, 'mime' => (string)$m['mime_type']];
        }
        if (!$images) return ['ok' => false, 'message' => "I couldn't read those photos (JPEG or PNG only)."];

        $res = $this->send(self::API . '?nb-results=3&lang=en&include-related-images=true&api-key=' . rawurlencode($this->transport ? 'test' : (string)PLANTNET_API_KEY), $images);
        if ($res['code'] === 404) {
            $matches = [];   // Pl@ntNet: "Species not found" — nothing it recognises
        } elseif ($res['code'] !== 200) {
            error_log('Plant ID: Pl@ntNet HTTP ' . $res['code']);
            return ['ok' => false, 'retry' => true, 'message' => "Pl@ntNet didn't answer just now (" . $res['code'] . '). Try again in a minute.'];
        } else {
            $j = json_decode((string)$res['body'], true);
            // A 200 that isn't Pl@ntNet's JSON (a proxy page, a cut-off answer) is "try again", never "not recognised".
            if (!is_array($j) || !array_key_exists('results', $j)) {
                error_log('Plant ID: Pl@ntNet 200 without results');
                return ['ok' => false, 'retry' => true, 'message' => "Pl@ntNet's answer didn't come through. Try again in a minute."];
            }
            $matches = self::parse($j);
        }

        $at = self::where($where, $mediaIds, $media);
        $bed = BedLocator::locate($at['lat'], $at['lng'], $at['accuracy'], $at['heading'], $this->beds($propertyId));
        $best = $matches[0] ?? null;
        $sure = $best && $best['score'] >= self::MIN_SCORE;
        $this->db->prepare('INSERT INTO plant_records (site_id, property_id, measurement_id, bed_name, bed_how, media_ids, lat, lng, accuracy_m, heading_deg,
                                                       matches, scientific_name, common_name, score, status, created_by, created_at)
                            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$propertyId, $bed['id'], $bed['name'], $bed['how'], implode(',', $mediaIds), $at['lat'], $at['lng'], $at['accuracy'], $at['heading'],
                      json_encode($matches), $sure ? $best['scientific'] : null, $sure ? $best['common'] : null, $sure ? $best['score'] : null,
                      $sure ? 'suggested' : 'unsure', $userId ?: null, date('Y-m-d H:i:s')]);
        $id = (int)$this->db->lastInsertId();
        return ['ok' => true, 'record' => $this->one($id), 'bed' => $bed,
                'message' => $sure ? 'Looks like ' . self::label($best) . '.' : "Not sure — take a closer photo of a leaf or flower and try again."];
    }

    /** Below this best score, or with the top two this close, the person is offered a choice (2026-10-20: 41% vs 29%). */
    public const CHOOSE_BELOW = 0.50;
    public const CHOOSE_GAP = 0.15;

    /** Should the result offer the top matches as a choice instead of one "Confirm"? PURE. */
    public static function shouldChoose(array $matches): bool
    {
        if (count($matches) < 2) return false;
        $a = (float)$matches[0]['score'];
        $b = (float)$matches[1]['score'];
        return $a < self::CHOOSE_BELOW || ($a - $b) < self::CHOOSE_GAP;
    }

    /**
     * A match's reference photo from Pl@ntNet (include-related-images): the first image, medium size, with its
     * credit — CC BY-SA, cited "Photo(s): <author> / Pl@ntNet, CC BY-SA" (my.plantnet.org/doc/references/using-images). PURE.
     * @return array{photo: ?string, photo_credit: ?string}
     */
    public static function photoOf(array $result): array
    {
        foreach ((array)($result['images'] ?? []) as $img) {
            $url = $img['url']['m'] ?? $img['url']['s'] ?? $img['url']['o'] ?? null;
            if (!is_string($url) || !preg_match('#^https://#', $url)) continue;
            $credit = trim((string)($img['citation'] ?? ''));
            if ($credit === '') $credit = 'Photo: ' . (trim((string)($img['author'] ?? '')) ?: 'unknown') . ' / Pl@ntNet, ' . (trim((string)($img['license'] ?? '')) ?: 'CC BY-SA');
            return ['photo' => mb_substr($url, 0, 500), 'photo_credit' => mb_substr($credit, 0, 200)];
        }
        return ['photo' => null, 'photo_credit' => null];
    }

    /** Pl@ntNet's answer → [{scientific, common, family, score, photo, photo_credit}] best first. */
    public static function parse(array $j): array
    {
        $out = [];
        foreach ($j['results'] ?? [] as $r) {
            $sp = $r['species'] ?? [];
            $name = trim((string)($sp['scientificNameWithoutAuthor'] ?? $sp['scientificName'] ?? ''));
            if ($name === '') continue;
            $out[] = ['scientific' => $name, 'common' => trim((string)($sp['commonNames'][0] ?? '')),
                      'family' => trim((string)($sp['family']['scientificNameWithoutAuthor'] ?? '')), 'score' => round((float)($r['score'] ?? 0), 3)]
                     + self::photoOf($r);
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, 3);
    }

    public static function label(array $m): string
    {
        return ($m['common'] !== '' ? $m['common'] . ' (' . $m['scientific'] . ')' : $m['scientific']) . ', ' . (int)round($m['score'] * 100) . '% sure';
    }

    /** The phone's own fix wins when it sent one; else the photo with the best GPS. Heading only from the phone. */
    public static function where(array $w, array $mediaIds, array $media): array
    {
        $num = fn($v, $lo, $hi) => is_numeric($v) && (float)$v >= $lo && (float)$v <= $hi ? (float)$v : null;
        $lat = $num($w['lat'] ?? null, -90, 90);
        $lng = $num($w['lng'] ?? null, -180, 180);
        $acc = $num($w['accuracy'] ?? null, 0, 100000);
        if ($lat === null || $lng === null) {
            $lat = $lng = $acc = null;
            foreach ($mediaIds as $id) {
                $m = $media[$id] ?? null;
                if (!$m || $m['gps_lat'] === null || $m['gps_lng'] === null) continue;
                $a = $m['gps_accuracy_m'] !== null ? (float)$m['gps_accuracy_m'] : null;
                if ($lat === null || ($a !== null && ($acc === null || $a < $acc))) { $lat = (float)$m['gps_lat']; $lng = (float)$m['gps_lng']; $acc = $a; }
            }
        }
        $h = $num($w['heading'] ?? null, 0, 360);
        return ['lat' => $lat, 'lng' => $lng, 'accuracy' => $acc, 'heading' => $h];
    }

    /** A person says what it is and where: a name from the matches or typed, and a bed (0 = none). */
    public function confirm(int $id, array $in, int $userId): array
    {
        $r = $this->one($id);
        if (!$r || $r['status'] === 'removed') return ['ok' => false, 'message' => 'That plant record is gone.'];
        $sci = mb_substr(trim((string)($in['scientific_name'] ?? '')), 0, 160);
        $common = mb_substr(trim((string)($in['common_name'] ?? '')), 0, 160);
        if ($sci === '' && $common === '') return ['ok' => false, 'message' => 'Pick a match or type what it is.'];
        $score = null;
        foreach ($r['matches'] as $m) if ($sci !== '' && strcasecmp($m['scientific'], $sci) === 0) { $score = $m['score']; if ($common === '') $common = $m['common']; }
        $bedId = isset($in['measurement_id']) ? (int)$in['measurement_id'] : -1;
        $bedName = $r['bed_name']; $how = $r['bed_how']; $mid = $r['measurement_id'];
        if ($bedId === 0) { $bedName = null; $how = null; $mid = null; }
        elseif ($bedId > 0) {
            $bed = null;
            foreach ($this->beds((int)$r['property_id']) as $b) if ($b['id'] === $bedId) $bed = $b;
            if (!$bed) return ['ok' => false, 'message' => 'That bed is not on this property.'];
            $mid = $bed['id']; $bedName = $bed['name']; $how = $bedId === (int)$r['measurement_id'] ? $r['bed_how'] : 'picked';
        }
        $this->db->prepare("UPDATE plant_records SET scientific_name = ?, common_name = ?, score = ?, measurement_id = ?, bed_name = ?, bed_how = ?,
                                   notes = ?, status = 'confirmed', confirmed_by = ?, confirmed_at = ?, updated_at = ? WHERE id = ?")
           ->execute([$sci ?: null, $common ?: null, $score, $mid, $bedName, $how, mb_substr(trim((string)($in['notes'] ?? '')), 0, 255) ?: null,
                      $userId ?: null, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
        // Pl@ntNet's best guess vs what the person said: a lesson (twice running = a rule — HeadLessons).
        $top = $r['matches'][0]['scientific'] ?? null;
        if ($top !== null) {
            require_once APP_ROOT . '/Services/HeadLessons.php';
            HeadLessons::recordQuietly($this->db, [
                'head' => 'otto', 'subject_kind' => 'plant_id', 'subject_key' => $top, 'field' => 'species', 'said' => $top,
                'correct' => $sci !== '' ? $sci : $common, 'source' => 'plant_confirm', 'agreement' => true,
                'ref_type' => 'plant_record', 'ref_id' => $id, 'corrected_by' => $userId,
            ]);
        }
        // Its photos teach the crew quiz (SitePlantQuizService, 2026-10-16). Never stops the save.
        try {
            require_once APP_ROOT . '/Modules/Quiz/Services/SitePlantQuizService.php';
            (new SitePlantQuizService($this->db))->fromConfirmed($id);
        } catch (Throwable $e) {
            error_log('PlantIdService confirm → quiz: ' . $e->getMessage());
        }
        return ['ok' => true, 'record' => $this->one($id), 'message' => 'Saved' . ($bedName ? ' in ' . $bedName : '') . '.'];
    }

    public function remove(int $id, int $userId): array
    {
        $s = $this->db->prepare("UPDATE plant_records SET status = 'removed', updated_at = ? WHERE id = ? AND status <> 'removed'");
        $s->execute([date('Y-m-d H:i:s'), $id]);
        if ($s->rowCount()) {
            // A removed plant's photos leave the quiz too.
            try {
                $q = $this->db->prepare("SELECT id, image_path FROM quiz_question_images WHERE plant_record_id = ? AND source = 'site'");
                $q->execute([$id]);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $img) {
                    $this->db->prepare("DELETE FROM quiz_question_images WHERE id = ?")->execute([(int)$img['id']]);
                    if (strpos((string)$img['image_path'], '/uploads/quiz/site_') === 0) @unlink($this->root . $img['image_path']);
                }
            } catch (Throwable $e) { /* before migration 1371 */ }
        }
        return $s->rowCount() ? ['ok' => true, 'message' => 'Removed.'] : ['ok' => false, 'message' => 'Already gone.'];
    }

    /** A property's plants, newest first, each with its bed found again (by id, then name, then where the photo was taken). */
    public function forProperty(int $propertyId): array
    {
        if (!$this->ready()) return [];
        $s = $this->db->prepare("SELECT * FROM plant_records WHERE property_id = ? AND status <> 'removed' ORDER BY id DESC LIMIT 200");
        $s->execute([$propertyId]);
        $beds = $this->beds($propertyId);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = $this->shape($r, $beds);
        return $out;
    }

    public function one(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM plant_records WHERE id = ?');
        $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? $this->shape($r, $this->beds((int)$r['property_id'])) : null;
    }

    private function shape(array $r, array $beds): array
    {
        $r['matches'] = json_decode((string)$r['matches'], true) ?: [];
        $r['choose'] = self::shouldChoose($r['matches']);   // offer the top matches (with photos) instead of one Confirm
        $r['bed'] = self::resolveBed($r, $beds);
        $r['thumbs'] = $this->thumbs(array_map('intval', array_filter(explode(',', (string)$r['media_ids']))));
        return $r;
    }

    /** Find the record's bed in today's outlines: same id + name, then same name, then where the photo was taken. */
    public static function resolveBed(array $r, array $beds): ?array
    {
        $name = (string)($r['bed_name'] ?? '');
        foreach ($beds as $b) if ($r['measurement_id'] !== null && $b['id'] === (int)$r['measurement_id'] && $b['name'] === $name) return ['id' => $b['id'], 'name' => $b['name'], 'sure' => true];
        if ($name !== '') foreach ($beds as $b) if (strcasecmp($b['name'], $name) === 0) return ['id' => $b['id'], 'name' => $b['name'], 'sure' => true];
        if (($r['bed_how'] ?? '') === 'picked' || $r['lat'] === null) return null;
        $l = BedLocator::locate((float)$r['lat'], (float)$r['lng'], $r['accuracy_m'] !== null ? (float)$r['accuracy_m'] : null,
                                $r['heading_deg'] !== null ? (float)$r['heading_deg'] : null, $beds);
        return $l['id'] ? ['id' => $l['id'], 'name' => $l['name'], 'sure' => false] : null;
    }

    /** The property's drawn beds (any measured outline: garden beds, hedges, lawns…). */
    public function beds(int $propertyId): array
    {
        try {
            $s = $this->db->prepare("SELECT id, measurement_name, measurement_type, polygon_coords FROM property_measurements
                                     WHERE property_id = ? AND polygon_coords IS NOT NULL AND polygon_coords <> ''");
            $s->execute([$propertyId]);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $ring = BedLocator::ring((string)$m['polygon_coords']);
            if (!$ring) continue;
            $name = trim((string)$m['measurement_name']) ?: ucwords(str_replace('_', ' ', (string)$m['measurement_type'])) . ' #' . (int)$m['id'];
            $out[] = ['id' => (int)$m['id'], 'name' => $name, 'type' => (string)$m['measurement_type'], 'ring' => $ring];
        }
        return $out;
    }

    /** The ids among $ids linked to this property, directly or through one of its visits (order kept). */
    private function ofProperty(int $propertyId, array $ids): array
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $s = $this->db->prepare("SELECT DISTINCT ml.media_id FROM media_links ml
                                     LEFT JOIN job_visits v ON ml.context_type = 'job_visit' AND v.id = ml.context_id
                                     LEFT JOIN job_plans jp ON jp.id = v.plan_id
                                     WHERE ml.media_id IN ({$in})
                                       AND ((ml.context_type = 'property' AND ml.context_id = ?) OR (ml.context_type = 'job_visit' AND jp.property_id = ?))");
            $s->execute(array_merge($ids, [$propertyId, $propertyId]));
            $ok = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
        return array_values(array_filter($ids, fn($i) => in_array($i, $ok, true)));
    }

    private function lock(): bool
    {
        if ($this->transport) return true;   // tests (SQLite has no GET_LOCK)
        try {
            return (int)$this->db->query("SELECT GET_LOCK('mw_plant_identify', 30)")->fetchColumn() === 1;
        } catch (Throwable $e) {
            return true;
        }
    }

    private function unlock(): void
    {
        if ($this->transport) return;
        try { $this->db->query("SELECT RELEASE_LOCK('mw_plant_identify')"); } catch (Throwable $e) {}
    }

    private function propertyExists(int $id): bool
    {
        $s = $this->db->prepare('SELECT 1 FROM properties WHERE id = ?');
        $s->execute([$id]);
        return (bool)$s->fetchColumn();
    }

    private function media(array $ids): array
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $this->db->prepare("SELECT id, file_path, mime_type, gps_lat, gps_lng, gps_accuracy_m FROM media_assets WHERE id IN ({$in})");
        $s->execute($ids);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $m) $out[(int)$m['id']] = $m;
        return $out;
    }

    private function thumbs(array $ids): array
    {
        if (!$ids) return [];
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $s = $this->db->prepare("SELECT media_id, file_path FROM media_variants WHERE media_id IN ({$in}) AND variant_type = 'thumb_square' AND format = 'jpeg'");
            $s->execute($ids);
            $by = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $v) $by[(int)$v['media_id']] = (string)$v['file_path'];
            return array_values(array_filter(array_map(fn($i) => $by[$i] ?? null, $ids)));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** One multipart POST: every photo as `images`, each with organ `auto`. */
    private function send(string $url, array $images): array
    {
        if ($this->transport) return ($this->transport)($url, $images);
        $boundary = '----mwplant' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($images as $i => $img) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"images\"; filename=\"plant{$i}." . ($img['mime'] === 'image/png' ? 'png' : 'jpg') . "\"\r\n"
                   . "Content-Type: {$img['mime']}\r\n\r\n" . file_get_contents($img['path']) . "\r\n";
        }
        foreach ($images as $_) $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"organs\"\r\n\r\nauto\r\n";
        $body .= "--{$boundary}--\r\n";
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 10,
                                CURLOPT_HTTPHEADER => ['Content-Type: multipart/form-data; boundary=' . $boundary], CURLOPT_POSTFIELDS => $body]);
        $out = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $out === false ? 0 : $code, 'body' => (string)$out];
    }
}
