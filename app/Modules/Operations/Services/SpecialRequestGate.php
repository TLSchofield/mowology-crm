<?php
/**
 * SpecialRequestGate — "read the client's special request before you start this visit".
 *
 * Server side of the gate the apps show in-flow: a crew member who has not tapped "Got it" on an
 * attached special request cannot start the visit's timer or upload a photo to it. Old app builds
 * get a 409 with the request in the body, so they can't walk past it.
 *
 * Offline-queued actions are never stuck: a replay (web offline queue: X-Queued-At /
 * Idempotency-Key on job-timer; photo queue: queued_at) is ACCEPTED and logged — 'gate_queued'
 * when it was queued before the request was attached, 'gate_late' when after (the work was
 * already done offline; refusing it would only lose it). The live tap is what is gated.
 *
 * Everything here fails OPEN: no tables / flag off / any error → allow. It must never be the
 * reason a crew member can't start a job when the feature is off.
 *
 * No namespace / no autoloader in production: require_once and call statically.
 */
require_once __DIR__ . '/SpecialRequestService.php';

class SpecialRequestGate
{
    public const CODE = 'special_request_unacknowledged';
    public const MESSAGE = 'Special request on this visit — read it and tap "Got it" before you start or take photos.';

    /** Tests swap in a service with a fixed clock and fake senders. */
    public static ?Closure $serviceFactory = null;

    private static function service(PDO $db): SpecialRequestService
    {
        return self::$serviceFactory ? (self::$serviceFactory)($db) : new SpecialRequestService($db);
    }

    /**
     * Pure decision.
     * @param list<array{request_visit_id:int, attached_at:string, acked:bool}> $open
     * @param ?string $queuedAt  'Y-m-d H:i:s' when the action was queued on the device, if known
     * @return array{allow:bool, reason:string, unacked:list<int>}
     */
    public static function decide(array $open, bool $applies, bool $isReplay, ?string $queuedAt): array
    {
        if (!$applies) return ['allow' => true, 'reason' => 'off', 'unacked' => []];
        $unacked = array_values(array_filter($open, fn($o) => !$o['acked']));
        if (!$open) return ['allow' => true, 'reason' => 'none', 'unacked' => []];
        if (!$unacked) return ['allow' => true, 'reason' => 'acked', 'unacked' => []];
        $ids = array_map(fn($o) => (int)$o['request_visit_id'], $unacked);
        if ($isReplay) {
            $first = min(array_map(fn($o) => $o['attached_at'], $unacked));
            $before = $queuedAt !== null && $queuedAt < $first;
            return ['allow' => true, 'reason' => $before ? 'queued_before' : 'queued_late', 'unacked' => $ids];
        }
        return ['allow' => false, 'reason' => 'blocked', 'unacked' => $ids];
    }

    /** ms epoch, seconds epoch or a date string → 'Y-m-d H:i:s' (server time); null if absent/garbage. */
    public static function normaliseQueuedAt($raw): ?string
    {
        if ($raw === null || $raw === '') return null;
        if (is_numeric($raw)) {
            $n = (float)$raw;
            if ($n > 1e11) $n /= 1000; // ms
            if ($n < 1e9) return null;
            return date('Y-m-d H:i:s', (int)$n);
        }
        $t = strtotime((string)$raw);
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    /**
     * Check one action. Logs blocks / queued passes and pushes the "arrival" reminder.
     * @return array{allow:bool, reason:string, requests:list<array>}
     */
    public static function check(PDO $db, int $visitId, int $userId, bool $isReplay = false, $queuedAtRaw = null, string $what = 'start'): array
    {
        try {
            $svc = self::service($db);
            if (!$visitId || !$userId || !$svc->appliesToUser($userId)) {
                return ['allow' => true, 'reason' => 'off', 'requests' => []];
            }
            $open = $svc->openForGate($visitId, $userId);
            $d = self::decide($open, true, $isReplay, self::normaliseQueuedAt($queuedAtRaw));
            if ($d['reason'] === 'none' || $d['reason'] === 'acked') {
                return ['allow' => true, 'reason' => $d['reason'], 'requests' => []];
            }
            $requests = array_map(fn($id) => $svc->payload($id, $userId), $d['unacked']);
            foreach ($requests as $r) {
                $kind = $d['allow'] ? ($d['reason'] === 'queued_before' ? 'gate_queued' : 'gate_late') : 'gate_block';
                // Auto-arrival re-checks on every few GPS pings — log its block once.
                if ($what === 'auto_start' && $svc->hasEvent($r['request_visit_id'], $userId, $kind, $what)) continue;
                $svc->event($r['request_id'], $r['request_visit_id'], $visitId, $userId, $kind, $d['allow'], $what);
            }
            $svc->notifyArrival($visitId);
            return ['allow' => $d['allow'], 'reason' => $d['reason'], 'requests' => $requests];
        } catch (Throwable $e) {
            error_log('SpecialRequestGate::check failed open: ' . $e->getMessage());
            return ['allow' => true, 'reason' => 'error', 'requests' => []];
        }
    }

    /**
     * For API files: on a block, answer 409 with the request(s) and exit. $msgKey is the key the
     * endpoint's clients read the error from ('error' on web endpoints, 'message' on JWT ones).
     */
    public static function enforce(PDO $db, int $visitId, int $userId, bool $isReplay = false, $queuedAtRaw = null, string $what = 'start', string $msgKey = 'error'): void
    {
        $resp = self::response($db, $visitId, $userId, $isReplay, $queuedAtRaw, $what, $msgKey);
        if ($resp === null) return;
        http_response_code($resp['status']);
        header('Content-Type: application/json');
        echo json_encode($resp['body']);
        exit;
    }

    /** The HTTP answer enforce() would give: null = carry on, else ['status' => 409, 'body' => …]. */
    public static function response(PDO $db, int $visitId, int $userId, bool $isReplay = false, $queuedAtRaw = null, string $what = 'start', string $msgKey = 'error'): ?array
    {
        $r = self::check($db, $visitId, $userId, $isReplay, $queuedAtRaw, $what);
        if ($r['allow']) return null;
        $body = ['success' => false, 'ok' => false, 'code' => self::CODE, 'special_requests' => $r['requests']];
        $body[$msgKey] = self::MESSAGE;
        if ($msgKey !== 'error') $body['error'] = self::MESSAGE;
        return ['status' => 409, 'body' => $body];
    }

    /**
     * Auto-arrival (geofence) must not start the timer past an unread request: returns true when it
     * should NOT auto-start. The arrival push tells the crew to open the visit.
     */
    public static function blocksAutoStart(PDO $db, int $visitId, int $userId): bool
    {
        return !self::check($db, $visitId, $userId, false, null, 'auto_start')['allow'];
    }

    /** Completion paths call this right after writing the completion sheet's extras. Never throws. */
    public static function afterCompletion(PDO $db, int $visitId): void
    {
        try {
            self::service($db)->foldExtras($visitId);
        } catch (Throwable $e) {
            error_log('SpecialRequestGate::afterCompletion: ' . $e->getMessage());
        }
    }
}
