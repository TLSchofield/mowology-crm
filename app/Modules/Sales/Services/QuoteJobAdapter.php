<?php
declare(strict_types=1);

/**
 * Sam's quote job for the job strip (Tim, 2026-11-10: "i cant decline this quote in the flow"). The decision he carries:
 * "this quote has gone quiet: follow up, or decline it" — one quote at a time, on the Quotes pages.
 *
 * A new face on existing logic only. Which quotes are waiting comes from SalesDeskService::openQuotes() (sent or opened,
 * in date, not a test) and touches() (a client who wrote back, or one Sam's desk snoozed, is not quiet). Nothing here
 * writes anything: Decline posts what the quote page's Decline posts, through /crm/quotes/api-quotes.php, which calls
 * QuoteService::decline() (the one write path; sends nothing). Follow up opens the quote page, whose own Send Follow-Up
 * button still asks before it emails: Sam proposes, the owner's click acts (Sam skill, rule 1).
 *
 * Contracts (a running contract is never nudged or declined from here) and quotes followed up in the last quiet window
 * are left out. Off unless ops_settings it_quote_job = '1'. Any failure means no job.
 * No namespace / no autoloader in production: require_once and `new`.
 */
class QuoteJobAdapter
{
    public const SETTING = 'it_quote_job';
    /** Days since it was sent (and since the last follow-up) before a quote counts as gone quiet. */
    public const QUIET_DAYS = 7;
    public const MAX_JOBS = 5;
    /** Declines Sam's record needs before his confidence reads mastered (the knowledge triangle: gold after five). */
    public const MASTERED_AT = 5;
    public const API = '/crm/quotes/api-quotes.php';
    public const DECLINE_ACTION = 'Quote declined (flow line)';

    public static function enabled(PDO $db): bool
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([self::SETTING]);
            return (string)$s->fetchColumn() === '1';
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return list<array> quiet quotes as jobs; the quote being looked at first. [] when off or on any failure. */
    public static function jobs(PDO $db, ?int $firstId = null, ?DateTimeImmutable $now = null): array
    {
        try {
            if (!self::enabled($db)) return [];
            $now = $now ?? new DateTimeImmutable('now');
            require_once __DIR__ . '/SalesDeskService.php';
            $desk = new SalesDeskService($db);
            $rows = $desk->openQuotes();
            if (!$rows) return [];
            $touches = $desk->touches(array_map(fn($r) => (int)$r['contact_id'], $rows));
            $contracts = self::contractIds($db, array_map(fn($r) => (int)$r['id'], $rows));
            return self::queue($rows, $touches, $contracts, self::record($db), $now, $firstId);
        } catch (Throwable $e) {
            error_log('QuoteJobAdapter::jobs: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Pure shaping, so it can be tested without a database.
     * @param array $rows       SalesDeskService::openQuotes() rows
     * @param array $touches    SalesDeskService::touches() by contact id
     * @param int[] $contractIds quotes that are contracts
     * @param array $record     [declined from the strip, still declined]
     */
    public static function queue(array $rows, array $touches, array $contractIds, array $record, DateTimeImmutable $now, ?int $firstId = null): array
    {
        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            if (in_array($id, $contractIds, true)) continue;
            $sent = !empty($r['sent_at']) ? new DateTimeImmutable((string)$r['sent_at']) : null;
            if (!$sent) continue;
            $quietSince = $sent;
            if (!empty($r['follow_up_sent_at'])) {
                $fu = new DateTimeImmutable((string)$r['follow_up_sent_at']);
                if ($fu > $quietSince) $quietSince = $fu;
            }
            $days = (int)$quietSince->diff($now)->format('%a');
            if ($quietSince > $now || $days < self::QUIET_DAYS) continue;
            $t = (array)($touches[(int)($r['contact_id'] ?? 0)] ?? []);
            if (!empty($t['last_in']) && new DateTimeImmutable((string)$t['last_in']) > $sent) continue;   // they wrote back
            if (!empty($t['snooze_until']) && new DateTimeImmutable((string)$t['snooze_until']) > $now) continue;
            $out[] = self::job($r, $days, $record, $now);
        }
        usort($out, fn($a, $b) => ($b['days'] <=> $a['days']));
        if ($firstId) {
            usort($out, fn($a, $b) => (($b['quote_id'] === $firstId) <=> ($a['quote_id'] === $firstId)));
        }
        return array_slice($out, 0, self::MAX_JOBS);
    }

    private static function job(array $r, int $days, array $record, DateTimeImmutable $now): array
    {
        $id = (int)$r['id'];
        $who = trim((string)(($r['company_name'] ?? '') !== '' ? $r['company_name'] : trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))));
        $amount = (float)($r['amount'] ?? 0);
        $seen = [];
        if (!empty($r['email_opened_at'])) $seen[] = 'opened the email';
        if ((int)($r['view_count'] ?? 0) > 0) $seen[] = 'viewed it ' . (int)$r['view_count'] . '×';
        $followed = (int)($r['follow_up_count'] ?? 0);
        $why = ($seen ? 'They ' . implode(' and ', $seen) . ' and have not answered.' : 'Not opened yet.')
            . ($followed > 0 ? ' Followed up ' . $followed . ($followed === 1 ? ' time.' : ' times.') : '');
        return [
            'head' => 'sam', 'kind' => 'quiet', 'key' => 'quiet:' . $id, 'quote_id' => $id, 'days' => $days,
            'title' => 'Follow up on ' . $r['quote_number'] . ', or decline it?',
            'why' => $why,
            'sure' => self::sure($record), 'confidence' => self::confidenceLine($record),
            'post' => [
                'platforms' => array_values(array_filter([$who, (string)($r['address'] ?? '')])),
                'when' => 'Sent ' . $days . ' day' . ($days === 1 ? '' : 's') . ' ago' . ($amount > 0 ? ' · $' . number_format($amount, 2) : ''),
                'caption' => (string)($r['title'] ?? ''),
            ],
            'detail' => '', 'fields' => ['lost_reason'],
            'right' => ['lost_reason' => ''],
            'primary' => ['label' => 'Follow up', 'sub' => 'opens the quote', 'href' => '/crm/quotes/view.php?id=' . $id],
            'secondary' => ['label' => 'Decline', 'sub' => 'sends nothing', 'withFields' => true,
                'request' => ['url' => self::API . '?action=decline', 'body' => ['id' => $id]]],
            'later' => true,
        ];
    }

    /** The four-state triangle from the record: none = forming, one = held, 2-4 still declined = known, 5+ = mastered; any undone = held. */
    public static function sure(array $record): string
    {
        $called = (int)($record[0] ?? 0);
        $kept = (int)($record[1] ?? 0);
        if ($called <= 0) return 'forming';
        if ($kept < $called) return 'held';
        return $called >= self::MASTERED_AT ? 'mastered' : ($called >= 2 ? 'known' : 'held');
    }

    private static function confidenceLine(array $record): string
    {
        $called = (int)($record[0] ?? 0);
        return $called > 0 ? $called . ' declined from here, ' . (int)($record[1] ?? 0) . ' still declined.' : '';
    }

    /** [declined from the flow line, of those still declined]. [0, 0] on any failure. */
    public static function record(PDO $db): array
    {
        try {
            $s = $db->prepare("SELECT COUNT(*) AS called, COALESCE(SUM(q.status = 'declined'), 0) AS kept
                FROM activity_log a JOIN quotes q ON q.id = a.quote_id WHERE a.action = ?");
            $s->execute([self::DECLINE_ACTION]);
            $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
            return [(int)($r['called'] ?? 0), (int)($r['kept'] ?? 0)];
        } catch (Throwable $e) {
            return [0, 0];
        }
    }

    /** @return int[] */
    private static function contractIds(PDO $db, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        try {
            return array_map('intval', $db->query("SELECT id FROM quotes WHERE is_contract = 1 AND id IN (" . implode(',', $ids) . ")")->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }
}
