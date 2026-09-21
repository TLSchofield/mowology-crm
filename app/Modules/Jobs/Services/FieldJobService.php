<?php
declare(strict_types=1);

/**
 * FieldJobService — "add a job / visit on the spot" for crew in the field.
 *
 * One implementation behind both front doors:
 *   /crm/api/field-job.php + nearby-properties.php   (session — Android / web overlay)
 *   /api/schedule/field-job                          (JWT — iOS)
 *
 * The flow it serves: read the crew's GPS, find the property they are standing at, then
 *   • property has an active plan  → add today's visit to that plan
 *   • property has no plan         → create a job for it
 *   • nothing nearby               → search, or create a new client + property + job
 *
 * Requires plan-functions.php (findNearbyProperties, addAdHocVisit, createJobPlan) and
 * ContactService to be loaded by the caller — there is no autoloader.
 */
class FieldJobService
{
    /** Offered in the field. Kept here so the web overlay and the iOS app cannot drift apart. */
    public const SERVICE_TYPES = [
        'Lawn Maintenance', 'Lawn Cut', 'Garden Care', 'Cleanup',
        'Hedge Trimming', 'Fertilizing', 'Power Raking', 'Aeration',
        'Snow Removal', 'Salt Application', 'Other',
    ];

    public const FREQUENCIES   = ['weekly', 'biweekly', 'monthly'];
    public const DEFAULT_RADIUS_M = 250;
    public const MAX_RADIUS_M     = 5000;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Pure rules ───────────────────────────────────────────────────────────

    /** A YYYY-MM-DD the crew picked, else today. */
    public static function resolveDate($date, string $today): string
    {
        $date = trim((string)$date);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false ? $date : $today;
    }

    public static function resolveRadius($radius): int
    {
        $radius = (int)$radius;
        return ($radius <= 0 || $radius > self::MAX_RADIUS_M) ? self::DEFAULT_RADIUS_M : $radius;
    }

    /**
     * The createJobPlan() payload for a field-created job. The plan is assigned to the person
     * creating it so it lands on their schedule; createJobPlan() generates the visit(s) itself.
     * Price is per visit, NET of GST, and optional — blank means the office prices it later.
     */
    public static function buildPlanData(array $input, int $propertyId, int $userId, string $today): array
    {
        $serviceType = trim((string)($input['service_type'] ?? ''));
        $title       = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            $title = $serviceType !== '' ? $serviceType : 'Field job';
        }

        $date      = self::resolveDate($input['date'] ?? '', $today);
        $recurring = !empty($input['recurring']);
        $freq      = (string)($input['frequency'] ?? 'weekly');
        if (!in_array($freq, self::FREQUENCIES, true)) {
            $freq = 'weekly';
        }

        $price = (isset($input['price']) && $input['price'] !== '' && is_numeric($input['price']))
            ? round((float)$input['price'], 2)
            : null;
        if ($price !== null && $price < 0) {
            $price = null;
        }

        $planData = [
            'property_id'      => $propertyId,
            'title'            => $title,
            'service_type'     => $serviceType,
            'description'      => trim((string)($input['notes'] ?? '')),
            'plan_start_date'  => $date,
            'default_crew_id'  => $userId,
            'crew_ids'         => [$userId],
            'pricing_model'    => 'per_visit',
            'price_per_visit'  => $price,
            'estimated_amount' => $price,
            'is_recurring'     => $recurring ? 1 : 0,
        ];
        if ($recurring) {
            $planData['recurrence_pattern']       = $freq;
            $planData['recurrence_interval']      = 1;
            $planData['recurrence_interval_unit'] = $freq === 'monthly' ? 'months' : 'weeks';
            $planData['recurrence_day_of_week']   = (int)date('w', strtotime($date)); // 0=Sun..6=Sat
        }
        return $planData;
    }

    /** What create_job / create_client_job cannot proceed without. Null when fine. */
    public static function missingForJob(array $input, bool $newClient): ?string
    {
        if (trim((string)($input['service_type'] ?? '')) === '') {
            return $newClient ? 'Name, address and service are required.' : 'Property and service are required.';
        }
        if ($newClient && (trim((string)($input['first_name'] ?? '')) === '' || trim((string)($input['property_address'] ?? '')) === '')) {
            return 'Name, address and service are required.';
        }
        return null;
    }

    // ── Lookups ──────────────────────────────────────────────────────────────

    /**
     * Properties near a GPS fix, nearest first. Each carries EVERY active plan on it (a property
     * can hold a lawn plan and a salting plan), with whether that plan already has a visit today.
     * The legacy single-plan keys (plan_id / plan_title / has_plan / has_visit_today) stay for
     * the web overlay.
     */
    public function nearby(float $lat, float $lng, int $radiusM): array
    {
        $results = findNearbyProperties($lat, $lng, $radiusM, 15);
        if (!$results) {
            return [];
        }

        $ids = array_map(static fn (array $r): int => (int)$r['id'], $results);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("
            SELECT jp.id, jp.property_id, jp.title, jp.service_type,
                   (SELECT COUNT(*) FROM job_visits jv
                     WHERE jv.plan_id = jp.id AND jv.scheduled_date = ? AND jv.status != 'cancelled') AS today_count
            FROM job_plans jp
            WHERE jp.status = 'active' AND jp.property_id IN ($in)
            ORDER BY jp.id DESC
        ");
        $stmt->execute(array_merge([date('Y-m-d')], $ids));

        $plansByProperty = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $plansByProperty[(int)$row['property_id']][] = [
                'id'              => (int)$row['id'],
                'title'           => (string)$row['title'],
                'service_type'    => $row['service_type'],
                'has_visit_today' => (int)$row['today_count'] > 0,
            ];
        }

        foreach ($results as &$r) {
            $r['plans'] = $plansByProperty[(int)$r['id']] ?? [];
        }
        unset($r);
        return $results;
    }

    // ── Actions ──────────────────────────────────────────────────────────────

    /** @return array{success:bool, error?:string, visit_id?:int, visit_number?:string} */
    public function addVisit(int $planId, $date, int $userId): array
    {
        if ($planId <= 0) {
            return ['success' => false, 'status' => 400, 'error' => 'A plan is required.'];
        }
        $result = addAdHocVisit($planId, self::resolveDate($date, date('Y-m-d')), $userId, $userId);
        if (empty($result['success'])) {
            return ['success' => false, 'status' => 422, 'error' => implode(' ', $result['errors'] ?? ['Could not add the visit.'])];
        }
        return ['success' => true, 'visit_id' => $result['visit_id'], 'visit_number' => $result['visit_number']];
    }

    /** @return array{success:bool, error?:string, plan_id?:int, plan_number?:string} */
    public function createJob(int $propertyId, array $input, int $userId): array
    {
        if ($propertyId <= 0 || ($missing = self::missingForJob($input, false)) !== null) {
            return ['success' => false, 'status' => 400, 'error' => $missing ?? 'Property and service are required.'];
        }
        $result = createJobPlan(self::buildPlanData($input, $propertyId, $userId, date('Y-m-d')), $userId);
        if (empty($result['success'])) {
            return ['success' => false, 'status' => 422, 'error' => implode(' ', $result['errors'] ?? ['Could not create the job.'])];
        }
        return ['success' => true, 'plan_id' => $result['plan_id'], 'plan_number' => $result['plan_number']];
    }

    /**
     * New contact + property (pinned at the crew's GPS fix) + job. The contact is flagged for the
     * office to review — a name typed on a phone in a driveway is a starting point, not a record.
     */
    public function createClientJob(array $input, int $userId, string $createdBy): array
    {
        if (($missing = self::missingForJob($input, true)) !== null) {
            return ['success' => false, 'status' => 400, 'error' => $missing];
        }

        $lat = isset($input['lat']) && $input['lat'] !== '' ? (float)$input['lat'] : null;
        $lng = isset($input['lng']) && $input['lng'] !== '' ? (float)$input['lng'] : null;

        $contactId = (new ContactService($this->db))->createContact([
            'first_name'               => trim((string)$input['first_name']),
            'last_name'                => trim((string)($input['last_name'] ?? '')),
            'phone'                    => trim((string)($input['phone'] ?? '')),
            'preferred_contact_method' => 'phone',
            'notes'                    => 'Created in the field by ' . ($createdBy !== '' ? $createdBy : 'crew') . ' — please review.',
            'property_address'         => trim((string)$input['property_address']),
            'property_city'            => trim((string)($input['property_city'] ?? 'Vancouver')) ?: 'Vancouver',
            'property_postal_code'     => trim((string)($input['property_postal_code'] ?? '')),
            'property_latitude'        => $lat,
            'property_longitude'       => $lng,
        ]);

        // The brand-new contact has exactly one property — fetch its id.
        $pStmt = $this->db->prepare("SELECT id FROM properties WHERE site_contact_id = ? ORDER BY id DESC LIMIT 1");
        $pStmt->execute([$contactId]);
        $propertyId = (int)$pStmt->fetchColumn();
        if ($propertyId <= 0) {
            return ['success' => false, 'status' => 500, 'error' => 'Client saved but property could not be created.'];
        }

        $result = createJobPlan(self::buildPlanData($input, $propertyId, $userId, date('Y-m-d')), $userId);
        if (empty($result['success'])) {
            return ['success' => false, 'status' => 422, 'error' => implode(' ', $result['errors'] ?? ['Could not create the job.'])];
        }
        return [
            'success'     => true,
            'contact_id'  => $contactId,
            'property_id' => $propertyId,
            'plan_id'     => $result['plan_id'],
            'plan_number' => $result['plan_number'],
        ];
    }

    /** Route one POSTed action. Shared by the session and JWT endpoints. */
    public function handle(string $action, array $input, int $userId, string $createdBy): array
    {
        switch ($action) {
            case 'add_visit':
                return $this->addVisit((int)($input['plan_id'] ?? 0), $input['date'] ?? '', $userId);
            case 'create_job':
                return $this->createJob((int)($input['property_id'] ?? 0), $input, $userId);
            case 'create_client_job':
                return $this->createClientJob($input, $userId, $createdBy);
            default:
                return ['success' => false, 'status' => 400, 'error' => 'Unknown action.'];
        }
    }
}
