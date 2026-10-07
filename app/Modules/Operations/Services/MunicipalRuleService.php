<?php
/**
 * MunicipalRuleService — the bylaw table and Otto's checks against it.
 *
 *   municipal_rules     hours / bans / notes per municipality (and area), with a status
 *   municipal_areas     areas inside a municipality by postal prefix (the West End)
 *   service_equipment   which equipment a service type uses (blower or not)
 *
 * Otto's suggestions from here (OpsDeskService gathers them):
 *   bylaw     a scheduled visit outside the allowed hours, or blower work on a banned day
 *   west_end  any visit inside an area with a blower ban — plan rake/vacuum time
 * Unverified rules give low-priority suggestions that say "Rule not yet confirmed".
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/DispatchRules.php';
require_once __DIR__ . '/OttoRules.php';
require_once __DIR__ . '/OpsDeskService.php';

class MunicipalRuleService
{
    public const LOOKAHEAD_DAYS = 7;
    public const STATUSES = ['verified', 'unverified', 'confirm_current_bylaw'];
    public const DAY_TYPES = ['weekday', 'saturday', 'sunday_holiday', 'any'];
    public const RULE_CLASSES = ['power_equipment', 'leaf_blower', 'all'];
    public const EQUIPMENT_CLASSES = ['mower', 'trimmer', 'blower', 'other'];

    private PDO $db;
    private string $today;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today ?? date('Y-m-d');
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'municipal_rules'")->rowCount() > 0
                && $this->db->query("SHOW TABLES LIKE 'service_equipment'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ── Reads ───────────────────────────────────────────────────────────────

    public function rules(): array
    {
        return $this->db->query("SELECT * FROM municipal_rules ORDER BY municipality, area IS NOT NULL, area, kind, equipment_class, FIELD(day_type, 'weekday', 'saturday', 'sunday_holiday', 'any')")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function areas(): array
    {
        return $this->db->query("SELECT * FROM municipal_areas ORDER BY municipality, name")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** service type (lowercase) => [classes] */
    public function serviceMap(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT service_type, equipment_class FROM service_equipment")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[strtolower(trim((string)$r['service_type']))][] = (string)$r['equipment_class'];
        }
        return $out;
    }

    /** Service types in use, with their equipment ticks. */
    public function serviceTypes(): array
    {
        $map = $this->serviceMap();
        $rows = $this->db->query("SELECT DISTINCT service_type FROM job_plans WHERE service_type IS NOT NULL AND service_type <> '' ORDER BY service_type")->fetchAll(PDO::FETCH_COLUMN);
        return array_map(fn($t) => ['service_type' => (string)$t, 'classes' => $map[strtolower(trim((string)$t))] ?? []], $rows);
    }

    /** Cities on active properties, with how many — shows which municipalities need rows. */
    public function cities(): array
    {
        try {
            return $this->db->query("SELECT COALESCE(NULLIF(TRIM(city), ''), '(blank)') AS city, COUNT(*) AS n FROM properties GROUP BY COALESCE(NULLIF(TRIM(city), ''), '(blank)') ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    // ── Writes (from /crm/ops/municipal-rules.php, jobs.edit) ───────────────

    public function saveRule(array $in, int $actorId): array
    {
        $muni = trim((string)($in['municipality'] ?? ''));
        if ($muni === '') return ['ok' => false, 'message' => 'Municipality is required.'];
        $kind = in_array($in['kind'] ?? '', ['hours', 'ban', 'note'], true) ? $in['kind'] : 'hours';
        $class = in_array($in['equipment_class'] ?? '', self::RULE_CLASSES, true) ? $in['equipment_class'] : 'power_equipment';
        $day = in_array($in['day_type'] ?? '', self::DAY_TYPES, true) ? $in['day_type'] : 'any';
        $status = in_array($in['status'] ?? '', self::STATUSES, true) ? $in['status'] : 'unverified';
        $time = static fn($t) => preg_match('/^\d{2}:\d{2}$/', (string)$t) ? $t : null;
        $vals = [
            $muni, trim((string)($in['area'] ?? '')) ?: null, $kind, $class,
            ($in['power_source'] ?? '') === 'gas' ? 'gas' : 'any', $day,
            $time($in['allowed_start'] ?? null), $time($in['allowed_end'] ?? null),
            isset($in['near_homes_m']) && $in['near_homes_m'] !== '' ? (int)$in['near_homes_m'] : null,
            $status, mb_substr(trim((string)($in['source_url'] ?? '')), 0, 255) ?: null, mb_substr(trim((string)($in['note'] ?? '')), 0, 500) ?: null,
        ];
        if ($kind === 'hours' && ($vals[6] === null) !== ($vals[7] === null)) return ['ok' => false, 'message' => 'Give both a start and an end time, or neither.'];
        $id = (int)($in['id'] ?? 0);
        $confirm = $status === 'verified' ? ', confirmed_by = ?, confirmed_at = NOW()' : ', confirmed_by = NULL, confirmed_at = NULL';
        if ($id > 0) {
            $args = array_merge($vals, $status === 'verified' ? [$actorId] : [], [$id]);
            $this->db->prepare("UPDATE municipal_rules SET municipality = ?, area = ?, kind = ?, equipment_class = ?, power_source = ?, day_type = ?,
                allowed_start = ?, allowed_end = ?, near_homes_m = ?, status = ?, source_url = ?, note = ?{$confirm} WHERE id = ?")->execute($args);
        } else {
            $this->db->prepare("INSERT INTO municipal_rules (municipality, area, kind, equipment_class, power_source, day_type, allowed_start, allowed_end, near_homes_m, status, source_url, note, confirmed_by, confirmed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($status === 'verified' ? 'NOW()' : 'NULL') . ")")
                ->execute(array_merge($vals, [$status === 'verified' ? $actorId : null]));
            $id = (int)$this->db->lastInsertId();
        }
        return ['ok' => true, 'message' => 'Saved.', 'id' => $id];
    }

    public function deleteRule(int $id): array
    {
        $this->db->prepare("DELETE FROM municipal_rules WHERE id = ?")->execute([$id]);
        return ['ok' => true, 'message' => 'Removed.'];
    }

    public function saveArea(array $in): array
    {
        $name = trim((string)($in['name'] ?? ''));
        $muni = trim((string)($in['municipality'] ?? ''));
        $fsa = strtoupper(preg_replace('/[^A-Za-z0-9,]/', '', (string)($in['fsa_prefixes'] ?? '')));
        if ($name === '' || $muni === '' || $fsa === '') return ['ok' => false, 'message' => 'Name, municipality and postal prefixes are required.'];
        $status = in_array($in['status'] ?? '', self::STATUSES, true) ? $in['status'] : 'unverified';
        $this->db->prepare("INSERT INTO municipal_areas (name, municipality, fsa_prefixes, status, note) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE fsa_prefixes = VALUES(fsa_prefixes), status = VALUES(status), note = VALUES(note)")
            ->execute([$name, $muni, $fsa, $status, mb_substr(trim((string)($in['note'] ?? '')), 0, 500) ?: null]);
        return ['ok' => true, 'message' => 'Saved.'];
    }

    public function saveServiceMap(string $serviceType, array $classes): array
    {
        $serviceType = trim($serviceType);
        if ($serviceType === '') return ['ok' => false, 'message' => 'Service type is required.'];
        $classes = array_values(array_intersect(self::EQUIPMENT_CLASSES, $classes));
        $this->db->prepare("DELETE FROM service_equipment WHERE service_type = ?")->execute([$serviceType]);
        $ins = $this->db->prepare("INSERT INTO service_equipment (service_type, equipment_class) VALUES (?, ?)");
        foreach ($classes as $c) $ins->execute([$serviceType, $c]);
        return ['ok' => true, 'message' => 'Saved.'];
    }

    // ── Otto's checks ───────────────────────────────────────────────────────

    /** @return array{0: array, 1: array} [bylaw items, west_end items] */
    public function items(): array
    {
        if (!$this->ready()) return [[], []];
        try {
            $rules = $this->rules();
            $areas = $this->areas();
            $map = $this->serviceMap();
            $s = $this->db->prepare("
                SELECT v.id, v.scheduled_date, v.scheduled_time_start, v.scheduled_time_end, v.assigned_crew_id,
                       p.service_type, p.default_time_start, p.estimated_duration_minutes,
                       prop.address, prop.city, prop.postal_code
                FROM job_visits v
                JOIN job_plans p ON p.id = v.plan_id
                JOIN properties prop ON prop.id = p.property_id
                WHERE v.scheduled_date BETWEEN ? AND ? AND v.status = 'scheduled'
                ORDER BY v.scheduled_date, v.scheduled_time_start
                LIMIT 300
            ");
            $s->execute([$this->today, date('Y-m-d', strtotime($this->today . ' +' . self::LOOKAHEAD_DAYS . ' days'))]);
            $visits = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Otto bylaw check: ' . $e->getMessage());
            return [[], []];
        }
        $byCity = [];
        foreach ($rules as $r) $byCity[DispatchRules::normalizeCity($r['municipality'])][] = $r;
        $holidays = DispatchRules::bcHolidays((int)substr($this->today, 0, 4)) + DispatchRules::bcHolidays((int)substr($this->today, 0, 4) + 1);
        $bylaw = $westEnd = [];
        foreach ($visits as $v) {
            $cityRules = $byCity[DispatchRules::normalizeCity($v['city'])] ?? [];
            if (!$cityRules) continue;
            $service = trim((string)($v['service_type'] ?: 'Visit'));
            $classes = $map[strtolower($service)] ?? [];
            $ruleClasses = DispatchRules::ruleClasses($classes);
            $date = (string)$v['scheduled_date'];
            $dayType = DispatchRules::dayType($date, $holidays);
            $start = $v['scheduled_time_start'] ?: $v['default_time_start'];
            $start = $start ? substr((string)$start, 0, 5) : null;
            $end = $v['scheduled_time_end'] ? substr((string)$v['scheduled_time_end'], 0, 5)
                 : ($start ? DispatchRules::endTime($start, (int)($v['estimated_duration_minutes'] ?: 60)) : null);
            $what = ucfirst(strtolower($service)) . ' at ' . OpsDeskService::street((string)$v['address']);
            $dayWord = OttoRules::dayWord($date, $this->today) . (isset($holidays[$date]) ? ' (' . $holidays[$date] . ')' : '');
            $base = ['subject_type' => 'visit', 'subject_id' => (int)$v['id'], 'for_date' => $date,
                     'user_id' => $v['assigned_crew_id'] ? (int)$v['assigned_crew_id'] : null,
                     'url' => '/crm/jobs/visit-detail.php?id=' . (int)$v['id'], 'since' => $date, 'detail' => ''];

            $b = DispatchRules::breach($cityRules, $dayType, $ruleClasses, [], $start, $end);
            if ($b) {
                $verified = $b['rule']['status'] === 'verified';
                $soon = $date <= date('Y-m-d', strtotime($this->today . ' +1 day'));
                $bylaw[] = $base + [
                    'key' => 'otto:bylaw:' . (int)$v['id'], 'kind' => 'bylaw',
                    'priority' => !$verified ? 3 : ($soon ? 1 : 2),
                    'text' => DispatchRules::breachText($b, $what, $dayWord, $start),
                    'detail' => (string)($b['rule']['note'] ?? ''), 'value' => $b['problem'],
                    'propose' => ['problem' => $b['problem'], 'start' => $start, 'end' => $end,
                                  'allowed_start' => $b['rule']['allowed_start'] ? substr((string)$b['rule']['allowed_start'], 0, 5) : null,
                                  'rule_id' => (int)$b['rule']['id'], 'verified' => $verified,
                                  'blower' => $b['rule']['equipment_class'] === 'leaf_blower'],
                ];
            }

            $inAreas = DispatchRules::areasFor(DispatchRules::fsa($v['postal_code'] ?? ''), $areas);
            foreach ($cityRules as $r) {
                if ($r['kind'] !== 'ban' || empty($r['area']) || !in_array($r['area'], $inAreas, true)) continue;
                $verified = $r['status'] === 'verified';
                $westEnd[] = $base + [
                    'key' => 'otto:westend:' . (int)$v['id'], 'kind' => 'west_end', 'priority' => $verified ? 2 : 3,
                    'text' => "{$what} {$dayWord} is in the {$r['area']}: no " . ($r['power_source'] === 'gas' ? 'gas ' : '')
                            . 'blowers there. Plan rake or vacuum time.' . ($verified ? '' : ' (Rule not yet confirmed.)'),
                    'detail' => (string)($r['note'] ?? ''), 'value' => $r['area'],
                    'propose' => ['area' => $r['area'], 'gas_only' => $r['power_source'] === 'gas'],
                ];
                break;
            }
        }
        return [$bylaw, $westEnd];
    }

    /** Rules Tim confirmed — a brain unit each. */
    public function confirmedCount(): int
    {
        try {
            return (int)$this->db->query("SELECT COUNT(*) FROM municipal_rules WHERE status = 'verified' AND confirmed_by IS NOT NULL")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
