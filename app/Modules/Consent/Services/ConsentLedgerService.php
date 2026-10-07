<?php
/**
 * ConsentLedgerService — who may receive marketing, on what basis, with proof (migration 1161).
 *
 * One row per piece of evidence: express consent (the quote-form checkbox in consent_log, a
 * confirmed double opt-in, an express date recorded on the contact), implied consent (a
 * completed job or a paid invoice: 2 years from that date, CASL's existing business
 * relationship) and withdrawals. Unsubscribes (marketing_unsubscribes) always win.
 *
 *   refresh()  idempotent backfill from the CRM's own history (INSERT IGNORE on the proof
 *              key) — safe to run often; Mia runs it on every prepare. Also lifts
 *              contacts.consent_email_implied_at / consent_email_express_at to match the
 *              proof, so the older canSendMarketing() check (campaign sender) agrees.
 *   allows()   THE gate: Mia's send path calls it immediately before every marketing send.
 *   bulk()     the same answer for many contacts at once (finding who to suggest).
 *   expiringSoon()  implied consent running out within N months, with no express consent —
 *              Mia asks those customers for express consent while goodwill is high.
 *
 * SMS never rides on implied consent (house rule): texts need express consent.
 * No namespace / no autoloader in production: require_once and `new`.
 */
class ConsentLedgerService
{
    public const IMPLIED_YEARS = 2;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'consent_ledger'")->fetchColumn() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Backfill
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, int|string> rows added per source (or the error, per source) */
    public function refresh(): array
    {
        $ins = "INSERT IGNORE INTO consent_ledger (contact_id, channel, consent_type, source, proof_key, proof_text, granted_at, expires_at) ";
        $done = "COALESCE(jv.completed_at, jv.scheduled_date)";
        $steps = [
            // Implied: the latest completed job per customer in the last 2 years.
            'completed_visit' => $ins . "
                SELECT x.cid, 'email', 'implied', 'completed_visit', CONCAT('visit:', x.vid),
                       CONCAT('Completed job (visit ', x.vid, ') on ', DATE(x.done), ' — existing business relationship'),
                       x.done, DATE_ADD(x.done, INTERVAL " . self::IMPLIED_YEARS . " YEAR)
                FROM (
                    SELECT p.site_contact_id AS cid, MAX($done) AS done,
                           SUBSTRING_INDEX(GROUP_CONCAT(jv.id ORDER BY $done DESC, jv.id DESC), ',', 1) AS vid
                    FROM job_visits jv
                    JOIN job_plans jp ON jp.id = jv.plan_id
                    JOIN properties p ON p.id = jp.property_id
                    WHERE jv.status = 'completed' AND p.site_contact_id IS NOT NULL
                      AND $done >= DATE_SUB(NOW(), INTERVAL " . self::IMPLIED_YEARS . " YEAR)
                    GROUP BY p.site_contact_id
                ) x",
            // Implied: the latest paid invoice per customer in the last 2 years.
            'paid_invoice' => $ins . "
                SELECT x.cid, 'email', 'implied', 'paid_invoice', CONCAT('invoice:', x.iid),
                       CONCAT('Paid invoice ', x.iid, ' on ', DATE(x.paid), ' — existing business relationship'),
                       x.paid, DATE_ADD(x.paid, INTERVAL " . self::IMPLIED_YEARS . " YEAR)
                FROM (
                    SELECT i.contact_id AS cid, MAX(COALESCE(i.paid_at, i.issue_date)) AS paid,
                           SUBSTRING_INDEX(GROUP_CONCAT(i.id ORDER BY COALESCE(i.paid_at, i.issue_date) DESC, i.id DESC), ',', 1) AS iid
                    FROM invoices i
                    WHERE i.status = 'paid' AND i.contact_id IS NOT NULL
                      AND COALESCE(i.paid_at, i.issue_date) >= DATE_SUB(NOW(), INTERVAL " . self::IMPLIED_YEARS . " YEAR)
                    GROUP BY i.contact_id
                ) x",
            // Express and withdrawn: the quote form's checkboxes, unsubscribe clicks, admin changes.
            'consent_log' => $ins . "
                SELECT cl.contact_id,
                       CASE WHEN cl.consent_type = 'sms' THEN 'sms' ELSE 'email' END,
                       CASE WHEN cl.consent_given = 1 THEN 'express' ELSE 'withdrawn' END,
                       COALESCE(cl.consent_source, 'unknown'), CONCAT('consent_log:', cl.id),
                       CONCAT(COALESCE(cl.consent_text, ''), ' — ', COALESCE(cl.consent_source, 'unknown'),
                              ', IP ', COALESCE(cl.ip_address, 'unknown'), ', ', cl.created_at),
                       cl.created_at, NULL
                FROM consent_log cl
                WHERE cl.consent_type IN ('marketing_email', 'sms')",
            // Express: a confirmed double opt-in (time and IP recorded by the opt-in flow).
            'optin_email' => $ins . "
                SELECT t.contact_id, 'email', 'express', 'optin_email', CONCAT('optin:', t.id),
                       CONCAT('Double opt-in confirmed by email link on ', t.confirmed_at, ', IP ', COALESCE(t.ip_address, 'unknown')),
                       t.confirmed_at, NULL
                FROM marketing_optin_tokens t
                WHERE t.status = 'confirmed' AND t.confirmed_at IS NOT NULL",
            // Express: dates already recorded on the contact (older records; proof is the CRM field).
            'contact_email' => $ins . "
                SELECT c.id, 'email', 'express', 'contact_record', CONCAT('contact:', c.id),
                       'Express consent date recorded on the contact (contacts.consent_email_express_at)',
                       c.consent_email_express_at, NULL
                FROM contacts c
                WHERE c.receive_marketing = 1 AND c.consent_email_express_at IS NOT NULL",
            'contact_sms' => $ins . "
                SELECT c.id, 'sms', 'express', 'contact_record', CONCAT('contact:', c.id),
                       'Express SMS consent date recorded on the contact (contacts.consent_sms_express_at)',
                       c.consent_sms_express_at, NULL
                FROM contacts c
                WHERE c.receive_sms = 1 AND c.consent_sms_express_at IS NOT NULL",
            // Keep the older contact fields in step with the proof (only ever later, never earlier),
            // so canSendMarketing() — used by the campaign sender — honours the same record.
            'sync_implied' => "
                UPDATE contacts c
                JOIN (SELECT contact_id, MAX(granted_at) AS g FROM consent_ledger
                      WHERE channel = 'email' AND consent_type = 'implied' GROUP BY contact_id) l ON l.contact_id = c.id
                SET c.consent_email_implied_at = l.g
                WHERE (c.consent_email_implied_at IS NULL OR c.consent_email_implied_at < l.g)
                  AND NOT EXISTS (SELECT 1 FROM consent_ledger w WHERE w.contact_id = c.id AND w.channel = 'email' AND w.consent_type = 'withdrawn')",
            'sync_express' => "
                UPDATE contacts c
                JOIN (SELECT contact_id, MIN(granted_at) AS g FROM consent_ledger
                      WHERE channel = 'email' AND consent_type = 'express' AND source IN ('optin_email', 'website_form') GROUP BY contact_id) l ON l.contact_id = c.id
                SET c.consent_email_express_at = l.g
                WHERE c.receive_marketing = 1 AND c.consent_email_express_at IS NULL
                  AND NOT EXISTS (SELECT 1 FROM consent_ledger w WHERE w.contact_id = c.id AND w.channel = 'email' AND w.consent_type = 'withdrawn' AND w.granted_at > l.g)",
        ];
        $out = [];
        foreach ($steps as $name => $sql) {
            try {
                $out[$name] = (int)$this->db->exec($sql);
            } catch (Throwable $e) {
                $out[$name] = 'skipped: ' . $e->getMessage(); // a source table missing here
            }
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The gate
    // ─────────────────────────────────────────────────────────────────────────

    /** May this contact receive a marketing message on this channel right now? */
    public function allows(int $contactId, string $channel = 'email', ?DateTimeImmutable $now = null): array
    {
        return $this->bulk([$contactId], $channel, $now)[$contactId] ?? self::evaluate([], false, $channel, $now ?? new DateTimeImmutable());
    }

    /** @return array<int, array> contact id → evaluate() result */
    public function bulk(array $contactIds, string $channel = 'email', ?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable();
        $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds))));
        if (!$ids) return [];
        $rows = [];
        $unsub = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $s = $this->db->prepare("SELECT contact_id, consent_type, source, proof_key, proof_text, granted_at, expires_at
                                     FROM consent_ledger WHERE channel = ? AND contact_id IN ($in)");
            $s->execute(array_merge([$channel], $chunk));
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(int)$r['contact_id']][] = $r;
            // Unsubscribes by email, matched in PHP (no cross-table collation to trip over).
            $e = $this->db->prepare("SELECT id, LOWER(TRIM(email)) AS email FROM contacts WHERE id IN ($in) AND email IS NOT NULL AND email <> ''");
            $e->execute($chunk);
            $byEmail = [];
            foreach ($e->fetchAll(PDO::FETCH_ASSOC) as $r) $byEmail[$r['email']][] = (int)$r['id'];
            if ($byEmail) {
                $ein = implode(',', array_fill(0, count($byEmail), '?'));
                try {
                    $u = $this->db->prepare("SELECT LOWER(TRIM(email)) FROM marketing_unsubscribes WHERE email IN ($ein)");
                    $u->execute(array_keys($byEmail));
                    foreach ($u->fetchAll(PDO::FETCH_COLUMN) as $em) foreach ($byEmail[$em] ?? [] as $id) $unsub[$id] = true;
                } catch (Throwable $ex) { /* no unsubscribe table here → nobody unsubscribed */ }
            }
        }
        $out = [];
        foreach ($ids as $id) $out[$id] = self::evaluate($rows[$id] ?? [], isset($unsub[$id]), $channel, $now);
        return $out;
    }

    /**
     * Customers whose only consent is implied and runs out within $months — ask them for
     * express consent now. @return array<int, array> contact id → evaluate() result
     */
    public function expiringSoon(DateTimeImmutable $now, int $months = 6): array
    {
        $s = $this->db->prepare("SELECT DISTINCT contact_id FROM consent_ledger WHERE channel = 'email' AND consent_type = 'implied' AND expires_at BETWEEN ? AND ?");
        $s->execute([$now->format('Y-m-d H:i:s'), $now->modify("+{$months} months")->format('Y-m-d H:i:s')]);
        $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        $limit = $now->modify("+{$months} months");
        return array_filter($this->bulk($ids, 'email', $now), fn($st) => $st['ok'] && $st['type'] === 'implied'
            && $st['expires_at'] && new DateTimeImmutable($st['expires_at']) <= $limit);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The decision for one contact and channel, from their ledger rows.
     * Order: unsubscribed → no; withdrawn after every grant → no; express → yes;
     * implied not yet expired (email only) → yes; otherwise no.
     * @return array{ok: bool, type: ?string, reason: string, source: ?string, proof: ?string, granted_at: ?string, expires_at: ?string}
     */
    public static function evaluate(array $rows, bool $unsubscribed, string $channel, DateTimeImmutable $now): array
    {
        $no = fn(string $why) => ['ok' => false, 'type' => null, 'reason' => $why, 'source' => null, 'proof' => null, 'granted_at' => null, 'expires_at' => null];
        if ($unsubscribed) return $no('unsubscribed');
        $withdrawn = null;
        foreach ($rows as $r) {
            if ($r['consent_type'] === 'withdrawn' && ($withdrawn === null || $r['granted_at'] > $withdrawn)) $withdrawn = $r['granted_at'];
        }
        $live = array_filter($rows, fn($r) => $r['consent_type'] !== 'withdrawn' && ($withdrawn === null || $r['granted_at'] > $withdrawn));
        $pick = function (array $rs) {
            usort($rs, fn($a, $b) => strcmp((string)$b['granted_at'], (string)$a['granted_at']));
            return $rs[0];
        };
        $express = array_filter($live, fn($r) => $r['consent_type'] === 'express');
        if ($express) {
            $r = $pick($express);
            return ['ok' => true, 'type' => 'express', 'reason' => 'express consent', 'source' => $r['source'],
                    'proof' => $r['proof_text'] ?? $r['proof_key'], 'granted_at' => $r['granted_at'], 'expires_at' => null];
        }
        if ($channel === 'email') {
            $implied = array_filter($live, fn($r) => $r['consent_type'] === 'implied' && !empty($r['expires_at'])
                && new DateTimeImmutable($r['expires_at']) > $now);
            if ($implied) {
                usort($implied, fn($a, $b) => strcmp((string)$b['expires_at'], (string)$a['expires_at']));
                $r = $implied[0];
                return ['ok' => true, 'type' => 'implied', 'reason' => 'implied consent until ' . substr($r['expires_at'], 0, 10),
                        'source' => $r['source'], 'proof' => $r['proof_text'] ?? $r['proof_key'], 'granted_at' => $r['granted_at'], 'expires_at' => $r['expires_at']];
            }
        }
        if ($withdrawn !== null) return $no('withdrew consent');
        $hadImplied = (bool)array_filter($rows, fn($r) => $r['consent_type'] === 'implied');
        return $no($hadImplied && $channel === 'email' ? 'implied consent expired' : 'no consent on record');
    }
}
