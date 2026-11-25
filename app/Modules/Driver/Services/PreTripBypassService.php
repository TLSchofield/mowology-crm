<?php
declare(strict_types=1);

/**
 * A manager's PIN waives the pre-trip inspection for ONE trip. One place for the rule, used by the
 * web page (crm/api/manager-pin-verify.php) and the phone API (Schedule/Api/trip-report.php), so the
 * lockout, the hashing and the audit trail cannot drift apart.
 *
 * The PIN is the control, not a role flag: anyone driving this shift may need a manager to waive it.
 */
class PreTripBypassService
{
    public const MAX_REASON = 100;

    /** What is stored as the reason: trimmed, never empty, capped. */
    public static function cleanReason(?string $reason): string
    {
        $r = trim((string)$reason);
        if ($r === '') {
            return 'manager_override';
        }
        return function_exists('mb_substr') ? mb_substr($r, 0, self::MAX_REASON) : substr($r, 0, self::MAX_REASON);
    }

    /**
     * @return array{ok:bool, status:int, error?:string, report_id?:int}
     *   status is the HTTP status the caller should send (200 on success).
     */
    public static function attempt(PDO $db, int $userId, string $pin, ?string $reason, ?string $vehicleId, string $ip, string $date): array
    {
        require_once APP_ROOT . '/Core/Auth/AttemptThrottle.php';
        require_once APP_ROOT . '/Modules/Settings/Services/ManagerPin.php';
        require_once APP_ROOT . '/Modules/Driver/Services/TripReportService.php';

        $pin = trim($pin);
        if ($pin === '') {
            return ['ok' => false, 'status' => 400, 'error' => 'PIN required'];
        }

        // 5 wrong PINs in 10 minutes locks this user out of the bypass (Fable audit 2026-10-12):
        // a 4-digit PIN otherwise falls to 10,000 tries.
        if (AttemptThrottle::pinIsLocked($db, $userId)) {
            logActivity($userId, null, 'Manager PIN locked out', 'IP: ' . $ip);
            return ['ok' => false, 'status' => 429, 'error' => 'Too many wrong PINs. Try again in 10 minutes or call the office.'];
        }

        $row    = $db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'manager_override_pin'")->fetch(PDO::FETCH_ASSOC);
        $stored = (string)($row['setting_value'] ?? '');
        if ($stored === '') {
            return ['ok' => false, 'status' => 403, 'error' => 'Manager override is not configured. Contact your administrator.'];
        }

        // Stored hashed; a legacy plaintext value is accepted once and re-hashed.
        $check = ManagerPin::verify($pin, $stored);
        if (!$check['ok']) {
            AttemptThrottle::record($db, AttemptThrottle::pinKey($userId), $ip);
            error_log('[pre-trip-bypass] failed PIN attempt for user #' . $userId);
            logActivity($userId, null, 'Manager PIN failed', 'IP: ' . $ip);
            return ['ok' => false, 'status' => 403, 'error' => 'Incorrect PIN'];
        }
        AttemptThrottle::clear($db, AttemptThrottle::pinKey($userId));
        if ($check['rehash'] !== null) {
            $db->prepare("UPDATE ops_settings SET setting_value = ? WHERE setting_key = 'manager_override_pin' AND setting_value = ?")
               ->execute([$check['rehash'], $stored]);
        }

        $reason   = self::cleanReason($reason);
        $reportId = (new TripReportService($db))->bypassPreTrip($userId, $vehicleId, $reason, $date);
        logActivity($userId, null, 'Pre-trip bypassed via manager PIN', 'Reason: ' . $reason);

        return ['ok' => true, 'status' => 200, 'report_id' => $reportId];
    }
}
