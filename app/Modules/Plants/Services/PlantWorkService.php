<?php
declare(strict_types=1);

require_once __DIR__ . '/PlantCareService.php';
require_once __DIR__ . '/PruningPlanService.php';

/**
 * PlantWorkService — a property's plants as tickable work (2026-11-04, migration 1436).
 *
 * Tim: the visit's Work Record checklist is the place for the property's plant care, filled automatically:
 * "What is this?" records the plants (plant_records), the care library says what each needs and when
 * (plant_care_cards, approved only), an approved pruning plan adds its crew lines. The visit checklist gets
 * one line per task per species due this month ("Thin crossing shoots — Japanese maple (×6)"), keyed so the
 * tick can be traced to the plants; every checklist save mirrors those lines into plant_work_log, which the
 * Plants card, the visit report and the portal read as the property's plant history.
 *
 * Keys: care:<card id>:<task n> (n from 1, the card's task order) and plan:<plan id>:<line n> (crew_checklist order).
 * The iPhone app re-sends only {item, checked, note}, so keys are re-attached by the line's exact text (reattach()).
 *
 * Global namespace, no autoloader: require_once this file, then `new PlantWorkService($db)`.
 */
class PlantWorkService
{
    public const MAX_LINES = 12;
    public const KEY_RE = '/^(care|plan):\d+:\d+$/';
    /** Visits that are not plant work: no plant lines (a salting run past a maple is not a pruning visit). */
    public const SKIP_SERVICE_TYPES = ['snow_removal', 'salt_application'];

    private PDO $db;
    private PlantCareService $care;
    private PruningPlanService $plans;

    public function __construct(PDO $db, ?PlantCareService $care = null, ?PruningPlanService $plans = null)
    {
        $this->db = $db;
        $this->care = $care ?? new PlantCareService($db);
        $this->plans = $plans ?? new PruningPlanService($db);
    }

    public function ready(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM plant_work_log LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ── Pure helpers ────────────────────────────────────────────────────────

    /** Is month $m inside the window from–to? Windows may wrap the year (11 → 2). */
    public static function inWindow(int $m, int $from, int $to): bool
    {
        if ($m < 1 || $m > 12 || $from < 1 || $from > 12 || $to < 1 || $to > 12) return false;
        return $from <= $to ? ($m >= $from && $m <= $to) : ($m >= $from || $m <= $to);
    }

    /**
     * The checklist lines due in a month.
     * @param array $plants rows {id, scientific_name, common_name}
     * @param array $cards  plant id => PlantCareService card (only approved ones are used)
     * @param array $plans  PruningPlanService shapes (approved or scheduled)
     * @return array{lines: array, more: int} lines {item, checked, note, key, plant_ids}; scheduled plans first, then approved plans in their window, then care
     */
    public static function compose(array $plants, array $cards, array $plans, int $month): array
    {
        $names = [];
        foreach ($plants as $p) $names[(int)$p['id']] = self::plantName($p);

        $planLines = [];
        usort($plans, fn($a, $b) => (($a['status'] ?? '') === 'scheduled' ? 0 : 1) <=> (($b['status'] ?? '') === 'scheduled' ? 0 : 1) ?: ((int)$a['id'] <=> (int)$b['id']));
        foreach ($plans as $pl) {
            $status = (string)($pl['status'] ?? '');
            $plantId = (int)($pl['plant_record_id'] ?? 0);
            if (!isset($names[$plantId]) || !in_array($status, ['approved', 'scheduled'], true)) continue;
            $plan = $pl['plan'] ?? [];
            if ($status === 'approved') {
                $now = $plan['now'] ?? []; $main = $plan['main'] ?? [];
                if (!self::inWindow($month, (int)($now['from'] ?? 0), (int)($now['to'] ?? 0))
                    && !self::inWindow($month, (int)($main['from'] ?? 0), (int)($main['to'] ?? 0))) continue;
            }
            foreach (array_values($plan['crew_checklist'] ?? []) as $i => $line) {
                $line = trim((string)$line);
                if ($line === '') continue;
                $planLines[] = self::line($line . ' — ' . $names[$plantId], 'plan:' . (int)$pl['id'] . ':' . ($i + 1), [$plantId]);
            }
        }

        $groups = [];   // key => [task, name, plant ids]
        foreach ($plants as $p) {
            $id = (int)$p['id'];
            $c = $cards[$id] ?? null;
            if (!$c || ($c['status'] ?? '') !== 'approved') continue;
            foreach (array_values($c['card']['tasks'] ?? []) as $i => $t) {
                if (!self::inWindow($month, (int)($t['from'] ?? 0), (int)($t['to'] ?? 0))) continue;
                $task = trim((string)($t['task'] ?? ''));
                if ($task === '') continue;
                $key = 'care:' . (int)$c['id'] . ':' . ($i + 1);
                if (!isset($groups[$key])) {
                    // A genus card covers several species: name the group by the card, one species by the plant.
                    $groups[$key] = ['task' => $task, 'name' => $names[$id], 'card_name' => trim((string)($c['common_name'] ?? '')), 'ids' => [], 'species' => []];
                }
                $groups[$key]['ids'][] = $id;
                $groups[$key]['species'][strtolower((string)($p['scientific_name'] ?? ''))] = true;
            }
        }
        $careLines = [];
        foreach ($groups as $key => $g) {
            $name = count($g['species']) > 1 && $g['card_name'] !== '' ? $g['card_name'] : $g['name'];
            $n = count($g['ids']);
            $careLines[] = self::line($g['task'] . ' — ' . $name . ($n > 1 ? ' (×' . $n . ')' : ''), $key, $g['ids']);
        }

        $all = array_merge($planLines, $careLines);
        return ['lines' => array_slice($all, 0, self::MAX_LINES), 'more' => max(0, count($all) - self::MAX_LINES)];
    }

    /** Append due lines the checklist does not already have (same key, or same text). Idempotent. */
    public static function seedChecklist(array $existing, array $lines): array
    {
        $keys = []; $texts = [];
        foreach ($existing as $it) {
            if (!empty($it['key'])) $keys[(string)$it['key']] = true;
            $texts[mb_strtolower(trim((string)($it['item'] ?? '')))] = true;
        }
        foreach ($lines as $l) {
            if (isset($keys[$l['key']]) || isset($texts[mb_strtolower($l['item'])])) continue;
            $existing[] = $l;
            $keys[$l['key']] = true;
            $texts[mb_strtolower($l['item'])] = true;
        }
        return $existing;
    }

    /**
     * Give back keys and plant ids that a client dropped (the iPhone app saves only item/checked/note):
     * an unkeyed item whose text matches a known line takes its key; a keyed item without plant ids takes the known ones.
     */
    public static function reattach(array $items, array $known): array
    {
        $byText = []; $byKey = [];
        foreach ($known as $k) {
            if (empty($k['key']) || !preg_match(self::KEY_RE, (string)$k['key'])) continue;
            $byText[mb_strtolower(trim((string)$k['item']))] = $k;
            if (!empty($k['plant_ids'])) $byKey[(string)$k['key']] = $k['plant_ids'];
        }
        foreach ($items as &$it) {
            if (!is_array($it)) continue;
            if (empty($it['key'])) {
                $m = $byText[mb_strtolower(trim((string)($it['item'] ?? '')))] ?? null;
                if ($m) { $it['key'] = $m['key']; $it['plant_ids'] = $m['plant_ids'] ?? []; }
            } elseif (empty($it['plant_ids']) && isset($byKey[(string)$it['key']])) {
                $it['plant_ids'] = $byKey[(string)$it['key']];
            }
        }
        unset($it);
        return $items;
    }

    private static function line(string $item, string $key, array $plantIds): array
    {
        return ['item' => mb_substr($item, 0, 255), 'checked' => false, 'note' => '', 'key' => $key, 'plant_ids' => array_values(array_unique(array_map('intval', $plantIds)))];
    }

    private static function plantName(array $p): string
    {
        $c = trim((string)($p['common_name'] ?? ''));
        return $c !== '' ? $c : trim((string)($p['scientific_name'] ?? 'plant'));
    }

    // ── Reading the property ───────────────────────────────────────────────

    /** May this visit carry plant lines? Off for snow/salt visits and for properties switched off. */
    public function seedsFor(int $propertyId, string $serviceType): bool
    {
        if ($propertyId < 1 || in_array($serviceType, self::SKIP_SERVICE_TYPES, true)) return false;
        try {
            $s = $this->db->prepare('SELECT plant_checklist FROM properties WHERE id = ?');
            $s->execute([$propertyId]);
            $v = $s->fetchColumn();
            return $v === false || (int)$v === 1;
        } catch (Throwable $e) {
            return true;   // before migration 1436 the switch does not exist: on
        }
    }

    public function setSeeding(int $propertyId, bool $on): bool
    {
        try {
            $this->db->prepare('UPDATE properties SET plant_checklist = ? WHERE id = ?')->execute([$on ? 1 : 0, $propertyId]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** The lines due on a property on a date. @return array{lines: array, more: int} */
    public function dueFor(int $propertyId, string $date): array
    {
        $none = ['lines' => [], 'more' => 0];
        $ts = strtotime($date);
        if ($propertyId < 1 || $ts === false) return $none;
        try {
            $s = $this->db->prepare("SELECT id, scientific_name, common_name FROM plant_records
                                     WHERE property_id = ? AND status IN ('confirmed','suggested') AND scientific_name IS NOT NULL AND scientific_name <> ''
                                     ORDER BY id ASC LIMIT 200");
            $s->execute([$propertyId]);
            $plants = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return $none;   // before migration 1367
        }
        if (!$plants) return $none;
        $cards = []; $bySpecies = [];
        foreach ($plants as $p) {
            $sci = (string)$p['scientific_name'];
            if (!array_key_exists($sci, $bySpecies)) $bySpecies[$sci] = $this->care->forPlant($sci);
            if ($bySpecies[$sci]) $cards[(int)$p['id']] = $bySpecies[$sci];
        }
        $plans = $this->plans->forProperties([$propertyId], ['approved', 'scheduled']);
        return self::compose($plants, $cards, $plans, (int)date('n', $ts));
    }

    // ── Writing ─────────────────────────────────────────────────────────────

    /**
     * Mirror a saved checklist's plant lines into plant_work_log: one row per plant per keyed line, ticked or not;
     * lines taken off the checklist lose their rows. A pruning plan whose every crew line is ticked becomes done.
     * Never throws: the crew's checklist save must not fail because the history could not be written.
     * @return int rows written
     */
    public function recordFromChecklist(int $visitId, int $propertyId, array $items, int $userId, ?string $date = null): int
    {
        if ($visitId < 1 || $propertyId < 1 || !$this->ready()) return 0;
        try {
            $own = $this->plantIdsOn($propertyId);
            $now = date('Y-m-d H:i:s');
            $up = $this->db->prepare("
                INSERT INTO plant_work_log (property_id, plant_record_id, visit_id, source, task_key, task, done, done_at, done_by, note, scheduled_for, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE task = VALUES(task),
                    done_at = IF(VALUES(done) = 1, IF(done = 1, done_at, VALUES(done_at)), NULL),
                    done_by = IF(VALUES(done) = 1, IF(done = 1, done_by, VALUES(done_by)), NULL),
                    done = VALUES(done), note = VALUES(note), scheduled_for = VALUES(scheduled_for), updated_at = VALUES(updated_at)
            ");
            $kept = []; $written = 0; $planLines = [];
            foreach ($items as $it) {
                $key = (string)($it['key'] ?? '');
                if (!preg_match(self::KEY_RE, $key)) continue;
                $done = !empty($it['checked']);
                [$kind, $refId] = explode(':', $key);
                if ($kind === 'plan') $planLines[(int)$refId][] = $done;
                foreach (array_unique(array_map('intval', (array)($it['plant_ids'] ?? []))) as $pid) {
                    if (!isset($own[$pid])) continue;   // a plant id from another property is ignored, not trusted
                    $up->execute([$propertyId, $pid, $visitId, $kind === 'plan' ? 'pruning_plan' : 'care', $key, mb_substr((string)$it['item'], 0, 255),
                                  $done ? 1 : 0, $done ? $now : null, $done ? $userId : null, mb_substr((string)($it['note'] ?? ''), 0, 500) ?: null,
                                  $date ?: null, $now, $now]);
                    $kept[] = $pid . '|' . $key;
                    $written++;
                }
            }
            $this->dropRemoved($visitId, $kept);
            foreach ($planLines as $planId => $ticks) $this->closePlanIfDone($planId, $propertyId, $ticks, $userId);
            return $written;
        } catch (Throwable $e) {
            error_log("PlantWorkService::recordFromChecklist visit {$visitId}: " . $e->getMessage());
            return 0;
        }
    }

    /** @return array<int, true> */
    private function plantIdsOn(int $propertyId): array
    {
        $s = $this->db->prepare('SELECT id FROM plant_records WHERE property_id = ?');
        $s->execute([$propertyId]);
        return array_fill_keys(array_map('intval', array_column($s->fetchAll(PDO::FETCH_ASSOC), 'id')), true);
    }

    private function dropRemoved(int $visitId, array $kept): void
    {
        $s = $this->db->prepare('SELECT id, plant_record_id, task_key FROM plant_work_log WHERE visit_id = ?');
        $s->execute([$visitId]);
        $keep = array_fill_keys($kept, true);
        $gone = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($keep[(int)$r['plant_record_id'] . '|' . $r['task_key']])) $gone[] = (int)$r['id'];
        }
        if ($gone) {
            $in = implode(',', array_fill(0, count($gone), '?'));
            $this->db->prepare("DELETE FROM plant_work_log WHERE id IN ({$in})")->execute($gone);
        }
    }

    private function closePlanIfDone(int $planId, int $propertyId, array $ticks, int $userId): void
    {
        $plan = $this->plans->get($planId);
        if (!$plan || $plan['property_id'] !== $propertyId || !in_array($plan['status'], ['approved', 'scheduled'], true)) return;
        $lines = array_filter(array_map('trim', array_map('strval', $plan['plan']['crew_checklist'] ?? [])), fn($l) => $l !== '');
        if ($lines && count($ticks) >= count($lines) && !in_array(false, $ticks, true)) $this->plans->markDone($planId, $userId);
    }

    // ── History ─────────────────────────────────────────────────────────────

    /** Work done on a property, newest first: {plant_record_id, plant, task, task_key, done_at, note, visit_id}. */
    public function history(int $propertyId, int $limit = 50): array
    {
        if (!$this->ready()) return [];
        $s = $this->db->prepare("
            SELECT l.plant_record_id, l.visit_id, l.task_key, l.task, l.done_at, l.note,
                   COALESCE(NULLIF(pr.common_name, ''), pr.scientific_name) AS plant, pr.scientific_name
            FROM plant_work_log l
            JOIN plant_records pr ON pr.id = l.plant_record_id
            WHERE l.property_id = ? AND l.done = 1
            ORDER BY l.done_at DESC, l.id DESC
            LIMIT " . max(1, min(500, $limit)));
        $s->execute([$propertyId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** One plant's history, newest first. */
    public function forPlant(int $plantRecordId, int $limit = 20): array
    {
        if (!$this->ready()) return [];
        $s = $this->db->prepare("SELECT visit_id, task_key, task, done_at, note FROM plant_work_log
                                 WHERE plant_record_id = ? AND done = 1 ORDER BY done_at DESC, id DESC LIMIT " . max(1, min(200, $limit)));
        $s->execute([$plantRecordId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** plant id => {task, done_at} of the latest work, for the Plants card. */
    public function lastDoneByPlant(int $propertyId): array
    {
        $out = [];
        foreach ($this->history($propertyId, 500) as $r) {
            $id = (int)$r['plant_record_id'];
            if (!isset($out[$id])) $out[$id] = ['task' => self::taskOnly((string)$r['task']), 'done_at' => (string)$r['done_at']];
        }
        return $out;
    }

    /**
     * A visit's plant lines, one per checklist line (plants merged): {task, done, note, plants: [names], source, key}.
     * For the visit report and the job page; empty before migration 1436.
     */
    public function forVisit(int $visitId): array
    {
        if (!$this->ready()) return [];
        $s = $this->db->prepare("
            SELECT l.task_key, l.task, l.done, l.note, l.source, COALESCE(NULLIF(pr.common_name, ''), pr.scientific_name) AS plant
            FROM plant_work_log l JOIN plant_records pr ON pr.id = l.plant_record_id
            WHERE l.visit_id = ? ORDER BY l.id ASC");
        $s->execute([$visitId]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (string)$r['task_key'];
            if (!isset($out[$k])) $out[$k] = ['key' => $k, 'task' => (string)$r['task'], 'done' => (int)$r['done'] === 1, 'note' => (string)($r['note'] ?? ''),
                                              'source' => (string)$r['source'], 'plants' => []];
            $out[$k]['plants'][(string)$r['plant']] = true;
        }
        return array_values(array_map(fn($l) => ['plants' => array_keys($l['plants'])] + $l, $out));
    }

    /** The task due now, else the next one to start: {task, when, now}. */
    public static function nextTask(array $tasks, int $month): ?array
    {
        $best = null; $bestGap = 13;
        foreach ($tasks as $t) {
            $from = (int)($t['from'] ?? 0); $to = (int)($t['to'] ?? 0);
            if ($from < 1 || $from > 12 || trim((string)($t['task'] ?? '')) === '') continue;
            $gap = self::inWindow($month, $from, $to) ? 0 : (($from - $month + 12) % 12 ?: 12);
            if ($gap < $bestGap) { $bestGap = $gap; $best = ['task' => trim((string)$t['task']), 'when' => PlantCareService::window($from, $to), 'now' => $gap === 0]; }
        }
        return $best;
    }

    /**
     * For the client portal: the property's confirmed plants, one row per species, with the last work done and
     * what comes next from the approved care card. {plant, count, last: {task, done_at}|null, next: {task, when, now}|null}
     */
    public function portalSummary(int $propertyId, string $date): array
    {
        try {
            $s = $this->db->prepare("SELECT id, scientific_name, common_name FROM plant_records
                                     WHERE property_id = ? AND status = 'confirmed' AND scientific_name IS NOT NULL AND scientific_name <> '' ORDER BY id ASC");
            $s->execute([$propertyId]);
            $plants = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        if (!$plants) return [];
        $last = $this->lastDoneByPlant($propertyId);
        $month = (int)date('n', strtotime($date) ?: time());
        $rows = [];
        foreach ($plants as $p) {
            $sci = strtolower((string)$p['scientific_name']);
            if (!isset($rows[$sci])) {
                $card = $this->care->forPlant((string)$p['scientific_name']);
                $rows[$sci] = ['plant' => self::plantName($p), 'count' => 0, 'last' => null,
                               'next' => $card && $card['status'] === 'approved' ? self::nextTask($card['card']['tasks'] ?? [], $month) : null];
            }
            $rows[$sci]['count']++;
            $l = $last[(int)$p['id']] ?? null;
            if ($l && (!$rows[$sci]['last'] || $l['done_at'] > $rows[$sci]['last']['done_at'])) $rows[$sci]['last'] = $l;
        }
        return array_values($rows);
    }

    /** "Thin crossing shoots — Japanese maple (×6)" → "Thin crossing shoots". */
    public static function taskOnly(string $line): string
    {
        $p = mb_strrpos($line, ' — ');
        return $p === false ? $line : mb_substr($line, 0, $p);
    }
}
