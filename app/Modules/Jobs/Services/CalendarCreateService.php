<?php
declare(strict_types=1);

require_once __DIR__ . '/VisitResizeService.php';

/**
 * "Add a job or task right on the calendar": one one-off job plan with ONE visit on a date and
 * time. It goes through createJobPlan() (which generates the visit), the same writer as the full
 * create-job page and the crew "add on the spot" flow, so there is no second path.
 */
final class CalendarCreateService
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @param array $in kind job|task, property_id, title, date, start, end, anytime, assignee_ids[], notes
     * @return array plan fields for createJobPlan()
     * @throws InvalidArgumentException
     */
    public static function buildPlanData(array $in): array
    {
        $kind  = (($in['kind'] ?? 'job') === 'task') ? 'task' : 'job';
        $title = trim((string)($in['title'] ?? ''));
        $date  = (string)($in['date'] ?? '');
        if ((int)($in['property_id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Pick a client or address first.');
        }
        if ($title === '') {
            throw new InvalidArgumentException('A title is needed.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
            throw new InvalidArgumentException('Pick a valid date.');
        }

        $start = $end = null;
        $minutes = 60;
        if (empty($in['anytime'])) {
            [$start, $end, $minutes] = VisitResizeService::normalise((string)($in['start'] ?? ''), (string)($in['end'] ?? ''));
        }
        $crew = array_values(array_unique(array_filter(array_map('intval', (array)($in['assignee_ids'] ?? [])), static function ($id) { return $id > 0; })));

        return [
            'property_id'               => (int)$in['property_id'],
            'title'                     => $title,
            'service_type'              => $kind === 'task' ? 'Task' : $title,
            'description'               => trim((string)($in['notes'] ?? '')),
            'plan_start_date'           => $date,
            'is_recurring'              => 0,
            'pricing_model'             => 'per_visit',
            'default_crew_id'           => $crew[0] ?? null,
            'default_crew_size'         => max(1, count($crew)),
            'crew_ids'                  => $crew,
            'estimated_duration_minutes' => $minutes,
            'default_time_start'        => $start,
            'default_time_end'          => $end,
        ];
    }

    /** @return array{success:bool, plan_id?:int, visit_id?:int, stop_id?:int|null, date?:string, error?:string} */
    public function create(array $in, int $userId): array
    {
        try {
            $plan = self::buildPlanData($in);
        } catch (InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
        $result = createJobPlan($plan, $userId);
        if (empty($result['success'])) {
            return ['success' => false, 'error' => implode(' ', $result['errors'] ?? ['Could not create the job.'])];
        }
        $s = $this->db->prepare("SELECT id, stop_id FROM job_visits WHERE plan_id = ? AND scheduled_date = ? ORDER BY id LIMIT 1");
        $s->execute([(int)$result['plan_id'], $plan['plan_start_date']]);
        $v = $s->fetch(PDO::FETCH_ASSOC);
        if (!$v) {
            return ['success' => false, 'error' => 'The job was created but no visit was placed. Check the plan.', 'plan_id' => (int)$result['plan_id']];
        }
        return ['success' => true, 'plan_id' => (int)$result['plan_id'], 'visit_id' => (int)$v['id'],
                'stop_id' => $v['stop_id'] ? (int)$v['stop_id'] : null, 'date' => $plan['plan_start_date']];
    }

    /**
     * Previous job titles for the autocomplete: this property's own first, then the business's most used.
     * @return string[]
     */
    public function titles(int $propertyId, string $q, int $limit = 8): array
    {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($q)) . '%';
        $out = [];
        if ($propertyId > 0) {
            $s = $this->db->prepare("SELECT title FROM job_plans WHERE property_id = ? AND title LIKE ? GROUP BY title ORDER BY MAX(id) DESC LIMIT " . (int)$limit);
            $s->execute([$propertyId, $like]);
            $out = $s->fetchAll(PDO::FETCH_COLUMN);
        }
        if (count($out) < $limit) {
            $s = $this->db->prepare("SELECT title FROM job_plans WHERE title LIKE ? AND title <> '' GROUP BY title ORDER BY COUNT(*) DESC LIMIT " . (int)$limit);
            $s->execute([$like]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $t) {
                if (!in_array($t, $out, true)) {
                    $out[] = $t;
                }
            }
        }
        return array_slice(array_map('strval', $out), 0, $limit);
    }
}
