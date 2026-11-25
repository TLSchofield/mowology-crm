<?php
/**
 * MiaSequenceService — the reminder and last call that follow an approved campaign.
 *
 * Tim's approval of the main email covers its sequence: the proposal says so ("includes a
 * reminder on Sep 29 and a last call Oct 8") and the words are frozen into
 * mia_campaigns.sequence_json at that moment. Nothing else is ever added.
 *
 * Each follow-up goes N days after THAT person's main email, and only to someone who:
 *   - got the main email (status sent),
 *   - has not replied (inbound sales_messages), had a quote made, or booked (quote accepted
 *     or a plan started) since it,
 *   - has not unsubscribed (marketing_unsubscribes always wins) and still passes the consent
 *     ledger,
 *   - doesn't already have this step,
 *   - is not more than MiaCalendar::STALE_DAYS past the due date (no stale reminders).
 * The campaign sender re-checks canSendMarketing() again when it actually sends.
 *
 * Follow-ups are their own marketing_campaigns row (status 'sending', one per step) so the
 * sender renders their shorter words unchanged; their sends carry step, parent_send_id and a
 * scheduled_at in the person's best slot (SendTimeService).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/MiaCalendar.php';
require_once __DIR__ . '/SendTimeService.php';
require_once dirname(__DIR__, 2) . '/Consent/Services/ConsentLedgerService.php';

class MiaSequenceService
{
    public const STEP_NAMES = [1 => 'reminder', 2 => 'last call'];
    /** Sequences of campaigns approved longer ago than this are finished. */
    public const ACTIVE_DAYS = 60;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * What each recipient did after their main email.
     * @param array $sends   [['contact_id', 'status', 'sent_at']]
     * @param array $replies [['contact_id', 'sent_at']]  inbound messages
     * @param array $quotes  [['contact_id', 'created_at', 'status']]
     * @param array $plans   [['contact_id', 'created_at']]
     * @return array<int, array{replied_at: ?string, quoted_at: ?string, booked_at: ?string}>
     */
    public static function responses(array $sends, array $replies, array $quotes, array $plans): array
    {
        $since = [];
        foreach ($sends as $s) {
            if (($s['status'] ?? '') === 'sent' && !empty($s['sent_at'])) $since[(int)$s['contact_id']] = (string)$s['sent_at'];
        }
        $out = [];
        $mark = function (int $cid, string $field, string $at) use (&$out, $since) {
            if (!isset($since[$cid]) || $at < $since[$cid]) return;
            $out[$cid] = $out[$cid] ?? ['replied_at' => null, 'quoted_at' => null, 'booked_at' => null];
            if ($out[$cid][$field] === null || $at < $out[$cid][$field]) $out[$cid][$field] = $at;
        };
        foreach ($replies as $r) $mark((int)$r['contact_id'], 'replied_at', (string)$r['sent_at']);
        foreach ($quotes as $q) {
            $mark((int)$q['contact_id'], 'quoted_at', (string)$q['created_at']);
            if (in_array($q['status'] ?? '', ['accepted', 'approved_verbal'], true)) $mark((int)$q['contact_id'], 'booked_at', (string)$q['created_at']);
        }
        foreach ($plans as $p) $mark((int)$p['contact_id'], 'booked_at', (string)$p['created_at']);
        return $out;
    }

    /**
     * May this recipient get follow-up $days after their main email, now?
     * @param array $send        ['contact_id', 'email', 'status', 'sent_at']
     * @param array $responded   contact_id => anything (replied, quoted or booked)
     * @param array $unsubscribed lower-cased email => true
     * @param array $haveStep    contact_id => true (already has this step)
     * @return array{ok: bool, reason: string}
     */
    public static function eligible(array $send, int $days, DateTimeImmutable $now, array $responded, array $unsubscribed, array $haveStep): array
    {
        if (($send['status'] ?? '') !== 'sent' || empty($send['sent_at'])) return ['ok' => false, 'reason' => 'main email not sent'];
        $cid = (int)$send['contact_id'];
        $due = (new DateTimeImmutable((string)$send['sent_at']))->modify("+$days days")->setTime(0, 0);
        if ($now < $due) return ['ok' => false, 'reason' => 'not due yet'];
        if ($now > $due->modify('+' . MiaCalendar::STALE_DAYS . ' days')->setTime(23, 59, 59)) return ['ok' => false, 'reason' => 'too late'];
        if (isset($responded[$cid])) return ['ok' => false, 'reason' => 'responded'];
        if (isset($unsubscribed[strtolower(trim((string)($send['email'] ?? '')))])) return ['ok' => false, 'reason' => 'unsubscribed'];
        if (isset($haveStep[$cid])) return ['ok' => false, 'reason' => 'already has it'];
        return ['ok' => true, 'reason' => ''];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Database
    // ─────────────────────────────────────────────────────────────────────────

    /** Does the schema have sequences (migration 1195)? */
    public function ready(): bool
    {
        try {
            $this->db->query("SELECT step, parent_send_id, scheduled_at, replied_at, booked_at FROM campaign_sends LIMIT 0");
            $this->db->query("SELECT sequence_json, sequence_state_json FROM mia_campaigns LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Approved campaigns whose sequences are still running. */
    public function active(DateTimeImmutable $now): array
    {
        if (!$this->ready()) return [];
        $s = $this->db->prepare("SELECT * FROM mia_campaigns WHERE status = 'approved' AND marketing_campaign_id IS NOT NULL
                                 AND sequence_json IS NOT NULL AND decided_at >= ? ORDER BY id");
        $s->execute([$now->modify('-' . self::ACTIVE_DAYS . ' days')->format('Y-m-d')]);
        return array_values(array_filter($s->fetchAll(PDO::FETCH_ASSOC), fn($r) => (bool)json_decode((string)$r['sequence_json'], true)));
    }

    /**
     * Nightly: stamp replied/booked on main sends, then queue any follow-ups now due.
     * @return array{queued: int, stamped: int}
     */
    public function run(?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable();
        $queued = $stamped = 0;
        foreach ($this->active($now) as $c) {
            try {
                [$q, $s] = $this->runOne($c, $now);
                $queued += $q;
                $stamped += $s;
            } catch (Throwable $e) {
                error_log('Mia sequence ' . $c['campaign_key'] . ': ' . $e->getMessage());
            }
        }
        return ['queued' => $queued, 'stamped' => $stamped];
    }

    private function runOne(array $c, DateTimeImmutable $now): array
    {
        $mainId = (int)$c['marketing_campaign_id'];
        $s = $this->db->prepare("SELECT id, contact_id, email, status, sent_at FROM campaign_sends WHERE campaign_id = ? AND COALESCE(step, 0) = 0");
        $s->execute([$mainId]);
        $sends = $s->fetchAll(PDO::FETCH_ASSOC);
        $sent = array_values(array_filter($sends, fn($r) => $r['status'] === 'sent' && $r['sent_at']));
        if (!$sent) return [0, 0];

        $resp = self::responses($sent, ...$this->evidence($sent));
        $stamped = $this->stamp($sent, $resp);

        $steps = json_decode((string)$c['sequence_json'], true) ?: [];
        $state = json_decode((string)($c['sequence_state_json'] ?? ''), true) ?: [];
        $unsub = $this->unsubscribed();
        $queued = 0;
        foreach ($steps as $st) {
            $step = (int)($st['step'] ?? 0);
            $days = (int)($st['days'] ?? 0);
            if ($step < 1 || $days < 1 || trim((string)($st['body'] ?? '')) === '') continue;
            $have = [];
            $h = $this->db->prepare("SELECT cs.contact_id FROM campaign_sends cs JOIN campaign_sends p ON p.id = cs.parent_send_id
                                     WHERE p.campaign_id = ? AND cs.step = ?");
            $h->execute([$mainId, $step]);
            foreach ($h->fetchAll(PDO::FETCH_COLUMN) as $cid) $have[(int)$cid] = true;

            $due = [];
            foreach ($sent as $r) {
                if (self::eligible($r, $days, $now, $resp, $unsub, $have)['ok']) $due[] = $r;
            }
            if (!$due) continue;
            $consent = (new ConsentLedgerService($this->db))->bulk(array_map(fn($r) => (int)$r['contact_id'], $due), 'email', $now);
            $due = array_values(array_filter($due, fn($r) => !empty($consent[(int)$r['contact_id']]['ok'])));
            if (!$due) continue;

            $childId = $this->childCampaign($c, $st, $state);
            $state[(string)$step] = $childId;
            require_once __DIR__ . '/MiaCampaignService.php';
            $pm = (new MiaCampaignService($this->db))->segmentIds('property_managers,stratas', $now);
            $windowFrom = $now->format('Y-m-d');
            $windowTo = $now->modify('+' . MiaCalendar::STALE_DAYS . ' days')->format('Y-m-d');
            $slots = (new SendTimeService($this->db))->schedule(array_map(fn($r) => (int)$r['contact_id'], $due), $pm, $now, $windowFrom, $windowTo);
            $ins = $this->db->prepare("INSERT INTO campaign_sends (campaign_id, contact_id, email, status, step, parent_send_id, scheduled_at, created_at)
                                       VALUES (?, ?, ?, 'pending', ?, ?, ?, ?)");
            foreach ($due as $r) {
                $ins->execute([$childId, (int)$r['contact_id'], (string)$r['email'], $step, (int)$r['id'], $slots[(int)$r['contact_id']], $now->format('Y-m-d H:i:s')]);
                $queued++;
            }
            $this->db->prepare("UPDATE marketing_campaigns SET status = 'sending', recipient_count = (SELECT COUNT(*) FROM campaign_sends WHERE campaign_id = ?) WHERE id = ?")
                ->execute([$childId, $childId]);
        }
        $this->db->prepare("UPDATE mia_campaigns SET sequence_state_json = ? WHERE id = ?")->execute([json_encode($state), (int)$c['id']]);
        return [$queued, $stamped];
    }

    /** The follow-up's own campaign row (created once per step). */
    private function childCampaign(array $c, array $st, array $state): int
    {
        $step = (int)$st['step'];
        if (!empty($state[(string)$step])) return (int)$state[(string)$step];
        require_once __DIR__ . '/MiaCampaignService.php';
        $this->db->prepare("
            INSERT INTO marketing_campaigns (name, segment_type, segment_rules, trigger_type, auto_send, subject_override, body_override, status, recipient_count, created_by)
            VALUES (?, 'custom_list', ?, 'manual', 0, ?, ?, 'sending', 0, ?)
        ")->execute([
            $c['name'] . ' — ' . (self::STEP_NAMES[$step] ?? 'follow-up'),
            json_encode(['source' => 'mia', 'mia_campaign' => $c['campaign_key'], 'step' => $step, 'parent_campaign' => (int)$c['marketing_campaign_id']]),
            (string)$st['subject'], MiaCampaignService::html((string)$st['body'], null), $c['decided_by'] ? (int)$c['decided_by'] : null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /** Replies, quotes and plans since the first main email, for these recipients. */
    private function evidence(array $sent): array
    {
        $ids = implode(',', array_map(fn($r) => (int)$r['contact_id'], $sent)); // ints only
        $from = min(array_column($sent, 'sent_at'));
        $replies = $quotes = $plans = [];
        try {
            $m = $this->db->prepare("SELECT contact_id, sent_at FROM sales_messages WHERE direction = 'inbound' AND sent_at >= ? AND contact_id IN ($ids)");
            $m->execute([$from]);
            $replies = $m->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* no office@ log */ }
        try {
            $q = $this->db->prepare("SELECT COALESCE(q.contact_id, p.site_contact_id) AS contact_id, q.created_at, q.status
                                     FROM quotes q LEFT JOIN properties p ON p.id = q.property_id
                                     WHERE q.created_at >= ? AND (q.contact_id IN ($ids) OR p.site_contact_id IN ($ids))");
            $q->execute([$from]);
            $quotes = $q->fetchAll(PDO::FETCH_ASSOC);
            $p = $this->db->prepare("SELECT p.site_contact_id AS contact_id, jp.created_at FROM job_plans jp JOIN properties p ON p.id = jp.property_id
                                     WHERE jp.created_at >= ? AND p.site_contact_id IN ($ids)");
            $p->execute([$from]);
            $plans = $p->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { error_log('Mia sequence evidence: ' . $e->getMessage()); }
        return [$replies, $quotes, $plans];
    }

    /** Write replied_at / booked_at onto the main sends (first time only). */
    private function stamp(array $sent, array $resp): int
    {
        $n = 0;
        $r = $this->db->prepare("UPDATE campaign_sends SET replied_at = ? WHERE id = ? AND replied_at IS NULL");
        $b = $this->db->prepare("UPDATE campaign_sends SET booked_at = ? WHERE id = ? AND booked_at IS NULL");
        foreach ($sent as $s) {
            $x = $resp[(int)$s['contact_id']] ?? null;
            if (!$x) continue;
            if ($x['replied_at']) { $r->execute([$x['replied_at'], (int)$s['id']]); $n += $r->rowCount(); }
            if ($x['booked_at']) { $b->execute([$x['booked_at'], (int)$s['id']]); $n += $b->rowCount(); }
        }
        return $n;
    }

    private function unsubscribed(): array
    {
        $out = [];
        try {
            foreach ($this->db->query("SELECT email FROM marketing_unsubscribes")->fetchAll(PDO::FETCH_COLUMN) as $e) $out[strtolower(trim((string)$e))] = true;
        } catch (Throwable $e) {}
        return $out;
    }

    /**
     * For the card: each running sequence with what's sent and what's next.
     * @return array<int, array{name: string, sent: int, next: ?string, next_step: ?string, followups: int}>
     */
    public function summary(DateTimeImmutable $now): array
    {
        $out = [];
        foreach ($this->active($now) as $c) {
            try {
                $steps = json_decode((string)$c['sequence_json'], true) ?: [];
                $first = $this->db->prepare("SELECT MIN(sent_at) AS first_at, SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS n FROM campaign_sends WHERE campaign_id = ?");
                $first->execute([(int)$c['marketing_campaign_id']]);
                $f = $first->fetch(PDO::FETCH_ASSOC) ?: [];
                $state = json_decode((string)($c['sequence_state_json'] ?? ''), true) ?: [];
                $follow = 0;
                foreach ($state as $mc) {
                    $q = $this->db->prepare("SELECT COUNT(*) FROM campaign_sends WHERE campaign_id = ?");
                    $q->execute([(int)$mc]);
                    $follow += (int)$q->fetchColumn();
                }
                $next = $nextStep = null;
                if (!empty($f['first_at'])) {
                    foreach ($steps as $st) {
                        $d = (new DateTimeImmutable((string)$f['first_at']))->modify('+' . (int)$st['days'] . ' days')->format('Y-m-d');
                        if ($d >= $now->format('Y-m-d')) { $next = $d; $nextStep = self::STEP_NAMES[(int)$st['step']] ?? 'follow-up'; break; }
                    }
                }
                $out[] = ['name' => (string)$c['name'], 'sent' => (int)($f['n'] ?? 0), 'next' => $next, 'next_step' => $nextStep, 'followups' => $follow];
            } catch (Throwable $e) {}
        }
        return $out;
    }
}
