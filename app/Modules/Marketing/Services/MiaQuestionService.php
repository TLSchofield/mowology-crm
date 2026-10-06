<?php
/**
 * MiaQuestionService — what Mia asks Tim when she can't tell from the data.
 *
 *   review_check — "Did Jane leave a Google review?" for customers the automatic review
 *                  request reached 14–120 days ago. "Yes" ticks contacts.has_reviewed (the
 *                  manual box on the client page), which stops further requests.
 *   pm_contact   — a property manager's work has fallen off but there is nobody on file to
 *                  write to.
 * Answers are kept (mia_questions) and the same question is never asked twice.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class MiaQuestionService
{
    public const ANSWERS = [
        'review_check' => ['yes', 'no'],
        'pm_contact'   => ['done', 'skip'],
    ];
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
        foreach ($pmNoContact as $pm) {
            $added += $this->ask('pm_contact', 'mia:company:' . (int)$pm['company_id'], sprintf(
                "Work at %s is down from %d visits this time last year to %d in the last three months, but I've got nobody there to write to. Can you add their property manager on the company page?",
                $pm['company_name'], (int)$pm['prior_visits'], (int)$pm['recent_visits']
            ));
        }
        return $added;
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
        $s = $this->db->prepare("SELECT id, kind, subject_key, question FROM mia_questions WHERE status = 'open' ORDER BY FIELD(kind, 'pm_contact', 'review_check'), id LIMIT " . max(1, $limit));
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
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['ok' => true];
    }
}
