<?php
/**
 * SitePlantQuizService — the crew quiz learns from our own plant photos (2026-10-16, Tim: "need to learn faster
 * with our photos").
 *
 * When a plant at a property is CONFIRMED (PlantIdService::confirm), its closer and close-up photos are added to that
 * plant's quiz question, captioned with where it grows ("Seen at 1935 West 12th Avenue · Front bed"). The whole-plant
 * shot is left out (it can show the house). Each quiz picture is a resized copy with the photo's metadata (GPS)
 * stripped, beside the other quiz images in /uploads/quiz. A question keeps its newest SITE_CAP site photos, one per
 * property first. A confirmed plant with no question gets a photo-ID question DRAFTED (inactive) for the office to
 * approve in Quiz admin. The pre-shift quiz puts questions with photos from today's sites first (mergeSiteFirst).
 *
 * Questions are linked to plants by quiz_questions.plant_key (lower-case scientific name; migration 1371), filled by
 * linkQuestions() from the care library's names. Tenant-neutral; global namespace (require_once + new).
 */
declare(strict_types=1);

class SitePlantQuizService
{
    public const SITE_CAP = 6;
    public const PLANT_CATEGORIES = [1, 2];   // Weed Identification, Plant & Grass ID (quiz_categories)
    public const WEED_CATEGORY = 1;
    public const PLANT_CATEGORY = 2;

    private PDO $db;
    /** @var callable(string, string): bool  copy+resize a media file (abs path) to a quiz image (abs path) */
    private $copier;
    /** @var callable(string): ?array  care card for a scientific name: {common_name, weed, notes} or null */
    private $care;
    private string $root;

    public function __construct(PDO $db, ?callable $copier = null, ?callable $care = null, ?string $root = null)
    {
        $this->db = $db;
        $this->root = $root ?? (defined('PUBLIC_ROOT') ? PUBLIC_ROOT : '');
        $this->copier = $copier ?? [self::class, 'copyResized'];
        $this->care = $care ?? function (string $sci): ?array {
            require_once APP_ROOT . '/Modules/Plants/Services/PlantCareService.php';
            $svc = new PlantCareService($this->db);
            $c = $svc->ready() ? $svc->forPlant($sci) : null;
            if (!$c) return null;
            $card = is_array($c['card'] ?? null) ? $c['card'] : [];
            return ['common_name' => (string)($c['common_name'] ?? ''), 'approved' => ($c['status'] ?? '') === 'approved',
                    'weed' => !empty($card['weed']) || ($c['plant_group'] ?? '') === 'weed',
                    'notes' => (string)($card['notes'] ?? ''), 'tasks' => (array)($card['tasks'] ?? [])];
        };
    }

    // ── Pure rules (tested) ────────────────────────────────────────────────────────────────────────

    /** "Annual Bluegrass (Poa annua)" → "annual bluegrass"; letters and spaces only, lower case. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower(preg_replace('/\([^)]*\)/u', ' ', $s));
        $s = preg_replace('/[^\p{L}\s]+/u', ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * The plant a question's correct answer names, from the care library: equal names (common or scientific), or the
     * scientific name in the answer's brackets ("Annual Bluegrass (Poa annua)"). Returns the lower-case scientific name.
     * @param array<int, array{scientific_name: string, common_name: ?string}> $cards
     */
    public static function matchKey(string $correct, array $cards): ?string
    {
        $c = self::norm($correct);
        if ($c === '') return null;
        preg_match('/\(([^)]*)\)/u', $correct, $m);
        $inBrackets = isset($m[1]) ? self::norm($m[1]) : '';
        foreach ($cards as $card) {
            $sci = self::norm((string)$card['scientific_name']);
            $com = self::norm((string)($card['common_name'] ?? ''));
            if ($sci !== '' && ($c === $sci || $inBrackets === $sci)) return mb_strtolower(trim((string)$card['scientific_name']));
            if ($com !== '' && $c === $com) return mb_strtolower(trim((string)$card['scientific_name']));
        }
        return null;
    }

    /** "Seen at 1935 West 12th Avenue · Front bed" — the street address, then the bed when there is one. */
    public static function caption(?string $address, ?string $bed): string
    {
        $a = trim(preg_replace('/\s+/', ' ', (string)$address));
        $b = trim((string)$bed);
        $out = $a !== '' ? 'Seen at ' . $a : 'Seen on one of our sites';
        return mb_substr($out . ($b !== '' ? ' · ' . $b : ''), 0, 250);
    }

    /** The photos that go in a quiz: closer + close-up (2nd and 3rd); with fewer, the last one. Never the whole shot of 3. */
    public static function pickMedia(array $mediaIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $mediaIds), fn($i) => $i > 0));
        if (count($ids) >= 3) return [$ids[1], $ids[2]];
        if (count($ids) === 2) return [$ids[1]];
        return $ids;
    }

    /**
     * Which site photos to drop so a question keeps $cap: the newest of each property first, then the newest others.
     * @param array<int, array{id: int, property_id: ?int, created_at: string}> $rows
     * @return int[] ids to remove
     */
    public static function toTrim(array $rows, int $cap = self::SITE_CAP): array
    {
        usort($rows, fn($a, $b) => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);
        $keep = []; $seen = [];
        foreach ($rows as $r) { $p = (int)($r['property_id'] ?? 0); if (!isset($seen[$p]) && count($keep) < $cap) { $keep[(int)$r['id']] = true; $seen[$p] = true; } }
        foreach ($rows as $r) { if (count($keep) >= $cap) break; $keep[(int)$r['id']] = true; }
        return array_values(array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => !isset($keep[(int)$r['id']]))));
    }

    /** Up to $maxSite of today's site questions first (not already in), then the rest, trimmed to $len. */
    public static function mergeSiteFirst(array $qids, array $siteIds, int $len, int $maxSite = 2): array
    {
        $front = array_slice(array_values(array_unique(array_map('intval', $siteIds))), 0, $maxSite);
        $rest = array_values(array_filter(array_map('intval', $qids), fn($q) => !in_array($q, $front, true)));
        return array_slice(array_merge($front, $rest), 0, max(1, $len));
    }

    // ── Linking questions to plants ────────────────────────────────────────────────────────────────

    /** Fill plant_key on unlinked plant / weed questions from the care library's names. Returns how many were linked. */
    public function linkQuestions(): int
    {
        $cards = $this->db->query("SELECT scientific_name, common_name FROM plant_care_cards")->fetchAll(PDO::FETCH_ASSOC);
        if (!$cards) return 0;
        $in = implode(',', self::PLANT_CATEGORIES);
        $qs = $this->db->query("SELECT q.id, (SELECT o.option_text FROM quiz_options o WHERE o.question_id = q.id AND o.is_correct = 1 ORDER BY o.id LIMIT 1) correct
                                FROM quiz_questions q WHERE q.category_id IN ($in) AND (q.plant_key IS NULL OR q.plant_key = '')")->fetchAll(PDO::FETCH_ASSOC);
        $n = 0;
        $upd = $this->db->prepare("UPDATE quiz_questions SET plant_key = ? WHERE id = ?");
        foreach ($qs as $q) {
            $key = self::matchKey((string)$q['correct'], $cards);
            if ($key !== null) { $upd->execute([$key, (int)$q['id']]); $n++; }
        }
        return $n;
    }

    // ── From a confirmed plant ─────────────────────────────────────────────────────────────────────

    /**
     * Add a confirmed plant's photos to its question (drafting one when there is none).
     * @return array{ok: bool, question_id?: int, drafted?: bool, added?: int, message?: string}
     */
    public function fromConfirmed(int $recordId): array
    {
        $r = $this->db->prepare("SELECT pr.id, pr.property_id, pr.media_ids, pr.bed_name, pr.scientific_name, pr.common_name, pr.status, p.address
                                 FROM plant_records pr LEFT JOIN properties p ON p.id = pr.property_id WHERE pr.id = ?");
        $r->execute([$recordId]);
        $rec = $r->fetch(PDO::FETCH_ASSOC);
        if (!$rec || $rec['status'] !== 'confirmed') return ['ok' => false, 'message' => 'not confirmed'];
        $sci = trim((string)$rec['scientific_name']);
        $common = trim((string)$rec['common_name']);
        if ($sci === '' && $common === '') return ['ok' => false, 'message' => 'no name'];

        $qid = $this->questionFor($sci, $common);
        $drafted = false;
        if (!$qid) { $qid = $this->draftQuestion($sci, $common); $drafted = true; }
        if (!$qid) return ['ok' => false, 'message' => 'no question'];

        $media = $this->mediaPaths(self::pickMedia(explode(',', (string)$rec['media_ids'])));
        $caption = self::caption($rec['address'], $rec['bed_name']);
        // Re-confirmed as something else, or moved bed: its photos leave the old question; captions follow (Codex review).
        $old = $this->db->prepare("SELECT id, question_id, image_path FROM quiz_question_images WHERE plant_record_id = ? AND source = 'site'");
        $old->execute([$recordId]);
        foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $o) {
            if ((int)$o['question_id'] !== $qid) {
                $this->db->prepare("DELETE FROM quiz_question_images WHERE id = ?")->execute([(int)$o['id']]);
                if (strpos((string)$o['image_path'], '/uploads/quiz/site_') === 0) @unlink($this->root . $o['image_path']);
            }
        }
        $this->db->prepare("UPDATE quiz_question_images SET caption = ? WHERE plant_record_id = ? AND question_id = ? AND source = 'site'")
                 ->execute([$caption, $recordId, $qid]);
        $have = $this->db->prepare("SELECT 1 FROM quiz_question_images WHERE question_id = ? AND plant_record_id = ? AND media_id = ? LIMIT 1");
        $next = $this->db->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM quiz_question_images WHERE question_id = ?");
        $ins = $this->db->prepare("INSERT INTO quiz_question_images (question_id, image_path, sort_order, caption, source, plant_record_id, property_id, media_id)
                                   VALUES (?, ?, ?, ?, 'site', ?, ?, ?)");
        $added = 0;
        foreach ($media as $mid => $path) {
            $have->execute([$qid, $recordId, $mid]);
            if ($have->fetchColumn()) continue;
            $rel = '/uploads/quiz/site_' . $recordId . '_' . $mid . '.jpg';
            if (!($this->copier)($this->root . $path, $this->root . $rel)) continue;
            $next->execute([$qid]);
            $ins->execute([$qid, $rel, min(120, (int)$next->fetchColumn()), $caption, $recordId, (int)$rec['property_id'] ?: null, $mid]);
            $added++;
        }
        $this->trim($qid);
        return ['ok' => true, 'question_id' => $qid, 'drafted' => $drafted, 'added' => $added];
    }

    /** The question about this plant: by plant_key, else an exact-name match on a correct answer (then linked). */
    public function questionFor(string $sci, string $common): ?int
    {
        $in = implode(',', self::PLANT_CATEGORIES);
        if ($sci !== '') {
            $s = $this->db->prepare("SELECT id FROM quiz_questions WHERE plant_key = ? AND category_id IN ($in) ORDER BY is_active DESC, id LIMIT 1");
            $s->execute([mb_strtolower($sci)]);
            if ($id = (int)($s->fetchColumn() ?: 0)) return $id;
        }
        $want = array_filter([self::norm($common), self::norm($sci)]);
        if (!$want) return null;
        $rows = $this->db->query("SELECT q.id, o.option_text FROM quiz_questions q JOIN quiz_options o ON o.question_id = q.id AND o.is_correct = 1
                                  WHERE q.category_id IN ($in) ORDER BY q.is_active DESC, q.id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if (in_array(self::norm((string)$row['option_text']), $want, true)) {
                if ($sci !== '') $this->db->prepare("UPDATE quiz_questions SET plant_key = ? WHERE id = ? AND (plant_key IS NULL OR plant_key = '')")
                                          ->execute([mb_strtolower($sci), (int)$row['id']]);
                return (int)$row['id'];
            }
        }
        return null;
    }

    /** A new photo-ID question, INACTIVE until the office approves it in Quiz admin. */
    private function draftQuestion(string $sci, string $common): ?int
    {
        $card = $sci !== '' ? ($this->care)($sci) : null;
        $name = $common !== '' ? $common : (($card['common_name'] ?? '') !== '' ? $card['common_name'] : $sci);
        if ($name === '') return null;
        $weed = !empty($card['weed']);
        $cat = $weed ? self::WEED_CATEGORY : self::PLANT_CATEGORY;
        $notes = [];
        if ($sci !== '' && strcasecmp($sci, $name) !== 0) $notes[] = $name . ' (' . $sci . ').';
        foreach (array_slice((array)($card['tasks'] ?? []), 0, 2) as $t) if (!empty($t['task'])) $notes[] = (string)$t['task'] . (!empty($t['how']) ? ': ' . $t['how'] : '');
        if (!empty($card['notes'])) $notes[] = (string)$card['notes'];

        $this->db->prepare("INSERT INTO quiz_questions (category_id, plant_key, question_text, learn_notes, difficulty, question_type, learning_level, is_active, created_by)
                            VALUES (?, ?, ?, ?, 'medium', 'photo_id', 1, 0, 0)")
                 ->execute([$cat, $sci !== '' ? mb_strtolower($sci) : null, $weed ? 'What is this weed?' : 'What is this plant?', $notes ? mb_substr(implode(' ', $notes), 0, 2000) : null]);
        $qid = (int)$this->db->lastInsertId();

        $opt = $this->db->prepare("INSERT INTO quiz_options (question_id, option_text, is_correct, sort_order) VALUES (?, ?, ?, ?)");
        $wrong = $this->db->prepare("SELECT DISTINCT o.option_text FROM quiz_options o JOIN quiz_questions q ON q.id = o.question_id
                                     WHERE q.category_id = ? AND o.is_correct = 1 AND q.id <> ? AND q.is_active = 1");
        $wrong->execute([$cat, $qid]);
        $pool = array_values(array_filter($wrong->fetchAll(PDO::FETCH_COLUMN), fn($t) => self::norm((string)$t) !== self::norm($name)));
        shuffle($pool);
        if (count($pool) < 3) {   // too few questions in the category: other plants from the care library (Codex review)
            $more = $this->db->query("SELECT common_name FROM plant_care_cards WHERE common_name IS NOT NULL AND common_name <> ''")->fetchAll(PDO::FETCH_COLUMN);
            shuffle($more);
            foreach ($more as $m) {
                if (count($pool) >= 3) break;
                $n = self::norm((string)$m);
                if ($n !== self::norm($name) && !in_array($n, array_map(fn($t) => self::norm((string)$t), $pool), true)) $pool[] = (string)$m;
            }
        }
        $options = array_merge([[$name, 1]], array_map(fn($t) => [(string)$t, 0], array_slice($pool, 0, 3)));
        shuffle($options);
        foreach ($options as $i => [$text, $ok]) $opt->execute([$qid, mb_substr($text, 0, 255), $ok, $i]);
        return $qid;
    }

    /** media id → file path, for the picked photos (images only). */
    private function mediaPaths(array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $this->db->prepare("SELECT id, file_path FROM media_assets WHERE id IN ($in) AND mime_type IN ('image/jpeg','image/png','image/webp')");
        $s->execute($ids);
        $by = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $m) $by[(int)$m['id']] = (string)$m['file_path'];
        $out = [];
        foreach ($ids as $id) if (isset($by[$id])) $out[$id] = $by[$id];
        return $out;
    }

    private function trim(int $qid): void
    {
        $s = $this->db->prepare("SELECT id, property_id, created_at, image_path FROM quiz_question_images WHERE question_id = ? AND source = 'site'");
        $s->execute([$qid]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $drop = self::toTrim($rows);
        if (!$drop) return;
        $paths = array_column($rows, 'image_path', 'id');
        $del = $this->db->prepare("DELETE FROM quiz_question_images WHERE id = ? AND source = 'site'");
        foreach ($drop as $id) {
            $del->execute([$id]);
            $p = (string)($paths[$id] ?? '');
            if (strpos($p, '/uploads/quiz/site_') === 0) @unlink($this->root . $p);
        }
    }

    // ── Today's sites, for the pre-shift quiz ──────────────────────────────────────────────────────

    /** Active questions with site photos from the properties this person works today. */
    public function todaySiteQuestionIds(int $userId, ?string $today = null, int $limit = 4, ?int $categoryId = null): array
    {
        $today = $today ?? date('Y-m-d');
        try {
            // All of today's visits (no row cap: an assigned visit must not fall outside it — Codex review).
            $s = $this->db->prepare("SELECT v.id, jp.property_id FROM job_visits v JOIN job_plans jp ON jp.id = v.plan_id
                                     WHERE v.scheduled_date = ? AND v.status NOT IN ('cancelled','skipped')");
            $s->execute([$today]);
            $visits = $s->fetchAll(PDO::FETCH_ASSOC);
            if (!$visits) return [];
            require_once APP_ROOT . '/Modules/Team/Services/TimeclockFunctions.php';
            $props = [];
            foreach ($visits as $v) if (function_exists('userIsCrewOnVisit') && userIsCrewOnVisit((int)$v['id'], $userId)) $props[(int)$v['property_id']] = true;
            if (!$props) return [];
            $in = implode(',', array_keys($props));
            return array_map('intval', $this->db->query("SELECT DISTINCT i.question_id FROM quiz_question_images i JOIN quiz_questions q ON q.id = i.question_id
                                                         WHERE i.source = 'site' AND i.property_id IN ($in) AND q.is_active = 1"
                                                         . ($categoryId !== null ? ' AND q.category_id = ' . (int)$categoryId : '') . " ORDER BY RAND() LIMIT " . max(1, $limit))
                                                ->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            error_log('SitePlantQuizService today: ' . $e->getMessage());
            return [];
        }
    }

    // ── The picture ────────────────────────────────────────────────────────────────────────────────

    /** A ≤1280 px JPEG copy, re-encoded through GD (which drops EXIF/GPS). False when it can't be made. */
    public static function copyResized(string $src, string $dst): bool
    {
        if (!is_file($src) || !extension_loaded('gd')) return false;
        $info = @getimagesize($src);
        if (!$info) return false;
        $img = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG  => @imagecreatefrompng($src),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            default => false,
        };
        if (!$img) return false;
        if (function_exists('exif_read_data') && $info[2] === IMAGETYPE_JPEG) {   // upright, as the phone saw it
            $o = (int)(@exif_read_data($src)['Orientation'] ?? 1);
            $img = match ($o) { 3 => imagerotate($img, 180, 0), 6 => imagerotate($img, -90, 0), 8 => imagerotate($img, 90, 0), default => $img };
        }
        $w = imagesx($img); $h = imagesy($img); $k = min(1, 1280 / max($w, $h));
        $out = imagecreatetruecolor(max(1, (int)round($w * $k)), max(1, (int)round($h * $k)));
        imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
        @mkdir(dirname($dst), 0755, true);
        $ok = imagejpeg($out, $dst, 85);
        imagedestroy($img); imagedestroy($out);
        return $ok;
    }
}
