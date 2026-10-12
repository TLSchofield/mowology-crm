<?php
/**
 * OfflineTime — when to trust the time a phone says something happened (2026-10-12).
 *
 * A clock-in tapped with no signal at 07:30 reached the server at 07:46 and was paid from 07:46
 * (iPhone and Android alike). Phones now send `client_time` (ISO 8601 with offset) with every
 * queued action; this decides which time is recorded. Pure, unit tested.
 *
 *   no client time                    → server time (as before)
 *   client time more than 2 min ahead → server time, flagged "phone clock ahead"
 *   older than MAX_AGE_HOURS          → server time, flagged "too old to trust"
 *   before the event it must follow   → server time, flagged "earlier than clock-in"
 *   otherwise                         → the phone's time; flagged for review when it arrived
 *                                       more than LATE_MINUTES after it happened
 */
final class OfflineTime
{
    public const MAX_AGE_HOURS = 24;
    public const AHEAD_TOLERANCE_SECONDS = 120;
    public const LATE_MINUTES = 10;

    /**
     * @param ?string $clientTime ISO 8601 from the phone (e.g. 2026-10-12T07:30:05-07:00)
     * @param string  $serverNow  'Y-m-d H:i:s' in the server's zone
     * @param ?string $notBefore  'Y-m-d H:i:s' the event cannot precede (clock-in, for a clock-out)
     * @return array{at: string, from_phone: bool, flag: ?string, note: ?string}
     */
    public static function accept(?string $clientTime, string $serverNow, ?string $notBefore = null): array
    {
        $now = strtotime($serverNow);
        $server = ['at' => $serverNow, 'from_phone' => false, 'flag' => null, 'note' => null];
        if ($clientTime === null || trim($clientTime) === '') return $server;
        $t = strtotime($clientTime);
        if ($t === false) return $server + ['flag' => 'unreadable phone time'];
        $at = date('Y-m-d H:i:s', $t);
        if ($t > $now + self::AHEAD_TOLERANCE_SECONDS) {
            return ['at' => $serverNow, 'from_phone' => false, 'flag' => 'phone clock ahead',
                    'note' => "Phone said {$at}, ahead of the server; server time used."];
        }
        if ($t < $now - self::MAX_AGE_HOURS * 3600) {
            return ['at' => $serverNow, 'from_phone' => false, 'flag' => 'too old to trust',
                    'note' => "Phone said {$at}, more than " . self::MAX_AGE_HOURS . " h ago; server time used — check this shift."];
        }
        if ($notBefore !== null && $t < strtotime($notBefore)) {
            return ['at' => $serverNow, 'from_phone' => false, 'flag' => 'earlier than clock-in',
                    'note' => "Phone said {$at}, before clock-in {$notBefore}; server time used."];
        }
        $lateMin = (int)floor(($now - $t) / 60);
        $late = $lateMin > self::LATE_MINUTES;
        return ['at' => $at, 'from_phone' => true, 'flag' => $late ? 'sent late' : null,
                'note' => $late ? 'Tapped at ' . date('H:i', $t) . " with no signal; reached the server {$lateMin} min later." : null];
    }
}
