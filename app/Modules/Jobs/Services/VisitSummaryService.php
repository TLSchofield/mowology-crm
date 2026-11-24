<?php
declare(strict_types=1);

/**
 * Everything the office wants to see about ONE visit without leaving the schedule: the visit, the job
 * (plan) it belongs to, the client and property, who is on it, the other visits at the same stop, the
 * checklist, materials, notes, photo counts and the invoice. Read-only. The Timeline's "click a job"
 * modal is the only caller (/crm/api/visit-summary.php).
 *
 * The SQL lives in forVisit(); the shaping rules (checklist, materials, repeat wording, length) are
 * pure static functions so they can be tested without a database.
 */
final class VisitSummaryService
{
    private const NOTE_LIMIT = 12;

    /** Job-type words shown to people (the raw service_type keys stay in the data). */
    private const SERVICE_LABELS = [
        'lawn_care'          => 'Lawn care',
        'mowing'             => 'Mowing',
        'hedge_trimming'     => 'Hedge trimming',
        'garden_maintenance' => 'Garden maintenance',
        'snow_removal'       => 'Snow removal',
        'snow'               => 'Snow removal',
        'snow_clearance'     => 'Snow removal',
        'salt'               => 'Salt and de-icing',
        'de_ice'             => 'Salt and de-icing',
        'landscaping'        => 'Landscaping',
        'seasonal_cleanup'   => 'Seasonal cleanup',
        'fertilizer'         => 'Fertilizer',
        'lawn_treatment'     => 'Lawn treatment',
        'fertilization'      => 'Fertilizer',
        'aeration'           => 'Aeration',
        'overseeding'        => 'Overseeding',
        'general'            => 'General service',
    ];

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Pure shaping rules ────────────────────────────────────────────

    public static function serviceLabel(?string $type): string
    {
        $type = trim((string)$type);
        if ($type === '') {
            return 'Visit';
        }
        return self::SERVICE_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    /**
     * Checklist rows for the modal. Uses what the crew ticked (checklist_json) and falls back to the plan's
     * template with nothing ticked, the same way the full visit page does.
     *
     * @param mixed $done      decoded visits.checklist_json
     * @param mixed $template  decoded plan.checklist_template
     * @return array{items:array<int,array{label:string,done:bool,note:string}>,done:int,total:int}
     */
    public static function shapeChecklist($done, $template): array
    {
        $source = [];
        if (is_array($done) && $done) {
            $source = $done;
        } elseif (is_array($template)) {
            foreach ($template as $t) {
                $source[] = is_array($t) ? $t : ['item' => (string)$t, 'checked' => false];
            }
        }
        $items = [];
        $ticked = 0;
        foreach ($source as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string)($row['item'] ?? $row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $isDone = !empty($row['checked']);
            $ticked += $isDone ? 1 : 0;
            $items[] = ['label' => $label, 'done' => $isDone, 'note' => trim((string)($row['note'] ?? ''))];
        }
        return ['items' => $items, 'done' => $ticked, 'total' => count($items)];
    }

    /**
     * @param mixed $materials decoded visits.materials_json
     * @return array<int,array{name:string,qty:string,unit:string}>
     */
    public static function shapeMaterials($materials): array
    {
        $out = [];
        if (!is_array($materials)) {
            return $out;
        }
        foreach ($materials as $m) {
            if (!is_array($m) || trim((string)($m['name'] ?? '')) === '') {
                continue;
            }
            $qty = $m['qty'] ?? null;
            $out[] = [
                'name' => trim((string)$m['name']),
                'qty'  => is_numeric($qty) ? rtrim(rtrim(number_format((float)$qty, 2, '.', ''), '0'), '.') : '',
                'unit' => trim((string)($m['unit'] ?? '')),
            ];
        }
        return $out;
    }

    /** "Every week", "Every 2 weeks", "One time". */
    public static function repeatLabel(array $plan): string
    {
        if (empty($plan['is_recurring'])) {
            return 'One time';
        }
        $n = max(1, (int)($plan['recurrence_interval'] ?? 1));
        $pattern = (string)($plan['recurrence_pattern'] ?? '');
        $unit = (string)($plan['recurrence_interval_unit'] ?? 'weeks');
        if ($pattern === 'weekly') {
            $n = 1;
            $unit = 'weeks';
        } elseif ($pattern === 'biweekly') {
            $n = 2;
            $unit = 'weeks';
        } elseif ($pattern === 'monthly') {
            $n = 1;
            $unit = 'months';
        }
        $word = rtrim($unit, 's');
        return $n === 1 ? 'Every ' . $word : 'Every ' . $n . ' ' . $word . 's';
    }

    /** Minutes the visit is booked for: its own length if it was resized, else the job's usual length. */
    public static function lengthMinutes(array $visit, array $plan): ?int
    {
        foreach ([$visit['duration_override_minutes'] ?? null, $plan['estimated_duration_minutes'] ?? null] as $m) {
            if ($m !== null && (int)$m > 0) {
                return (int)$m;
            }
        }
        return null;
    }

    // ── Data ──────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>|null null when the visit does not exist
     */
    public function forVisit(int $visitId): ?array
    {
        $st = $this->db->prepare("
            SELECT v.*,
                   p.plan_number, p.title AS plan_title, p.description AS plan_description,
                   p.service_type AS plan_service_type, p.price_per_visit, p.pricing_model,
                   p.estimated_duration_minutes, p.checklist_template, p.is_recurring,
                   p.recurrence_pattern, p.recurrence_interval, p.recurrence_interval_unit,
                   p.status AS plan_status,
                   pr.id AS property_id, pr.address AS property_address, pr.city AS property_city,
                   pr.postal_code AS property_postal, pr.latitude, pr.longitude, pr.notes AS property_notes,
                   COALESCE(pr.total_lawn_sqft, pr.lawn_size_sqft) AS lawn_sqft,
                   c.id AS contact_id, CONCAT_WS(' ', c.first_name, c.last_name) AS contact_name,
                   COALESCE(c.phone, c.mobile) AS contact_phone, c.email AS contact_email,
                   cs.stop_date, cs.estimated_arrival, cs.estimated_departure, cs.notes AS stop_notes,
                   cs.status AS stop_status,
                   u.full_name AS crew_name
            FROM job_visits v
            JOIN job_plans p ON p.id = v.plan_id
            LEFT JOIN properties pr ON pr.id = p.property_id
            LEFT JOIN contacts c ON c.id = pr.site_contact_id
            LEFT JOIN calendar_stops cs ON cs.id = v.stop_id
            LEFT JOIN users u ON u.id = v.assigned_crew_id
            WHERE v.id = ?
            LIMIT 1
        ");
        $st->execute([$visitId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $companyName = null;
        if (!empty($row['property_id'])) {
            $cq = $this->db->prepare("
                SELECT co.id, co.company_name
                FROM company_properties cp JOIN companies co ON co.id = cp.company_id
                WHERE cp.property_id = ? LIMIT 1
            ");
            $cq->execute([(int)$row['property_id']]);
            $co = $cq->fetch(PDO::FETCH_ASSOC);
            $companyName = $co ? $co['company_name'] : null;
        }

        $serviceType = $row['service_type'] ?? $row['plan_service_type'] ?? '';
        $plan = [
            'is_recurring' => $row['is_recurring'], 'recurrence_pattern' => $row['recurrence_pattern'],
            'recurrence_interval' => $row['recurrence_interval'], 'recurrence_interval_unit' => $row['recurrence_interval_unit'],
            'estimated_duration_minutes' => $row['estimated_duration_minutes'],
        ];
        $checklist = self::shapeChecklist(
            self::decode($row['checklist_json'] ?? null),
            self::decode($row['checklist_template'] ?? null)
        );

        return [
            'visit' => [
                'id'          => (int)$row['id'],
                'stop_id'     => !empty($row['stop_id']) ? (int)$row['stop_id'] : null,
                'plan_id'     => (int)$row['plan_id'],
                'invoiced'    => !empty($row['invoice_id']) || !empty($row['is_invoiced']),
                'number'      => $row['visit_number'],
                'status'      => $row['status'],
                'date'        => $row['stop_date'] ?: $row['scheduled_date'],
                'start'       => $row['scheduled_time_start'],
                'end'         => $row['scheduled_time_end'],
                'minutes'     => self::lengthMinutes($row, $plan),
                'price'       => $row['price_per_visit'] !== null ? (float)$row['price_per_visit'] : null,
                'started_at'  => $row['started_at'] ?? null,
                'completed_at'=> $row['completed_at'] ?? null,
                'locked'      => !empty($row['locked_at']),
                'completion_notes' => trim((string)($row['completion_notes'] ?? '')),
            ],
            'job' => [
                'plan_id'      => (int)$row['plan_id'],
                'plan_number'  => $row['plan_number'],
                'title'        => $row['plan_title'],
                'description'  => trim((string)($row['plan_description'] ?? '')),
                'service'      => self::serviceLabel($serviceType),
                'repeat'       => self::repeatLabel($plan),
                'plan_status'  => $row['plan_status'],
                'recurring'    => !empty($row['is_recurring']),
                'recurrence'   => [
                    'unit'  => ($row['recurrence_pattern'] ?? '') === 'monthly' ? 'months' : 'weeks',
                    'every' => ($row['recurrence_pattern'] ?? '') === 'biweekly' ? max(2, (int)$row['recurrence_interval']) : max(1, (int)($row['recurrence_interval'] ?: 1)),
                    'days'  => self::weekdays($row['recurrence_pattern'] ?? '', $row['recurrence_day_of_week'] ?? null, $row['stop_date'] ?: $row['scheduled_date']),
                    'monthly' => ($row['recurrence_pattern'] ?? '') === 'monthly',
                ],
            ],
            'client' => [
                'company' => $companyName,
                'name'    => $row['contact_name'] !== null ? trim((string)$row['contact_name']) : null,
                'phone'   => $row['contact_phone'],
                'email'   => $row['contact_email'],
            ],
            'property' => [
                'address'  => $row['property_address'],
                'city'     => $row['property_city'],
                'postal'   => $row['property_postal'],
                'lat'      => $row['latitude'] !== null ? (float)$row['latitude'] : null,
                'lng'      => $row['longitude'] !== null ? (float)$row['longitude'] : null,
                'sqft'     => $row['lawn_sqft'] !== null && (float)$row['lawn_sqft'] > 0 ? (float)$row['lawn_sqft'] : null,
                'notes'    => trim((string)($row['property_notes'] ?? '')),
                'stop_notes' => trim((string)($row['stop_notes'] ?? '')),
            ],
            'crew'      => $this->crew((int)$row['id'], (int)($row['stop_id'] ?? 0), $row['crew_name']),
            'crew_ids'  => $this->crewIds((int)($row['stop_id'] ?? 0), (int)($row['assigned_crew_id'] ?? 0)),
            'same_stop' => $this->sameStop((int)$row['id'], (int)($row['stop_id'] ?? 0)),
            'checklist' => $checklist,
            'materials' => self::shapeMaterials(self::decode($row['materials_json'] ?? null)),
            'notes'     => $this->notes((int)$row['id'], (int)$row['plan_id']),
            'job_line_items' => $this->hasLineItems((int)$row['plan_id']),
            'photos'    => $this->photoCounts((int)$row['id']),
            'invoice'   => $this->invoice((int)$row['id']),
        ];
    }

    /** Weekdays 0 (Sunday) to 6 a weekly repeat falls on. Biweekly is every 2 weeks; the stored interval is read by the caller. PURE. */
    public static function weekdays(string $pattern, $dowList, ?string $fallbackDate): array
    {
        $days = [];
        foreach (explode(',', (string)$dowList) as $d) {
            if ($d !== '' && is_numeric($d) && (int)$d >= 0 && (int)$d <= 6) {
                $days[(int)$d] = true;
            }
        }
        if (!$days && $fallbackDate && preg_match('/^\d{4}-\d{2}-\d{2}/', $fallbackDate)) {
            $days[(int)date('w', strtotime(substr($fallbackDate, 0, 10)))] = true;
        }
        $out = array_keys($days);
        sort($out);
        return $out;
    }

    /** @return mixed */
    private static function decode($json)
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $d = json_decode($json, true);
        return is_array($d) ? $d : null;
    }

    /** @return string[] names, assigned person first */
    private function crew(int $visitId, int $stopId, ?string $assigned): array
    {
        $names = $assigned ? [$assigned] : [];
        if ($stopId > 0) {
            try {
                $q = $this->db->prepare("
                    SELECT u.full_name FROM calendar_stop_crew csc
                    JOIN users u ON u.id = csc.user_id WHERE csc.stop_id = ? ORDER BY u.full_name
                ");
                $q->execute([$stopId]);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $n) {
                    if ($n && !in_array($n, $names, true)) {
                        $names[] = $n;
                    }
                }
            } catch (Throwable $e) {
                error_log('VisitSummaryService::crew ' . $e->getMessage());
            }
        }
        return $names;
    }

    /** @return int[] who is on this stop (the people the crew picker starts with) */
    private function crewIds(int $stopId, int $assigned): array
    {
        $ids = $assigned > 0 ? [$assigned] : [];
        if ($stopId > 0) {
            try {
                $q = $this->db->prepare("SELECT user_id FROM calendar_stop_crew WHERE stop_id = ?");
                $q->execute([$stopId]);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    if (!in_array((int)$id, $ids, true)) {
                        $ids[] = (int)$id;
                    }
                }
            } catch (Throwable $e) {
                error_log('VisitSummaryService::crewIds ' . $e->getMessage());
            }
        }
        return $ids;
    }

    /** @return array<int,array<string,mixed>> the other visits booked at the same stop */
    private function sameStop(int $visitId, int $stopId): array
    {
        if ($stopId < 1) {
            return [];
        }
        $q = $this->db->prepare("
            SELECT v.id, v.status, v.scheduled_time_start, v.scheduled_time_end,
                   p.title, p.service_type
            FROM job_visits v JOIN job_plans p ON p.id = v.plan_id
            WHERE v.stop_id = ? AND v.id <> ? AND v.status <> 'cancelled'
            ORDER BY v.scheduled_time_start IS NULL, v.scheduled_time_start, v.id
            LIMIT 20
        ");
        $q->execute([$stopId, $visitId]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id' => (int)$r['id'], 'status' => $r['status'], 'start' => $r['scheduled_time_start'],
                'end' => $r['scheduled_time_end'], 'title' => $r['title'] ?: self::serviceLabel($r['service_type']),
            ];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> newest first: this visit's notes, then the job's */
    private function notes(int $visitId, int $planId): array
    {
        $out = [];
        $sources = [
            ['visit', "SELECT n.id, n.created_by, n.note_type, n.content, n.created_at, u.full_name AS author
                       FROM visit_notes n LEFT JOIN users u ON u.id = n.created_by
                       WHERE n.visit_id = ? ORDER BY n.created_at DESC LIMIT " . self::NOTE_LIMIT, $visitId],
            ['job', "SELECT n.note_type, n.content, n.created_at, u.full_name AS author
                     FROM plan_notes n LEFT JOIN users u ON u.id = n.created_by
                     WHERE n.plan_id = ? ORDER BY n.created_at DESC LIMIT " . self::NOTE_LIMIT, $planId],
        ];
        foreach ($sources as [$scope, $sql, $id]) {
            try {
                $q = $this->db->prepare($sql);
                $q->execute([$id]);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $n) {
                    $out[] = [
                        'id' => $scope === 'visit' ? (int)$n['id'] : null,
                        'author_id' => $scope === 'visit' && $n['created_by'] !== null ? (int)$n['created_by'] : null,
                        'scope' => $scope, 'type' => $n['note_type'], 'text' => (string)$n['content'],
                        'author' => $n['author'], 'at' => $n['created_at'],
                    ];
                }
            } catch (Throwable $e) {
                error_log('VisitSummaryService::notes ' . $e->getMessage());
            }
        }
        return $out;
    }

    private function hasLineItems(int $planId): bool
    {
        try {
            $q = $this->db->prepare("SELECT 1 FROM plan_line_items WHERE plan_id = ? LIMIT 1");
            $q->execute([$planId]);
            return (bool)$q->fetchColumn();
        } catch (Throwable $e) {
            return true; // unknown: keep the price read-only
        }
    }

    /** @return array<string,int> */
    private function photoCounts(int $visitId): array
    {
        $counts = ['before' => 0, 'during' => 0, 'after' => 0, 'other' => 0, 'total' => 0];
        try {
            $q = $this->db->prepare("SELECT photo_type, COUNT(*) FROM visit_photos WHERE visit_id = ? AND deleted_at IS NULL GROUP BY photo_type");
            $q->execute([$visitId]);
            foreach ($q->fetchAll(PDO::FETCH_NUM) as [$type, $n]) {
                $key = isset($counts[$type]) ? $type : 'other';
                $counts[$key] += (int)$n;
                $counts['total'] += (int)$n;
            }
        } catch (Throwable $e) {
            error_log('VisitSummaryService::photoCounts ' . $e->getMessage());
        }
        return $counts;
    }

    /** @return array<string,mixed>|null */
    private function invoice(int $visitId): ?array
    {
        try {
            $q = $this->db->prepare("
                SELECT i.id, i.invoice_number, i.status, i.total, i.balance_due
                FROM job_visits jv JOIN invoices i ON i.id = jv.invoice_id WHERE jv.id = ?
            ");
            $q->execute([$visitId]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            return $r ? [
                'id' => (int)$r['id'], 'number' => $r['invoice_number'], 'status' => $r['status'],
                'total' => (float)$r['total'], 'balance_due' => (float)$r['balance_due'],
            ] : null;
        } catch (Throwable $e) {
            error_log('VisitSummaryService::invoice ' . $e->getMessage());
            return null;
        }
    }
}
