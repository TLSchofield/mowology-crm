<?php
/**
 * TrackingSetupGate — refuses a self clock-in, for named users only, unless the phone
 * proves its location settings let background tracking work.
 *
 * Why per-user: from 2026-09-23 one crew phone clocked in every day through the Crew
 * app while its background tracker never ran and its only fixes were 2 km-grid
 * (Approximate) positions. The in-app setup screen could not catch it — it only opens
 * once the tracker has started, and it never checked Precise. The owner chose to enforce
 * this for that user rather than every phone (the truck tablet still runs "While using").
 *
 * The list lives in time_clock_settings so no migration is needed:
 *   tracking_setup_required_user_ids = "6"   (comma-separated user ids)
 *
 * The phone sends its permission snapshot (MwTracking.checkTrackingPermissions()) as
 * `tracking_perms` with the clock-in. No snapshot means it was not the Crew app, or the
 * app could not read its own state — both are refused, because both are exactly the
 * cases where tracking cannot be confirmed. An admin clocking the user in from the
 * office is never gated, so nobody can be locked out of being paid for a shift.
 */
class TrackingSetupGate
{
    public const SETTING = 'tracking_setup_required_user_ids';

    /** @return int[] */
    public static function parseIds(?string $value): array
    {
        $ids = [];
        foreach (preg_split('/[\s,;]+/', (string)$value) ?: [] as $part) {
            if (ctype_digit($part) && (int)$part > 0) {
                $ids[] = (int)$part;
            }
        }
        return array_values(array_unique($ids));
    }

    /** Which setting is missing, as an instruction the crew member can follow. PURE. */
    public static function problem(?array $perms): ?string
    {
        if (!$perms) {
            return 'Clock in from the Mowology Crew app on your phone. It has to check your location settings first.';
        }
        if (array_key_exists('gpsEnabled', $perms) && $perms['gpsEnabled'] === false) {
            return 'Location is switched off on your phone. Turn it on and try again.';
        }
        if (empty($perms['location'])) {
            return 'The app has no location permission. Settings > Apps > Mowology Crew > Permissions > Location: "Allow all the time".';
        }
        if (empty($perms['background'])) {
            return 'Location must be "Allow all the time". Settings > Apps > Mowology Crew > Permissions > Location.';
        }
        if (empty($perms['precise'])) {
            return 'Turn on "Use precise location". Settings > Apps > Mowology Crew > Permissions > Location.';
        }
        return null;
    }

    /** The refusal message for this user, or null when they may clock in. PURE. */
    public static function refusal(?string $settingValue, int $userId, ?array $perms): ?string
    {
        if (!in_array($userId, self::parseIds($settingValue), true)) {
            return null;
        }
        $problem = self::problem($perms);
        return $problem === null ? null : 'Clock-in blocked: ' . $problem;
    }

    /** Whether this user is on the list at all (used where no snapshot can be sent). PURE. */
    public static function appliesTo(?string $settingValue, int $userId): bool
    {
        return in_array($userId, self::parseIds($settingValue), true);
    }
}
