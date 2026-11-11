<?php
/**
 * MiaQueueBrief — what Mia's Search & Website work contributes to Charlie's morning brief (Phase 1.14).
 *
 * Rule: say something only when there is something worth the owner's time, never to look busy.
 *   - suggestions waiting: at most TWO lines a day (the best one, and "N more" if there are others), and only for
 *     suggestions scoring at least MIN_SCORE; weak ideas wait on the Growth tab without a nudge;
 *   - a website Mia can no longer look at (proof expired or removed), or about to expire within WARN_DAYS.
 * Items follow Charlie's contract: key, text, url, priority (1 most urgent), kind, value. Keys carry the situation
 * (the suggestion's id and how many are waiting; the website's id and the date of the proof), never the day, so the same
 * situation is the same item tomorrow and a changed one is new.
 *
 * Read-only: no writes, no model calls, no network. Tenant: everything is scoped to $siteId.
 * Global namespace, no autoloader: require_once it. PHP 7.4-compatible.
 */
declare(strict_types=1);

final class MiaQueueBrief
{
    public const MIN_SCORE = 25.0;
    public const WARN_DAYS = 10;
    /** Mirrors MiaWebsiteService::REVERIFY_DAYS (kept as a literal so this file has no dependencies). */
    public const REVERIFY_DAYS = 90;
    public const URL_GROWTH = '/crm/marketing/mia.php#growth';
    public const URL_PRESENCE = '/crm/marketing/mia.php#presence';

    /**
     * @return array<int,array{key:string,text:string,url:string,priority:int,kind:string,value:int}>
     */
    public static function items(PDO $db, int $siteId = 1, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $out = [];
        foreach (self::websiteItems($db, $siteId, $now) as $i) { $out[] = $i; }
        foreach (self::suggestionItems($db, $siteId, $now) as $i) { $out[] = $i; }
        return $out;
    }

    private static function suggestionItems(PDO $db, int $siteId, DateTimeImmutable $now): array
    {
        try {
            $n = $db->prepare("SELECT COUNT(*) FROM mia_actions WHERE site_id = ? AND status = 'proposed' AND score IS NOT NULL AND score >= ?");
            $n->execute([$siteId, self::MIN_SCORE]);
            $count = (int)$n->fetchColumn();
            if ($count === 0) { return []; }
            $s = $db->prepare("SELECT id, title FROM mia_actions WHERE site_id = ? AND status = 'proposed' AND score IS NOT NULL AND score >= ?
                                ORDER BY score DESC, id DESC LIMIT 1");
            $s->execute([$siteId, self::MIN_SCORE]);
            $top = $s->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];                                              // tables from migration 1400 not there yet: stay quiet
        }
        if (!$top) { return []; }
        $items = [[
            'key' => 'mia:actions:top:' . (int)$top['id'],
            'text' => 'Search & Website: ' . self::clip((string)$top['title'], 110) . ($count === 1 ? ' (waiting for your OK)' : ' (best of ' . $count . ' waiting for your OK)'),
            'url' => self::URL_GROWTH, 'priority' => 3, 'kind' => 'actions', 'value' => $count,
        ]];
        if ($count > 1) {
            // The key carries the situation (how many), not the date, so an unchanged queue is the same item tomorrow.
            $items[] = [
                'key' => 'mia:actions:more:' . ($count - 1),
                'text' => ($count - 1) . ' more website suggestion' . ($count === 2 ? '' : 's') . ' waiting on the Growth tab.',
                'url' => self::URL_GROWTH, 'priority' => 4, 'kind' => 'actions', 'value' => $count - 1,
            ];
        }
        return $items;
    }

    private static function websiteItems(PDO $db, int $siteId, DateTimeImmutable $now): array
    {
        try {
            $s = $db->prepare("SELECT id, host, status, verified_at FROM mia_websites WHERE site_id = ? AND status IN ('verified','pending') ORDER BY id");
            $s->execute([$siteId]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        $items = [];
        foreach ($rows as $w) {
            if ($w['status'] !== 'verified' || empty($w['verified_at'])) { continue; }          // a never-verified website is not news
            try {
                $verified = new DateTimeImmutable((string)$w['verified_at']);
                $expiry = $verified->modify('+' . self::REVERIFY_DAYS . ' days');                // calendar days, like MiaWebsiteService::isVerified
            } catch (Throwable $e) { continue; }
            $host = self::clip((string)$w['host'], 80);
            // The key carries the proof it is about (its date): after a renewal the next expiry is a NEW item.
            $tag = (int)$w['id'] . ':' . $verified->format('Ymd');
            if ($expiry <= $now) {
                $items[] = ['key' => 'mia:web:expired:' . $tag, 'text' => 'Mia can no longer look at ' . $host . ': the ownership check ran out. Press Check now to renew it.',
                            'url' => self::URL_PRESENCE, 'priority' => 2, 'kind' => 'website', 'value' => 0];
                continue;
            }
            $left = (int)$now->setTime(0, 0)->diff($expiry->setTime(0, 0))->days;                // whole calendar days, DST-proof
            if ($left <= self::WARN_DAYS) {
                $items[] = ['key' => 'mia:web:expiring:' . $tag, 'text' => 'The ownership check for ' . $host . ($left === 0 ? ' runs out today.' : ' runs out in ' . $left . ' day' . ($left === 1 ? '' : 's') . '.') . ' Press Check now to renew it.',
                            'url' => self::URL_PRESENCE, 'priority' => 3, 'kind' => 'website', 'value' => $left];
            }
        }
        return $items;
    }

    private static function clip(string $s, int $max): string
    {
        $s = trim((string)preg_replace('/\s+/u', ' ', str_replace(['<', '>'], '', $s)));            // plain text: no angle brackets reach a screen
        return mb_strlen($s, 'UTF-8') > $max ? rtrim(mb_substr($s, 0, $max - 1, 'UTF-8')) . '…' : $s;
    }
}
