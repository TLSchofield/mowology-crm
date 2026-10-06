<?php
/**
 * MiaFinder — who Mia thinks Tim should get back in touch with.
 *
 *   reconnect — completed work before, nothing in the last N months (mia_lapsed_months, 12)
 *   seasonal  — bought a service around this date last year, not had it again since
 *   pm_quiet  — a property-management company whose properties got half the visits or fewer
 *               in the last 90 days than in the same 90 days a year ago
 *   referral  — a customer of two or more seasons, or one who left a review, with work done
 *               recently, not asked in the last 12 months (only when the referral program is on)
 *
 * Who Mia leaves alone:
 *   - anyone with an open quote or new quote request — Sam owns them (SAM_OPEN rule below,
 *     agreed with the Sales head)
 *   - anyone with an active plan, a future visit or an active contract (except for referral
 *     asks — happy current customers are exactly who to ask)
 *   - anyone muted or snoozed (mia_mutes), or suggested in the last 60 days
 *   - anyone the CRM may not market to: unsubscribed, or no consent (canSendMarketing rules)
 *   - test records named ZZTEST
 *
 * Property-managed properties go to the PM bucket, never to the homeowner reconnect list.
 * No namespace / no autoloader in production: require_once and `new`.
 */
class MiaFinder
{
    public const RECENT_DAYS = 60;
    public const LOOKBACK_MONTHS = 36;
    public const PM_MIN_PRIOR = 3;
    public const PM_RATIO = 0.5;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function setting(string $key, int $default): int
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return ($v === false || $v === null || $v === '') ? $default : (int)$v;
        } catch (Throwable $e) {
            return $default;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Candidates (each row: kind, subject_key, contact, reason, priority, value)
    // ─────────────────────────────────────────────────────────────────────────

    private const CONTACT_COLS = "c.id AS contact_id, c.first_name, c.last_name, c.email, c.mobile, c.phone,
        c.receive_marketing, c.receive_sms, c.consent_email_express_at, c.consent_sms_express_at,
        c.consent_email_implied_at, c.has_reviewed";

    /** Past customers with no completed work in $months months. */
    public function reconnect(int $months, DateTimeImmutable $today): array
    {
        $s = $this->db->prepare("
            SELECT " . self::CONTACT_COLS . ",
                   MAX(COALESCE(jv.completed_at, jv.scheduled_date)) AS last_done,
                   COUNT(jv.id) AS visits,
                   COALESCE(SUM(jv.actual_amount), 0) AS value,
                   MAX(p.property_manager_id) AS pm_id,
                   SUBSTRING_INDEX(GROUP_CONCAT(jp.service_type ORDER BY COALESCE(jv.completed_at, jv.scheduled_date) DESC SEPARATOR '|'), '|', 1) AS last_service,
                   SUBSTRING_INDEX(GROUP_CONCAT(p.id ORDER BY COALESCE(jv.completed_at, jv.scheduled_date) DESC SEPARATOR '|'), '|', 1) AS property_id,
                   SUBSTRING_INDEX(GROUP_CONCAT(p.address ORDER BY COALESCE(jv.completed_at, jv.scheduled_date) DESC SEPARATOR '|'), '|', 1) AS address
            FROM job_visits jv
            JOIN job_plans jp ON jp.id = jv.plan_id
            JOIN properties p ON p.id = jp.property_id
            JOIN contacts c ON c.id = p.site_contact_id
            WHERE jv.status = 'completed' AND c.is_active = 1
            GROUP BY c.id
            HAVING last_done < ? AND last_done >= ?
        ");
        $s->execute([
            $today->modify("-{$months} months")->format('Y-m-d'),
            $today->modify('-' . self::LOOKBACK_MONTHS . ' months')->format('Y-m-d'),
        ]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!empty($r['pm_id'])) continue; // managed buildings → PM bucket
            $out[] = self::row('reconnect', $r, [
                'last_done' => substr((string)$r['last_done'], 0, 10),
                'service' => $r['last_service'],
                'visits' => (int)$r['visits'],
            ], 2, (float)$r['value']);
        }
        return $out;
    }

    /** Last year's seasonal work around this date, not had again since. */
    public function seasonal(DateTimeImmutable $today, int $before, int $after): array
    {
        [$from, $to, $bookedAfter] = self::seasonalWindow($today, $before, $after);
        $s = $this->db->prepare("
            SELECT " . self::CONTACT_COLS . ", jp.service_type,
                   MAX(COALESCE(jv.completed_at, jv.scheduled_date)) AS last_done,
                   COALESCE(SUM(jv.actual_amount), 0) AS value,
                   MAX(p.property_manager_id) AS pm_id,
                   MAX(p.id) AS property_id, MAX(p.address) AS address
            FROM job_visits jv
            JOIN job_plans jp ON jp.id = jv.plan_id
            JOIN properties p ON p.id = jp.property_id
            JOIN contacts c ON c.id = p.site_contact_id
            WHERE jv.status = 'completed' AND c.is_active = 1
              AND jp.service_type IS NOT NULL AND jp.service_type <> ''
              AND COALESCE(jv.completed_at, jv.scheduled_date) BETWEEN ? AND ?
              AND NOT EXISTS (
                  SELECT 1 FROM job_visits jv2
                  JOIN job_plans jp2 ON jp2.id = jv2.plan_id
                  JOIN properties p2 ON p2.id = jp2.property_id
                  WHERE p2.site_contact_id = c.id AND jp2.service_type = jp.service_type
                    AND jv2.status IN ('scheduled', 'in_progress', 'completed')
                    AND jv2.scheduled_date >= ?
              )
            GROUP BY c.id, jp.service_type
        ");
        $s->execute([$from . ' 00:00:00', $to . ' 23:59:59', $bookedAfter]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!empty($r['pm_id'])) continue;
            $out[] = self::row('seasonal', $r, [
                'last_done' => substr((string)$r['last_done'], 0, 10),
                'service' => $r['service_type'],
            ], 1, (float)$r['value']);
        }
        return $out;
    }

    /** Property-management companies whose work has fallen off. */
    public function pmQuiet(DateTimeImmutable $today): array
    {
        $recentFrom = $today->modify('-90 days')->format('Y-m-d');
        $priorFrom  = $today->modify('-455 days')->format('Y-m-d');
        $priorTo    = $today->modify('-365 days')->format('Y-m-d');
        $s = $this->db->prepare("
            SELECT co.id AS company_id, co.company_name, COALESCE(co.primary_contact_id, co.billing_contact_id) AS pm_contact_id,
                   SUM(CASE WHEN jv.scheduled_date >= ? THEN 1 ELSE 0 END) AS recent_visits,
                   SUM(CASE WHEN jv.scheduled_date >= ? AND jv.scheduled_date < ? THEN 1 ELSE 0 END) AS prior_visits,
                   SUM(CASE WHEN jv.scheduled_date >= ? AND jv.scheduled_date < ? THEN COALESCE(jv.actual_amount, 0) ELSE 0 END) AS prior_value,
                   COUNT(DISTINCT p.id) AS properties
            FROM job_visits jv
            JOIN job_plans jp ON jp.id = jv.plan_id
            JOIN properties p ON p.id = jp.property_id
            JOIN companies co ON co.id = p.property_manager_id
            WHERE jv.status = 'completed' AND jv.scheduled_date >= ?
            GROUP BY co.id
        ");
        $s->execute([$recentFrom, $priorFrom, $priorTo, $priorFrom, $priorTo, $priorFrom]);
        $rows = array_filter($s->fetchAll(PDO::FETCH_ASSOC),
            fn($r) => self::isQuiet((int)$r['prior_visits'], (int)$r['recent_visits']));
        if (!$rows) return [];

        $ids = array_values(array_filter(array_map(fn($r) => (int)$r['pm_contact_id'], $rows)));
        $contacts = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $c = $this->db->prepare("SELECT " . self::CONTACT_COLS . " FROM contacts c WHERE c.id IN ($in) AND c.is_active = 1");
            $c->execute($ids);
            foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) $contacts[(int)$r['contact_id']] = $r;
        }
        $out = [];
        foreach ($rows as $r) {
            $contact = $contacts[(int)$r['pm_contact_id']] ?? ['contact_id' => null];
            $row = self::row('pm_quiet', $contact + ['address' => null, 'property_id' => null], [
                'company' => $r['company_name'],
                'prior_visits' => (int)$r['prior_visits'],
                'recent_visits' => (int)$r['recent_visits'],
                'properties' => (int)$r['properties'],
            ], 1, (float)$r['prior_value']);
            $row['subject_key'] = 'mia:company:' . (int)$r['company_id'];
            $row['company_id'] = (int)$r['company_id'];
            $row['company_name'] = (string)$r['company_name'];
            $out[] = $row;
        }
        return $out;
    }

    /** Happy customers worth asking for a referral. */
    public function referral(DateTimeImmutable $today): array
    {
        try {
            $on = $this->db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'referral_program_enabled' LIMIT 1")->fetchColumn();
            if ((string)$on !== '1') return [];
            if ($this->db->query("SHOW TABLES LIKE 'referral_links'")->fetchColumn() === false) return [];
        } catch (Throwable $e) {
            return [];
        }
        $s = $this->db->prepare("
            SELECT " . self::CONTACT_COLS . ",
                   COUNT(DISTINCT YEAR(COALESCE(jv.completed_at, jv.scheduled_date))) AS seasons,
                   MAX(COALESCE(jv.completed_at, jv.scheduled_date)) AS last_done,
                   COALESCE(SUM(jv.actual_amount), 0) AS value,
                   MAX(p.property_manager_id) AS pm_id,
                   MAX(p.id) AS property_id, MAX(p.address) AS address
            FROM job_visits jv
            JOIN job_plans jp ON jp.id = jv.plan_id
            JOIN properties p ON p.id = jp.property_id
            JOIN contacts c ON c.id = p.site_contact_id
            LEFT JOIN referral_links rl ON rl.contact_id = c.id
            WHERE jv.status = 'completed' AND c.is_active = 1
              AND (rl.last_invite_sent_at IS NULL OR rl.last_invite_sent_at < ?)
            GROUP BY c.id
            HAVING last_done >= ? AND (seasons >= 2 OR MAX(c.has_reviewed) = 1)
        ");
        $s->execute([$today->modify('-12 months')->format('Y-m-d'), $today->modify('-120 days')->format('Y-m-d')]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!empty($r['pm_id'])) continue;
            $out[] = self::row('referral', $r, ['seasons' => (int)$r['seasons'], 'reviewed' => (bool)$r['has_reviewed']], 3, (float)$r['value']);
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Who to leave alone (sets of contact ids / subject keys)
    // ─────────────────────────────────────────────────────────────────────────

    /** Contacts Sam owns: an open quote or a new quote request (the Sales head's rule). */
    public function samOwns(DateTimeImmutable $today): array
    {
        $ids = [];
        $q = $this->db->prepare("
            SELECT DISTINCT COALESCE(q.contact_id, p.site_contact_id) AS cid
            FROM quotes q LEFT JOIN properties p ON p.id = q.property_id
            WHERE q.status IN ('sent', 'viewed') AND (q.valid_until IS NULL OR q.valid_until >= ?)
        ");
        $q->execute([$today->format('Y-m-d')]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) if ($id) $ids[(int)$id] = true;
        $r = $this->db->prepare("
            SELECT DISTINCT contact_id FROM quote_requests
            WHERE status IN ('new', 'reviewing') AND quote_id IS NULL AND contact_id IS NOT NULL AND created_at >= ?
        ");
        $r->execute([$today->modify('-60 days')->format('Y-m-d')]);
        foreach ($r->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[(int)$id] = true;
        return $ids;
    }

    /** Contacts with current work: an active plan, a future visit or an active contract. */
    public function current(DateTimeImmutable $today): array
    {
        $ids = [];
        $sqls = [
            "SELECT DISTINCT p.site_contact_id FROM job_plans jp JOIN properties p ON p.id = jp.property_id WHERE jp.status = 'active'",
            "SELECT DISTINCT p.site_contact_id FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id JOIN properties p ON p.id = jp.property_id
              WHERE jv.status IN ('scheduled', 'in_progress') AND jv.scheduled_date >= " . $this->db->quote($today->format('Y-m-d')),
            "SELECT DISTINCT contact_id FROM contracts WHERE status = 'active'",
            "SELECT DISTINCT p.site_contact_id FROM contracts ct JOIN properties p ON p.id = ct.property_id WHERE ct.status = 'active'",
        ];
        foreach ($sqls as $sql) {
            try {
                foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $id) if ($id) $ids[(int)$id] = true;
            } catch (Throwable $e) { /* a table that doesn't exist here holds nobody */ }
        }
        return $ids;
    }

    /** Subject keys muted, snoozed or suggested recently. */
    public function leaveAlone(DateTimeImmutable $today): array
    {
        $keys = [];
        $m = $this->db->prepare("SELECT subject_key FROM mia_mutes WHERE until_date IS NULL OR until_date >= ?");
        $m->execute([$today->format('Y-m-d')]);
        foreach ($m->fetchAll(PDO::FETCH_COLUMN) as $k) $keys[$k] = true;
        $r = $this->db->prepare("SELECT DISTINCT subject_key FROM mia_suggestions WHERE created_at >= ? OR status = 'open'");
        $r->execute([$today->modify('-' . self::RECENT_DAYS . ' days')->format('Y-m-d')]);
        foreach ($r->fetchAll(PDO::FETCH_COLUMN) as $k) $keys[$k] = true;
        return $keys;
    }

    /** Lower-cased unsubscribed emails. */
    public function unsubscribed(): array
    {
        $set = [];
        try {
            foreach ($this->db->query("SELECT LOWER(TRIM(email)) FROM marketing_unsubscribes")->fetchAll(PDO::FETCH_COLUMN) as $e) $set[$e] = true;
        } catch (Throwable $e) { /* no table → nobody unsubscribed */ }
        return $set;
    }

    /** Contacts who paid an invoice in the last 2 years (for the implied-consent gap). */
    public function paidRecently(DateTimeImmutable $today): array
    {
        $ids = [];
        try {
            $s = $this->db->prepare("SELECT DISTINCT contact_id FROM invoices WHERE status = 'paid' AND COALESCE(paid_at, issue_date) >= ? AND contact_id IS NOT NULL");
            $s->execute([$today->modify('-2 years')->format('Y-m-d')]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[(int)$id] = true;
        } catch (Throwable $e) {}
        return $ids;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** Common shape for a candidate. */
    public static function row(string $kind, array $c, array $reason, int $priority, float $value): array
    {
        $cid = isset($c['contact_id']) ? (int)$c['contact_id'] : 0;
        return [
            'kind' => $kind,
            'subject_key' => 'mia:contact:' . $cid,
            'contact_id' => $cid ?: null,
            'company_id' => null,
            'property_id' => !empty($c['property_id']) ? (int)$c['property_id'] : null,
            'contact' => $c,
            'address' => $c['address'] ?? null,
            'reason' => $reason,
            'priority' => $priority,
            'value' => round($value, 2),
        ];
    }

    /**
     * The seasonal window: around today's date last year. Works across the new year
     * (window dates are computed from today, never from a calendar season).
     * @return array{0: string, 1: string, 2: string} [from, to, bookedAfter] as Y-m-d
     */
    public static function seasonalWindow(DateTimeImmutable $today, int $before, int $after): array
    {
        $lastYear = $today->modify('-1 year');
        return [
            $lastYear->modify("-{$before} days")->format('Y-m-d'),
            $lastYear->modify("+{$after} days")->format('Y-m-d'),
            // Had the same service in the last 120 days, or has one booked: already looked after.
            $today->modify('-120 days')->format('Y-m-d'),
        ];
    }

    public static function isQuiet(int $prior, int $recent): bool
    {
        return $prior >= self::PM_MIN_PRIOR && $recent <= $prior * self::PM_RATIO;
    }

    /**
     * Mirror of canSendMarketing() for filtering many contacts at once (the real function
     * is called again, authoritatively, at send time).
     */
    public static function consent(array $c, array $unsub, string $channel, DateTimeImmutable $today): bool
    {
        $email = strtolower(trim((string)($c['email'] ?? '')));
        if ($email === '' || isset($unsub[$email])) return false;
        if ($channel === 'sms') {
            return !empty($c['receive_sms']) && !empty($c['consent_sms_express_at']);
        }
        if (!empty($c['receive_marketing']) && !empty($c['consent_email_express_at'])) return true;
        if (!empty($c['consent_email_implied_at'])) {
            $exp = strtotime($c['consent_email_implied_at'] . ' +2 years');
            return $exp && $today->getTimestamp() < $exp;
        }
        return false;
    }

    public static function isTest(array $c): bool
    {
        return stripos(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '') . ' ' . ($c['company_name'] ?? '')), 'ZZTEST') !== false;
    }

    /**
     * Apply every "leave alone" rule; one suggestion per person (most timely first), best first.
     * @return array{keep: array, no_consent: array<int, true>}
     */
    public static function filter(array $candidates, array $samOwns, array $current, array $leaveAlone, array $unsub, DateTimeImmutable $today): array
    {
        usort($candidates, fn($a, $b) => [$a['priority'], -$a['value']] <=> [$b['priority'], -$b['value']]);
        $keep = [];
        $noConsent = [];
        $seen = [];
        foreach ($candidates as $c) {
            $cid = (int)($c['contact_id'] ?? 0);
            $key = $c['subject_key'];
            if (isset($seen[$key]) || isset($leaveAlone[$key])) continue;
            if ($cid && isset($leaveAlone['mia:contact:' . $cid])) continue;
            if (self::isTest($c['contact'] + ['company_name' => $c['company_name'] ?? ''])) continue;
            if ($cid && isset($samOwns[$cid])) continue;
            if ($c['kind'] !== 'referral' && $c['kind'] !== 'pm_quiet' && $cid && isset($current[$cid])) continue;
            if (!$cid) { // a quiet PM with nobody to write to → a question, not a suggestion
                if ($c['kind'] === 'pm_quiet') { $c['needs_contact'] = true; $keep[] = $c; $seen[$key] = true; }
                continue;
            }
            if (!self::consent($c['contact'], $unsub, 'email', $today)) {
                $noConsent[$cid] = true;
                continue;
            }
            $c['sms_ok'] = self::consent($c['contact'], $unsub, 'sms', $today) && (($c['contact']['mobile'] ?? '') !== '' || ($c['contact']['phone'] ?? '') !== '');
            $seen[$key] = true;
            $keep[] = $c;
        }
        return ['keep' => $keep, 'no_consent' => $noConsent];
    }

    /**
     * What came of a message: the strongest thing that happened within $days of sending.
     * Null while the window is still open and nothing has happened; 'none' once it closes.
     * @param array $events [['type' => booked|quote|reply, 'at' => 'Y-m-d H:i:s', 'ref' => string]]
     */
    public static function outcome(string $sentAt, array $events, DateTimeImmutable $now, int $days = 30): ?array
    {
        $start = strtotime($sentAt);
        $end = $start + $days * 86400;
        $rank = ['booked' => 3, 'quote' => 2, 'reply' => 1];
        $best = null;
        foreach ($events as $e) {
            $t = strtotime((string)$e['at']);
            if ($t === false || $t < $start || $t > $end || !isset($rank[$e['type']])) continue;
            if ($best === null || $rank[$e['type']] > $rank[$best['type']]) $best = $e;
        }
        if ($best) return ['outcome' => $best['type'], 'at' => $best['at'], 'ref' => (string)($best['ref'] ?? '')];
        return $now->getTimestamp() > $end ? ['outcome' => 'none', 'at' => date('Y-m-d H:i:s', $end), 'ref' => ''] : null;
    }

    /**
     * Learned threshold: if Tim skipped 7 of Mia's last 10 reconnects as "not a fit",
     * she waits 3 months longer before suggesting a lapsed customer (up to 24).
     * @param array $recent newest first: ['status' => sent|skipped, 'skip_reason' => ?string]
     */
    public static function tunedLapse(int $base, array $recent): int
    {
        $last = array_slice($recent, 0, 10);
        if (count($last) < 10) return $base;
        $notFit = count(array_filter($last, fn($d) => $d['status'] === 'skipped' && $d['skip_reason'] === 'not_fit'));
        return $notFit >= 7 ? min(24, $base + 3) : $base;
    }
}
