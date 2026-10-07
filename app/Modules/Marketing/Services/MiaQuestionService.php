<?php
/**
 * MiaQuestionService — what Mia asks Tim when she can't tell from the data.
 *
 *   review_check — "Did Jane leave a Google review?" for customers the automatic review
 *                  request reached 14–120 days ago. "Yes" ticks contacts.has_reviewed (the
 *                  manual box on the client page), which stops further requests.
 *   pm_contact   — a property manager's work has fallen off but there is nobody on file to
 *                  write to.
 *   spring_prebook — a customer wrote back "spring" after one of Mia's campaigns reached them
 *                  (Tim's campaign P.S.: spring work is pre-booked only). Read from Sam's office@
 *                  log (sales_messages — the customer's own words, quoted history stripped).
 *                  "Booked for spring" puts a "Spring pre-booking" line on the client's activity
 *                  log; nothing is sent to the customer.
 * Answers are kept (mia_questions) and the same question is never asked twice.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class MiaQuestionService
{
    public const ANSWERS = [
        'review_check' => ['yes', 'no'],
        'pm_contact'   => ['done', 'skip'],
        'spring_prebook' => ['booked', 'not_prebook'],
    ];
    /** Look back this far for "spring" replies to a campaign. */
    public const SPRING_LOOKBACK_DAYS = 120;
    public const MAX_REVIEW_OPEN = 3;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Ask what's worth asking. Returns how many new questions were added. */
    public function scan(DateTimeImmutable $today, array $pmNoContact = []): int
    {
        $added = 0;
        $open = (int)$this->db->query("SELECT COUNT(*) FROM mia_questions WHERE status = 'open' AND kind = 'review_check'")->fetchColumn();
        if ($open < self::MAX_REVIEW_OPEN) {
            try {
                $s = $this->db->prepare("
                    SELECT c.id, c.first_name, c.last_name, c.review_request_sent_at
                    FROM contacts c
                    WHERE c.review_request_sent_at BETWEEN ? AND ?
                      AND COALESCE(c.has_reviewed, 0) = 0 AND COALESCE(c.review_request_opted_out, 0) = 0
                      AND c.is_active = 1
                      AND NOT EXISTS (SELECT 1 FROM mia_questions q WHERE q.kind = 'review_check' AND q.subject_key = CONCAT('mia:contact:', c.id))
                    ORDER BY c.review_request_sent_at DESC
                    LIMIT " . (self::MAX_REVIEW_OPEN - $open)
                );
                $s->execute([$today->modify('-120 days')->format('Y-m-d'), $today->modify('-14 days')->format('Y-m-d 23:59:59')]);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    if (stripos($c['first_name'] . ' ' . $c['last_name'], 'ZZTEST') !== false) continue;
                    $added += $this->ask('review_check', 'mia:contact:' . (int)$c['id'], self::reviewWording($c));
                }
            } catch (Throwable $e) { /* review columns missing here — nothing to ask */ }
        }
        $added += $this->scanSpringReplies($today);
        foreach ($pmNoContact as $pm) {
            $added += $this->ask('pm_contact', 'mia:company:' . (int)$pm['company_id'], sprintf(
                "Work at %s is down from %d visits this time last year to %d in the last three months, but I've got nobody there to write to. Can you add their property manager on the company page?",
                $pm['company_name'], (int)$pm['prior_visits'], (int)$pm['recent_visits']
            ));
        }
        return $added;
    }

    /** Customers who answered a Mia campaign with "spring" — one question per reply. */
    private function scanSpringReplies(DateTimeImmutable $today): int
    {
        $added = 0;
        try {
            $s = $this->db->prepare("
                SELECT sm.id, sm.contact_id, sm.snippet, sm.sent_at, c.first_name, c.last_name, mc.name AS campaign
                FROM sales_messages sm
                JOIN campaign_sends cs ON cs.contact_id = sm.contact_id AND cs.status = 'sent' AND cs.sent_at IS NOT NULL AND sm.sent_at >= cs.sent_at
                JOIN mia_campaigns mc ON mc.marketing_campaign_id = cs.campaign_id AND mc.status = 'approved'
                JOIN contacts c ON c.id = sm.contact_id
                WHERE sm.direction = 'inbound' AND sm.sent_at >= ?
                ORDER BY sm.sent_at, sm.id
                LIMIT 200
            ");
            $s->execute([$today->modify('-' . self::SPRING_LOOKBACK_DAYS . ' days')->format('Y-m-d')]);
            $seen = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (isset($seen[$r['id']]) || !self::saysSpring((string)$r['snippet'])) continue;
                $seen[$r['id']] = true;
                $added += $this->ask('spring_prebook', 'mia:spring:' . (int)$r['id'] . ':' . (int)$r['contact_id'], self::springWording($r));
            }
        } catch (Throwable $e) { /* no office@ log or no campaign yet — nothing to ask */ }
        return $added;
    }

    /** The customer's own words mention spring (the campaign's P.S. asked them to reply "spring"). */
    public static function saysSpring(string $text): bool
    {
        return (bool)preg_match('/\bspring\b/i', $text);
    }

    public static function springWording(array $r): string
    {
        $name = trim($r['first_name'] . ' ' . $r['last_name']) ?: 'A customer';
        $said = trim(preg_replace('/\s+/', ' ', (string)$r['snippet']));
        if (mb_strlen($said) > 140) $said = rtrim(mb_substr($said, 0, 139)) . '…';
        return sprintf('%s replied to your "%s" email on %s: "%s". Hold them a spring spot?',
            $name, $r['campaign'], date('M j', strtotime((string)$r['sent_at'])), $said);
    }

    public static function reviewWording(array $c): string
    {
        $name = trim($c['first_name'] . ' ' . $c['last_name']);
        $when = date('M j', strtotime((string)$c['review_request_sent_at']));
        return "Did {$name} leave a Google review? The automatic request went out on {$when}.";
    }

    private function ask(string $kind, string $key, string $question): int
    {
        $s = $this->db->prepare("INSERT IGNORE INTO mia_questions (kind, subject_key, question) VALUES (?, ?, ?)");
        $s->execute([$kind, $key, $question]);
        return $s->rowCount() > 0 ? 1 : 0;
    }

    public function open(int $limit = 5): array
    {
        $s = $this->db->prepare("SELECT id, kind, subject_key, question FROM mia_questions WHERE status = 'open' ORDER BY FIELD(kind, 'spring_prebook', 'pm_contact', 'review_check'), id LIMIT " . max(1, $limit));
        $s->execute();
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $q['answers'] = self::ANSWERS[$q['kind']] ?? ['done'];
            $q['url'] = self::link($q['subject_key']);
            $out[] = $q;
        }
        return $out;
    }

    public static function link(string $key): ?string
    {
        if (preg_match('/^mia:contact:(\d+)$/', $key, $m)) return '/crm/clients_appstack.php?action=view_contact&id=' . $m[1];
        if (preg_match('/^mia:company:(\d+)$/', $key, $m)) return '/crm/companies/view.php?id=' . $m[1];
        if (preg_match('/^mia:spring:\d+:(\d+)$/', $key, $m)) return '/crm/clients_appstack.php?action=view_contact&id=' . $m[1];
        return null;
    }

    public function answer(int $id, string $answer, int $userId): array
    {
        $s = $this->db->prepare("SELECT * FROM mia_questions WHERE id = ? AND status = 'open'");
        $s->execute([$id]);
        $q = $s->fetch(PDO::FETCH_ASSOC);
        if (!$q) return ['ok' => false, 'error' => 'That question was already answered.'];
        if (!in_array($answer, self::ANSWERS[$q['kind']] ?? [], true)) return ['ok' => false, 'error' => 'Unknown answer.'];

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE mia_questions SET status = 'answered', answer = ?, answered_by = ?, answered_at = NOW() WHERE id = ?")
                ->execute([$answer, $userId, $id]);
            if ($q['kind'] === 'review_check' && $answer === 'yes' && preg_match('/^mia:contact:(\d+)$/', $q['subject_key'], $m)) {
                $this->db->prepare("UPDATE contacts SET has_reviewed = 1 WHERE id = ?")->execute([(int)$m[1]]);
            }
            if ($q['kind'] === 'spring_prebook' && $answer === 'booked' && preg_match('/^mia:spring:\d+:(\d+)$/', $q['subject_key'], $m)) {
                $this->db->prepare("INSERT INTO activity_log (user_id, contact_id, action, details) VALUES (?, ?, 'Spring pre-booking', ?)")
                    ->execute([$userId ?: null, (int)$m[1], 'Held a spring spot after they replied "spring" to a Mia campaign (confirmed by Tim on Mia\'s card)']);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['ok' => true];
    }
}
