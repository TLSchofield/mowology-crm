<?php
declare(strict_types=1);

require_once __DIR__ . '/TaskService.php';

/**
 * AgentDetectors — turn what the desks already find into durable tasks (Ittai audit 2026-10-27, stage 4).
 *
 * No new detection logic and no model: each detector asks an existing desk for its own list
 *   quote.stale      Sam   SalesDeskService::queue()            quotes nobody has chased / customers who replied
 *   reply.unclaimed  Yui   UnclaimedReplyService::items()       client replies nobody has answered
 *   invoice.overdue  Penny YuiDeskService::arrears()            clients owing >60 days
 * and maps each item to a task whose dedupe_key is the item's own key. Running it twice creates nothing new; an
 * item that drops off the desk's list closes its still-open task ("resolved"), so tasks track the desks exactly.
 *
 * tasksFromItems() is pure and unit tested. Global namespace; require_once it.
 */
final class AgentDetectors
{
    /** kind => [owner head, title prefix] */
    public const KINDS = [
        'quote.stale'     => ['sam',   'Follow up'],
        'reply.unclaimed' => ['yui',   'Reply to'],
        'invoice.overdue' => ['penny', 'Chase'],
    ];

    /**
     * @param array[] $items desk items; each needs 'key'. Optional: text, name, value, since, days, url, kind
     * @return array[] TaskService::create() arguments
     */
    public static function tasksFromItems(string $kind, array $items, string $now): array
    {
        [$owner, $verb] = self::KINDS[$kind];
        $out = [];
        foreach ($items as $it) {
            $key = trim((string)($it['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $label = trim((string)($it['name'] ?? '')) ?: trim((string)($it['text'] ?? '')) ?: $key;
            $days = (int)($it['days'] ?? 0);
            $value = (float)($it['value'] ?? $it['amount'] ?? $it['total'] ?? 0);
            $since = (string)($it['since'] ?? '');
            if ($days === 0 && $since !== '' && strtotime($since) !== false) {
                $days = max(0, (int)floor((strtotime($now) - strtotime($since)) / 86400));
            }
            $out[] = [
                'kind'        => $kind,
                'owner_head'  => $owner,
                'origin_head' => 'system',
                'entity_type' => (string)($it['kind'] ?? $kind),
                'entity_id'   => mb_substr($key, 0, 64),
                'title'       => mb_substr($verb . ' ' . $label, 0, 200),
                'priority'    => ($days >= 60 || $value >= 1000) ? 'high' : 'normal',
                'dedupe_key'  => mb_substr($kind . ':' . $key, 0, 120),
                'payload'     => array_filter(['days' => $days ?: null, 'value' => $value ?: null, 'url' => $it['url'] ?? null, 'text' => $it['text'] ?? null]),
            ];
        }
        return $out;
    }

    /**
     * Create tasks for $items and close open ones that left the list. Returns counts.
     * @return array{seen:int, created:int, closed:int}
     */
    public static function sync(TaskService $tasks, string $kind, array $items, string $now): array
    {
        $specs = self::tasksFromItems($kind, $items, $now);
        $created = 0;
        $live = [];
        foreach ($specs as $spec) {
            $live[] = $spec['dedupe_key'];
            $r = $tasks->create($spec);
            if (!empty($r['created'])) {
                $created++;
            }
        }
        return ['seen' => count($specs), 'created' => $created, 'closed' => $tasks->closeResolved($kind, $live)];
    }

    /**
     * Ask the real desks. Each runs on its own: a desk that fails is reported and skipped, and its tasks are NOT
     * closed (an unreadable list is not an empty list).
     * @return array<string, array|string> kind => counts, or an error message
     */
    public static function run(PDO $db, TaskService $tasks, string $now): array
    {
        $sources = [
            'quote.stale' => static function () use ($db) {
                require_once dirname(__DIR__, 2) . '/Sales/Services/SalesDeskService.php';
                return (new SalesDeskService($db))->queue();
            },
            'reply.unclaimed' => static function () use ($db) {
                require_once dirname(__DIR__, 2) . '/Sales/Services/UnclaimedReplyService.php';
                return (new UnclaimedReplyService($db))->items();
            },
            'invoice.overdue' => static function () use ($db) {
                require_once dirname(__DIR__, 2) . '/Comms/Services/YuiDeskService.php';
                return (new YuiDeskService($db))->arrears();
            },
        ];
        $out = [];
        foreach ($sources as $kind => $fn) {
            try {
                $items = $fn();
                if (!is_array($items)) {
                    throw new RuntimeException('desk returned no list');
                }
                $out[$kind] = self::sync($tasks, $kind, $items, $now);
            } catch (Throwable $e) {
                $out[$kind] = 'failed: ' . $e->getMessage();
                error_log("AgentDetectors {$kind}: " . $e->getMessage());
            }
        }
        return $out;
    }
}
