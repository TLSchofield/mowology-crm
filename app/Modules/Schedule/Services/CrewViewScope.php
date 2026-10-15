<?php
/**
 * CrewViewScope — whose jobs a person sees on the schedule (web, Android, iPhone, my-schedule).
 *
 * Tim, 2026-10-14: "a toggle for the details for all crew; managers/admin should be able to see all and
 * also just see their own", plus a job-type filter. Plan: docs/plans/2026-10-14-penny-learning-and-crew-view.md B.
 *
 *   Time Clock setting crew_job_visibility = 'own' (default) | 'all'
 *     own — crew see only the stops they are on; enforced on the server (was: every role got every
 *           crew's stops on web/Android, and my-schedule embedded other crews' addresses — Codex review).
 *     all — crew may switch between Mine and All crews, like the office.
 *   Office (admin, manager, any non-crew role): Mine / All crews switch, default All; on All the
 *   desktop crew dropdown still narrows to one crew.
 *
 * Tenant-neutral: no trade words; the job-type filter is the tenant's own service_types list.
 * No namespace / no autoloader in production: require_once and `new`.
 */
class CrewViewScope
{
    public const SETTING = 'crew_job_visibility';
    public const OWN = 'own';
    public const ALL = 'all';
    public const CREW_ROLE = 'user';

    /**
     * @param string  $role       users.role
     * @param string  $setting    crew_job_visibility ('own' | 'all'; anything else = own)
     * @param ?string $asked      'mine' | 'all' | null (the person's switch, remembered)
     * @param ?int    $crewPicked the desktop crew dropdown (office, or crew when allowed to see all)
     * @return array{crew: ?int, scope: string, can_switch: bool} crew = the crew filter for getCalendarStops (null = everyone)
     */
    public static function decide(string $role, int $userId, string $setting, ?string $asked, ?int $crewPicked = null): array
    {
        $isCrew = $role === self::CREW_ROLE;
        if ($isCrew && $setting !== self::ALL) {
            return ['crew' => $userId, 'scope' => 'mine', 'can_switch' => false];
        }
        $scope = $asked === 'mine' ? 'mine' : 'all';
        if ($scope === 'mine') return ['crew' => $userId, 'scope' => 'mine', 'can_switch' => true];
        return ['crew' => $crewPicked && $crewPicked > 0 ? $crewPicked : null, 'scope' => 'all', 'can_switch' => true];
    }

    /** May this person see other crews' jobs at all (my-schedule's "near another crew's job" prompt)? Pure. */
    public static function seesOtherCrews(string $role, string $setting): bool
    {
        return $role !== self::CREW_ROLE || $setting === self::ALL;
    }

    /** The company setting, 'own' unless set to 'all'. */
    public static function setting(PDO $db): string
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM time_clock_settings WHERE setting_key = ?");
            $s->execute([self::SETTING]);
            return $s->fetchColumn() === self::ALL ? self::ALL : self::OWN;
        } catch (Throwable $e) {
            return self::OWN;
        }
    }
}
