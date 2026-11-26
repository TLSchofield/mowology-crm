<?php
/**
 * TaskScheduleService — the tasks a day's schedule shows (2026-11-26).
 *
 * Open general tasks due on the day, plus open overdue ones on today. Same people filter as the stops
 * and appointments ($crewFilter: array of user ids, null = everyone). Purchase tasks and tasks with
 * no due date stay on the web task list. Sent by /api/schedule/day as `tasks`, never as stops.
 */
declare(strict_types=1);

class TaskScheduleService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Does this task belong on $date's schedule? Pure. $today is the real current date. */
    public static function visibleOn(string $due, string $status, string $type, string $date, string $today): bool
    {
        if ($due === '' || $status === 'completed' || $type !== 'general') return false;
        if ($due === $date) return true;
        return $date === $today && $due < $today;
    }

    /** Whole days past due (0 when due today or later). Pure. */
    public static function daysOverdue(string $due, string $today): int
    {
        if ($due >= $today) return 0;
        $a = DateTime::createFromFormat('!Y-m-d', $due);
        $b = DateTime::createFromFormat('!Y-m-d', $today);
        return ($a && $b) ? (int)$a->diff($b)->days : 0;
    }

    /** @return array<int, array<string, mixed>> */
    public function forDay(string $date, ?array $crewFilter, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $where = "t.status <> 'completed' AND t.task_type = 'general' AND t.due_date IS NOT NULL AND (t.due_date = ?";
        $args = [$date];
        if ($date === $today) {
            $where .= ' OR t.due_date < ?';
            $args[] = $today;
        }
        $where .= ')';
        // null = everyone; an empty list = nobody (never fail open).
        if ($crewFilter !== null) {
            $ids = array_values(array_filter(array_map('intval', $crewFilter)));
            if (!$ids) return [];
            $where .= ' AND t.assigned_to IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            foreach ($ids as $id) $args[] = $id;
        }
        $s = $this->db->prepare("
            SELECT t.id, t.title, t.description, t.due_date, t.due_time, t.priority, t.status, t.assigned_to,
                   u.full_name AS assigned_name, c.first_name, c.last_name, c.phone AS contact_phone,
                   p.address, p.city, p.latitude, p.longitude
            FROM tasks t
            LEFT JOIN users u ON u.id = t.assigned_to
            LEFT JOIN contacts c ON c.id = t.contact_id
            LEFT JOIN properties p ON p.id = t.property_id
            WHERE {$where}");
        $s->execute($args);
        $out = array_map(static fn(array $r): array => self::shape($r, $today), $s->fetchAll(PDO::FETCH_ASSOC));
        usort($out, static function (array $a, array $b): int {
            return [$b['overdue'], $a['due_time'] ?? '99:99', self::rank($a['priority']), $a['task_id']]
               <=> [$a['overdue'], $b['due_time'] ?? '99:99', self::rank($b['priority']), $b['task_id']];
        });
        return $out;
    }

    private static function rank(string $p): int
    {
        return $p === 'high' ? 0 : ($p === 'low' ? 2 : 1);
    }

    /** One row as the phones read it. Pure. */
    public static function shape(array $r, string $today): array
    {
        $lat = isset($r['latitude']) ? (float)$r['latitude'] : 0.0;
        $lng = isset($r['longitude']) ? (float)$r['longitude'] : 0.0;
        $coords = abs($lat) > 0.0001 || abs($lng) > 0.0001;   // 0,0 is a failed geocode
        $due = (string)$r['due_date'];
        $days = self::daysOverdue($due, $today);
        $name = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
        return [
            'task_id'          => (int)$r['id'],
            'title'            => (string)$r['title'],
            'description'      => !empty($r['description']) ? (string)$r['description'] : null,
            'due_date'         => $due,
            'due_time'         => !empty($r['due_time']) ? substr((string)$r['due_time'], 0, 5) : null,
            'priority'         => in_array($r['priority'] ?? '', ['high', 'low'], true) ? (string)$r['priority'] : 'normal',
            'status'           => (string)$r['status'],
            'overdue'          => $days > 0,
            'days_overdue'     => $days,
            'assigned_to'      => isset($r['assigned_to']) ? (int)$r['assigned_to'] : null,
            'assigned_name'    => isset($r['assigned_name']) ? (string)$r['assigned_name'] : null,
            'contact_name'     => $name !== '' ? $name : null,
            'contact_phone'    => !empty($r['contact_phone']) ? (string)$r['contact_phone'] : null,
            'property_address' => (string)($r['address'] ?? ''),
            'property_city'    => (string)($r['city'] ?? ''),
            'latitude'         => $coords ? $lat : null,
            'longitude'        => $coords ? $lng : null,
        ];
    }
}
