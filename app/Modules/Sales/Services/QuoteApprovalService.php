<?php
/**
 * QuoteApprovalService — when a quote is approved, Sam says exactly what was approved and
 * the job lands in the schedule's Unscheduled tray (owner, 2026-10-10).
 *
 * Why: approved quotes were only turned into jobs when someone remembered "Convert to Job",
 * and that put the visit on TODAY's schedule. And now that clients can untick lines
 * (QuoteLineChoiceService), a line can be missing from the job — the owner said he would
 * look at that and wonder whether the system had worked. So every approval produces:
 *   - one plain summary (summary(), pure + tested) used everywhere: the push, Sam's
 *     "Just approved" card item, the job's description. Every line is listed ✓ / ✗ with
 *     its amount, the approved total sits next to the quoted total, and a full approval
 *     says so ("All 4 lines approved");
 *   - a job (createPlanFromQuote — only the approved lines) whose visit is taken off the
 *     calendar so it waits in the Unscheduled tray, not on today, not on crew phones;
 *   - a quote_approvals row (migration 1316): once per quote, so the hooks and the nightly
 *     sweep can never make two jobs; it stays on Sam's card until "Got it".
 * Contracts get the summary but never a job here (they set themselves up).
 * Hooks: the client signing page, "Approved (verbal)", the old portal page; plus sweep()
 * in the nightly generate_visits cron for anything approved another way.
 * Settings: quote_auto_job_enabled (1/0), quote_auto_job_since (only quotes approved from
 * that date — older approvals never flood the tray).
 *
 * Global-namespace service: require_once the file. Never blocks or undoes an approval.
 */
declare(strict_types=1);

class QuoteApprovalService
{
    public const TAX_RATE = 0.05;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PURE (unit-tested)
    // ══════════════════════════════════════════════════════════════════════

    /** How it was approved, from what the quote row holds. */
    public static function via(array $quote): string
    {
        $by = (string)($quote['accepted_by_name'] ?? '');
        if (stripos($by, 'verbal approval') !== false) {
            return stripos($by, 'email') !== false ? 'by email' : 'verbal';
        }
        if (!empty($quote['signature_data'])) return 'signed online';
        return 'approved';
    }

    /** The approver's name without the office's "(verbal approval by …)" tail. */
    public static function approver(array $quote): string
    {
        $by = trim((string)($quote['accepted_by_name'] ?? ''));
        return trim((string)preg_replace('/\s*\(verbal approval by [^)]*\)\s*$/i', '', $by));
    }

    /**
     * The one summary every surface uses.
     * $lines: the quote's lines (service_type, description, line_total, is_optional, client_declined).
     * @return array{number:string, partial:bool, approved_count:int, line_count:int, approved_total:float,
     *               quoted_total:float, lines: array<int, array{label:string, amount:float, approved:bool}>,
     *               declined: string[], headline:string, push_title:string, push_body:string, job_note:string,
     *               via:string, approver:string}
     */
    public static function summary(array $quote, array $lines): array
    {
        $number = (string)($quote['quote_number'] ?? 'Quote');
        $out = [];
        $approvedSub = 0.0;
        $quotedSub = 0.0;
        $declined = [];
        foreach ($lines as $l) {
            if (!empty($l['is_optional'])) continue;   // optional lines were never in the price
            $amount = round((float)($l['line_total'] ?? 0), 2);
            $label = trim((string)($l['service_type'] ?? '')) ?: trim((string)($l['description'] ?? '')) ?: 'Service';
            $ok = empty($l['client_declined']);
            $out[] = ['label' => $label, 'amount' => $amount, 'approved' => $ok];
            $quotedSub += $amount;
            if ($ok) $approvedSub += $amount; else $declined[] = $label . ' $' . number_format($amount, 2);
        }
        $approvedTotal = round($approvedSub * (1 + self::TAX_RATE), 2);
        $quotedTotal = round($quotedSub * (1 + self::TAX_RATE), 2);
        $n = count($out);
        $k = $n - count($declined);
        $partial = count($declined) > 0;
        $money = fn(float $v): string => '$' . number_format($v, 2);
        $via = self::via($quote);
        $approver = self::approver($quote);

        $count = $partial ? "{$k} of {$n} lines" : ($n === 1 ? 'the 1 line' : "all {$n} lines");
        $headline = "{$number} approved: " . ($partial ? $count : ucfirst($count)) . ', ' . $money($approvedTotal) . ' incl. GST'
                  . ($partial ? ' (quoted ' . $money($quotedTotal) . ')' : '') . '.';
        $notIncl = $partial ? ' Not included: ' . implode(', ', $declined) . '.' : '';
        $who = $approver !== '' ? " Approved by {$approver}" . ($via !== 'approved' ? " ({$via})" : '') . '.' : '';

        return [
            'number'         => $number,
            'partial'        => $partial,
            'approved_count' => $k,
            'line_count'     => $n,
            'approved_total' => $approvedTotal,
            'quoted_total'   => $quotedTotal,
            'lines'          => $out,
            'declined'       => $declined,
            'headline'       => $headline,
            'push_title'     => "Sam: {$number} approved",
            'push_body'      => ($partial ? ucfirst($count) : ucfirst($count) . ' approved') . ', ' . $money($approvedTotal)
                                . ($partial ? ' (quoted ' . $money($quotedTotal) . ')' : '') . '.' . $notIncl,
            'job_note'       => "From {$number}: " . ($partial ? $count . ' approved.' . $notIncl : ucfirst($count) . ' approved.') . $who,
            'via'            => $via,
            'approver'       => $approver,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // DB
    // ══════════════════════════════════════════════════════════════════════

    public function ready(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM quote_approvals LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Everything that follows an approval. Safe to call more than once (the first wins).
     * @return array{status:string, plan_id?:?int, summary?:array, detail?:string}
     */
    public function afterApproval(int $quoteId, bool $push = true): array
    {
        if (!$this->ready()) return ['status' => 'skipped', 'detail' => 'migration 1316 not run'];
        $q = $this->quote($quoteId);
        if (!$q || ($q['status'] ?? '') !== 'accepted') return ['status' => 'skipped', 'detail' => 'not an accepted quote'];

        // Claim the quote first: the hook and the nightly sweep must never both make a job.
        $claim = $this->db->prepare("INSERT IGNORE INTO quote_approvals (quote_id, via, approved_by, created_at) VALUES (?, ?, ?, NOW())");
        $claim->execute([$quoteId, self::via($q), mb_substr(self::approver($q), 0, 190)]);
        if ($claim->rowCount() === 0) return ['status' => 'already'];

        $summary = self::summary($q, $this->lines($quoteId));
        $planId = null;
        $jobNote = null;
        if (empty($q['is_contract']) && $this->jobsOn($q)) {
            try {
                [$planId, $jobNote] = $this->trayJob($q, $summary);
            } catch (Throwable $e) {
                error_log('[quote approval] job for quote ' . $quoteId . ': ' . $e->getMessage());
                $jobNote = 'Job not created automatically: ' . $e->getMessage();
            }
        } elseif (!empty($q['is_contract'])) {
            $jobNote = 'Contract: set up from the contract, not as a tray job.';
        }
        $this->db->prepare("UPDATE quote_approvals SET plan_id = ?, summary = ?, job_note = ? WHERE quote_id = ?")
                 ->execute([$planId, json_encode($summary), $jobNote, $quoteId]);

        if ($push) $this->push($summary, $quoteId, $planId);
        return ['status' => 'done', 'plan_id' => $planId, 'summary' => $summary, 'detail' => $jobNote];
    }

    /** Nightly: approvals from the start date that no hook caught. */
    public function sweep(): array
    {
        $out = ['checked' => 0, 'done' => 0];
        if (!$this->ready()) return $out + ['skipped' => 'migration 1316'];
        $since = $this->setting('quote_auto_job_since', '');
        if ($since === '') return $out;
        $st = $this->db->prepare("
            SELECT q.id FROM quotes q
            LEFT JOIN quote_approvals a ON a.quote_id = q.id
            WHERE q.status = 'accepted' AND q.accepted_at >= ? AND a.quote_id IS NULL
            ORDER BY q.accepted_at LIMIT 50
        ");
        $st->execute([$since]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out['checked']++;
            if (($this->afterApproval((int)$id)['status'] ?? '') === 'done') $out['done']++;
        }
        return $out;
    }

    /** Sam's "Just approved" items: not yet acknowledged, last 30 days, newest first. */
    public function pending(int $limit = 10): array
    {
        if (!$this->ready()) return [];
        $st = $this->db->prepare("
            SELECT a.quote_id, a.plan_id, a.summary, a.job_note, a.created_at, jp.plan_number
            FROM quote_approvals a
            LEFT JOIN job_plans jp ON jp.id = a.plan_id
            WHERE a.acknowledged_at IS NULL AND a.summary IS NOT NULL AND a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY a.created_at DESC LIMIT " . max(1, min(30, $limit)));
        $st->execute();
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $s = json_decode((string)$r['summary'], true) ?: [];
            $out[] = [
                'quote_id'    => (int)$r['quote_id'],
                'plan_id'     => $r['plan_id'] !== null ? (int)$r['plan_id'] : null,
                'plan_number' => $r['plan_number'],
                'at'          => $r['created_at'],
                'headline'    => $s['headline'] ?? '',
                'partial'     => !empty($s['partial']),
                'lines'       => $s['lines'] ?? [],
                'approver'    => $s['approver'] ?? '',
                'via'         => $s['via'] ?? '',
                'job_note'    => $r['job_note'],
            ];
        }
        return $out;
    }

    public function acknowledge(int $quoteId, int $userId): bool
    {
        if (!$this->ready()) return false;
        $st = $this->db->prepare("UPDATE quote_approvals SET acknowledged_at = NOW(), acknowledged_by = ? WHERE quote_id = ? AND acknowledged_at IS NULL");
        $st->execute([$userId, $quoteId]);
        return $st->rowCount() > 0;
    }

    // ──────────────────────────────────────────────────────────────────────

    /** Make the job from the approved lines and take its visit off the calendar → tray. */
    private function trayJob(array $q, array $summary): array
    {
        if (!function_exists('createPlanFromQuote')) {
            require_once CRM_INCLUDES . '/functions.php';
            require_once CRM_INCLUDES . '/plan-functions.php';
        }
        $r = createPlanFromQuote((int)$q['id'], $this->ownerId());
        if (empty($r['success']) || empty($r['plan_id'])) {
            $why = implode('; ', (array)($r['errors'] ?? [])) ?: 'unknown reason';
            return [null, 'Job not created automatically: ' . $why];
        }
        $planId = (int)$r['plan_id'];
        // Into the tray: no calendar stop, so it is on nobody's day until it is placed.
        $stops = $this->db->prepare("SELECT DISTINCT stop_id FROM job_visits WHERE plan_id = ? AND stop_id IS NOT NULL");
        $stops->execute([$planId]);
        $stopIds = array_map('intval', $stops->fetchAll(PDO::FETCH_COLUMN));
        $this->db->prepare("UPDATE job_visits SET stop_id = NULL WHERE plan_id = ? AND status = 'scheduled'")->execute([$planId]);
        if ($stopIds) {
            if (!class_exists('CalendarStopTidyService')) require_once APP_ROOT . '/Modules/Jobs/Services/CalendarStopTidyService.php';
            foreach ($stopIds as $sid) CalendarStopTidyService::deleteIfEmpty($this->db, $sid);
        }
        // The job says what it covers — and what it doesn't — in the same words as Sam.
        $title = $summary['partial'] ? ' (' . $summary['approved_count'] . ' of ' . $summary['line_count'] . ' lines)' : '';
        $this->db->prepare("UPDATE job_plans SET description = TRIM(CONCAT(?, '\n\n', COALESCE(description, ''))),
                                                 title = LEFT(CONCAT(title, ?), 255) WHERE id = ?")
                 ->execute([$summary['job_note'], $title, $planId]);
        return [$planId, 'In the Unscheduled tray as ' . ($r['plan_number'] ?? ('plan ' . $planId)) . '.'];
    }

    private function push(array $summary, int $quoteId, ?int $planId): void
    {
        try {
            if (!class_exists('PushDispatcher')) require_once APP_ROOT . '/Services/Push/PushDispatcher.php';
            $body = $summary['push_body'] . ($planId ? ' Job is in the tray.' : '');
            PushDispatcher::notifyUsers($this->adminIds(), $summary['push_title'], $body,
                ['open' => 'team', 'head' => 'sam', 'type' => 'quote_approved', 'quote_id' => $quoteId]);
            if (!class_exists('ApnsService')) require_once APP_ROOT . '/Services/Push/ApnsService.php';
            PushDispatcher::drainQueue();
        } catch (Throwable $e) {
            error_log('[quote approval] push: ' . $e->getMessage());
        }
    }

    private function jobsOn(array $q): bool
    {
        if ($this->setting('quote_auto_job_enabled', '0') !== '1') return false;
        $since = $this->setting('quote_auto_job_since', '');
        return $since !== '' && (string)($q['accepted_at'] ?? '') >= $since;
    }

    private function quote(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM quotes WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function lines(int $quoteId): array
    {
        $st = $this->db->prepare("SELECT * FROM quote_line_items WHERE quote_id = ? ORDER BY sort_order, id");
        $st->execute([$quoteId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function setting(string $key, string $default): string
    {
        try {
            $st = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return $v === false || $v === null ? $default : (string)$v;
        } catch (Throwable $e) {
            return $default;
        }
    }

    private function adminIds(): array
    {
        try {
            return array_map('intval', $this->db->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function ownerId(): int
    {
        return $this->adminIds()[0] ?? 1;
    }
}
