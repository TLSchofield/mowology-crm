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
     * @param int|int[]|null $crewPicked the desktop crew picker: one crew, several (tick-box picker, 2026-11-12) or none (office, or crew when allowed to see all)
     * @param int     $people     active people in the business; with one, Mine and All crews are the same list,
     *                            so there is nothing to switch (Claudia walk 1, 2026-11-03)
     * @return array{crew: int|int[]|null, scope: string, can_switch: bool} crew = the crew filter for getCalendarStops (null = everyone)
     */
    public static function decide(string $role, int $userId, string $setting, ?string $asked, $crewPicked = null, int $people = 2): array
    {
        if ($people <= 1) return ['crew' => null, 'scope' => 'all', 'can_switch' => false];
        $isCrew = $role === self::CREW_ROLE;
        if ($isCrew && $setting !== self::ALL) {
            return ['crew' => $userId, 'scope' => 'mine', 'can_switch' => false];
        }
        $scope = $asked === 'mine' ? 'mine' : 'all';
        if ($scope === 'mine') return ['crew' => $userId, 'scope' => 'mine', 'can_switch' => true];
        return ['crew' => self::pick($crewPicked), 'scope' => 'all', 'can_switch' => true];
    }

    /** One id, a list of ids or nothing -> null (everyone), an int (one crew) or a list of ints (several); junk and duplicates dropped. */
    public static function pick($ids)
    {
        $ids = is_array($ids) ? $ids : [$ids];
        $keep = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, $keep, true)) {
                $keep[] = $id;
            }
        }
        return count($keep) === 0 ? null : (count($keep) === 1 ? $keep[0] : $keep);
    }

    /**
     * The "All crews" checklist (Tim, 2026-11-08: "same for all crews"): several people at once.
     * On the wire one comma string of user ids, like the job-type filter. Keeps only people who can
     * be on a job, in the order asked, without repeats. Empty = everyone. Pure.
     *
     * @param string[] $validIds user ids as strings (crewOptions' values)
     * @return int[]
     */
    public static function crewList(string $raw, array $validIds): array
    {
        $keep = [];
        foreach (explode(',', $raw) as $id) {
            $id = trim($id);
            if ($id !== '' && in_array($id, $validIds, true) && !in_array((int)$id, $keep, true)) {
                $keep[] = (int)$id;
            }
        }
        return $keep;
    }

    /**
     * The people the checklist offers: the same people people() counts.
     * @return list<array{value: string, label: string}>
     */
    public static function crewOptions(PDO $db): array
    {
        try {
            $rows = $db->query("SELECT id, full_name FROM users WHERE is_active = 1 AND role <> 'accountant' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        return array_map(fn($r) => ['value' => (string)$r['id'], 'label' => trim((string)$r['full_name']) ?: ('#' . $r['id'])], $rows);
    }

    /** May this person see other crews' jobs at all (my-schedule's "near another crew's job" prompt)? Pure. */
    public static function seesOtherCrews(string $role, string $setting): bool
    {
        return $role !== self::CREW_ROLE || $setting === self::ALL;
    }

    /** Active people who can be on a job (the outside accountant's login is not one). Unknown = 2 (keep the switch). */
    public static function people(PDO $db): int
    {
        try {
            return (int)$db->query("SELECT COUNT(*) FROM users WHERE is_active = 1 AND role <> 'accountant'")->fetchColumn();
        } catch (Throwable $e) {
            return 2;
        }
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
