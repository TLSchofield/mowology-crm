<?php
declare(strict_types=1);

/**
 * The few edits the Timeline job modal makes that no existing endpoint offered:
 * cancel one visit, edit or delete a visit note, change a job's title / description / price.
 * Time, date, crew, adding a note, start, complete and skip stay on their existing endpoints.
 *
 * The rules are pure static functions (tested without a database); the methods apply them and write
 * a visit_audit_log row for every change. Callers check the permission (jobs.edit) first.
 */
final class VisitEditService
{
    public const NOTE_MAX = 4000;
    public const TITLE_MAX = 255;

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Rules ─────────────────────────────────────────────────────────

    /**
     * Why this visit cannot be cancelled, in words for the office; null when it can.
     * @param array{status?:string,locked_at?:?string,invoice_id?:?int|string,is_invoiced?:?int|string} $visit
     */
    public static function cancelRefusal(array $visit, bool $timerRunning): ?string
    {
        $status = (string)($visit['status'] ?? '');
        if ($status === 'cancelled') {
            return 'This visit is already cancelled.';
        }
        if ($status === 'completed') {
            return 'This visit is done, so it can’t be cancelled. Reopen it on the full visit page first.';
        }
        if (!empty($visit['locked_at'])) {
            return 'This visit is locked. An admin has to unlock it first.';
        }
        if (!empty($visit['invoice_id']) || !empty($visit['is_invoiced'])) {
            return 'This visit has an invoice, so it can’t be cancelled here.';
        }
        if ($timerRunning || $status === 'in_progress') {
            return 'Someone is working on this visit right now. Finish or skip it instead.';
        }
        return null;
    }

    /** Why a note can't be changed by this person; null when it can. System notes have no author. */
    public static function noteRefusal(?int $createdBy, int $userId, bool $canEditAll): ?string
    {
        if ($createdBy === null || $createdBy < 1) {
            return 'This note was added by the system, so it can’t be changed.';
        }
        if ($createdBy !== $userId && !$canEditAll) {
            return 'Only the person who wrote this note can change it.';
        }
        return null;
    }

    /** Trimmed note text, or null when empty or too long. */
    public static function cleanNote($text): ?string
    {
        if (!is_string($text)) {
            return null;
        }
        $t = trim(str_replace("\r\n", "\n", $text));
        return $t === '' || mb_strlen($t) > self::NOTE_MAX ? null : $t;
    }

    /**
     * Validate the job fields the modal may change.
     * @param array<string,mixed> $in  title, description, price_per_visit, confirm_price
     * @return array{data:array<string,mixed>,errors:string[]}
     */
    public static function cleanPlanFields(array $in): array
    {
        $data = [];
        $errors = [];
        if (array_key_exists('title', $in)) {
            $t = is_string($in['title']) ? trim($in['title']) : '';
            if ($t === '') {
                $errors[] = 'Give the job a title.';
            } elseif (mb_strlen($t) > self::TITLE_MAX) {
                $errors[] = 'The title is too long. Keep it under ' . self::TITLE_MAX . ' characters.';
            } else {
                $data['title'] = $t;
            }
        }
        if (array_key_exists('description', $in)) {
            $d = is_string($in['description']) ? trim($in['description']) : '';
            if (mb_strlen($d) > 5000) {
                $errors[] = 'The description is too long.';
            } else {
                $data['description'] = $d;
            }
        }
        if (array_key_exists('price_per_visit', $in)) {
            $p = $in['price_per_visit'];
            if (!is_numeric($p) || (float)$p <= 0 || (float)$p > 1000000) {
                $errors[] = 'Enter a price above $0.';
            } elseif (empty($in['confirm_price'])) {
                $errors[] = 'Confirm the new price first. It changes every visit of this job that isn’t invoiced yet.';
            } else {
                $data['price_per_visit'] = round((float)$p, 2);
            }
        }
        if (!$data && !$errors) {
            $errors[] = 'Nothing to change.';
        }
        return ['data' => $data, 'errors' => $errors];
    }

    // ── Writes ────────────────────────────────────────────────────────

    /** @return array{success:bool,error?:string} */
    public function cancelVisit(int $visitId, int $userId, ?string $ip): array
    {
        $st = $this->db->prepare("SELECT id, status, locked_at, invoice_id, is_invoiced FROM job_visits WHERE id = ?");
        $st->execute([$visitId]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v) {
            return ['success' => false, 'error' => 'This visit no longer exists. Refresh the schedule.'];
        }
        $t = $this->db->prepare("SELECT 1 FROM job_time_entries WHERE visit_id = ? AND status = 'active' AND end_time IS NULL LIMIT 1");
        $t->execute([$visitId]);
        $why = self::cancelRefusal($v, (bool)$t->fetchColumn());
        if ($why !== null) {
            return ['success' => false, 'error' => $why];
        }
        if (!VisitLifecycleService::updateVisitStatus($visitId, 'cancelled', $userId, 'Cancelled from the schedule')) {
            return ['success' => false, 'error' => 'Couldn’t cancel this visit. Refresh the schedule and try again.'];
        }
        $this->audit($visitId, $userId, 'cancelled', ['from' => $v['status']], $ip);
        return ['success' => true];
    }

    /** @return array{success:bool,error?:string} */
    public function editNote(int $noteId, int $userId, bool $canEditAll, $text, ?string $ip): array
    {
        $note = $this->loadNote($noteId);
        if (!$note) {
            return ['success' => false, 'error' => 'This note no longer exists.'];
        }
        $why = self::noteRefusal($note['created_by'], $userId, $canEditAll);
        if ($why !== null) {
            return ['success' => false, 'error' => $why];
        }
        $clean = self::cleanNote($text);
        if ($clean === null) {
            return ['success' => false, 'error' => 'Write a note up to ' . self::NOTE_MAX . ' characters.'];
        }
        $this->db->prepare("UPDATE visit_notes SET content = ? WHERE id = ?")->execute([$clean, $noteId]);
        $this->audit($note['visit_id'], $userId, 'note_edited', ['note_id' => $noteId, 'was' => mb_substr($note['content'], 0, 200)], $ip);
        return ['success' => true];
    }

    /** @return array{success:bool,error?:string} */
    public function deleteNote(int $noteId, int $userId, bool $canEditAll, ?string $ip): array
    {
        $note = $this->loadNote($noteId);
        if (!$note) {
            return ['success' => false, 'error' => 'This note no longer exists.'];
        }
        $why = self::noteRefusal($note['created_by'], $userId, $canEditAll);
        if ($why !== null) {
            return ['success' => false, 'error' => $why];
        }
        $this->db->prepare("DELETE FROM visit_notes WHERE id = ?")->execute([$noteId]);
        $this->audit($note['visit_id'], $userId, 'note_deleted', ['note_id' => $noteId, 'was' => mb_substr($note['content'], 0, 200)], $ip);
        return ['success' => true];
    }

    /**
     * Title, description, price for the job a visit belongs to. Price is refused for jobs priced by line items
     * (their price is the sum of the items; change it on the job page).
     * @param array<string,mixed> $input
     * @return array{success:bool,error?:string,errors?:string[]}
     */
    public function updatePlan(int $visitId, int $userId, array $input, ?string $ip): array
    {
        $st = $this->db->prepare("SELECT plan_id FROM job_visits WHERE id = ?");
        $st->execute([$visitId]);
        $planId = (int)$st->fetchColumn();
        if ($planId < 1) {
            return ['success' => false, 'error' => 'This visit no longer exists. Refresh the schedule.'];
        }
        $clean = self::cleanPlanFields($input);
        if ($clean['errors']) {
            return ['success' => false, 'error' => $clean['errors'][0], 'errors' => $clean['errors']];
        }
        if (array_key_exists('price_per_visit', $clean['data'])) {
            $li = $this->db->prepare("SELECT 1 FROM plan_line_items WHERE plan_id = ? LIMIT 1");
            $li->execute([$planId]);
            if ($li->fetchColumn()) {
                return ['success' => false, 'error' => 'This job is priced by its line items. Change the price on the job page.'];
            }
        }
        $res = updateJobPlan($planId, $clean['data'], $userId);
        if (empty($res['success'])) {
            return ['success' => false, 'error' => (string)($res['errors'][0] ?? 'Couldn’t save the job. Nothing was changed.')];
        }
        $this->audit($visitId, $userId, 'plan_edited', ['plan_id' => $planId, 'fields' => array_keys($clean['data'])], $ip);
        return ['success' => true];
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /** @return array{visit_id:int,created_by:?int,content:string}|null */
    private function loadNote(int $noteId): ?array
    {
        $st = $this->db->prepare("SELECT visit_id, created_by, content FROM visit_notes WHERE id = ?");
        $st->execute([$noteId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['visit_id' => (int)$r['visit_id'], 'created_by' => $r['created_by'] !== null ? (int)$r['created_by'] : null, 'content' => (string)$r['content']] : null;
    }

    /** @param array<string,mixed> $payload */
    private function audit(int $visitId, int $userId, string $action, array $payload, ?string $ip): void
    {
        try {
            $this->db->prepare("INSERT INTO visit_audit_log (visit_id, user_id, action, payload_json, ip_address) VALUES (?, ?, ?, ?, ?)")
                ->execute([$visitId, $userId, $action, json_encode($payload), $ip ? substr($ip, 0, 45) : null]);
        } catch (Throwable $e) {
            error_log('VisitEditService::audit ' . $e->getMessage());
        }
    }
}
