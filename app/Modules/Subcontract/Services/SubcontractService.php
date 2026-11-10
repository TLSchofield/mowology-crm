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
    private const MAX_FILE_BYTES = 15728640;
    private const MAX_FILES = 24;

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
        // Work already done off the system: the visit may be closed, and the link only asks for proof and the bill.
        $alreadyDone = !empty($options['already_done']);
        if ($row['status'] === 'cancelled' || ($row['status'] === 'completed' && !$alreadyDone)) throw new InvalidArgumentException('This visit is already closed.');

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
                 share_client_name, share_client_phone, after_the_fact, work_status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $subcontractorId, $visitId, (int)$row['plan_id'], hash('sha256', $token), substr($token, 0, 8),
            SubcontractRules::expiryFrom($this->now()),
            $amount === null ? null : round((float)$amount, 2),
            trim((string)($options['note'] ?? '')) ?: null,
            array_key_exists('share_client_name', $options) && !$options['share_client_name'] ? 0 : 1,
            array_key_exists('share_client_phone', $options) && !$options['share_client_phone'] ? 0 : 1,
            $alreadyDone ? 1 : 0,
            $alreadyDone ? 'started' : 'sent',
            $ownerId,
        ]);
        $jobId = (int)$this->db->lastInsertId();
        // Two tabs can both pass the check above; the earlier job wins.
        $first = $this->db->prepare("SELECT MIN(id) FROM subcontract_jobs WHERE visit_id = ? AND revoked_at IS NULL AND work_status NOT IN ('declined', 'cancelled')");
        $first->execute([$visitId]);
        if ((int)$first->fetchColumn() !== $jobId) {
            $this->db->prepare("DELETE FROM subcontract_jobs WHERE id = ?")->execute([$jobId]);
            throw new InvalidArgumentException('This visit is already with a subcontractor. Cancel that first.');
        }
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
        $job = $this->syncBill($job);
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
        $afterTheFact = !empty($job['after_the_fact']);
        if ($to === 'finished' && $afterTheFact) {
            $doneOn = (string)($input['done_on'] ?? '');
            $parsed = DateTime::createFromFormat('Y-m-d', $doneOn);
            if (!$parsed || $parsed->format('Y-m-d') !== $doneOn || $doneOn > substr($now, 0, 10)) return ['ok' => false, 'error' => 'Enter the day you did the work.'];
            $set[] = 'planned_date = ?';
            $params[] = $doneOn;
        }
        if (in_array($to, ['declined'], true)) { $set[] = 'expires_at = ?'; $params[] = SubcontractRules::expiryFrom($now, true); }

        if ($to === 'finished') {
            $problem = SubcontractRules::proofProblem($afterTheFact, count($this->files($id, 'photo')), (string)($input['note'] ?? ''));
            if ($problem !== null) return ['ok' => false, 'error' => $problem];
        }

        $params[] = $id;
        $params[] = $from;
        $stmt = $this->db->prepare('UPDATE subcontract_jobs SET ' . implode(', ', $set) . ' WHERE id = ? AND work_status = ?');
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) return ['ok' => false, 'error' => 'That step has already been done. Refresh to see where the job is.'];

        $reading = $this->reading($visitId, $input, in_array($to, ['started', 'finished'], true));
        $this->event($id, $visitId, $to, 'sub', null, trim((string)($input['note'] ?? '')) ?: null, $reading);
        if ($to === 'started') $this->hooks->visitInProgress($visitId, $ownerId);
        $this->notifyOwner($job, $to === 'finished' && $afterTheFact ? 'proof_sent' : $to);
        return ['ok' => true];
    }

    /** Keep one photo or invoice she sent. @return array{ok:bool, error?:string, file_id?:int} */
    public function addFile(array $job, string $kind, array $file): array
    {
        if (($job['link_state'] ?? '') !== 'ok') return ['ok' => false, 'error' => 'This link is no longer active.'];
        $step = SubcontractRules::nextStep($job);
        $allowed = $kind === 'invoice' ? ['send_bill'] : ['finish', 'send_proof', 'send_bill'];
        if (!in_array($step, $allowed, true)) return ['ok' => false, 'error' => 'Files can\'t be added at this step.'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'That file did not arrive. Please try again.'];
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_FILE_BYTES) return ['ok' => false, 'error' => 'That file is too large. The limit is 15 MB.'];
        if (count($this->files((int)$job['id'])) >= self::MAX_FILES) return ['ok' => false, 'error' => 'That is the most files this job can hold.'];

        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        $extension = SubcontractRules::extensionFor($kind, $mime);
        if ($extension === null) return ['ok' => false, 'error' => $kind === 'invoice' ? 'Send a PDF or a photo.' : 'Send a photo.'];

        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        if (!$this->hooks->storeFile((string)$file['tmp_name'], $this->hooks->fileDir() . '/' . $name)) {
            return ['ok' => false, 'error' => 'That file could not be saved. Please try again.'];
        }
        $this->db->prepare("INSERT INTO subcontract_files (job_id, kind, stored_name, original_name, mime_type, size_bytes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([(int)$job['id'], $kind, $name, mb_substr(basename((string)($file['name'] ?? '')), 0, 191), $mime, $size, $this->now()]);
        return ['ok' => true, 'file_id' => (int)$this->db->lastInsertId()];
    }

    public function files(int $jobId, ?string $kind = null): array
    {
        $sql = "SELECT id, kind, stored_name, original_name, mime_type FROM subcontract_files WHERE job_id = ?" . ($kind !== null ? " AND kind = ?" : '') . " ORDER BY id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($kind !== null ? [$jobId, $kind] : [$jobId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** One file with the path to read it from, for the signed-in API only. */
    public function fileForStaff(int $fileId): ?array
    {
        $stmt = $this->db->prepare("SELECT id, job_id, kind, stored_name, original_name, mime_type FROM subcontract_files WHERE id = ?");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$file) return null;
        $file['path'] = $this->hooks->fileDir() . '/' . basename((string)$file['stored_name']);
        return $file;
    }

    /** Her bill becomes a draft expense on the job, for Penny's normal review. */
    public function submitBill(array $job, array $input): array
    {
        if (($job['link_state'] ?? '') !== 'ok') return ['ok' => false, 'error' => 'This link is no longer active.'];
        if (SubcontractRules::nextStep($job) !== 'send_bill') return ['ok' => false, 'error' => 'This bill has already been sent.'];
        $amount = round((float)($input['amount'] ?? 0), 2);
        $gst = round((float)($input['gst'] ?? 0), 2);
        $invoiceNo = trim((string)($input['invoice_no'] ?? ''));
        $problem = SubcontractRules::billProblem($amount, $gst, $invoiceNo);
        if ($problem !== null) return ['ok' => false, 'error' => $problem];

        $id = (int)$job['id'];
        $claim = $this->db->prepare("UPDATE subcontract_jobs SET bill_status = 'sent', bill_amount = ?, bill_gst = ?, bill_invoice_no = ? WHERE id = ? AND bill_status IN ('none', 'returned')");
        $claim->execute([$amount, $gst, $invoiceNo ?: null, $id]);
        if ($claim->rowCount() !== 1) return ['ok' => false, 'error' => 'This bill has already been sent.'];

        $visit = $this->context((int)$job['visit_id']);
        try {
            $vendor = $this->db->prepare("SELECT vendor_id FROM subcontractors WHERE id = ?");
            $vendor->execute([(int)$job['subcontractor_id']]);
            $expenseId = $this->hooks->recordBill([
                'expense_date' => substr($this->now(), 0, 10),
                'vendor_id' => (int)$vendor->fetchColumn() ?: null,
                'vendor_name_raw' => (string)($job['company_name'] ?? ''),
                'description' => 'Subcontract: ' . (string)($visit['plan_title'] ?? ''),
                'amount' => $amount,
                'gst_amount' => $gst,
                'total' => round($amount + $gst, 2),
                'accounting_category' => self::SUBCONTRACT_CATEGORY,
                'job_id' => (int)$job['plan_id'],
                'property_id' => (int)($visit['property_id'] ?? 0) ?: null,
                'notes' => trim('Bill from the subcontract link, visit #' . $job['visit_id'] . ($invoiceNo !== '' ? ', their invoice ' . $invoiceNo : '')),
                'status' => 'draft',
                'source' => 'subcontract',
                'created_by' => (int)$job['created_by'],
            ], (int)$job['created_by']);
        } catch (Throwable $e) {
            error_log('SubcontractService::submitBill job ' . $id . ': ' . $e->getMessage());
            $this->db->prepare("UPDATE subcontract_jobs SET bill_status = ? WHERE id = ?")->execute([(string)$job['bill_status'], $id]);
            return ['ok' => false, 'error' => 'We could not record this bill. Please try again, or call the office.'];
        }
        $this->db->prepare("UPDATE subcontract_jobs SET bill_expense_id = ? WHERE id = ?")->execute([$expenseId, $id]);
        $this->event($id, (int)$job['visit_id'], 'bill_sent', 'sub', null, '$' . number_format($amount + $gst, 2));
        $this->notifyOwner($job, 'bill_sent');
        // Accepted before the bill came in: the visit's cost was taken from the agreed price, so take it again from the bill.
        if (!empty($job['work_accepted_at'])) $this->hooks->refreshVisitCost((int)$job['visit_id'], (int)$job['created_by']);
        return ['ok' => true];
    }

    /** The owner marks it paid once Penny has approved the bill and the work is accepted. */
    public function markPaid(int $jobId, int $userId): array
    {
        $job = $this->find($jobId);
        if (!$job) return ['ok' => false, 'error' => 'Job not found.'];
        $job = $this->syncBill($job);
        if (empty($job['work_accepted_at'])) return ['ok' => false, 'error' => 'Accept the work first.'];
        if ((string)$job['bill_status'] !== 'approved') return ['ok' => false, 'error' => 'Approve the bill with Penny first.'];
        $now = $this->now();
        $stmt = $this->db->prepare("UPDATE subcontract_jobs SET bill_status = 'paid', bill_paid_at = ?, expires_at = ? WHERE id = ? AND bill_status = 'approved'");
        $stmt->execute([$now, SubcontractRules::expiryFrom($now, true), $jobId]);
        if ($stmt->rowCount() !== 1) return ['ok' => false, 'error' => 'This bill has already been marked paid.'];
        $this->event($jobId, (int)$job['visit_id'], 'paid', 'staff', $userId);
        return ['ok' => true];
    }

    /** Once per job: she saw the Ittai panel. Counted, so the owner can see what subcontracting brings in. */
    public function markPanelSeen(array $job): void
    {
        if (!$this->hasEvent((int)$job['id'], 'panel_seen')) $this->event((int)$job['id'], (int)$job['visit_id'], 'panel_seen');
    }

    public function hasAskedAboutIttai(int $jobId): bool
    {
        return $this->hasEvent($jobId, 'ittai_interest');
    }

    public function askAboutIttai(array $job): array
    {
        if (($job['link_state'] ?? '') !== 'ok') return ['ok' => false, 'error' => 'This link is no longer active.'];
        if (!$this->hasAskedAboutIttai((int)$job['id'])) {
            $this->event((int)$job['id'], (int)$job['visit_id'], 'ittai_interest');
            $this->notifyOwner($job, 'ittai_interest');
        }
        return ['ok' => true];
    }

    /** @return array{invited:int, opened:int, panel_seen:int, asked:int} what subcontracting has brought in, across every job */
    public function marketingCounts(): array
    {
        $count = fn (string $type): int => (int)$this->db->query("SELECT COUNT(DISTINCT job_id) FROM subcontract_events WHERE event_type = '{$type}'")->fetchColumn();
        return ['invited' => $count('sent'), 'opened' => $count('opened'), 'panel_seen' => $count('panel_seen'), 'asked' => $count('ittai_interest')];
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
        if ($status === 'cancelled' && (string)$job['work_status'] === 'started' && empty($job['after_the_fact'])) {
            $this->hooks->visitBackToScheduled((int)$job['visit_id'], $userId);
        }
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
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        return $job ? $this->syncBill($job) : null;
    }

    public function timeline(int $jobId): array
    {
        $stmt = $this->db->prepare("SELECT event_type, actor, note, on_site, created_at FROM subcontract_events WHERE job_id = ? ORDER BY id");
        $stmt->execute([$jobId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** A bill Penny has approved or sent back shows on her page without anyone touching this job. */
    private function syncBill(array $job): array
    {
        if (!in_array((string)$job['bill_status'], ['sent', 'approved'], true) || empty($job['bill_expense_id'])) return $job;
        $stmt = $this->db->prepare("SELECT status FROM expenses WHERE id = ?");
        $stmt->execute([(int)$job['bill_expense_id']]);
        $status = (string)$stmt->fetchColumn();
        $next = in_array($status, ['approved', 'forwarded'], true) ? 'approved' : (in_array($status, ['rejected', 'cancelled'], true) ? 'returned' : null);
        if ($next === null || $next === $job['bill_status']) return $job;
        $this->db->prepare("UPDATE subcontract_jobs SET bill_status = ? WHERE id = ?")->execute([$next, (int)$job['id']]);
        $this->event((int)$job['id'], (int)$job['visit_id'], $next === 'approved' ? 'bill_approved' : 'bill_returned');
        $job['bill_status'] = $next;
        return $job;
    }

    private function hasEvent(int $jobId, string $type): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM subcontract_events WHERE job_id = ? AND event_type = ? LIMIT 1");
        $stmt->execute([$jobId, $type]);
        return (bool)$stmt->fetchColumn();
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
            SELECT jv.scheduled_date, jp.title AS plan_title, jp.property_id,
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
            ->execute([$name, self::SUBCONTRACT_CATEGORY, mb_substr($phone, 0, 20) ?: null]);
        return (int)$this->db->lastInsertId();
    }

    private function now(): string
    {
        return (string)$this->db->query('SELECT NOW()')->fetchColumn();
    }
}
