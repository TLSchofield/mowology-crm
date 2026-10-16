<?php
/**
 * VisitNeedsService — what each visit needs from the crew before it can be finished, in one answer, so a
 * phone can work a visit with no signal (native Android visit screen, 2026-10-16; the iPhone can use it too).
 *
 *   snow            { is_route, choice } — a snow & salt route stop bills only what the crew record
 *                   (SnowContractService); choice = what was recorded so far, or null
 *   photos          { required: [types], blocks: bool, taken: [types] } — taken = types already uploaded; the plan's photo_types_required / photos_block_completion
 *                   (the web's end_visit gate). Checked on the phone: photos upload on their own and may land after
 *                   an offline Finish, so the server never refuses a completion for them.
 *   special_requests the crew payload per visit (SpecialRequestService::forVisits; empty unless switched on)
 *
 * Plus SNOW_CHOICES, the four answers, for the screen. Tenant-neutral; global namespace (require_once + new).
 */
declare(strict_types=1);

class VisitNeedsService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** The answers a route stop takes, with their labels (SnowContractService::CHOICES / choiceLabel). */
    public static function snowChoices(): array
    {
        require_once APP_ROOT . '/Modules/Contracts/Services/SnowContractService.php';
        return array_map(fn($c) => ['value' => $c, 'label' => SnowContractService::choiceLabel($c)], SnowContractService::CHOICES);
    }

    /** "[\"before\",\"after\"]" / "before,after" / null → ['before', 'after']. Pure. */
    public static function photoTypes($raw): array
    {
        if ($raw === null || $raw === '') return [];
        $a = json_decode((string)$raw, true);
        if (!is_array($a)) $a = explode(',', (string)$raw);
        return array_values(array_unique(array_filter(array_map(fn($t) => strtolower(trim((string)$t)), $a))));
    }

    /**
     * @param array<int,array{visit_id:int,plan_id:int}> $visits
     * @return array<int,array{snow:array,photos:array,special_requests:array}> keyed by visit id
     */
    public function forVisits(array $visits, int $userId, bool $isOffice): array
    {
        $visitIds = array_values(array_unique(array_filter(array_map(fn($v) => (int)($v['visit_id'] ?? 0), $visits))));
        $planIds  = array_values(array_unique(array_filter(array_map(fn($v) => (int)($v['plan_id'] ?? 0), $visits))));
        $out = [];
        foreach ($visitIds as $id) $out[$id] = ['snow' => ['is_route' => false, 'choice' => null], 'photos' => ['required' => [], 'blocks' => false, 'taken' => []], 'special_requests' => []];
        if (!$visitIds) return $out;

        $routePlans = []; $photoRules = []; $choices = [];
        if ($planIds) {
            $in = implode(',', array_fill(0, count($planIds), '?'));
            try {
                $s = $this->db->prepare("SELECT DISTINCT plan_id FROM snow_route_rates WHERE plan_id IN ($in)");
                $s->execute($planIds);
                $routePlans = array_flip(array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)));
            } catch (Throwable $e) { /* before migration 1310: no routes */ }
            try {
                $s = $this->db->prepare("SELECT id, photo_types_required, photos_block_completion FROM job_plans WHERE id IN ($in)");
                $s->execute($planIds);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $photoRules[(int)$r['id']] = $r;
            } catch (Throwable $e) { /* no compliance columns */ }
        }
        try {
            $in = implode(',', array_fill(0, count($visitIds), '?'));
            $s = $this->db->prepare("SELECT visit_id, choice FROM visit_service_choices WHERE visit_id IN ($in)");
            $s->execute($visitIds);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $choices[(int)$r['visit_id']] = (string)$r['choice'];
        } catch (Throwable $e) { /* no choices table */ }

        $taken = [];
        try {
            $in = implode(',', array_fill(0, count($visitIds), '?'));
            $s = $this->db->prepare("SELECT DISTINCT visit_id, LOWER(photo_type) t FROM visit_photos WHERE visit_id IN ($in) AND deleted_at IS NULL");
            $s->execute($visitIds);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $taken[(int)$r['visit_id']][] = (string)$r['t'];
        } catch (Throwable $e) { /* no photos table */ }

        foreach ($visits as $v) {
            $id = (int)($v['visit_id'] ?? 0); $plan = (int)($v['plan_id'] ?? 0);
            if (!isset($out[$id])) continue;
            if (isset($routePlans[$plan])) $out[$id]['snow'] = ['is_route' => true, 'choice' => $choices[$id] ?? null];
            if (isset($photoRules[$plan])) {
                $types = self::photoTypes($photoRules[$plan]['photo_types_required'] ?? null);
                $out[$id]['photos'] = ['required' => $types, 'blocks' => $types && (int)($photoRules[$plan]['photos_block_completion'] ?? 0) === 1, 'taken' => []];
            }
            $out[$id]['photos']['taken'] = array_values(array_unique($taken[$id] ?? []));
        }

        try {
            require_once APP_ROOT . '/Modules/Operations/Services/SpecialRequestService.php';
            $sr = new SpecialRequestService($this->db);
            if ($sr->ready()) {
                foreach ($sr->forVisits($visitIds, $userId, $isOffice) as $vid => $list) {
                    if (isset($out[(int)$vid])) $out[(int)$vid]['special_requests'] = array_values($list);
                }
            }
        } catch (Throwable $e) {
            error_log('VisitNeedsService special requests: ' . $e->getMessage());
        }
        return $out;
    }
}
