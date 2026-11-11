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
 *   older than MAX_TRUST_DAYS         → rejected: record nothing, log it for the office
 *   older than MAX_AGE_HOURS          → the phone's time, flagged "older than 24 h" — check it
 *                                       (Tim, 2026-10-12: a shift synced next morning is real;
 *                                       stamping the replay time, or dropping it, is worse)
 *   before the event it must follow   → server time, flagged "earlier than clock-in"
 *   otherwise                         → the phone's time; flagged for review when it arrived
 *                                       more than LATE_MINUTES after it happened
 */
final class OfflineTime
{
    public const MAX_AGE_HOURS = 24;
    public const MAX_TRUST_DAYS = 7;
    public const AHEAD_TOLERANCE_SECONDS = 120;
    public const LATE_MINUTES = 10;

    /**
     * @param ?string $clientTime ISO 8601 from the phone (e.g. 2026-10-12T07:30:05-07:00)
     * @param string  $serverNow  'Y-m-d H:i:s' in the server's zone
     * @param ?string $notBefore  'Y-m-d H:i:s' the event cannot precede (clock-in, for a clock-out)
     * @return array{at: string, from_phone: bool, flag: ?string, note: ?string, reject: bool}
     */
    public static function accept(?string $clientTime, string $serverNow, ?string $notBefore = null): array
    {
        $now = strtotime($serverNow);
        $server = ['at' => $serverNow, 'from_phone' => false, 'flag' => null, 'note' => null, 'reject' => false];
        if ($clientTime === null || trim($clientTime) === '') return $server;
        $t = strtotime($clientTime);
        if ($t === false) return ['flag' => 'unreadable phone time'] + $server;
        $at = date('Y-m-d H:i:s', $t);
        if ($t > $now + self::AHEAD_TOLERANCE_SECONDS) {
            return ['at' => $serverNow, 'from_phone' => false, 'flag' => 'phone clock ahead',
                    'note' => "Phone said {$at}, ahead of the server; server time used.", 'reject' => false];
        }
        if ($t < $now - self::MAX_TRUST_DAYS * 86400) {
            return ['at' => $serverNow, 'from_phone' => false, 'flag' => 'too old to trust',
                    'note' => "Phone said {$at}, more than " . self::MAX_TRUST_DAYS . " days ago; not recorded — enter this by hand if it was real.",
                    'reject' => true];
        }
        if ($notBefore !== null && $t < strtotime($notBefore)) {
            return ['at' => $serverNow, 'from_phone' => false, 'flag' => 'earlier than clock-in',
                    'note' => "Phone said {$at}, before clock-in {$notBefore}; server time used.", 'reject' => false];
        }
        $lateMin = (int)floor(($now - $t) / 60);
        if ($t < $now - self::MAX_AGE_HOURS * 3600) {
            return ['at' => $at, 'from_phone' => true, 'flag' => 'older than 24 h',
                    'note' => 'Tapped ' . date('D M j H:i', $t) . ' with no signal; reached the server '
                              . round($lateMin / 60) . ' h later — check this shift.', 'reject' => false];
        }
        $late = $lateMin > self::LATE_MINUTES;
        return ['at' => $at, 'from_phone' => true, 'flag' => $late ? 'sent late' : null,
                'note' => $late ? 'Tapped at ' . date('H:i', $t) . " with no signal; reached the server {$lateMin} min later." : null,
                'reject' => false];
    }
}
