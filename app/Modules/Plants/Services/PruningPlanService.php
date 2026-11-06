<?php
/**
 * PruningPlanService — a pruning plan for one plant on a property, drafted by Claude from the
 * plant's photos (2026-11-03, migration 1430, table plant_work_plans).
 *
 * Why: a ChatGPT picture of cuts is pretty but species-blind, site-blind and goes nowhere. This
 * plan is drafted only for an identified plant, carries the "if it is actually X" branch, two
 * Vancouver windows, numbered actions with a photo anchor each (fractions of the image, like
 * visit_photo_annotations), what must be checked on site, a client summary and a crew checklist.
 * Later phases turn an approved plan into a dated job, a quote, a portal block and a visit-report
 * block; corrections become head lessons (Otto).
 *
 * Money: one draft = up to three images + ~2.5k output tokens; LlmDailyCap bucket `pruning_plans`.
 * No autoloader: require_once this file; `new PruningPlanService($db)`.
 */
declare(strict_types=1);

require_once __DIR__ . '/PruningKnowledge.php';
require_once dirname(__DIR__, 3) . '/Services/Media/ImageForLlm.php';
require_once dirname(__DIR__, 3) . '/Services/LlmDailyCap.php';
require_once dirname(__DIR__, 3) . '/Services/HeadLessons.php';

final class PruningPlanService
{
    public const MODEL = 'claude-sonnet-5-5';
    public const API_URL = 'https://api.anthropic.com/v1/messages';
    public const BUCKET = 'pruning_plans';
    public const DAILY_CAP = 20;
    public const MAX_ACTIONS = 14;
    public const MAX_PHOTOS = 3;
    /** USD per million tokens, for the cost column only (an estimate, not a bill). */
    public const PRICE_IN = 3.0;
    public const PRICE_OUT = 15.0;
    public const STATUSES = ['draft', 'approved', 'scheduled', 'done', 'rejected', 'failed'];

    private PDO $db;
    /** @var callable|null fn(array $body): array{code:int, body:string} */
    private $transport;
    private ?bool $ready = null;
    private ?string $root;

    public function __construct(PDO $db, ?callable $transport = null, ?string $root = null)
    {
        $this->db = $db;
        $this->transport = $transport;
        $this->root = $root;
    }

    public function ready(): bool
    {
        if ($this->ready !== null) return $this->ready;
        try {
            $this->db->query('SELECT 1 FROM plant_work_plans LIMIT 0');
            return $this->ready = true;
        } catch (Throwable $e) {
            return $this->ready = false;
        }
    }

    public function hasKey(): bool
    {
        return $this->transport !== null || (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Drafting
    // ─────────────────────────────────────────────────────────────────────

    /** @return array{ok: bool, message?: string, plan?: array} */
    public function draft(int $plantRecordId, int $userId): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Pruning plans need migration 1430.'];
        if (!$this->hasKey()) return ['ok' => false, 'message' => 'No Anthropic key set up.'];
        $plant = $this->plant($plantRecordId);
        if (!$plant) return ['ok' => false, 'message' => 'That plant is gone.'];
        if (($plant['status'] ?? '') === 'removed') return ['ok' => false, 'message' => 'That plant was removed from the property.'];
        if (trim((string)($plant['scientific_name'] ?? '')) === '') {
            return ['ok' => false, 'message' => 'Identify the plant first — the plan depends on the species.'];
        }
        $mediaIds = self::ids((string)$plant['media_ids']);
        $images = [];
        $usedIds = [];
        foreach ($this->media($mediaIds) as $m) {
            if (count($images) >= self::MAX_PHOTOS) break;
            $img = ImageForLlm::load([(string)($m['file_path'] ?? '')], $this->root);
            if ($img) { $images[] = $img; $usedIds[] = (int)$m['id']; }
        }
        if (!$images) return ['ok' => false, 'message' => 'None of the plant\'s photos could be read.'];
        if (!LlmDailyCap::take($this->db, self::BUCKET, self::DAILY_CAP)) {
            return ['ok' => false, 'message' => 'Today\'s allowance of pruning plans is used up. Try tomorrow.'];
        }

        $ctx = [
            'scientific' => (string)$plant['scientific_name'],
            'common' => (string)($plant['common_name'] ?? ''),
            'score' => $plant['score'] !== null ? (float)$plant['score'] : null,
            'confirmed' => ($plant['status'] ?? '') === 'confirmed',
            'bed' => (string)($plant['bed_name'] ?? ''),
            'address' => trim((string)($plant['address'] ?? '')),
            'city' => trim((string)($plant['city'] ?? '')),
            'date' => date('Y-m-d'),
            'photos' => count($images),
        ];
        $res = $this->send(self::request($images, $ctx));
        $parsed = $res['code'] === 200 ? self::parse($res['body']) : null;
        $plan = $parsed ? self::clean($parsed['plan']) : null;
        $now = date('Y-m-d H:i:s');
        if (!$plan) {
            $j = json_decode((string)$res['body'], true);
            $stop = is_array($j) ? (string)($j['stop_reason'] ?? ($j['error']['message'] ?? '')) : '';
            $why = $res['code'] !== 200 ? 'Anthropic answered ' . $res['code'] . ($stop !== '' ? " ($stop)" : '')
                 : 'The answer did not fit the plan shape' . ($stop !== '' ? " (stop_reason $stop)" : '');
            $this->db->prepare("INSERT INTO plant_work_plans (property_id, plant_record_id, kind, media_ids, scientific_name, common_name, status, model, error, created_by, created_at)
                                VALUES (?, ?, 'pruning', ?, ?, ?, 'failed', ?, ?, ?, ?)")
                     ->execute([(int)$plant['property_id'], $plantRecordId, implode(',', $usedIds), $ctx['scientific'], $ctx['common'], self::MODEL,
                                mb_substr($why . ': ' . mb_substr((string)$res['body'], 0, 160), 0, 255), $userId, $now]);
            return ['ok' => false, 'message' => $why . '. Nothing was saved as a plan; try again.'];
        }
        $cost = self::cost($parsed['usage'] ?? []);
        $this->db->prepare("INSERT INTO plant_work_plans (property_id, plant_record_id, kind, media_ids, scientific_name, common_name, species_confidence, plan,
                                anchors_usable, status, model, cost_usd, created_by, created_at)
                            VALUES (?, ?, 'pruning', ?, ?, ?, ?, ?, 1, 'draft', ?, ?, ?, ?)")
                 ->execute([(int)$plant['property_id'], $plantRecordId, implode(',', $usedIds),
                            (string)($plan['species']['scientific'] ?: $ctx['scientific']), (string)($plan['species']['common'] ?: $ctx['common']),
                            $plan['species']['confidence'], json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::MODEL, $cost, $userId, $now]);
        $id = (int)$this->db->lastInsertId();
        return ['ok' => true, 'message' => 'Plan drafted.', 'plan' => $this->get($id)];
    }

    /** The request body: photos + context, answer constrained to schema(). PURE. */
    public static function request(array $images, array $ctx): array
    {
        $note = PruningKnowledge::forName($ctx['scientific'] ?? null);
        $system = PruningKnowledge::SYSTEM;
        if ($note) {
            $system .= "\n\nSpecies notes for " . strtok((string)$ctx['scientific'], ' ') . " (from the books; 'unknown' means the books do not say):\n"
                . "- Season: {$note['season']}\n- Method: {$note['method']}\n- Cautions: {$note['cautions']}\n- Gaps: {$note['gaps']}";
        }
        $who = $ctx['scientific'] . ($ctx['common'] !== '' ? " ({$ctx['common']})" : '');
        $how = $ctx['confirmed'] ? 'confirmed by staff' : ('suggested by Pl@ntNet' . ($ctx['score'] !== null ? sprintf(' at %.0f%%', $ctx['score'] * 100) : ''));
        $where = trim(($ctx['bed'] !== '' ? "bed \"{$ctx['bed']}\", " : '') . ($ctx['address'] !== '' ? $ctx['address'] : 'the property') . ($ctx['city'] !== '' ? ', ' . $ctx['city'] : ''));
        $text = "Plant: {$who}, {$how}. Where: {$where}. Today: {$ctx['date']}. "
              . ($ctx['photos'] > 1 ? "Photo 0 is the whole plant; the other photos are closer views to help you read it. Every anchor is a position on photo 0 (set photo to 0); if a cut is only visible in a closer photo, anchor the branch it is on in photo 0 with lower confidence. " : "Only one photo (photo 0). ")
              . "Write the pruning plan.";
        $content = [];
        foreach ($images as $img) $content[] = ImageForLlm::block($img);
        $content[] = ['type' => 'text', 'text' => $text];
        return [
            'model' => self::MODEL,
            // Sonnet 5.5 thinks by default and the thinking counts against max_tokens: 3000 was eaten
            // before the JSON finished (2026-11-03, "did not fit the plan shape"). Effort low: a plan,
            // not a proof.
            'max_tokens' => 16000,
            'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => self::schema()]],
            'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => [['role' => 'user', 'content' => $content]],
        ];
    }

    /** The plan's JSON shape (strict: every key present, no extras). */
    public static function schema(): array
    {
        $str = ['type' => 'string'];
        $int = ['type' => 'integer'];
        $num = ['type' => 'number'];
        $strs = ['type' => 'array', 'items' => $str];
        // Structured outputs accept no minimum/maximum/minLength (Anthropic answers 400); the ranges
        // live in the description and clean() clamps them.
        $month = ['type' => 'integer', 'description' => 'Month number 1-12'];
        $obj = fn(array $props) => ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];
        $window = $obj(['from' => $month, 'to' => $month, 'actions' => ['type' => 'array', 'items' => $int], 'note' => $str]);
        return $obj([
            'species' => $obj([
                'scientific' => $str, 'common' => $str, 'confidence' => ['type' => 'number', 'description' => '0 to 1'],
                'if_wrong' => ['type' => 'array', 'items' => $obj(['instead' => $str, 'what_changes' => $str])],
            ]),
            'form_goal' => $str,
            'now' => $window,
            'main' => $window,
            'actions' => ['type' => 'array', 'items' => $obj([
                'n' => $int,
                'type' => ['type' => 'string', 'enum' => array_keys(PruningKnowledge::TYPES)],
                'what' => $str, 'why' => $str,
                'anchor' => $obj(['photo' => $int, 'x' => $num, 'y' => $num, 'confidence' => $num]),
            ])],
            'multi_year' => ['type' => 'array', 'items' => $obj(['year' => $int, 'aim' => $str])],
            'site_constraints' => $strs,
            'checks_on_site' => $strs,
            'client_summary' => $str,
            'crew_checklist' => $strs,
        ]);
    }

    /** @return array{plan: array, usage: array}|null */
    public static function parse(string $body): ?array
    {
        $j = json_decode($body, true);
        if (!is_array($j)) return null;
        $text = '';
        foreach ((array)($j['content'] ?? []) as $c) if (($c['type'] ?? '') === 'text') $text .= (string)($c['text'] ?? '');
        $plan = json_decode(trim($text), true);
        if (!is_array($plan)) return null;
        return ['plan' => $plan, 'usage' => (array)($j['usage'] ?? [])];
    }

    /** Validate and clamp a plan; null when it is not usable. PURE. */
    public static function clean(array $p): ?array
    {
        $m = fn($v, int $d) => (is_numeric($v) && (int)$v >= 1 && (int)$v <= 12) ? (int)$v : $d;
        $f01 = fn($v) => is_numeric($v) ? max(0.0, min(1.0, round((float)$v, 4))) : 0.0;
        $s = fn($v, int $max) => mb_substr(trim((string)(is_scalar($v) ? $v : '')), 0, $max);
        $list = fn($v, int $max, int $len) => array_slice(array_values(array_filter(array_map(fn($x) => $s($x, $len), is_array($v) ? $v : []), fn($x) => $x !== '')), 0, $max);

        $actions = [];
        $renum = [];   // the model's n => ours (the windows refer to the model's numbering)
        $n = 0;
        foreach ((array)($p['actions'] ?? []) as $a) {
            if (!is_array($a) || !isset(PruningKnowledge::TYPES[(string)($a['type'] ?? '')])) continue;
            $n++;
            if ($n > self::MAX_ACTIONS) break;
            if (isset($a['n']) && is_numeric($a['n'])) $renum[(int)$a['n']] = $n;
            $an = is_array($a['anchor'] ?? null) ? $a['anchor'] : [];
            $photo = 0;   // every anchor is on the whole-plant photo: that is the only one the UI draws on
            $x = $f01($an['x'] ?? null);
            $y = $f01($an['y'] ?? null);
            $conf = $f01($an['confidence'] ?? 0);
            if ($x <= 0.0 && $y <= 0.0) $conf = 0.0;   // nothing placed
            $actions[] = ['n' => $n, 'type' => (string)$a['type'], 'what' => $s($a['what'] ?? '', 160), 'why' => $s($a['why'] ?? '', 200),
                          'anchor' => ['photo' => $photo, 'x' => $x, 'y' => $y, 'confidence' => $conf]];
        }
        if (!$actions) return null;
        $ids = array_map(fn($a) => $a['n'], $actions);
        $win = function ($w, int $from, int $to) use ($m, $s, $ids, $renum) {
            $w = is_array($w) ? $w : [];
            $acts = [];
            foreach ((array)($w['actions'] ?? []) as $ref) {
                $new = $renum[(int)$ref] ?? null;
                if ($new !== null && in_array($new, $ids, true) && !in_array($new, $acts, true)) $acts[] = $new;
            }
            sort($acts);
            return ['from' => $m($w['from'] ?? null, $from), 'to' => $m($w['to'] ?? null, $to), 'actions' => $acts, 'note' => $s($w['note'] ?? '', 200)];
        };
        $sp = is_array($p['species'] ?? null) ? $p['species'] : [];
        $ifWrong = [];
        foreach ((array)($sp['if_wrong'] ?? []) as $w) {
            if (!is_array($w)) continue;
            $i = $s($w['instead'] ?? '', 120);
            $c = $s($w['what_changes'] ?? '', 200);
            if ($i !== '' && $c !== '') $ifWrong[] = ['instead' => $i, 'what_changes' => $c];
            if (count($ifWrong) >= 3) break;
        }
        $years = [];
        foreach ((array)($p['multi_year'] ?? []) as $y) {
            if (!is_array($y)) continue;
            $aim = $s($y['aim'] ?? '', 160);
            if ($aim !== '') $years[] = ['year' => max(1, min(10, (int)($y['year'] ?? count($years) + 1))), 'aim' => $aim];
            if (count($years) >= 6) break;
        }
        $month = (int)date('n');
        return [
            'species' => ['scientific' => $s($sp['scientific'] ?? '', 160), 'common' => $s($sp['common'] ?? '', 160), 'confidence' => $f01($sp['confidence'] ?? 0), 'if_wrong' => $ifWrong],
            'form_goal' => $s($p['form_goal'] ?? '', 400),
            'now' => $win($p['now'] ?? null, $month, $month),
            'main' => $win($p['main'] ?? null, 2, 3),
            'actions' => $actions,
            'multi_year' => $years,
            'site_constraints' => $list($p['site_constraints'] ?? [], 8, 120),
            'checks_on_site' => $list($p['checks_on_site'] ?? [], 8, 140),
            'client_summary' => $s($p['client_summary'] ?? '', 600),
            'crew_checklist' => $list($p['crew_checklist'] ?? [], self::MAX_ACTIONS, 90),
        ];
    }

    /** Estimated USD from the API's usage block. PURE. */
    public static function cost(array $usage): float
    {
        $in = (int)($usage['input_tokens'] ?? 0) + (int)($usage['cache_creation_input_tokens'] ?? 0) + (int)($usage['cache_read_input_tokens'] ?? 0);
        $out = (int)($usage['output_tokens'] ?? 0);
        return round(($in * self::PRICE_IN + $out * self::PRICE_OUT) / 1_000_000, 4);
    }

    /** Both windows as text, e.g. "late Feb–Mar" → "Feb–Mar". PURE. */
    public static function window(int $from, int $to): string
    {
        $names = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return $from === $to ? $names[$from] : $names[$from] . '–' . $names[$to];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reading
    // ─────────────────────────────────────────────────────────────────────

    /** One plan with its photos (urls) and decoded JSON. */
    public function get(int $id): ?array
    {
        $s = $this->db->prepare("SELECT * FROM plant_work_plans WHERE id = ?");
        $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? $this->shape($r) : null;
    }

    /** The current plan for a plant: newest row that is not rejected or failed. */
    public function forPlant(int $plantRecordId): ?array
    {
        $s = $this->db->prepare("SELECT * FROM plant_work_plans WHERE plant_record_id = ? AND status NOT IN ('rejected','failed') ORDER BY id DESC LIMIT 1");
        $s->execute([$plantRecordId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? $this->shape($r) : null;
    }

    /** plant_record_id => ['id', 'status'] for the Plants card list. */
    public function statusByPlant(int $propertyId): array
    {
        if (!$this->ready()) return [];
        $s = $this->db->prepare("SELECT id, plant_record_id, status FROM plant_work_plans WHERE property_id = ? AND status NOT IN ('rejected','failed') ORDER BY id ASC");
        $s->execute([$propertyId]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['plant_record_id']] = ['id' => (int)$r['id'], 'status' => (string)$r['status']];
        return $out;
    }

    /** Approved or scheduled plans for a set of properties (portal, visit report). */
    public function forProperties(array $propertyIds, array $statuses = ['approved', 'scheduled', 'done']): array
    {
        $propertyIds = array_values(array_filter(array_map('intval', $propertyIds)));
        if (!$propertyIds || !$this->ready()) return [];
        $in = implode(',', array_fill(0, count($propertyIds), '?'));
        $st = implode(',', array_fill(0, count($statuses), '?'));
        $s = $this->db->prepare("SELECT * FROM plant_work_plans WHERE property_id IN ({$in}) AND status IN ({$st}) ORDER BY id DESC");
        $s->execute(array_merge($propertyIds, $statuses));
        return array_map(fn($r) => $this->shape($r), $s->fetchAll(PDO::FETCH_ASSOC));
    }

    private function shape(array $r): array
    {
        $plan = json_decode((string)($r['plan'] ?? ''), true);
        $ids = self::ids((string)$r['media_ids']);
        $photos = [];
        foreach ($this->media($ids) as $m) $photos[] = ['id' => (int)$m['id'], 'url' => (string)$m['file_path'], 'w' => $m['width'] ?? null, 'h' => $m['height'] ?? null];
        return [
            'id' => (int)$r['id'], 'property_id' => (int)$r['property_id'], 'plant_record_id' => (int)$r['plant_record_id'], 'kind' => (string)$r['kind'],
            'status' => (string)$r['status'], 'scientific_name' => (string)($r['scientific_name'] ?? ''), 'common_name' => (string)($r['common_name'] ?? ''),
            'species_confidence' => $r['species_confidence'] !== null ? (float)$r['species_confidence'] : null,
            'anchors_usable' => (int)($r['anchors_usable'] ?? 1) === 1,
            'plan' => is_array($plan) ? $plan : null, 'photos' => $photos,
            'created_at' => (string)$r['created_at'], 'approved_at' => $r['approved_at'], 'job_plan_id' => $r['job_plan_id'] ? (int)$r['job_plan_id'] : null,
            'quote_id' => $r['quote_id'] ? (int)$r['quote_id'] : null, 'after_media_ids' => self::ids((string)($r['after_media_ids'] ?? '')),
            'cost_usd' => $r['cost_usd'] !== null ? (float)$r['cost_usd'] : null,
            'error' => ($r['status'] ?? '') === 'failed' ? (string)($r['error'] ?? '') : null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Office decisions
    // ─────────────────────────────────────────────────────────────────────

    public function approve(int $id, int $userId): array
    {
        return $this->setStatus($id, ['draft'], 'approved', $userId, true);
    }

    /** The visit that ticked every crew line closes the plan (PlantWorkService::recordFromChecklist). */
    public function markDone(int $id, int $userId): array
    {
        return $this->setStatus($id, ['approved', 'scheduled'], 'done', $userId, false);
    }

    public function reject(int $id, int $userId): array
    {
        return $this->setStatus($id, ['draft', 'approved'], 'rejected', $userId, false);
    }

    /** Hide the badges on a plan whose anchors are off (the numbered list stays). */
    public function setAnchorsUsable(int $id, bool $usable): array
    {
        $row = $this->get($id);
        if (!$row) return ['ok' => false, 'message' => 'That plan is gone.'];
        if (!in_array($row['status'], ['draft', 'approved'], true)) return ['ok' => false, 'message' => 'That plan is already ' . $row['status'] . '.'];
        $u = $this->db->prepare("UPDATE plant_work_plans SET anchors_usable = ?, updated_at = ? WHERE id = ? AND status IN ('draft','approved')");
        $u->execute([$usable ? 1 : 0, date('Y-m-d H:i:s'), $id]);
        return ['ok' => true, 'message' => $usable ? 'Markers shown.' : 'Markers hidden.', 'plan' => $this->get($id)];
    }

    /**
     * Office drags badges to the right stems: store the new anchors and remember the correction
     * as a lesson for Otto (how far off the model was for this genus).
     * @param list<array{n:int, x:float, y:float, photo?:int}> $anchors
     */
    public function setAnchors(int $id, array $anchors, int $userId): array
    {
        $row = $this->get($id);
        if (!$row || !$row['plan']) return ['ok' => false, 'message' => 'That plan is gone.'];
        if (!in_array($row['status'], ['draft', 'approved'], true)) return ['ok' => false, 'message' => 'That plan is already scheduled.'];
        $plan = $row['plan'];
        $moved = 0;
        $drift = [];
        foreach ($anchors as $a) {
            if (!is_array($a)) continue;
            $n = (int)($a['n'] ?? 0);
            foreach ($plan['actions'] as &$act) {
                if ((int)$act['n'] !== $n) continue;
                $x = max(0.0, min(1.0, round((float)($a['x'] ?? 0), 4)));
                $y = max(0.0, min(1.0, round((float)($a['y'] ?? 0), 4)));
                $drift[] = round(hypot($x - (float)$act['anchor']['x'], $y - (float)$act['anchor']['y']), 3);
                $act['anchor']['x'] = $x;
                $act['anchor']['y'] = $y;
                $act['anchor']['confidence'] = 1.0;
                $act['anchor']['by_staff'] = true;
                $moved++;
            }
            unset($act);
        }
        if (!$moved) return ['ok' => false, 'message' => 'Nothing to move.'];
        $this->db->prepare("UPDATE plant_work_plans SET plan = ?, updated_at = ? WHERE id = ?")
                 ->execute([json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), date('Y-m-d H:i:s'), $id]);
        HeadLessons::recordQuietly($this->db, [
            'head' => 'otto', 'subject_kind' => 'pruning_plan', 'subject_key' => strtolower((string)strtok($row['scientific_name'], ' ') ?: 'plant'),
            'field' => 'anchor_drift', 'said' => 'model', 'correct' => 'staff moved ' . $moved . ' (mean drift ' . round(array_sum($drift) / max(1, count($drift)), 3) . ')',
            'source' => 'plants-card', 'ref_type' => 'plant_work_plan', 'ref_id' => $id, 'corrected_by' => $userId,
        ]);
        return ['ok' => true, 'message' => $moved . ' marker' . ($moved === 1 ? '' : 's') . ' moved.', 'plan' => $this->get($id)];
    }

    private function setStatus(int $id, array $from, string $to, int $userId, bool $stampApproval): array
    {
        $row = $this->get($id);
        if (!$row) return ['ok' => false, 'message' => 'That plan is gone.'];
        if (!in_array($row['status'], $from, true)) return ['ok' => false, 'message' => 'A ' . $row['status'] . ' plan cannot be ' . $to . '.'];
        $now = date('Y-m-d H:i:s');
        $in = implode(',', array_fill(0, count($from), '?'));
        if ($stampApproval) {
            $u = $this->db->prepare("UPDATE plant_work_plans SET status = ?, approved_by = ?, approved_at = ?, updated_at = ? WHERE id = ? AND status IN ({$in})");
            $u->execute(array_merge([$to, $userId, $now, $now, $id], $from));
        } else {
            $u = $this->db->prepare("UPDATE plant_work_plans SET status = ?, updated_at = ? WHERE id = ? AND status IN ({$in})");
            $u->execute(array_merge([$to, $now, $id], $from));
        }
        if ($u->rowCount() !== 1) return ['ok' => false, 'message' => 'The plan changed under you; reload.', 'plan' => $this->get($id)];
        return ['ok' => true, 'message' => 'Plan ' . $to . '.', 'plan' => $this->get($id)];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Lookups and transport
    // ─────────────────────────────────────────────────────────────────────

    private function plant(int $id): ?array
    {
        $s = $this->db->prepare("SELECT pr.*, p.address, p.city FROM plant_records pr LEFT JOIN properties p ON p.id = pr.property_id WHERE pr.id = ?");
        $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** media_assets rows in the order the ids were given. */
    private function media(array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $this->db->prepare("SELECT id, file_path, mime_type FROM media_assets WHERE id IN ({$in})");
        $s->execute($ids);
        $by = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $m) $by[(int)$m['id']] = $m;
        $out = [];
        foreach ($ids as $i) if (isset($by[$i])) $out[] = $by[$i];
        return $out;
    }

    /** @return list<int> */
    public static function ids(string $csv): array
    {
        return array_values(array_filter(array_map('intval', explode(',', $csv)), fn($i) => $i > 0));
    }

    /** @return array{code:int, body:string} */
    private function send(array $body): array
    {
        if ($this->transport) return ($this->transport)($body);
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 150, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01'],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $raw === false ? 0 : $code, 'body' => (string)$raw];
    }
}
