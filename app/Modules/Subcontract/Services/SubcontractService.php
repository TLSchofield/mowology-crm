<?php
declare(strict_types=1);

require_once __DIR__ . '/SubcontractRules.php';
require_once __DIR__ . '/SubcontractHooks.php';

/**
 * SubcontractService — give a visit to another company through one private link, and record what happens.
 *
 * Every step is a conditional UPDATE on the status it expects, so two taps (or two phones) cannot run a step twice.
 * Subcontract time stays in subcontract_events and never touches job_time_entries, so payroll cannot see it.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class SubcontractService
{
    private const SUBCONTRACT_CATEGORY = 'Subcontractors';

    private PDO $db;
    private SubcontractHooks $hooks;

    public function __construct(PDO $db, ?SubcontractHooks $hooks = null)
    {
        $this->db = $db;
        $this->hooks = $hooks ?? new SubcontractHooks();
    }

    public function listCompanies(): array
    {
        return $this->db->query("SELECT id, company_name, contact_name, email, phone, on_ittai FROM subcontractors WHERE is_active = 1 ORDER BY company_name")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Add a company, or refresh the details of one already on file. Its bills are booked against a vendor of the same name. */
    public function saveCompany(array $input, int $userId): int
    {
        $name = trim((string)($input['company_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 191) throw new InvalidArgumentException('Enter the company name.');
        $email = trim((string)($input['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('That email address does not look right.');
        $contact = mb_substr(trim((string)($input['contact_name'] ?? '')), 0, 120);
        $phone = mb_substr(trim((string)($input['phone'] ?? '')), 0, 30);
        $onIttai = !empty($input['on_ittai']) ? 1 : 0;

        $find = $this->db->prepare("SELECT id FROM subcontractors WHERE company_name = ? LIMIT 1");
        $find->execute([$name]);
        $id = (int)$find->fetchColumn();
        if ($id > 0) {
            $this->db->prepare("UPDATE subcontractors SET contact_name = ?, email = ?, phone = ?, on_ittai = ?, is_active = 1 WHERE id = ?")
                ->execute([$contact ?: null, $email ?: null, $phone ?: null, $onIttai, $id]);
            return $id;
        }
        $this->db->prepare("INSERT INTO subcontractors (company_name, contact_name, email, phone, vendor_id, on_ittai, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$name, $contact ?: null, $email ?: null, $phone ?: null, $this->vendorFor($name, $phone), $onIttai, $userId]);
        return (int)$this->db->lastInsertId();
    }

    /** @return array{job_id:int, token:string} the raw token exists only here and in the link */
    public function create(int $visitId, int $subcontractorId, int $ownerId, array $options = []): array
    {
        $visit = $this->db->prepare("SELECT plan_id, status FROM job_visits WHERE id = ?");
        $visit->execute([$visitId]);
        $row = $visit->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new InvalidArgumentException('That visit no longer exists.');
        if (in_array($row['status'], ['completed', 'cancelled'], true)) throw new InvalidArgumentException('This visit is already closed.');

        $company = $this->db->prepare("SELECT id FROM subcontractors WHERE id = ? AND is_active = 1");
        $company->execute([$subcontractorId]);
        if (!$company->fetchColumn()) throw new InvalidArgumentException('Pick a company first.');
        if ($this->activeForVisit($visitId)) throw new InvalidArgumentException('This visit is already with a subcontractor. Cancel that first.');

        $amount = $options['agreed_amount'] ?? null;
        if ($amount === '') $amount = null;
        if ($amount !== null && (!is_numeric($amount) || (float)$amount < 0)) throw new InvalidArgumentException('Enter what you will pay as a number.');

        $token = bin2hex(random_bytes(32));
        $this->db->prepare("
            INSERT INTO subcontract_jobs
                (subcontractor_id, visit_id, plan_id, token_hash, token_preview, expires_at, agreed_amount, note_to_sub,
                 share_client_name, share_client_phone, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $subcontractorId, $visitId, (int)$row['plan_id'], hash('sha256', $token), substr($token, 0, 8),
            SubcontractRules::expiryFrom($this->now()),
            $amount === null ? null : round((float)$amount, 2),
            trim((string)($options['note'] ?? '')) ?: null,
            array_key_exists('share_client_name', $options) && !$options['share_client_name'] ? 0 : 1,
            array_key_exists('share_client_phone', $options) && !$options['share_client_phone'] ? 0 : 1,
            $ownerId,
        ]);
        $jobId = (int)$this->db->lastInsertId();
        $this->event($jobId, $visitId, 'sent', 'staff', $ownerId);
        return ['job_id' => $jobId, 'token' => $token];
    }

    /** The job a link opens, with link_state and db_now added, or null when no job has this link. */
    public function resolve(string $rawToken): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) return null;
        $hash = hash('sha256', $rawToken);
        $stmt = $this->db->prepare("
            SELECT j.*, s.company_name, s.on_ittai
            FROM subcontract_jobs j JOIN subcontractors s ON s.id = j.subcontractor_id
            WHERE j.token_hash = ? LIMIT 1
        ");
        $stmt->execute([$hash]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job || !hash_equals((string)$job['token_hash'], $hash)) return null;
        $job['db_now'] = $this->now();
        $job['link_state'] = SubcontractRules::linkState($job, $job['db_now']);
        return $job;
    }

    /** Called when her page loads. The first open is on the timeline, so the owner can see the link reached her. */
    public function recordOpened(array $job): void
    {
        $this->db->prepare("UPDATE subcontract_jobs SET open_count = open_count + 1, last_opened_at = ? WHERE id = ?")
            ->execute([$this->now(), (int)$job['id']]);
        if ((int)$job['open_count'] === 0) $this->event((int)$job['id'], (int)$job['visit_id'], 'opened');
    }

    /** What her page shows. Only SubcontractRules::pagePayload() decides the fields. */
    public function page(array $job): array
    {
        return SubcontractRules::pagePayload($job, $this->context((int)$job['visit_id']), $this->workItems((int)$job['plan_id']), (string)$job['db_now']);
    }

    /** @return array{ok:bool, error?:string, noop?:bool} */
    public function act(array $job, string $action, array $input = []): array
    {
        if (($job['link_state'] ?? '') !== 'ok') return ['ok' => false, 'error' => 'This link is no longer active.'];
        $from = (string)$job['work_status'];
        $id = (int)$job['id'];
        $visitId = (int)$job['visit_id'];
        $ownerId = (int)$job['created_by'];
        $now = $this->now();

        if ($action === 'undo') return $this->undo($job, $now);

        $targets = ['accept' => 'accepted', 'decline' => 'declined', 'pick_day' => 'scheduled', 'on_my_way' => 'on_way', 'start' => 'started', 'finish' => 'finished'];
        if (!isset($targets[$action])) return ['ok' => false, 'error' => 'That step is not available.'];
        $to = $targets[$action];
        if (!SubcontractRules::canMove($from, $to)) return ['ok' => false, 'error' => 'That step has already been done. Refresh to see where the job is.'];

        $set = ['work_status = ?', 'status_changed_at = ?'];
        $params = [$to, $now];
        if ($action === 'pick_day') {
            $date = (string)($input['date'] ?? '');
            $problem = $this->dateProblem($date, $now);
            if ($problem !== null) return ['ok' => false, 'error' => $problem];
            if ($from === 'scheduled' && $date === (string)$job['planned_date']) return ['ok' => true, 'noop' => true];
            $set[] = 'planned_date = ?';
            $params[] = $date;
            $visit = $this->context($visitId);
            if ($date !== (string)($visit['scheduled_date'] ?? '') && !$this->hooks->moveVisit($visitId, $date, $ownerId)) {
                return ['ok' => false, 'error' => 'We could not move this visit to that day. Please call the office.'];
            }
        }
        if ($to === 'started')  { $set[] = 'started_at = ?';  $params[] = $now; }
        if ($to === 'finished') { $set[] = 'finished_at = ?'; $params[] = $now; }
        if (in_array($to, ['declined'], true)) { $set[] = 'expires_at = ?'; $params[] = SubcontractRules::expiryFrom($now, true); }

        $params[] = $id;
        $params[] = $from;
        $stmt = $this->db->prepare('UPDATE subcontract_jobs SET ' . implode(', ', $set) . ' WHERE id = ? AND work_status = ?');
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) return ['ok' => false, 'error' => 'That step has already been done. Refresh to see where the job is.'];

        $reading = $this->reading($visitId, $input, in_array($to, ['started', 'finished'], true));
        $this->event($id, $visitId, $to, 'sub', null, trim((string)($input['note'] ?? '')) ?: null, $reading);
        if ($to === 'started') $this->hooks->visitInProgress($visitId, $ownerId);
        $this->notifyOwner($job, $to);
        return ['ok' => true];
    }

    /** Stop a job and kill its link. Work already finished stays on record. */
    public function cancel(int $jobId, int $userId): bool
    {
        $job = $this->find($jobId);
        if (!$job) return false;
        $now = $this->now();
        $status = SubcontractRules::canMove((string)$job['work_status'], 'cancelled') ? 'cancelled' : (string)$job['work_status'];
        $this->db->prepare("UPDATE subcontract_jobs SET work_status = ?, status_changed_at = ?, revoked_at = ? WHERE id = ? AND revoked_at IS NULL")
            ->execute([$status, $now, $now, $jobId]);
        $this->event($jobId, (int)$job['visit_id'], 'cancelled', 'staff', $userId);
        return true;
    }

    /** A fresh link for a job. Only the hash is kept, so the old link stops working. @return ?string the new raw token */
    public function newLink(int $jobId, int $userId): ?string
    {
        $job = $this->find($jobId);
        if (!$job || !empty($job['revoked_at'])) return null;
        $token = bin2hex(random_bytes(32));
        $this->db->prepare("UPDATE subcontract_jobs SET token_hash = ?, token_preview = ?, expires_at = ? WHERE id = ?")
            ->execute([hash('sha256', $token), substr($token, 0, 8), SubcontractRules::expiryFrom($this->now()), $jobId]);
        $this->event($jobId, (int)$job['visit_id'], 'new_link', 'staff', $userId);
        return $token;
    }

    /** The owner has seen the work. Only now does the visit complete, through the normal path. */
    public function acceptWork(int $jobId, int $userId): array
    {
        $job = $this->find($jobId);
        if (!$job) return ['ok' => false, 'error' => 'Job not found.'];
        $stmt = $this->db->prepare("UPDATE subcontract_jobs SET work_accepted_at = ?, work_accepted_by = ? WHERE id = ? AND work_status = 'finished' AND work_accepted_at IS NULL");
        $stmt->execute([$this->now(), $userId, $jobId]);
        if ($stmt->rowCount() !== 1) return ['ok' => false, 'error' => 'This job is not waiting for your OK.'];
        if (!$this->hooks->completeVisit((int)$job['visit_id'], $userId)) {
            $this->db->prepare("UPDATE subcontract_jobs SET work_accepted_at = NULL, work_accepted_by = NULL WHERE id = ?")->execute([$jobId]);
            return ['ok' => false, 'error' => 'The visit could not be completed. Nothing was changed.'];
        }
        $this->event($jobId, (int)$job['visit_id'], 'work_accepted', 'staff', $userId);
        return ['ok' => true];
    }

    /** The job on a visit that is still running, with its company, or null. */
    public function activeForVisit(int $visitId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT j.*, s.company_name, s.contact_name, s.email AS company_email, s.phone AS company_phone
            FROM subcontract_jobs j JOIN subcontractors s ON s.id = j.subcontractor_id
            WHERE j.visit_id = ? AND j.revoked_at IS NULL AND j.work_status NOT IN ('declined', 'cancelled')
            ORDER BY j.id DESC LIMIT 1
        ");
        $stmt->execute([$visitId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function timeline(int $jobId): array
    {
        $stmt = $this->db->prepare("SELECT event_type, actor, note, on_site, created_at FROM subcontract_events WHERE job_id = ? ORDER BY id");
        $stmt->execute([$jobId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function undo(array $job, string $now): array
    {
        $target = SubcontractRules::undoTarget($job, $now);
        if ($target === null) return ['ok' => false, 'error' => 'That step can no longer be taken back. Please call the office.'];
        $clear = (string)$job['work_status'] === 'finished' ? ', finished_at = NULL' : '';
        $stmt = $this->db->prepare("UPDATE subcontract_jobs SET work_status = ?, status_changed_at = ?{$clear} WHERE id = ? AND work_status = ? AND work_accepted_at IS NULL");
        $stmt->execute([$target, $now, (int)$job['id'], (string)$job['work_status']]);
        if ($stmt->rowCount() !== 1) return ['ok' => false, 'error' => 'That step can no longer be taken back. Please call the office.'];
        $this->event((int)$job['id'], (int)$job['visit_id'], 'undo', 'sub', null, 'Took back: ' . $job['work_status']);
        return ['ok' => true];
    }

    private function find(int $jobId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM subcontract_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function context(int $visitId): array
    {
        $stmt = $this->db->prepare("
            SELECT jv.scheduled_date, jp.title AS plan_title,
                   p.address AS property_address, p.city AS property_city, p.latitude, p.longitude,
                   c.first_name AS contact_first_name, c.last_name AS contact_last_name, c.phone AS contact_phone
            FROM job_visits jv
            JOIN job_plans jp ON jp.id = jv.plan_id
            LEFT JOIN properties p ON p.id = jp.property_id
            LEFT JOIN contacts c ON c.id = p.site_contact_id
            WHERE jv.id = ?
        ");
        $stmt->execute([$visitId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<string> the names of the work, no prices */
    private function workItems(int $planId): array
    {
        $stmt = $this->db->prepare("SELECT COALESCE(NULLIF(description, ''), service_type) FROM plan_line_items WHERE plan_id = ? ORDER BY sort_order, id");
        $stmt->execute([$planId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** One location reading, taken only at Start and Finish and only if she allowed it. */
    private function reading(int $visitId, array $input, bool $wanted): array
    {
        if (!$wanted || !is_numeric($input['lat'] ?? null) || !is_numeric($input['lng'] ?? null)) return [];
        $lat = (float)$input['lat'];
        $lng = (float)$input['lng'];
        $accuracy = is_numeric($input['accuracy'] ?? null) ? (int)round((float)$input['accuracy']) : null;
        $visit = $this->context($visitId);
        $siteLat = is_numeric($visit['latitude'] ?? null) ? (float)$visit['latitude'] : null;
        $siteLng = is_numeric($visit['longitude'] ?? null) ? (float)$visit['longitude'] : null;
        $onSite = SubcontractRules::onSite($lat, $lng, $accuracy, $siteLat, $siteLng);
        return ['lat' => $lat, 'lng' => $lng, 'accuracy' => $accuracy, 'on_site' => $onSite === null ? null : (int)$onSite];
    }

    private function event(int $jobId, int $visitId, string $type, string $actor = 'sub', ?int $userId = null, ?string $note = null, array $reading = []): void
    {
        $this->db->prepare("
            INSERT INTO subcontract_events (job_id, visit_id, event_type, actor, user_id, note, latitude, longitude, accuracy_m, on_site, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$jobId, $visitId, $type, $actor, $userId, $note, $reading['lat'] ?? null, $reading['lng'] ?? null, $reading['accuracy'] ?? null, $reading['on_site'] ?? null, $this->now()]);
    }

    private function notifyOwner(array $job, string $event): void
    {
        $visit = $this->context((int)$job['visit_id']);
        $push = SubcontractRules::pushFor($event, (string)($job['company_name'] ?? 'Your subcontractor'), trim((string)($visit['property_address'] ?? 'the job')));
        if ($push === null) return;
        $this->hooks->push([(int)$job['created_by']], $push[0], $push[1], ['visit_id' => (int)$job['visit_id'], 'screen' => 'schedule']);
    }

    private function dateProblem(string $date, string $now): ?string
    {
        $parsed = DateTime::createFromFormat('Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) return 'Pick a day.';
        if ($date < substr($now, 0, 10)) return 'Pick today or a later day.';
        if ($date > date('Y-m-d', (int)strtotime($now) + 366 * 86400)) return 'Pick a day within the next year.';
        return null;
    }

    private function vendorFor(string $name, string $phone): ?int
    {
        $find = $this->db->prepare("SELECT id FROM vendors WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $find->execute([$name]);
        $id = (int)$find->fetchColumn();
        if ($id > 0) return $id;
        $this->db->prepare("INSERT INTO vendors (name, default_accounting_category, phone) VALUES (?, ?, ?)")
            ->execute([$name, self::SUBCONTRACT_CATEGORY, $phone ?: null]);
        return (int)$this->db->lastInsertId();
    }

    private function now(): string
    {
        return (string)$this->db->query('SELECT NOW()')->fetchColumn();
    }
}
