<?php
/**
 * ConflictRules — Tim's rules for when two heads' proposals collide (pure, unit tested).
 *
 * Live now (phase B1):
 *   collections_first — no sales message to a client while one of their invoices is more
 *                       than late_days (14) late. Collections first. Never escalates.
 *   one_message_week  — one message per client per window_days (7): if they were messaged
 *                       inside the window, or a higher-ranked message to them is already
 *                       queued, the rest wait. Replies to a customer who wrote in, legal notices and contract copies always go.
 * Later (B3/B4, seeded switched off with what they wait for): protected_time, margin_floor,
 * route_full.
 *
 * A held proposal is never thrown away: it's shown with the rule's sentence and an
 * Override button. Overrides are logged; a rule overridden twice in 60 days gets a
 * proposed rewrite (rewrite()).
 *
 * No namespace / no autoloader in production: require_once and call statically.
 */
class ConflictRules
{
    public const LIVE = ['collections_first', 'one_message_week'];
    public const DEFAULTS = ['collections_first' => ['late_days' => 14], 'one_message_week' => ['window_days' => 7]];
    public const OVERRIDES_TO_REWRITE = 2;
    public const OVERRIDE_WINDOW_DAYS = 60;
    /** Kinds that are themselves collections (never held by collections_first). */
    public const COLLECTION_KINDS = '/(collect|overdue|invoice|payment|reminder)/i';
    /** Kinds that always go (escalation for one_message_week). */
    public const ESCALATE_KINDS = '/(legal|contract_copy|notice)/i';
    /** The client wrote first: a reply is not outreach (never held by one_message_week). */
    public const REPLY_KINDS = '/(replied|reply)/i';

    /**
     * @param array $proposals ranked best first: [key, head, kind, channel, contact_id, contact_name?, score, …]
     * @param array $facts     ['late' => contact_id => days late, 'last_message' => contact_id => days since]
     * @param array $rules     slug => ['enabled' => bool, 'params' => array, 'text' => string]
     * @param array $released  "slug|proposal_key" => true — overridden today
     * @return array{allowed: array, held: array, escalated: array}
     *   held: [proposal, rule, reason, detail]; escalated: [proposal, rule, reason]
     */
    public static function apply(array $proposals, array $facts, array $rules, array $released = []): array
    {
        $allowed = [];
        $held = [];
        $escalated = [];
        $on = static fn(string $slug) => !empty($rules[$slug]['enabled']);
        $param = static fn(string $slug, string $k) => (int)($rules[$slug]['params'][$k] ?? self::DEFAULTS[$slug][$k]);
        $sentContact = [];

        foreach ($proposals as $p) {
            $cid = (int)($p['contact_id'] ?? 0);
            $isMessage = ($p['channel'] ?? '') === 'message' && $cid > 0;
            if (!$isMessage) { $allowed[] = $p; continue; }
            $who = trim((string)($p['contact_name'] ?? '')) ?: 'This client';
            $kind = (string)($p['kind'] ?? '');

            // Collections before upsell.
            if ($on('collections_first') && !preg_match(self::COLLECTION_KINDS, $kind)) {
                $late = (int)($facts['late'][$cid] ?? 0);
                $limit = $param('collections_first', 'late_days');
                if ($late > $limit) {
                    if (empty($released['collections_first|' . $p['key']])) {
                        $held[] = ['proposal' => $p, 'rule' => 'collections_first',
                                   'reason' => "{$who} has an invoice {$late} days late — no sales messages until it's paid.",
                                   'detail' => ['days_late' => $late]];
                        continue;
                    }
                }
            }

            // One message per client per week.
            if ($on('one_message_week')) {
                $window = $param('one_message_week', 'window_days');
                if (preg_match(self::ESCALATE_KINDS, $kind)) {
                    if (isset($sentContact[$cid]) || (isset($facts['last_message'][$cid]) && (int)$facts['last_message'][$cid] < $window)) {
                        $escalated[] = ['proposal' => $p, 'rule' => 'one_message_week', 'reason' => 'Legal notices and contract copies always go.'];
                    }
                    $sentContact[$cid] = true;
                    $allowed[] = $p;
                    continue;
                }
                // They wrote to us: answering them isn't outreach, so it's never held — but it is
                // this week's message, so anything else queued for them waits.
                if (preg_match(self::REPLY_KINDS, $kind)) {
                    $sentContact[$cid] = true;
                    $allowed[] = $p;
                    continue;
                }
                $free = empty($released['one_message_week|' . $p['key']]);
                if ($free && isset($facts['last_message'][$cid]) && (int)$facts['last_message'][$cid] < $window) {
                    $d = (int)$facts['last_message'][$cid];
                    $held[] = ['proposal' => $p, 'rule' => 'one_message_week',
                               'reason' => "{$who} was messaged " . ($d === 0 ? 'today' : ($d === 1 ? 'yesterday' : "{$d} days ago")) . '.',
                               'detail' => ['days_since' => $d]];
                    continue;
                }
                if ($free && isset($sentContact[$cid])) {
                    $held[] = ['proposal' => $p, 'rule' => 'one_message_week',
                               'reason' => "A higher-priority message to {$who} is already in the queue.",
                               'detail' => ['days_since' => 0]];
                    continue;
                }
                $sentContact[$cid] = true;
            }
            $allowed[] = $p;
        }
        return ['allowed' => $allowed, 'held' => $held, 'escalated' => $escalated];
    }

    /**
     * After repeated overrides: the one parameter change that would have let them all through,
     * or null if no single change would.
     * @param array $details the overridden rulings' detail arrays
     */
    public static function rewrite(string $slug, array $params, array $details): ?array
    {
        if ($slug === 'collections_first') {
            $days = array_filter(array_map(fn($d) => (int)($d['days_late'] ?? 0), $details));
            if (!$days) return null;
            $new = max($days);
            $cur = (int)($params['late_days'] ?? 14);
            return $new > $cur && $new <= 120 ? ['late_days' => $new] : null;
        }
        if ($slug === 'one_message_week') {
            $days = array_map(fn($d) => (int)($d['days_since'] ?? 0), $details);
            $new = $days ? min($days) : 0;
            $cur = (int)($params['window_days'] ?? 7);
            return $new >= 1 && $new < $cur ? ['window_days' => $new] : null;
        }
        return null;
    }

    /** The rule's sentence with its current numbers. */
    public static function sentence(string $slug, array $params, string $fallback): string
    {
        if ($slug === 'collections_first') {
            return 'Collections before upsell: no sales message to a client while one of their invoices is more than '
                . (int)($params['late_days'] ?? 14) . ' days late.';
        }
        if ($slug === 'one_message_week') {
            $w = (int)($params['window_days'] ?? 7);
            return 'One message per client ' . ($w === 7 ? 'per week' : "every {$w} days") . ' — the highest-priority one goes, the rest wait.';
        }
        return $fallback;
    }
}
