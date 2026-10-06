<?php
/**
 * SamQuestionService — what Sam asks Tim when he can't tell on his own.
 *
 * First kind: a quote that ran out (valid-until passed, last 120 days) with no acceptance.
 * Sam can't know whether it was lost, is still alive, or was agreed by phone, so he asks:
 *   lost  → quote marked expired (counts as lost in the win rate, no more follow-ups)
 *   keep  → valid-until moved 30 days on, so it comes back to the follow-up queue
 *   won   → opens the quote so Tim can record the yes (verbal approval on the quote page)
 * Asked once per quote. Every answer is a lesson in Sam's brain ("quotes cleared up").
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/SalesDeskService.php';

class SamQuestionService
{
    public const ANSWERS = ['lost', 'keep', 'won'];
    public const LOOKBACK_DAYS = 120;
    public const KEEP_DAYS = 30;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'sam_questions'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Ask about quotes that ran out. Cheap SQL — safe on card load. */
    public function scan(): int
    {
        if (!$this->ready()) return 0;
        $amt = SalesDeskService::AMOUNT_SQL;
        $notTest = SalesDeskService::NOT_TEST_SQL;
        $s = $this->db->prepare("
            INSERT IGNORE INTO sam_questions (kind, dedupe_key, quote_id, contact_id, amount)
            SELECT 'expired_quote', CONCAT('expired_quote:', q.id), q.id, q.contact_id, {$amt}
            FROM quotes q
            LEFT JOIN contacts c ON c.id = q.contact_id
            WHERE q.status IN ('sent', 'viewed') AND q.valid_until IS NOT NULL
              AND q.valid_until < CURDATE() AND q.valid_until >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              AND {$notTest}
        ");
        $s->execute([self::LOOKBACK_DAYS]);
        return $s->rowCount();
    }

    public function open(string $name = '', int $limit = 5): array
    {
        if (!$this->ready()) return [];
        $amt = SalesDeskService::AMOUNT_SQL;
        $rows = $this->db->query("
            SELECT sq.id, sq.quote_id, q.quote_number, q.valid_until, {$amt} AS amount, q.status,
                   c.first_name, c.last_name, co.company_name AS company_name, p.address
            FROM sam_questions sq
            JOIN quotes q ON q.id = sq.quote_id
            LEFT JOIN contacts c ON c.id = q.contact_id
            LEFT JOIN companies co ON co.id = q.company_id
            LEFT JOIN properties p ON p.id = q.property_id
            WHERE sq.status = 'open' AND q.status IN ('sent', 'viewed')
            ORDER BY amount DESC, sq.id
            LIMIT " . max(1, min(20, $limit))
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => [
            'id'       => (int)$r['id'],
            'quote_id' => (int)$r['quote_id'],
            'question' => self::wording($r, $name),
        ], $rows);
    }

    /** @return array{ok: bool, message: string, url?: string} */
    public function answer(int $id, string $answer, int $userId, string $name = ''): array
    {
        if (!in_array($answer, self::ANSWERS, true)) return ['ok' => false, 'message' => 'Unknown answer'];
        $s = $this->db->prepare("SELECT quote_id, status FROM sam_questions WHERE id = ?");
        $s->execute([$id]);
        $q = $s->fetch(PDO::FETCH_ASSOC);
        if (!$q || $q['status'] !== 'open') return ['ok' => false, 'message' => 'Already answered'];
        $quoteId = (int)$q['quote_id'];

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE sam_questions SET status = 'answered', answer = ?, answered_by = ?, answered_at = NOW() WHERE id = ?")
                ->execute([$answer, $userId, $id]);
            if ($answer === 'lost') {
                $this->db->prepare("UPDATE quotes SET status = 'expired' WHERE id = ? AND status IN ('sent', 'viewed')")->execute([$quoteId]);
            } elseif ($answer === 'keep') {
                $this->db->prepare("UPDATE quotes SET valid_until = DATE_ADD(CURDATE(), INTERVAL " . self::KEEP_DAYS . " DAY) WHERE id = ? AND status IN ('sent', 'viewed')")
                    ->execute([$quoteId]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        if (function_exists('logActivityExtended')) {
            $what = ['lost' => 'marked lost (expired) from Sam\'s question', 'keep' => 'kept open another ' . self::KEEP_DAYS . ' days from Sam\'s question',
                     'won' => 'Tim said it was won another way (Sam\'s question)'][$answer];
            logActivityExtended($userId, 'Quote ' . $answer, 'Quote ' . $what, null, null, $quoteId);
        }
        $hi = $name !== '' ? ", {$name}" : '';
        $out = ['ok' => true, 'message' => [
            'lost' => "Got it{$hi} — marked lost. I won't chase it again.",
            'keep' => "Got it{$hi} — kept open " . self::KEEP_DAYS . " more days. It's back on my follow-up list.",
            'won'  => "Nice{$hi}. I'll open the quote so you can record the yes.",
        ][$answer]];
        if ($answer === 'won') $out['url'] = '/crm/quotes/view.php?id=' . $quoteId;
        return $out;
    }

    public function answeredCount(): int
    {
        if (!$this->ready()) return 0;
        return (int)$this->db->query("SELECT COUNT(*) FROM sam_questions WHERE status = 'answered'")->fetchColumn();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    public static function wording(array $r, string $name = ''): string
    {
        $who = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? '')) ?: ((string)($r['company_name'] ?? '') ?: 'a customer');
        $where = trim((string)($r['address'] ?? '')) !== '' ? ' at ' . $r['address'] : '';
        return sprintf('%s %s for %s%s (%s) ran out on %s and I never saw a yes. Lost, still alive, or did they say yes another way?',
            $name !== '' ? "Hey {$name} —" : 'Hey —',
            (string)$r['quote_number'], $who, $where,
            SalesDeskService::money((float)$r['amount']),
            date('M j', strtotime((string)$r['valid_until'])));
    }
}
