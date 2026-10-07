<?php
/**
 * TrainingService — Otto watches crew training (the quiz and certification system that
 * nobody watched): who is scheduled on work they aren't certified for, repeat problems
 * on one service, quiz questions most of the crew gets wrong, and safety refreshers.
 *
 * Reads: cert_service_type_requirements (service ↔ tier/course — Tim ticks the course on
 * /crm/ops/municipal-rules.php), cert_records (+ expires_at; a lapsed cert is missing),
 * cert_tier_achievements, quiz_answers / cert_exam_answers, job_visits, media_links
 * (proof photos), visit_notes (issues), equipment + service_equipment (who runs tools).
 *
 * Every action makes a TASK FOR TIM (assigned to whoever clicked). Nothing reaches the
 * crew in v1 (Tim, 2026-10-06). Suggestion kinds: training_gap, training_quality,
 * training_topic, safety_refresher.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/TrainingRules.php';
require_once __DIR__ . '/OttoRules.php';
require_once __DIR__ . '/OpsDeskService.php';

class TrainingService
{
    /** Office roles are not flagged for crew training. */
    public const OFFICE_ROLES = ['admin'];
    /** Brain counts mappings Tim made from here on, not the migration-990 seeds. */
    public const LEARNING_SINCE = '2026-10-06';

    private PDO $db;
    private string $today;
    private string $now;
    private ?array $reqMemo = null;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today ?? date('Y-m-d');
        $this->now = $today ? $today . ' 12:00:00' : date('Y-m-d H:i:s');
    }

    public function ready(): bool
    {
        try {
            foreach (['cert_service_type_requirements', 'cert_records', 'cert_courses', 'service_types'] as $t) {
                if ($this->db->query("SHOW TABLES LIKE '{$t}'")->rowCount() === 0) return false;
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ── Reads ───────────────────────────────────────────────────────────────

    /** service key (slug and label) => [st_id, label, reqs[]] */
    public function requirements(): array
    {
        if ($this->reqMemo !== null) return $this->reqMemo;
        $rows = $this->db->query("
            SELECT st.id AS st_id, st.slug, st.label, r.min_tier_level, r.course_id, cc.name AS course_name, cc.slug AS course_slug
            FROM service_types st
            LEFT JOIN cert_service_type_requirements r ON r.service_type_id = st.id
            LEFT JOIN cert_courses cc ON cc.id = r.course_id
        ")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            foreach ([TrainingRules::serviceKey($r['slug']), TrainingRules::serviceKey($r['label'])] as $k) {
                if ($k === '') continue;
                $out[$k] ??= ['st_id' => (int)$r['st_id'], 'label' => (string)$r['label'], 'reqs' => []];
                if ($r['min_tier_level'] !== null) {
                    $out[$k]['reqs'][] = ['min_tier_level' => (int)$r['min_tier_level'], 'course_id' => $r['course_id'] ? (int)$r['course_id'] : null,
                                          'course_name' => $r['course_name'], 'course_slug' => $r['course_slug']];
                }
            }
        }
        return $this->reqMemo = $out;
    }

    public function courses(): array
    {
        return $this->db->query("SELECT id, name, slug FROM cert_courses WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
    }

    private function tiers(): array
    {
        $out = [];
        try {
            foreach ($this->db->query("SELECT cta.user_id, MAX(ct.tier_level) AS t FROM cert_tier_achievements cta JOIN cert_tiers ct ON ct.id = cta.tier_id GROUP BY cta.user_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['user_id']] = (int)$r['t'];
            }
        } catch (Throwable $e) { /* no tiers yet */ }
        return $out;
    }

    /** user_id => course_id => record */
    private function certs(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT cr.user_id, cr.course_id, cr.status, cr.expires_at, cr.issued_at FROM cert_records cr")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['user_id']][(int)$r['course_id']] = $r;
        }
        return $out;
    }

    /** user_id => [name, role] */
    private function people(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT id, full_name, role FROM users")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = ['name' => OpsDeskService::firstName((string)$r['full_name']), 'role' => (string)($r['role'] ?? '')];
        }
        return $out;
    }

    private function crew(array $people, int $uid): bool
    {
        return isset($people[$uid]) && !in_array($people[$uid]['role'], self::OFFICE_ROLES, true);
    }

    private function lessons(): array
    {
        $out = [];
        try {
            $s = $this->db->prepare("SELECT scope_key FROM otto_lessons WHERE scope = 'training'");
            $s->execute();
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $k) $out[(string)$k] = true;
        } catch (Throwable $e) {}
        return $out;
    }

    // ── Otto's suggestions ──────────────────────────────────────────────────

    /** @return array all training items */
    public function items(): array
    {
        if (!$this->ready()) return [];
        $out = [];
        foreach (['gapItems', 'qualityItems', 'topicItems', 'safetyItems'] as $m) {
            try {
                $out = array_merge($out, $this->$m());
            } catch (Throwable $e) {
                error_log("Otto training {$m}: " . $e->getMessage());
            }
        }
        return $out;
    }

    /** 1. Scheduled on a service they aren't certified for (next 7 days). */
    public function gapItems(): array
    {
        $reqs = $this->requirements();
        $s = $this->db->prepare("
            SELECT cs.id AS stop_id, cs.stop_date, cs.crew_id, p.service_type
            FROM calendar_stops cs
            JOIN job_visits v ON v.stop_id = cs.id
            JOIN job_plans p ON p.id = v.plan_id
            WHERE cs.stop_date BETWEEN ? AND ? AND v.status = 'scheduled'
        ");
        $s->execute([$this->today, date('Y-m-d', strtotime($this->today . ' +' . TrainingRules::LOOKAHEAD_DAYS . ' days'))]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];
        $crewOf = [];
        foreach ($rows as $r) if ($r['crew_id']) $crewOf[(int)$r['stop_id']][(int)$r['crew_id']] = true;
        try {
            $ids = array_values(array_unique(array_map(fn($r) => (int)$r['stop_id'], $rows)));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $c = $this->db->prepare("SELECT stop_id, user_id FROM calendar_stop_crew WHERE stop_id IN ({$in})");
            $c->execute($ids);
            foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) $crewOf[(int)$r['stop_id']][(int)$r['user_id']] = true;
        } catch (Throwable $e) { /* lead crew only */ }

        $tiers = $this->tiers();
        $certs = $this->certs();
        $people = $this->people();
        $lessons = $this->lessons();
        $pairs = [];
        foreach ($rows as $r) {
            $key = TrainingRules::serviceKey($r['service_type']);
            if (empty($reqs[$key]['reqs'])) continue;
            foreach (array_keys($crewOf[(int)$r['stop_id']] ?? []) as $uid) {
                if (!$this->crew($people, $uid)) continue;
                $p = &$pairs[$uid . '|' . $key];
                $p ??= ['uid' => $uid, 'key' => $key, 'dates' => [], 'visits' => 0, 'stops' => []];
                $p['dates'][(string)$r['stop_date']] = true;
                $p['visits']++;
                $p['stops'][(int)$r['stop_id']] = true;
                unset($p);
            }
        }
        $out = [];
        foreach ($pairs as $p) {
            $svc = $reqs[$p['key']];
            $miss = TrainingRules::missing($svc['reqs'], $tiers[$p['uid']] ?? 0, $certs[$p['uid']] ?? [], $this->now);
            if (!$miss) continue;
            // Who on the same stops does meet it — for the shadowing words and lesson.
            $mentors = [];
            $everyStopHasOne = true;
            foreach (array_keys($p['stops']) as $sid) {
                $has = false;
                foreach (array_keys($crewOf[$sid] ?? []) as $other) {
                    if ($other === $p['uid']) continue;
                    if (!TrainingRules::missing($svc['reqs'], $tiers[$other] ?? 0, $certs[$other] ?? [], $this->now)) { $has = true; $mentors[$other] = $people[$other]['name'] ?? 'someone'; }
                }
                if (!$has) $everyStopHasOne = false;
            }
            if ($everyStopHasOne && isset($lessons['shadow:' . $p['uid'] . ':' . $p['key']])) continue;
            $dates = array_keys($p['dates']);
            sort($dates);
            $who = $people[$p['uid']]['name'] ?? 'Someone';
            $when = count($dates) === 1 ? OttoRules::dayWord($dates[0], $this->today) : 'this week (' . implode(', ', array_map(fn($d) => date('D', strtotime($d)), $dates)) . ')';
            $label = strtolower($svc['label']);
            $lapsed = $miss['course_id'] && !empty($certs[$p['uid']][$miss['course_id']]);
            $why = $miss['tier'] ? "{$who} isn't at Tier {$miss['tier']} yet" . ($miss['course'] ? ", and it also needs {$miss['course']}." : '.')
                 : ($lapsed ? "{$who}'s {$miss['course']} cert has lapsed." : "{$who} hasn't passed {$miss['course']}.");
            $text = "{$who} is on " . OttoRules::plural($p['visits'], $label . ' visit') . " {$when}. {$why}";
            if ($mentors && $everyStopHasOne) $text .= ' ' . implode(' and ', array_values($mentors)) . ' is certified and on the same stops.';
            $out[] = [
                'key' => 'otto:training:' . $p['uid'] . ':' . $p['key'], 'kind' => 'training_gap',
                'subject_type' => 'training', 'subject_id' => TrainingRules::pairId($p['uid'], $svc['st_id']), 'for_date' => $dates[0],
                'user_id' => $p['uid'], 'priority' => $dates[0] <= date('Y-m-d', strtotime($this->today . ' +1 day')) ? 1 : 2,
                'text' => $text, 'detail' => 'From the training each service needs (Bylaw rules page → Training column).',
                'url' => '/crm/certification_appstack.php', 'value' => $miss['course'] ?? ('Tier ' . $miss['tier']), 'since' => $dates[0],
                'propose' => ['service' => $p['key'], 'service_label' => $svc['label'], 'course_id' => $miss['course_id'], 'course' => $miss['course'],
                              'tier' => $miss['tier'], 'who' => $who, 'first' => $dates[0], 'shadowed' => $everyStopHasOne && (bool)$mentors],
            ];
        }
        return $out;
    }

    /** 2. Repeat problems on one service → the training that covers it. */
    public function qualityItems(): array
    {
        $since = date('Y-m-d', strtotime($this->today . ' -' . TrainingRules::PROBLEM_DAYS . ' days'));
        $s = $this->db->prepare("
            SELECT v.id, v.status, v.scheduled_date, v.assigned_crew_id, p.service_type, p.photo_types_required,
                   (SELECT GROUP_CONCAT(DISTINCT ml.category) FROM media_links ml WHERE ml.context_type = 'job_visit' AND ml.context_id = v.id) AS cats,
                   (SELECT COUNT(*) FROM visit_notes n WHERE n.visit_id = v.id AND n.note_type = 'issue') AS issue_notes,
                   (SELECT GROUP_CONCAT(DISTINCT jte.user_id) FROM job_time_entries jte WHERE jte.visit_id = v.id AND jte.status <> 'void') AS doers
            FROM job_visits v JOIN job_plans p ON p.id = v.plan_id
            WHERE v.scheduled_date BETWEEN ? AND ? AND v.status IN ('completed', 'skipped')
            LIMIT 3000
        ");
        $s->execute([$since, $this->today]);
        $people = $this->people();
        $reqs = $this->requirements();
        $by = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $probs = TrainingRules::visitProblems([
                'status' => $v['status'], 'photo_types_required' => $v['photo_types_required'],
                'photo_categories' => $v['cats'] ? explode(',', (string)$v['cats']) : [], 'issue_notes' => (int)$v['issue_notes'],
            ]);
            if (!$probs) continue;
            $doers = $v['doers'] ? array_map('intval', explode(',', (string)$v['doers'])) : ($v['assigned_crew_id'] ? [(int)$v['assigned_crew_id']] : []);
            $key = TrainingRules::serviceKey($v['service_type']);
            foreach ($doers as $uid) {
                if (!$this->crew($people, $uid)) continue;
                $by[$uid . '|' . $key]['uid'] = $uid;
                $by[$uid . '|' . $key]['key'] = $key;
                $by[$uid . '|' . $key]['label'] = (string)$v['service_type'];
                foreach ($probs as $pr) $by[$uid . '|' . $key]['what'][$pr] = ($by[$uid . '|' . $key]['what'][$pr] ?? 0) + 1;
                $by[$uid . '|' . $key]['visits'][(int)$v['id']] = true;
            }
        }
        $month = substr($this->today, 0, 7) . '-01';
        $out = [];
        foreach ($by as $b) {
            $n = count($b['visits']);
            if ($n < TrainingRules::PROBLEM_MIN) continue;
            $svc = $reqs[$b['key']] ?? null;
            $course = null;
            foreach ((array)($svc['reqs'] ?? []) as $r) if (!empty($r['course_name'])) $course = $r['course_name'];
            arsort($b['what']);
            $evidence = implode(', ', array_map(fn($k, $c) => "{$k} ×{$c}", array_keys($b['what']), $b['what']));
            $who = $people[$b['uid']]['name'] ?? 'Someone';
            $label = strtolower($svc['label'] ?? $b['label']);
            $out[] = [
                'key' => 'otto:training-quality:' . $b['uid'] . ':' . $b['key'], 'kind' => 'training_quality',
                'subject_type' => 'trn_quality', 'subject_id' => TrainingRules::pairId($b['uid'], $svc['st_id'] ?? abs(crc32($b['key']))),
                'for_date' => $month, 'user_id' => $b['uid'], 'priority' => 3,
                'text' => "{$who} has had problems on {$n} {$label} visits in the last " . TrainingRules::PROBLEM_DAYS . " days ({$evidence}). "
                        . ($course ? "The {$course} module covers it." : 'No course is mapped to ' . $label . ' yet.'),
                'detail' => 'Problems: missing required photos, issue notes or photos, skipped visits.',
                'url' => '/crm/certification_appstack.php', 'value' => $n, 'since' => $since,
                'propose' => ['who' => $who, 'service_label' => $svc['label'] ?? $b['label'], 'course' => $course, 'evidence' => $evidence],
            ];
        }
        return $out;
    }

    /** 3. Quiz questions most of the crew gets wrong → crew-meeting topics. */
    public function topicItems(): array
    {
        $since = date('Y-m-d 00:00:00', strtotime($this->today . ' -' . TrainingRules::TOPIC_DAYS . ' days'));
        $rows = [];
        $sqls = [
            "SELECT s.user_id, a.question_id, a.is_correct, a.answered_at, q.category_id, c.name AS category, q.question_text
             FROM quiz_answers a JOIN quiz_sessions s ON s.id = a.session_id JOIN quiz_questions q ON q.id = a.question_id
             LEFT JOIN quiz_categories c ON c.id = q.category_id WHERE a.answered_at >= ?",
            "SELECT es.user_id, ea.question_id, ea.is_correct, ea.answered_at, q.category_id, c.name AS category, q.question_text
             FROM cert_exam_answers ea JOIN cert_exam_sessions es ON es.id = ea.exam_session_id JOIN quiz_questions q ON q.id = ea.question_id
             LEFT JOIN quiz_categories c ON c.id = q.category_id WHERE ea.answered_at >= ?",
        ];
        foreach ($sqls as $sql) {
            try {
                $st = $this->db->prepare($sql);
                $st->execute([$since]);
                $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));
            } catch (Throwable $e) { /* that quiz isn't installed */ }
        }
        $month = substr($this->today, 0, 7);
        $out = [];
        foreach (TrainingRules::topics($rows) as $t) {
            $qs = array_slice($t['questions'], 0, 3);
            $out[] = [
                'key' => 'otto:training-topic:' . $t['category_id'] . ':' . $month, 'kind' => 'training_topic',
                'subject_type' => 'quiz_topic', 'subject_id' => $t['category_id'], 'for_date' => $month . '-01', 'user_id' => null, 'priority' => 3,
                'text' => "Crew meeting topic: {$t['category']}. " . OttoRules::plural(count($t['questions']), 'question') . ' most of the crew got wrong this month, e.g. "'
                        . mb_strimwidth($qs[0]['text'], 0, 90, '…') . '" (' . $qs[0]['wrong'] . ' of ' . $qs[0]['people'] . ').',
                'detail' => 'At least ' . TrainingRules::TOPIC_PEOPLE . ' people answered; half or more got it wrong on their latest try.',
                'url' => '/crm/quiz_appstack.php', 'value' => count($t['questions']), 'since' => $month . '-01',
                'propose' => ['category' => $t['category'], 'questions' => array_map(fn($q) => $q['text'] . ' (' . $q['wrong'] . '/' . $q['people'] . ' wrong)', $qs)],
            ];
        }
        return $out;
    }

    /** 4. Safety refreshers for the people who run mowers, trimmers and blowers. */
    public function safetyItems(): array
    {
        $courses = [];
        foreach ($this->db->query("SELECT id, name, slug FROM cert_courses WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (in_array($c['slug'], TrainingRules::SAFETY_COURSES, true)) $courses[$c['slug']] = $c;
        }
        if (!$courses) return [];
        $people = $this->people();
        $certs = $this->certs();
        $users = [];
        try {
            foreach ($this->db->query("SELECT DISTINCT assigned_user_id FROM equipment WHERE status = 'active' AND assigned_user_id IS NOT NULL AND equipment_class IN ('mower', 'trimmer', 'blower')")->fetchAll(PDO::FETCH_COLUMN) as $u) $users[(int)$u] = true;
        } catch (Throwable $e) { /* no register yet */ }
        try {
            $s = $this->db->prepare("
                SELECT DISTINCT jte.user_id FROM job_time_entries jte
                JOIN job_visits v ON v.id = jte.visit_id JOIN job_plans p ON p.id = v.plan_id
                JOIN service_equipment se ON se.service_type = p.service_type AND se.equipment_class IN ('mower', 'trimmer', 'blower')
                WHERE jte.start_time >= ?
            ");
            $s->execute([date('Y-m-d', strtotime($this->today . ' -30 days'))]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $u) $users[(int)$u] = true;
        } catch (Throwable $e) { /* no service ticks yet */ }
        $month = substr($this->today, 0, 7) . '-01';
        $out = [];
        $add = function (int $uid, array $course, array $st) use (&$out, $people, $month) {
            $who = $people[$uid]['name'] ?? 'Someone';
            $text = $st['state'] === 'missing' ? "{$who} runs power equipment but has no {$course['name']} cert."
                  : ($st['state'] === 'expired' ? "{$who}'s {$course['name']} cert lapsed on " . date('M j', strtotime($st['expires'])) . '.'
                  : "{$who}'s {$course['name']} cert runs out on " . date('M j', strtotime($st['expires'])) . '.');
            $out[] = [
                'key' => 'otto:safety:' . $uid . ':' . $course['slug'], 'kind' => 'safety_refresher',
                'subject_type' => 'safety', 'subject_id' => TrainingRules::pairId($uid, (int)$course['id']),
                'for_date' => $st['expires'] ?? $month, 'user_id' => $uid, 'priority' => $st['state'] === 'due' ? 2 : 1,
                'text' => $text, 'detail' => 'A lapsed cert counts as missing.', 'url' => '/crm/certification_appstack.php',
                'value' => $st['expires'], 'since' => $st['expires'] ?? $month,
                'propose' => ['who' => $who, 'course' => $course['name'], 'state' => $st['state'], 'expires' => $st['expires']],
            ];
        };
        foreach (array_keys($users) as $uid) {
            if (!$this->crew($people, $uid) || empty($courses[TrainingRules::EQUIPMENT_SAFETY])) continue;
            $c = $courses[TrainingRules::EQUIPMENT_SAFETY];
            $st = TrainingRules::refresher($certs[$uid][(int)$c['id']] ?? null, $this->now);
            if ($st) $add($uid, $c, $st);
        }
        foreach ($courses as $slug => $c) {
            if ($slug === TrainingRules::EQUIPMENT_SAFETY) continue;
            foreach ($certs as $uid => $list) {
                if (!isset($list[(int)$c['id']]) || !$this->crew($people, (int)$uid)) continue;
                $st = TrainingRules::refresher($list[(int)$c['id']], $this->now);
                if ($st && $st['state'] !== 'missing') $add((int)$uid, $c, $st);
            }
        }
        return $out;
    }

    // ── Writes ──────────────────────────────────────────────────────────────

    /** A task for the person who clicked (Tim). Never assigned to the crew member. */
    public function taskForOwner(string $title, string $body, string $due, int $actorId): int
    {
        $this->db->prepare("INSERT INTO tasks (title, description, due_date, priority, status, assigned_to, created_by) VALUES (?, ?, ?, 'normal', 'pending', ?, ?)")
            ->execute([mb_substr($title, 0, 255), $body . "\n\nFrom Otto (crew training).", $due, $actorId ?: null, $actorId]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Tim's tick on the Bylaw rules page: the training a service type needs.
     * Writes the existing cert_service_type_requirements (replaces that service's rows).
     */
    public function saveMapping(string $serviceType, ?int $courseId, int $minTier): array
    {
        $svc = $this->requirements()[TrainingRules::serviceKey($serviceType)] ?? null;
        if (!$svc) return ['ok' => false, 'message' => "\"{$serviceType}\" is not in the service types list, so it can't carry a requirement."];
        $minTier = max(0, min(5, $minTier));
        $this->db->prepare("DELETE FROM cert_service_type_requirements WHERE service_type_id = ?")->execute([$svc['st_id']]);
        if ($minTier > 0 || $courseId) {
            $this->db->prepare("INSERT INTO cert_service_type_requirements (service_type_id, min_tier_level, course_id) VALUES (?, ?, ?)")
                ->execute([$svc['st_id'], max(1, $minTier), $courseId ?: null]);
        }
        $this->reqMemo = null;
        return ['ok' => true, 'message' => 'Saved.'];
    }

    /** Things learned, for Otto's brain. */
    public function learnedCounts(): array
    {
        $c = function (string $sql): int {
            try { return (int)$this->db->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
        };
        $coached = 0;
        try {
            $rows = $this->db->query("SELECT user_id, suggestion_json, decided_at FROM otto_suggestions WHERE kind = 'training_gap' AND status = 'accepted'")->fetchAll(PDO::FETCH_ASSOC);
            $chk = $this->db->prepare("SELECT 1 FROM cert_records WHERE user_id = ? AND course_id = ? AND status = 'active' AND issued_at > ? LIMIT 1");
            foreach ($rows as $r) {
                $j = json_decode((string)$r['suggestion_json'], true) ?: [];
                if (empty($j['course_id']) || !$r['user_id']) continue;
                $chk->execute([(int)$r['user_id'], (int)$j['course_id'], (string)$r['decided_at']]);
                if ($chk->fetchColumn()) $coached++;
            }
        } catch (Throwable $e) {}
        return [
            'mapped' => $c("SELECT COUNT(*) FROM cert_service_type_requirements WHERE course_id IS NOT NULL AND created_at >= '" . self::LEARNING_SINCE . "'"),
            'shadow' => $c("SELECT COUNT(*) FROM otto_lessons WHERE scope = 'training'"),
            'topics' => $c("SELECT COUNT(*) FROM otto_suggestions WHERE kind = 'training_topic' AND status = 'accepted'"),
            'coached' => $coached,
        ];
    }
}
