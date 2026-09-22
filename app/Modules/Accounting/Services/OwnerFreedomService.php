<?php
/**
 * OwnerFreedomService — "could the business pay me if I stopped working?"
 *
 * The owner draws a fixed paycheque (default 40 h/week at their hourly rate).
 * This service measures how much of that cheque the business could keep paying
 * if the owner worked zero hours and every hour they currently work was bought
 * from someone else, then turns the gap into ranked, dollar-sized directions and
 * a tracking checklist that says how trustworthy the numbers are.
 *
 * Money truth   : invoices (issue_date, total, amount_paid, balance_due)
 * Labour truth  : job_time_entries × users.hourly_rate (crew) / owner minutes
 * Owner hours   : time_clock_entries (shift) + job_time_entries (field)
 * Attribution   : visit_crew_assignments / job_visits.assigned_crew_id / owner time entries
 * Settings      : ops_settings (key/value) — no migration required
 *
 * compute(), recommend(), scoreTrend() and dataQualityFromCounts() are pure and
 * unit-tested; the load*() methods only fetch rows. Every query is wrapped so a
 * missing table on production degrades to a "cannot verify" line in the
 * tracking panel instead of a fatal.
 */
declare(strict_types=1);

class OwnerFreedomService
{
    private PDO $db;

    /** Categories in `expenses` that are labour/owner pay — never double-count with time entries. */
    private const LABOUR_CATEGORIES = ['payroll', 'wages', 'labour', 'labor', 'salaries', 'salary', 'owner_draw', 'owners_draw', 'owner draw'];

    public const SETTING_KEYS = [
        'freedom_owner_user_id',
        'freedom_target_hours_week',
        'freedom_owner_rate',
        'freedom_field_replacement_rate',
        'freedom_admin_replacement_rate',
        'freedom_burden_pct',
        'freedom_fixed_overhead_month',
        'freedom_replacement_mode',
        'freedom_planned_hires',
        'freedom_season_start_month',
        'freedom_season_end_month',
        'freedom_cheque_weeks_year',
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SETTINGS
    // ══════════════════════════════════════════════════════════════════════════

    /** Effective settings: saved values with sensible fallbacks (first admin, their pay rate). */
    public function settings(): array
    {
        $raw = [];
        try {
            $in   = implode(',', array_fill(0, count(self::SETTING_KEYS), '?'));
            $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM ops_settings WHERE setting_key IN ($in)");
            $stmt->execute(self::SETTING_KEYS);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $raw[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Throwable $e) { /* no ops_settings → all defaults */ }

        $ownerId = (int)($raw['freedom_owner_user_id'] ?? 0);
        $owner   = null;
        try {
            if ($ownerId > 0) {
                $s = $this->db->prepare("SELECT id, full_name, hourly_rate FROM users WHERE id = ? LIMIT 1");
                $s->execute([$ownerId]);
                $owner = $s->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$owner) {
                $owner = $this->db->query("SELECT id, full_name, hourly_rate FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")
                                  ->fetch(PDO::FETCH_ASSOC) ?: null;
                $ownerId = $owner ? (int)$owner['id'] : 0;
            }
        } catch (Throwable $e) { /* users table unreadable — leave owner unknown */ }

        $ownerRate = isset($raw['freedom_owner_rate']) && $raw['freedom_owner_rate'] !== ''
            ? (float)$raw['freedom_owner_rate']
            : (float)($owner['hourly_rate'] ?? 0);

        return [
            'owner_user_id'          => $ownerId,
            'owner_name'             => $owner['full_name'] ?? null,
            'owner_explicit'         => isset($raw['freedom_owner_user_id']) && (int)$raw['freedom_owner_user_id'] > 0,
            'target_hours_week'      => (float)($raw['freedom_target_hours_week'] ?? 40),
            'owner_rate'             => $ownerRate,
            'owner_rate_explicit'    => isset($raw['freedom_owner_rate']) && $raw['freedom_owner_rate'] !== '',
            // null = derive from the crew's average wage at compute time
            'field_replacement_rate' => isset($raw['freedom_field_replacement_rate']) && $raw['freedom_field_replacement_rate'] !== ''
                                        ? (float)$raw['freedom_field_replacement_rate'] : null,
            'admin_replacement_rate' => (float)($raw['freedom_admin_replacement_rate'] ?? 30),
            'burden_pct'             => (float)($raw['freedom_burden_pct'] ?? 15),
            'fixed_overhead_month'   => (float)($raw['freedom_fixed_overhead_month'] ?? 0),
            // 'hours' = buy back the owner's logged hours at crew rates; 'planned' = a named replacement crew
            'replacement_mode'       => ($raw['freedom_replacement_mode'] ?? 'hours') === 'planned' ? 'planned' : 'hours',
            'planned_hires'          => self::parseHires((string)($raw['freedom_planned_hires'] ?? '')),
            'planned_hires_raw'      => (string)($raw['freedom_planned_hires'] ?? ''),
            'season_start_month'     => max(1, min(12, (int)($raw['freedom_season_start_month'] ?? 3))),
            'season_end_month'       => max(1, min(12, (int)($raw['freedom_season_end_month'] ?? 12))),
            'cheque_weeks_year'      => max(1.0, min(52.0, (float)($raw['freedom_cheque_weeks_year'] ?? 52))),
        ];
    }

    /** Persist the settings form. Blank values clear the override so the fallback applies again. */
    public function saveSettings(array $post, int $userId): void
    {
        $map = [
            'freedom_owner_user_id'          => ['owner_user_id', 'int'],
            'freedom_target_hours_week'      => ['target_hours_week', 'float'],
            'freedom_owner_rate'             => ['owner_rate', 'float_or_blank'],
            'freedom_field_replacement_rate' => ['field_replacement_rate', 'float_or_blank'],
            'freedom_admin_replacement_rate' => ['admin_replacement_rate', 'float'],
            'freedom_burden_pct'             => ['burden_pct', 'float'],
            'freedom_fixed_overhead_month'   => ['fixed_overhead_month', 'float'],
            'freedom_replacement_mode'       => ['replacement_mode', 'mode'],
            'freedom_planned_hires'          => ['planned_hires', 'hires'],
            'freedom_season_start_month'     => ['season_start_month', 'int'],
            'freedom_season_end_month'       => ['season_end_month', 'int'],
            'freedom_cheque_weeks_year'      => ['cheque_weeks_year', 'float'],
        ];
        $stmt = $this->db->prepare("
            INSERT INTO ops_settings (setting_key, setting_value, description, updated_by)
            VALUES (?, ?, 'Owner Freedom dashboard', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
        ");
        foreach ($map as $key => [$field, $type]) {
            if (!array_key_exists($field, $post)) continue;
            $v = trim((string)$post[$field]);
            if ($type === 'int')            $v = (string)max(0, (int)$v);
            elseif ($type === 'float')      $v = (string)max(0, (float)$v);
            elseif ($type === 'mode')       $v = $v === 'planned' ? 'planned' : 'hours';
            elseif ($type === 'hires')      $v = self::normaliseHires($v);
            elseif ($v !== '')              $v = (string)max(0, (float)$v);   // float_or_blank
            $stmt->execute([$key, $v, $userId]);
        }
    }

    /**
     * Parse "Nigel 28 40, Assistant 25 40" (or "Nigel:28:40" / "28x40") into
     * [['name'=>..., 'rate'=>28.0, 'hours'=>40.0], ...]. Hours default to 40.
     */
    public static function parseHires(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,;\n]+/', $raw) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') continue;
            if (!preg_match_all('/\d+(?:\.\d+)?/', $chunk, $mm) || count($mm[0]) === 0) continue;
            $nums = array_map('floatval', $mm[0]);
            $name = preg_replace('/[\d.:@\/$\-]+/', ' ', $chunk);
            $name = preg_replace('/\b(h|hr|hrs|hour|hours|x|per|at|wk|week)\b/i', ' ', $name);
            $name = trim(preg_replace('/\s+/', ' ', $name));
            $out[] = [
                'name'  => $name !== '' ? $name : 'Hire ' . (count($out) + 1),
                'rate'  => $nums[0],
                'hours' => isset($nums[1]) && $nums[1] > 0 ? $nums[1] : 40.0,
            ];
        }
        return $out;
    }

    /** Canonical "Name 28 40, Name 25 40" form for storage. */
    public static function normaliseHires(string $raw): string
    {
        return implode(', ', array_map(
            fn($h) => $h['name'] . ' ' . rtrim(rtrim(number_format($h['rate'], 2, '.', ''), '0'), '.') . ' ' . rtrim(rtrim(number_format($h['hours'], 1, '.', ''), '0'), '.'),
            self::parseHires($raw)
        ));
    }

    /** Weeks of the working season in a calendar year (inclusive months, may wrap the year end). */
    public static function seasonWeeks(int $startMonth, int $endMonth, int $year = 0): float
    {
        $year = $year ?: (int)date('Y');
        $days = 0;
        $m = $startMonth;
        for ($i = 0; $i < 12; $i++) {
            $days += (int)cal_days_in_month(CAL_GREGORIAN, $m, $year);
            if ($m === $endMonth) break;
            $m = $m % 12 + 1;
        }
        return $days / 7;
    }

    /** Weeks of a date range that fall inside the season months. */
    public static function seasonWeeksInRange(string $from, string $to, int $startMonth, int $endMonth): float
    {
        $inSeason = function (int $month) use ($startMonth, $endMonth): bool {
            return $startMonth <= $endMonth
                ? ($month >= $startMonth && $month <= $endMonth)
                : ($month >= $startMonth || $month <= $endMonth);
        };
        $days = 0;
        $d = new DateTime($from);
        $end = new DateTime($to);
        while ($d <= $end) {
            if ($inSeason((int)$d->format('n'))) $days++;
            $d->modify('+1 day');
        }
        return $days / 7;
    }

    /** Active users for the owner picker. */
    public function users(): array
    {
        try {
            return $this->db->query("SELECT id, full_name, role, hourly_rate FROM users WHERE is_active = 1 ORDER BY full_name")
                            ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // REPORT (fetch → pure compute)
    // ══════════════════════════════════════════════════════════════════════════

    /** Full dashboard payload for a date range (inclusive). */
    public function report(string $from, string $to): array
    {
        $settings = $this->settings();
        $inputs   = $this->loadInputs($from, $to, $settings);
        $computed = self::compute($inputs, $settings);
        $trend    = $this->loadTrend(12, $settings);
        $quality  = self::dataQualityFromCounts($this->loadQualityCounts($from, $to, $settings), $settings, $inputs);
        $recs     = self::recommend($computed, $inputs, $settings, $quality);

        return [
            'from'     => $from,
            'to'       => $to,
            'settings' => $settings,
            'inputs'   => $inputs,
            'metrics'  => $computed,
            'trend'    => $trend,
            'quality'  => $quality,
            'directions' => $recs,
        ];
    }

    /**
     * Pure: turn raw period inputs + settings into the freedom metrics.
     * All money is for the period; *_week figures are normalised by $inputs['weeks'].
     */
    public static function compute(array $in, array $s): array
    {
        $weeks  = max(0.142857, (float)($in['weeks'] ?? 1));          // never divide by less than a day
        $months = $weeks / 4.345;

        $targetWeek   = (float)$s['target_hours_week'] * (float)$s['owner_rate'];
        $targetPeriod = $targetWeek * $weeks;

        $crewAvgRate = (float)($in['crew_avg_rate'] ?? 0);
        $burden      = 1 + ((float)($s['burden_pct'] ?? 0) / 100);
        $fieldRate   = $s['field_replacement_rate'] !== null
            ? (float)$s['field_replacement_rate']
            : ($crewAvgRate > 0 ? $crewAvgRate * $burden : 25 * $burden);
        $adminRate   = (float)($s['admin_replacement_rate'] ?? 30) * $burden;

        $ownerClockH = ((float)($in['owner_clock_minutes'] ?? 0)) / 60;
        $ownerFieldH = ((float)($in['owner_field_minutes'] ?? 0)) / 60;
        // Field time is only "extra" when it wasn't already inside a clocked shift.
        $ownerTotalH = max($ownerClockH, $ownerFieldH);
        $ownerAdminH = max(0.0, $ownerTotalH - $ownerFieldH);

        $revenue     = (float)($in['revenue'] ?? 0);
        $crewLabour  = (float)($in['crew_labour_cost'] ?? 0);
        $expenses    = (float)($in['expenses'] ?? 0);
        $fixedOh     = (float)($s['fixed_overhead_month'] ?? 0) * $months;

        $profitBeforeOwner = $revenue - $crewLabour - $expenses - $fixedOh;
        $replaceField      = $ownerFieldH * $fieldRate;
        $replaceAdmin      = $ownerAdminH * $adminRate;
        $replacement       = $replaceField + $replaceAdmin;

        // Planned replacement crew: named hires × their hours × burden, paid only in season.
        $mode          = ($s['replacement_mode'] ?? 'hours') === 'planned' && !empty($s['planned_hires']) ? 'planned' : 'hours';
        $plannedWeekly = 0.0;
        $plannedRows   = [];
        foreach (($s['planned_hires'] ?? []) as $hire) {
            $w = (float)$hire['rate'] * (float)$hire['hours'] * $burden;
            $plannedWeekly += $w;
            $plannedRows[] = ['name' => $hire['name'], 'rate' => (float)$hire['rate'], 'hours' => (float)$hire['hours'], 'weekly' => round($w, 2)];
        }
        $seasonWeeksYear = self::seasonWeeks((int)($s['season_start_month'] ?? 3), (int)($s['season_end_month'] ?? 12));
        $seasonWeeksHere = isset($in['season_weeks']) ? (float)$in['season_weeks'] : $weeks;
        if ($mode === 'planned') {
            $replacement  = $plannedWeekly * $seasonWeeksHere;
            $replaceField = $replacement;
            $replaceAdmin = 0.0;
        }
        $profitAfter       = $profitBeforeOwner - $replacement;

        // Turnover needed: revenue R where R − variable costs − fixed overhead − replacement crew − your cheque = 0.
        // Variable ratio = (other crew labour + expenses) / revenue for the period.
        $chequeWeeksYear = (float)($s['cheque_weeks_year'] ?? 52);
        $varRatio        = $revenue > 0 ? min(0.95, ($crewLabour + $expenses) / $revenue) : null;
        $chequeYear      = $targetWeek * $chequeWeeksYear;
        $fixedOhYear     = (float)($s['fixed_overhead_month'] ?? 0) * 12;
        $crewSeason      = $mode === 'planned' ? $plannedWeekly * $seasonWeeksYear : ($weeks > 0 ? $replacement / $weeks * $seasonWeeksYear : 0.0);
        $needYear        = $varRatio === null ? null : ($chequeYear + $fixedOhYear + $crewSeason) / (1 - $varRatio);
        $needSeasonWeek  = $needYear === null || $seasonWeeksYear <= 0 ? null : $needYear / $seasonWeeksYear;

        $coverageNow = $targetPeriod > 0 ? $profitBeforeOwner / $targetPeriod * 100 : null;
        $freedomCov  = $targetPeriod > 0 ? $profitAfter / $targetPeriod * 100 : null;
        $score       = $freedomCov === null ? null : (int)round(max(0, min(100, $freedomCov)));

        $visitTotal = (float)($in['visit_revenue_total'] ?? 0);
        $visitOwner = (float)($in['visit_revenue_owner'] ?? 0);
        $recurring  = (float)($in['recurring_revenue'] ?? 0);

        $ownerHoursWeek = $ownerTotalH / $weeks;
        $stage = self::stage($freedomCov, $ownerHoursWeek);

        return [
            'weeks'                   => round($weeks, 2),
            'target_week'             => round($targetWeek, 2),
            'target_period'           => round($targetPeriod, 2),
            'revenue'                 => round($revenue, 2),
            'collected'               => round((float)($in['collected'] ?? 0), 2),
            'crew_labour_cost'        => round($crewLabour, 2),
            'expenses'                => round($expenses, 2),
            'fixed_overhead'          => round($fixedOh, 2),
            'profit_before_owner'     => round($profitBeforeOwner, 2),
            'profit_before_owner_week'=> round($profitBeforeOwner / $weeks, 2),
            'gross_margin_pct'        => $revenue > 0 ? round(($revenue - $crewLabour - $expenses) / $revenue * 100, 1) : null,
            'owner_hours'             => round($ownerTotalH, 1),
            'owner_hours_week'        => round($ownerHoursWeek, 1),
            'owner_field_hours_week'  => round($ownerFieldH / $weeks, 1),
            'owner_admin_hours_week'  => round($ownerAdminH / $weeks, 1),
            'owner_labour_cost'       => round($ownerTotalH * (float)$s['owner_rate'], 2),
            'field_replacement_rate'  => round($fieldRate, 2),
            'admin_replacement_rate'  => round($adminRate, 2),
            'replacement_cost'        => round($replacement, 2),
            'replacement_cost_week'   => round($replacement / $weeks, 2),
            'replacement_field_week'  => round($replaceField / $weeks, 2),
            'replacement_admin_week'  => round($replaceAdmin / $weeks, 2),
            'profit_after_replacement'=> round($profitAfter, 2),
            'available_week'          => round($profitAfter / $weeks, 2),
            'coverage_now_pct'        => $coverageNow === null ? null : round($coverageNow, 1),
            'freedom_pct'             => $freedomCov === null ? null : round($freedomCov, 1),
            'freedom_score'           => $score,
            'gap_period'              => round(max(0, $targetPeriod - $profitAfter), 2),
            'gap_week'                => round(max(0, ($targetPeriod - $profitAfter) / $weeks), 2),
            'surplus_week'            => round(max(0, ($profitAfter - $targetPeriod) / $weeks), 2),
            'owner_revenue_share_pct' => $visitTotal > 0 ? round($visitOwner / $visitTotal * 100, 1) : null,
            'visit_revenue_total'     => round($visitTotal, 2),
            'visit_revenue_owner'     => round($visitOwner, 2),
            'visit_revenue_crew_only' => round(max(0, $visitTotal - $visitOwner), 2),
            'recurring_share_pct'     => $visitTotal > 0 ? round($recurring / $visitTotal * 100, 1) : null,
            'top_client_share_pct'    => isset($in['top_client_share_pct']) ? round((float)$in['top_client_share_pct'], 1) : null,
            'top_client_name'         => $in['top_client_name'] ?? null,
            'outstanding'             => round((float)($in['outstanding'] ?? 0), 2),
            'overdue'                 => round((float)($in['overdue'] ?? 0), 2),
            'stage'                   => $stage,
            'replacement_mode'        => $mode,
            'planned_hires'           => $plannedRows,
            'planned_weekly'          => round($plannedWeekly, 2),
            'season_weeks_year'       => round($seasonWeeksYear, 1),
            'season_weeks_in_period'  => round($seasonWeeksHere, 1),
            'cheque_weeks_year'       => $chequeWeeksYear,
            'variable_cost_pct'       => $varRatio === null ? null : round($varRatio * 100, 1),
            'turnover_needed_year'    => $needYear === null ? null : round($needYear, 0),
            'turnover_needed_season_week' => $needSeasonWeek === null ? null : round($needSeasonWeek, 0),
            'turnover_now_week'       => round($revenue / $weeks, 0),
            'turnover_gap_week'       => $needSeasonWeek === null ? null : round(max(0, $needSeasonWeek - $revenue / $weeks), 0),
            'cost_stack_year'         => [
                'cheque'      => round($chequeYear, 0),
                'crew'        => round($crewSeason, 0),
                'overhead'    => round($fixedOhYear, 0),
            ],
        ];
    }

    /** Where the owner sits on the operator → free spectrum. */
    public static function stage(?float $freedomPct, float $ownerHoursWeek): array
    {
        if ($freedomPct === null) {
            return ['key' => 'unknown', 'label' => 'Not measurable yet', 'blurb' => 'Set your pay rate and target hours below to score the business.'];
        }
        if ($freedomPct >= 100 && $ownerHoursWeek < 10) {
            return ['key' => 'free', 'label' => 'Free', 'blurb' => 'The business pays your full cheque without your hours. Protect it: watch concentration and margins.'];
        }
        if ($freedomPct >= 100) {
            return ['key' => 'owner', 'label' => 'Owner', 'blurb' => 'The numbers already work without you — the remaining job is handing off your hours.'];
        }
        if ($freedomPct >= 60) {
            return ['key' => 'manager', 'label' => 'Manager', 'blurb' => 'Most of your cheque is covered without you. Close the gap with margin and recurring work.'];
        }
        if ($freedomPct >= 25) {
            return ['key' => 'builder', 'label' => 'Builder', 'blurb' => 'The business earns real profit but still leans on your labour. Grow crew-delivered revenue.'];
        }
        return ['key' => 'operator', 'label' => 'Operator', 'blurb' => 'Right now you are the business. Every direction below is about changing that.'];
    }

    /**
     * Pure: ranked directions with a dollar or hour size on each.
     * $quality is the data-quality list so a shaky dataset is flagged first.
     */
    public static function recommend(array $m, array $in, array $s, array $quality = []): array
    {
        $out   = [];
        $weeks = max(0.142857, (float)($m['weeks'] ?? 1));
        $gapW  = (float)($m['gap_week'] ?? 0);
        $rev   = (float)($m['revenue'] ?? 0);
        $revW  = $rev / $weeks;

        // 0. Trust the data before acting on it
        $fails = array_values(array_filter($quality, fn($q) => ($q['status'] ?? '') === 'fail'));
        if ($fails) {
            $names = array_map(fn($q) => $q['label'], array_slice($fails, 0, 3));
            $out[] = [
                'key'     => 'fix-tracking',
                'rank'    => 'first',
                'title'   => 'Fix tracking before trusting this score',
                'why'     => count($fails) . ' tracking check' . (count($fails) === 1 ? '' : 's') . ' failed: ' . implode(', ', $names) . '.',
                'action'  => 'Work through the red items in the Tracking panel below. Until they pass, the score is a guess.',
                'impact'  => 'Accuracy',
                'href'    => '#mwFreedomQuality',
            ];
        }

        if ($m['freedom_pct'] === null) {
            $out[] = [
                'key' => 'set-target', 'rank' => 'first',
                'title' => 'Set your pay rate and hours',
                'why' => 'Without a target cheque there is nothing to measure against.',
                'action' => 'Open Settings below and enter your hourly rate and the 40 h/week you draw.',
                'impact' => 'Unlocks the score', 'href' => '#mwFreedomSettings',
            ];
            return $out;
        }

        // 0b. Turnover target (always shown when computable and there is a gap)
        if (!empty($m['turnover_needed_season_week']) && (float)$m['turnover_gap_week'] > 0) {
            $crewNames = $m['replacement_mode'] === 'planned'
                ? implode(' + ', array_map(fn($h) => $h['name'], $m['planned_hires']))
                : 'crew hired for your hours';
            $out[] = [
                'key'    => 'turnover-target',
                'rank'   => 'lever',
                'title'  => 'Turn over $' . number_format((float)$m['turnover_needed_season_week'], 0) . ' a week in season to cover your work',
                'why'    => 'That pays ' . $crewNames . ' ($' . number_format((float)$m['cost_stack_year']['crew'], 0) . '/yr), your cheque ($' . number_format((float)$m['cost_stack_year']['cheque'], 0) . '/yr)'
                            . ((float)$m['cost_stack_year']['overhead'] > 0 ? ', fixed overhead ($' . number_format((float)$m['cost_stack_year']['overhead'], 0) . '/yr)' : '')
                            . ' and the ' . number_format((float)$m['variable_cost_pct'], 0) . '% of every dollar that goes to other crew, materials and fuel. You averaged $' . number_format((float)$m['turnover_now_week'], 0) . '/week in this period.',
                'action' => 'Close the $' . number_format((float)$m['turnover_gap_week'], 0) . '/week gap with the price and recurring-client levers below — or lower the target by entering fixed overhead accurately and cutting the variable-cost share.',
                'impact' => '$' . number_format((float)$m['turnover_needed_year'], 0) . '/yr turnover',
                'href'   => '#mwFreedomSettings',
            ];
        }

        // 1. Replace the owner's field hours
        $fieldHW = (float)($m['owner_field_hours_week'] ?? 0);
        if (($m['replacement_mode'] ?? 'hours') === 'planned' && $fieldHW >= 2) {
            $out[] = [
                'key'    => 'hand-off-field',
                'rank'   => 'lever',
                'title'  => 'Hand your ' . number_format((float)$m['owner_hours_week'], 1) . ' h/week to ' . implode(' and ', array_map(fn($h) => $h['name'], $m['planned_hires'])),
                'why'    => 'Your replacement crew costs $' . number_format((float)$m['planned_weekly'], 0) . '/week loaded for ' . number_format((float)$m['season_weeks_year'], 0) . ' season weeks — already subtracted from your score.',
                'action' => 'Make them the default crew on every plan you are on today, and schedule yourself onto nothing new. Track your hours falling on the trend chart.',
                'impact' => '−' . number_format((float)$m['owner_hours_week'], 1) . ' h/week for you',
                'href'   => '/crm/jobs/plans.php',
            ];
        } elseif ($fieldHW >= 2) {
            $costW = (float)($m['replacement_field_week'] ?? 0);
            $out[] = [
                'key'    => 'hand-off-field',
                'rank'   => 'lever',
                'title'  => 'Hand off ' . number_format($fieldHW, 1) . ' field hours a week',
                'why'    => 'You are still delivering work on site. Those hours only stop when someone else is scheduled for them.',
                'action' => 'Assign a crew lead to every recurring plan you are on today (Plans → default crew). Hiring or extending hours to cover this costs about $' . number_format($costW, 0) . '/week at $' . number_format((float)$m['field_replacement_rate'], 2) . '/h loaded — already subtracted from your score.',
                'impact' => '−' . number_format($fieldHW, 1) . ' h/week for you',
                'href'   => '/crm/jobs/plans.php',
            ];
        }

        // 2. Delegate admin hours (in planned mode the hires cover everything)
        $adminHW = (float)($m['owner_admin_hours_week'] ?? 0);
        if ($adminHW >= 4 && ($m['replacement_mode'] ?? 'hours') !== 'planned') {
            $bits = [];
            if (!empty($in['owner_quotes']))   $bits[] = (int)$in['owner_quotes'] . ' quotes';
            if (!empty($in['owner_invoices'])) $bits[] = (int)$in['owner_invoices'] . ' invoices';
            $created = $bits ? ' In this period you personally created ' . implode(' and ', $bits) . '.' : '';
            $out[] = [
                'key'    => 'delegate-admin',
                'rank'   => 'lever',
                'title'  => 'Delegate ' . number_format($adminHW, 1) . ' office hours a week',
                'why'    => 'Clocked time that is not on a job is quoting, invoicing, scheduling and chasing payment.' . $created,
                'action' => 'Turn on contract auto-billing and Autopay so invoices go out without you, and give a lead the quotes.create permission. A part-time admin at $' . number_format((float)$m['admin_replacement_rate'], 0) . '/h is about $' . number_format((float)$m['replacement_admin_week'], 0) . '/week.',
                'impact' => '−' . number_format($adminHW, 1) . ' h/week for you',
                'href'   => '/crm/settings.php',
            ];
        }

        // 3. Owner-dependent revenue
        $share = $m['owner_revenue_share_pct'];
        if ($share !== null && $share > 40) {
            $out[] = [
                'key'    => 'owner-dependent-revenue',
                'rank'   => 'risk',
                'title'  => number_format($share, 0) . '% of visit revenue happens only when you are on site',
                'why'    => '$' . number_format((float)$m['visit_revenue_owner'], 0) . ' of $' . number_format((float)$m['visit_revenue_total'], 0) . ' in this period was on visits you attended. That revenue disappears the week you stop.',
                'action' => 'Pair yourself with the same crew member on every visit for four weeks, then drop yourself from the plan. Track this number falling.',
                'impact' => 'Goal: under 20%',
                'href'   => '/crm/jobs/schedule.php',
            ];
        }

        // 4. Close the money gap — price
        if ($gapW > 0 && $revW > 0) {
            $pct = $gapW / $revW * 100;
            $out[] = [
                'key'    => 'price',
                'rank'   => 'lever',
                'title'  => 'Raise prices ' . number_format(min($pct, 99), 1) . '% to close the gap on your own',
                'why'    => 'You are $' . number_format($gapW, 0) . '/week short of your cheque once your hours are bought back. Price is the only lever that adds profit without adding hours.',
                'action' => $pct > 15
                    ? 'Too big for one move. Take 5–8% at the next renewal (contracts → renewal increase %) and combine with the recurring and margin levers.'
                    : 'Set renewal_increase_pct on active contracts and reprice per-visit plans at renewal. Nobody leaves over single digits.',
                'impact' => '+$' . number_format($gapW, 0) . '/week',
                'href'   => '/crm/contracts_appstack.php',
            ];
        }

        // 5. Close the gap — more recurring work at current margin
        $marginPct = $m['gross_margin_pct'];
        if ($gapW > 0 && $marginPct !== null && $marginPct > 0) {
            $neededRevW  = $gapW / ($marginPct / 100);
            $avgPlanM    = (float)($in['avg_plan_monthly_value'] ?? 0);
            $clients     = $avgPlanM > 0 ? (int)ceil(($neededRevW * 4.345) / $avgPlanM) : null;
            $out[] = [
                'key'    => 'grow-recurring',
                'rank'   => 'lever',
                'title'  => $clients !== null
                    ? 'Add about ' . $clients . ' recurring clients delivered by crew'
                    : 'Add $' . number_format($neededRevW, 0) . '/week of crew-delivered revenue',
                'why'    => 'At your current ' . number_format($marginPct, 0) . '% gross margin, every $1 of new revenue keeps $' . number_format($marginPct / 100, 2) . '. Recurring plans compound; one-offs need re-selling.',
                'action' => 'Only count a client toward this when a crew lead — not you — is the default crew on the plan. Sell maintenance agreements, not single visits.',
                'impact' => '+$' . number_format($neededRevW, 0) . '/week revenue',
                'href'   => '/crm/quotes/index.php',
            ];
        }

        // 6. Recurring share
        $recShare = $m['recurring_share_pct'];
        if ($recShare !== null && $recShare < 60) {
            $out[] = [
                'key'    => 'recurring-share',
                'rank'   => 'risk',
                'title'  => 'Only ' . number_format($recShare, 0) . '% of visit revenue is recurring',
                'why'    => 'A business that must be re-sold every month needs its owner to keep selling. Recurring work runs on a schedule.',
                'action' => 'Convert repeat one-off clients to a seasonal or monthly plan at their next visit. Aim for 70%+.',
                'impact' => 'Goal: 70% recurring',
                'href'   => '/crm/jobs/plans.php',
            ];
        }

        // 7. Weak-margin service
        if (!empty($in['weak_service'])) {
            $ws = $in['weak_service'];
            $svc = ucwords(str_replace('_', ' ', (string)$ws['service_type']));
            if ((float)$ws['margin_pct'] < -100) {
                // A margin this negative is a pricing-data problem, not a business one.
                $out[] = [
                    'key'    => 'weak-margin-data',
                    'rank'   => 'first',
                    'title'  => $svc . ' visits are recorded far below cost',
                    'why'    => 'Margin snapshots show ' . number_format((float)$ws['margin_pct'], 0) . '% — the visit amount is near zero while labour was logged. That is almost always a plan with no price, not a real loss.',
                    'action' => 'Open the plans for this service and give each a per-visit or monthly price, then run the margin snapshot backfill.',
                    'impact' => 'Accuracy',
                    'href'   => '/crm/jobs/plans.php',
                ];
            } else {
            $out[] = [
                'key'    => 'weak-margin',
                'rank'   => 'lever',
                'title'  => $svc . ' runs at ' . number_format((float)$ws['margin_pct'], 0) . '% margin',
                'why'    => 'It brought in $' . number_format((float)$ws['revenue'], 0) . ' this period but keeps little of it. Low-margin work needs more of your hours per dollar of profit.',
                'action' => 'Reprice it, tighten the crew size or minutes on those plans, or stop offering it.',
                'impact' => 'Lift to 35%+',
                'href'   => '/crm/profitability_appstack.php',
            ];
            }
        }

        // 8. Concentration
        if ($m['top_client_share_pct'] !== null && $m['top_client_share_pct'] > 25) {
            $out[] = [
                'key'    => 'concentration',
                'rank'   => 'risk',
                'title'  => ($m['top_client_name'] ? (string)$m['top_client_name'] : 'One client') . ' is ' . number_format((float)$m['top_client_share_pct'], 0) . '% of revenue',
                'why'    => 'Income you cannot lose is not income you can retire on. Losing this account undoes the whole plan.',
                'action' => 'Lock them into a multi-year contract with auto-renew, and treat every new client as diluting this number.',
                'impact' => 'Goal: no client over 15%',
                'href'   => '/crm/clients_appstack.php',
            ];
        }

        // 9. Cash owed
        if ((float)$m['overdue'] > 0) {
            $out[] = [
                'key'    => 'collect',
                'rank'   => 'cash',
                'title'  => 'Collect $' . number_format((float)$m['overdue'], 0) . ' that is overdue',
                'why'    => 'Profit on paper does not pay your cheque. Late money is also the admin work that keeps you at the desk.',
                'action' => 'Send reminders from Invoices and move repeat late payers to Autopay so this stops being your job.',
                'impact' => '+$' . number_format((float)$m['overdue'], 0) . ' cash',
                'href'   => '/crm/invoices/index.php?status=overdue',
            ];
        }

        // 10. Already there
        if ($gapW <= 0 && $out === []) {
            $out[] = [
                'key'    => 'protect',
                'rank'   => 'done',
                'title'  => 'You are covered — now protect it',
                'why'    => 'The business would pay your full cheque with a $' . number_format((float)$m['surplus_week'], 0) . '/week surplus after replacing your hours.',
                'action' => 'Keep your clocked hours falling, keep recurring share above 70%, and bank the surplus as a buffer.',
                'impact' => '+$' . number_format((float)$m['surplus_week'], 0) . '/week surplus',
                'href'   => '/crm/accounting_appstack.php',
            ];
        }

        return $out;
    }

    /**
     * Pure: monthly freedom score series from per-month raw rows.
     * Each row: month (YYYY-MM), weeks, revenue, crew_labour_cost, expenses,
     * owner_clock_minutes, owner_field_minutes, visit_revenue_total, visit_revenue_owner, crew_avg_rate.
     */
    public static function scoreTrend(array $months, array $settings): array
    {
        $out = [];
        foreach ($months as $row) {
            $m = self::compute($row, $settings);
            $out[] = [
                'month'             => $row['month'],
                'label'             => date('M y', strtotime($row['month'] . '-01')),
                'freedom_pct'       => $m['freedom_pct'],
                'coverage_now_pct'  => $m['coverage_now_pct'],
                'owner_hours_week'  => $m['owner_hours_week'],
                'revenue'           => $m['revenue'],
                'available_week'    => $m['available_week'],
                'target_week'       => $m['target_week'],
                'visit_revenue_owner'     => $m['visit_revenue_owner'],
                'visit_revenue_crew_only' => $m['visit_revenue_crew_only'],
            ];
        }
        return $out;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DATA QUALITY — "what needs tracking for this to be precise"
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Pure: turn raw counts into a checklist. Each item: key, label, status
     * (ok|warn|fail|unknown), detail, why, fix, href.
     */
    public static function dataQualityFromCounts(array $c, array $s, array $in = []): array
    {
        $q = [];
        $pct = function ($num, $den): ?float {
            if ($den === null || $num === null) return null;
            return $den > 0 ? round($num / $den * 100, 0) : null;
        };
        $add = function (string $key, string $label, string $status, string $detail, string $why, string $fix, string $href = '') use (&$q) {
            $q[] = compact('key', 'label', 'status', 'detail', 'why', 'fix', 'href');
        };

        // Owner identity + pay
        $add('owner', 'Owner selected',
            $s['owner_user_id'] > 0 ? ($s['owner_explicit'] ? 'ok' : 'warn') : 'fail',
            $s['owner_user_id'] > 0 ? ($s['owner_name'] ?? 'user #' . $s['owner_user_id']) . ($s['owner_explicit'] ? '' : ' (assumed: first admin)') : 'Nobody chosen',
            'Every hour and every visit is split into "with you" and "without you" by this one user id.',
            'Pick yourself in Settings so the split never guesses.', '#mwFreedomSettings');

        $add('owner_rate', 'Your pay rate set',
            $s['owner_rate'] > 0 ? 'ok' : 'fail',
            $s['owner_rate'] > 0 ? '$' . number_format($s['owner_rate'], 2) . '/h × ' . number_format($s['target_hours_week'], 0) . ' h/week' : 'No rate',
            'The target cheque is hours × rate. Zero rate means a zero target and a meaningless score.',
            'Enter the hourly figure your 40-hour pay is based on.', '#mwFreedomSettings');

        // Owner clocks in
        $shifts = $c['owner_shifts_28d'] ?? null;
        $add('owner_clocking', 'You clock every working day',
            $shifts === null ? 'unknown' : ($shifts >= 12 ? 'ok' : ($shifts > 0 ? 'warn' : 'fail')),
            $shifts === null ? 'Cannot read time_clock_entries' : $shifts . ' shifts in the last 28 days',
            'Your clocked hours ARE the thing being replaced. Un-clocked office days make you look freer than you are.',
            'Clock in for office days too, not just field days — the app works at the desk.', '/crm/timeclock/my-timesheet.php');

        // Owner field time recorded on visits
        $ofv = $c['owner_visits_with_time'] ?? null; $ov = $c['owner_visits'] ?? null;
        $p = $pct($ofv, $ov);
        $add('owner_visit_time', 'Your visits have job timers',
            $ov === null ? 'unknown' : ($ov === 0 ? 'ok' : ($p >= 90 ? 'ok' : ($p >= 60 ? 'warn' : 'fail'))),
            $ov === null ? 'Cannot read job_time_entries' : ($ov === 0 ? 'No visits attributed to you in period' : "$ofv of $ov visits you were on have a timer"),
            'Field hours are priced at crew replacement cost; admin hours at office cost. Without timers everything is billed as office time.',
            'Start the job timer on every stop (auto-start on arrival handles this when GPS is on).', '/crm/timeclock/timesheets.php');

        // Crew wages
        $noRate = $c['users_without_rate'] ?? null;
        $add('crew_rates', 'Every active user has an hourly rate',
            $noRate === null ? 'unknown' : ($noRate === 0 ? 'ok' : 'fail'),
            $noRate === null ? 'Cannot read users' : ($noRate === 0 ? 'All set' : "$noRate user(s) missing a rate — costed at \$25/h"),
            'Crew labour cost and your replacement rate both come from users.hourly_rate.',
            'Set the rate on each team member in Team settings.', '/crm/team/index.php');

        // Visits attributed to someone
        $cv = $c['completed_visits'] ?? null;
        $p = $pct($c['visits_attributed'] ?? null, $cv);
        $add('visits_attributed', 'Completed visits name their crew',
            $cv === null ? 'unknown' : ($cv === 0 ? 'warn' : ($p >= 95 ? 'ok' : ($p >= 75 ? 'warn' : 'fail'))),
            $cv === null ? 'Cannot read job_visits' : ($cv === 0 ? 'No completed visits in period' : ($c['visits_attributed'] ?? 0) . " of $cv visits have crew recorded"),
            'A visit with no crew cannot be classed as "with you" or "without you", so its revenue drops out of the split.',
            'Assign crew on the schedule before the day starts; the crew app records who actually attended.', '/crm/jobs/schedule.php');

        // Visits timed
        $p = $pct($c['visits_timed'] ?? null, $cv);
        $add('visits_timed', 'Completed visits have labour time',
            $cv === null ? 'unknown' : ($cv === 0 ? 'warn' : ($p >= 90 ? 'ok' : ($p >= 70 ? 'warn' : 'fail'))),
            $cv === null ? '—' : ($cv === 0 ? '—' : ($c['visits_timed'] ?? 0) . " of $cv visits have job time entries"),
            'Crew labour is the largest cost. Un-timed visits make labour look cheaper than it is and inflate the score.',
            'Every crew member starts and ends the job timer on each stop.', '/crm/timeclock/timesheets.php');

        // Visits priced
        $p = $pct($c['visits_priced'] ?? null, $cv);
        $add('visits_priced', 'Completed visits carry a price',
            $cv === null ? 'unknown' : ($cv === 0 ? 'warn' : ($p >= 95 ? 'ok' : ($p >= 80 ? 'warn' : 'fail'))),
            $cv === null ? '—' : ($cv === 0 ? '—' : ($c['visits_priced'] ?? 0) . " of $cv visits have an amount"),
            'The with/without-you revenue split uses the visit amount or the plan price. Unpriced visits count as $0.',
            'Give every plan a per-visit or monthly price; enter actual_amount when a visit differs.', '/crm/jobs/plans.php');

        // Uninvoiced completed work
        $uninv = $c['uninvoiced_visits'] ?? null;
        $add('visits_invoiced', 'Completed work is invoiced',
            $uninv === null ? 'unknown' : ($uninv === 0 ? 'ok' : ($uninv <= 5 ? 'warn' : 'fail')),
            $uninv === null ? '—' : ($uninv === 0 ? 'Nothing older than 14 days waiting' : "$uninv completed visits over 14 days old not invoiced"),
            'Revenue here is invoiced revenue. One-off work you did but never billed makes the business look weaker than it is (contract visits bill monthly and are not counted).',
            'Run contract billing or invoice the visits from the Jobs list.', '/crm/jobs/index.php');

        // Expenses tracked
        $exp30 = $c['expenses_30d'] ?? null;
        $add('expenses_recent', 'Expenses are being recorded',
            $exp30 === null ? 'unknown' : ($exp30 >= 8 ? 'ok' : ($exp30 > 0 ? 'warn' : 'fail')),
            $exp30 === null ? 'Cannot read expenses' : "$exp30 receipts in the last 30 days",
            'Every untracked fuel fill, blade or dump fee is fake profit in this score.',
            'Photograph every receipt in the crew app the day it happens; approve them weekly.', '/crm/expenses_appstack.php');

        // Expenses categorised
        $p = $pct($c['expenses_categorised'] ?? null, $c['expenses_total'] ?? null);
        $add('expenses_categorised', 'Expenses have a category',
            !isset($c['expenses_total']) ? 'unknown' : (($c['expenses_total'] ?? 0) === 0 ? 'warn' : ($p >= 90 ? 'ok' : 'warn')),
            !isset($c['expenses_total']) ? '—' : (($c['expenses_total'] ?? 0) === 0 ? 'No expenses in period' : ($c['expenses_categorised'] ?? 0) . ' of ' . $c['expenses_total'] . ' categorised'),
            'Payroll-type categories are excluded so wages are not counted twice. Uncategorised receipts cannot be told apart.',
            'Categorise on approval; set vendor rules so it happens automatically.', '/crm/expenses_appstack.php');

        // Fixed overhead
        $add('fixed_overhead', 'Fixed monthly overhead entered',
            $s['fixed_overhead_month'] > 0 ? 'ok' : 'warn',
            $s['fixed_overhead_month'] > 0 ? '$' . number_format($s['fixed_overhead_month'], 0) . '/month' : 'Not set',
            'Insurance, phone, software, loan payments and vehicle leases rarely arrive as receipts, so they are missing from Expenses.',
            'Add up a typical month of those bills and enter it once in Settings.', '#mwFreedomSettings');

        // Margin snapshots
        $p = $pct($c['visits_snapshotted'] ?? null, $cv);
        $add('margin_snapshots', 'Visits have margin snapshots',
            $cv === null || !isset($c['visits_snapshotted']) ? 'unknown' : ($cv === 0 ? 'warn' : ($p >= 90 ? 'ok' : 'warn')),
            $cv === null || !isset($c['visits_snapshotted']) ? 'Cannot read visit_margin_snapshots' : ($cv === 0 ? '—' : ($c['visits_snapshotted'] ?? 0) . " of $cv"),
            'The weak-service direction and Profitability page read from snapshots written at completion.',
            'Run the margin snapshot backfill once, then complete visits through the app.', '/crm/api/backfill-margin-snapshots.php');

        return $q;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // LOADERS (thin SQL; every one degrades to null/0 on failure)
    // ══════════════════════════════════════════════════════════════════════════

    /** Owner-presence predicate on alias jv, bound with the owner id 3 times. */
    private function ownerPresentSql(): string
    {
        return "(jv.assigned_crew_id = ?
                 OR EXISTS (SELECT 1 FROM visit_crew_assignments vca WHERE vca.visit_id = jv.id AND vca.user_id = ?)
                 OR EXISTS (SELECT 1 FROM job_time_entries ote WHERE ote.visit_id = jv.id AND ote.user_id = ? AND ote.status IN ('completed','edited')))";
    }

    private const VISIT_AMOUNT = "COALESCE(jv.actual_amount, vms.quoted_amount, jp.price_per_visit,
        CASE WHEN jp.pricing_model = 'monthly_flat' THEN jp.monthly_flat_price / 4.345 END, 0)";

    private function one(string $sql, array $params, $default = null)
    {
        try {
            $st = $this->db->prepare($sql);
            $st->execute($params);
            $v = $st->fetchColumn();
            return $v === false ? $default : $v;
        } catch (Throwable $e) {
            return $default;
        }
    }

    private function row(string $sql, array $params): ?array
    {
        try {
            $st = $this->db->prepare($sql);
            $st->execute($params);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function labourCategoryNotIn(): string
    {
        return "(accounting_category IS NULL OR LOWER(accounting_category) NOT IN ('" . implode("','", self::LABOUR_CATEGORIES) . "'))";
    }

    public function loadInputs(string $from, string $to, array $s): array
    {
        $owner = (int)$s['owner_user_id'];
        $days  = (new DateTime($from))->diff(new DateTime($to))->days + 1;
        $in    = ['weeks' => $days / 7, 'days' => $days,
                  'season_weeks' => self::seasonWeeksInRange($from, $to, (int)$s['season_start_month'], (int)$s['season_end_month'])];

        // Money truth
        $inv = $this->row("
            SELECT COALESCE(SUM(total),0) AS revenue, COALESCE(SUM(amount_paid),0) AS collected
            FROM invoices WHERE issue_date BETWEEN ? AND ? AND status NOT IN ('draft','cancelled')", [$from, $to]);
        $in['revenue']   = (float)($inv['revenue'] ?? 0);
        $in['collected'] = (float)($inv['collected'] ?? 0);

        $in['outstanding'] = (float)$this->one("SELECT COALESCE(SUM(balance_due),0) FROM invoices WHERE status IN ('sent','viewed','partial','overdue')", [], 0);
        $in['overdue']     = (float)$this->one("SELECT COALESCE(SUM(balance_due),0) FROM invoices WHERE status IN ('sent','viewed','partial','overdue') AND due_date < CURDATE()", [], 0);

        // Labour
        $in['crew_labour_cost'] = (float)$this->one("
            SELECT COALESCE(SUM(jte.duration_minutes * COALESCE(NULLIF(u.hourly_rate,0), 25) / 60), 0)
            FROM job_time_entries jte JOIN users u ON u.id = jte.user_id
            WHERE jte.status IN ('completed','edited') AND jte.user_id <> ?
              AND DATE(jte.start_time) BETWEEN ? AND ?", [$owner, $from, $to], 0);
        $in['owner_field_minutes'] = (float)$this->one("
            SELECT COALESCE(SUM(duration_minutes),0) FROM job_time_entries
            WHERE status IN ('completed','edited') AND user_id = ? AND DATE(start_time) BETWEEN ? AND ?", [$owner, $from, $to], 0);
        $in['owner_clock_minutes'] = (float)$this->one("
            SELECT COALESCE(SUM(COALESCE(total_minutes, TIMESTAMPDIFF(MINUTE, clock_in, clock_out))),0) FROM time_clock_entries
            WHERE status IN ('completed','edited') AND user_id = ? AND DATE(clock_in) BETWEEN ? AND ?", [$owner, $from, $to], 0);
        $in['crew_avg_rate'] = (float)$this->one("
            SELECT AVG(hourly_rate) FROM users WHERE is_active = 1 AND id <> ? AND hourly_rate > 0", [$owner], 0);

        // Expenses (non-labour)
        $in['expenses'] = (float)$this->one("
            SELECT COALESCE(SUM(total),0) FROM expenses
            WHERE status IN ('draft','approved','forwarded') AND expense_date BETWEEN ? AND ? AND " . $this->labourCategoryNotIn(), [$from, $to], 0);

        // Visit attribution
        $amt = self::VISIT_AMOUNT;
        $vis = $this->row("
            SELECT COALESCE(SUM($amt),0) AS total,
                   COALESCE(SUM(CASE WHEN " . $this->ownerPresentSql() . " THEN $amt ELSE 0 END),0) AS owner_total,
                   COALESCE(SUM(CASE WHEN jp.is_recurring = 1 OR jp.pricing_model IN ('monthly_flat','seasonal') THEN $amt ELSE 0 END),0) AS recurring,
                   COUNT(*) AS visits
            FROM job_visits jv
            JOIN job_plans jp ON jp.id = jv.plan_id
            LEFT JOIN visit_margin_snapshots vms ON vms.visit_id = jv.id
            WHERE jv.status = 'completed' AND jv.scheduled_date BETWEEN ? AND ?", [$owner, $owner, $owner, $from, $to]);
        if ($vis === null) {
            // visit_margin_snapshots may not exist — retry without it
            $amt2 = "COALESCE(jv.actual_amount, jp.price_per_visit, CASE WHEN jp.pricing_model = 'monthly_flat' THEN jp.monthly_flat_price / 4.345 END, 0)";
            $vis = $this->row("
                SELECT COALESCE(SUM($amt2),0) AS total,
                       COALESCE(SUM(CASE WHEN " . $this->ownerPresentSql() . " THEN $amt2 ELSE 0 END),0) AS owner_total,
                       COALESCE(SUM(CASE WHEN jp.is_recurring = 1 OR jp.pricing_model IN ('monthly_flat','seasonal') THEN $amt2 ELSE 0 END),0) AS recurring,
                       COUNT(*) AS visits
                FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id
                WHERE jv.status = 'completed' AND jv.scheduled_date BETWEEN ? AND ?", [$owner, $owner, $owner, $from, $to]);
        }
        $in['visit_revenue_total'] = (float)($vis['total'] ?? 0);
        $in['visit_revenue_owner'] = (float)($vis['owner_total'] ?? 0);
        $in['recurring_revenue']   = (float)($vis['recurring'] ?? 0);
        $in['visits_completed']    = (int)($vis['visits'] ?? 0);

        // Average monthly value of an active recurring plan (for "how many clients")
        $in['avg_plan_monthly_value'] = (float)$this->one("
            SELECT AVG(CASE
                WHEN pricing_model = 'monthly_flat' THEN monthly_flat_price
                WHEN pricing_model = 'per_visit' AND recurrence_pattern = 'weekly'   THEN price_per_visit * 4.345
                WHEN pricing_model = 'per_visit' AND recurrence_pattern = 'biweekly' THEN price_per_visit * 2.17
                WHEN pricing_model = 'per_visit' AND recurrence_pattern = 'monthly'  THEN price_per_visit
                WHEN pricing_model = 'seasonal' THEN seasonal_price / 7
                ELSE price_per_visit END)
            FROM job_plans WHERE status = 'active' AND (is_recurring = 1 OR pricing_model IN ('monthly_flat','seasonal'))", [], 0);

        // Client concentration (invoice payer, best-effort across the 3-way payer split)
        $top = $this->row("
            SELECT COALESCE(c.company_name, CONCAT(ct.first_name, ' ', ct.last_name), i.bill_to_name, 'Unknown') AS name, SUM(i.total) AS total
            FROM invoices i
            LEFT JOIN companies c ON c.id = i.company_id
            LEFT JOIN contacts ct ON ct.id = i.contact_id
            WHERE i.issue_date BETWEEN ? AND ? AND i.status NOT IN ('draft','cancelled')
            GROUP BY COALESCE(i.company_id, 0), COALESCE(i.contact_id, 0), COALESCE(i.bill_to_name, '')
            ORDER BY total DESC LIMIT 1", [$from, $to]);
        if ($top && $in['revenue'] > 0) {
            $in['top_client_name']      = $top['name'];
            $in['top_client_share_pct'] = (float)$top['total'] / $in['revenue'] * 100;
        }

        // Owner's admin footprint
        $in['owner_quotes']   = (int)$this->one("SELECT COUNT(*) FROM quotes WHERE created_by = ? AND DATE(created_at) BETWEEN ? AND ?", [$owner, $from, $to], 0);
        $in['owner_invoices'] = (int)$this->one("SELECT COUNT(*) FROM invoices WHERE created_by = ? AND DATE(created_at) BETWEEN ? AND ?", [$owner, $from, $to], 0);

        // Weakest service by margin (needs meaningful revenue)
        $weak = $this->row("
            SELECT service_type, SUM(quoted_amount) AS revenue,
                   CASE WHEN SUM(quoted_amount) > 0 THEN SUM(gross_margin) / SUM(quoted_amount) * 100 ELSE NULL END AS margin_pct
            FROM visit_margin_snapshots WHERE visit_date BETWEEN ? AND ?
            GROUP BY service_type HAVING SUM(quoted_amount) > 500 AND margin_pct < 25
            ORDER BY margin_pct ASC LIMIT 1", [$from, $to]);
        if ($weak) $in['weak_service'] = $weak;

        return $in;
    }

    /** Per-month inputs for the last N months, run through compute() via scoreTrend(). */
    public function loadTrend(int $months, array $s): array
    {
        $owner = (int)$s['owner_user_id'];
        $start = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
        $end   = date('Y-m-t');

        $rows = [];
        $d = new DateTime($start);
        for ($i = 0; $i < $months; $i++) {
            $key = $d->format('Y-m');
            $mStart = $d->format('Y-m-01');
            $mEnd   = min($d->format('Y-m-t'), date('Y-m-d'));
            $days   = $mEnd >= $mStart ? ((new DateTime($mStart))->diff(new DateTime($mEnd))->days + 1) : 1;
            $rows[$key] = [
                'month' => $key, 'weeks' => $days / 7, 'revenue' => 0.0, 'crew_labour_cost' => 0.0, 'expenses' => 0.0,
                'owner_clock_minutes' => 0.0, 'owner_field_minutes' => 0.0,
                'visit_revenue_total' => 0.0, 'visit_revenue_owner' => 0.0, 'crew_avg_rate' => 0.0,
            ];
            $d->modify('+1 month');
        }

        $fill = function (string $sql, array $params, string $field) use (&$rows) {
            try {
                $st = $this->db->prepare($sql);
                $st->execute($params);
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                    if (isset($rows[$r['m']])) $rows[$r['m']][$field] = (float)$r['v'];
                }
            } catch (Throwable $e) { /* leave zeros */ }
        };

        $fill("SELECT DATE_FORMAT(issue_date,'%Y-%m') m, SUM(total) v FROM invoices
               WHERE issue_date BETWEEN ? AND ? AND status NOT IN ('draft','cancelled') GROUP BY m", [$start, $end], 'revenue');
        $fill("SELECT DATE_FORMAT(jte.start_time,'%Y-%m') m, SUM(jte.duration_minutes * COALESCE(NULLIF(u.hourly_rate,0),25)/60) v
               FROM job_time_entries jte JOIN users u ON u.id = jte.user_id
               WHERE jte.status IN ('completed','edited') AND jte.user_id <> ? AND DATE(jte.start_time) BETWEEN ? AND ? GROUP BY m", [$owner, $start, $end], 'crew_labour_cost');
        $fill("SELECT DATE_FORMAT(start_time,'%Y-%m') m, SUM(duration_minutes) v FROM job_time_entries
               WHERE status IN ('completed','edited') AND user_id = ? AND DATE(start_time) BETWEEN ? AND ? GROUP BY m", [$owner, $start, $end], 'owner_field_minutes');
        $fill("SELECT DATE_FORMAT(clock_in,'%Y-%m') m, SUM(COALESCE(total_minutes, TIMESTAMPDIFF(MINUTE, clock_in, clock_out))) v FROM time_clock_entries
               WHERE status IN ('completed','edited') AND user_id = ? AND DATE(clock_in) BETWEEN ? AND ? GROUP BY m", [$owner, $start, $end], 'owner_clock_minutes');
        $fill("SELECT DATE_FORMAT(expense_date,'%Y-%m') m, SUM(total) v FROM expenses
               WHERE status IN ('draft','approved','forwarded') AND expense_date BETWEEN ? AND ? AND " . $this->labourCategoryNotIn() . " GROUP BY m", [$start, $end], 'expenses');

        $amt = "COALESCE(jv.actual_amount, jp.price_per_visit, CASE WHEN jp.pricing_model = 'monthly_flat' THEN jp.monthly_flat_price / 4.345 END, 0)";
        try {
            $st = $this->db->prepare("
                SELECT DATE_FORMAT(jv.scheduled_date,'%Y-%m') m, SUM($amt) total,
                       SUM(CASE WHEN " . $this->ownerPresentSql() . " THEN $amt ELSE 0 END) owner_total
                FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id
                WHERE jv.status = 'completed' AND jv.scheduled_date BETWEEN ? AND ? GROUP BY m");
            $st->execute([$owner, $owner, $owner, $start, $end]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                if (isset($rows[$r['m']])) {
                    $rows[$r['m']]['visit_revenue_total'] = (float)$r['total'];
                    $rows[$r['m']]['visit_revenue_owner'] = (float)$r['owner_total'];
                }
            }
        } catch (Throwable $e) { /* zeros */ }

        $crewAvg = (float)$this->one("SELECT AVG(hourly_rate) FROM users WHERE is_active = 1 AND id <> ? AND hourly_rate > 0", [$owner], 0);
        foreach ($rows as &$r) $r['crew_avg_rate'] = $crewAvg;
        unset($r);

        return self::scoreTrend(array_values($rows), $s);
    }

    /** Raw counts for the tracking checklist. null = table unreadable. */
    public function loadQualityCounts(string $from, string $to, array $s): array
    {
        $owner = (int)$s['owner_user_id'];
        $c = [];

        $c['owner_shifts_28d'] = $this->intOrNull("SELECT COUNT(*) FROM time_clock_entries WHERE user_id = ? AND clock_in >= DATE_SUB(CURDATE(), INTERVAL 28 DAY) AND status IN ('completed','edited','active')", [$owner]);
        $c['users_without_rate'] = $this->intOrNull("SELECT COUNT(*) FROM users WHERE is_active = 1 AND (hourly_rate IS NULL OR hourly_rate <= 0)", []);

        $c['completed_visits'] = $this->intOrNull("SELECT COUNT(*) FROM job_visits WHERE status = 'completed' AND scheduled_date BETWEEN ? AND ?", [$from, $to]);
        if ($c['completed_visits'] !== null) {
            $c['visits_attributed'] = $this->intOrNull("
                SELECT COUNT(*) FROM job_visits jv WHERE jv.status = 'completed' AND jv.scheduled_date BETWEEN ? AND ?
                  AND (jv.assigned_crew_id IS NOT NULL
                       OR EXISTS (SELECT 1 FROM visit_crew_assignments vca WHERE vca.visit_id = jv.id)
                       OR EXISTS (SELECT 1 FROM job_time_entries jte WHERE jte.visit_id = jv.id))", [$from, $to]);
            $c['visits_timed'] = $this->intOrNull("
                SELECT COUNT(*) FROM job_visits jv WHERE jv.status = 'completed' AND jv.scheduled_date BETWEEN ? AND ?
                  AND EXISTS (SELECT 1 FROM job_time_entries jte WHERE jte.visit_id = jv.id AND jte.status IN ('completed','edited'))", [$from, $to]);
            $c['visits_priced'] = $this->intOrNull("
                SELECT COUNT(*) FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id
                WHERE jv.status = 'completed' AND jv.scheduled_date BETWEEN ? AND ?
                  AND COALESCE(jv.actual_amount, jp.price_per_visit, jp.monthly_flat_price, jp.seasonal_price, 0) > 0", [$from, $to]);
            $c['visits_snapshotted'] = $this->intOrNull("
                SELECT COUNT(*) FROM job_visits jv WHERE jv.status = 'completed' AND jv.scheduled_date BETWEEN ? AND ?
                  AND EXISTS (SELECT 1 FROM visit_margin_snapshots vms WHERE vms.visit_id = jv.id)", [$from, $to]);
            $c['owner_visits'] = $this->intOrNull("
                SELECT COUNT(*) FROM job_visits jv WHERE jv.status = 'completed' AND jv.scheduled_date BETWEEN ? AND ?
                  AND (jv.assigned_crew_id = ? OR EXISTS (SELECT 1 FROM visit_crew_assignments vca WHERE vca.visit_id = jv.id AND vca.user_id = ?))", [$from, $to, $owner, $owner]);
            $c['owner_visits_with_time'] = $this->intOrNull("
                SELECT COUNT(*) FROM job_visits jv WHERE jv.status = 'completed' AND jv.scheduled_date BETWEEN ? AND ?
                  AND (jv.assigned_crew_id = ? OR EXISTS (SELECT 1 FROM visit_crew_assignments vca WHERE vca.visit_id = jv.id AND vca.user_id = ?))
                  AND EXISTS (SELECT 1 FROM job_time_entries jte WHERE jte.visit_id = jv.id AND jte.user_id = ? AND jte.status IN ('completed','edited'))", [$from, $to, $owner, $owner, $owner]);
        }
        // Only per-visit plans are invoiced visit-by-visit; monthly/seasonal plans bill through contracts.
        $c['uninvoiced_visits'] = $this->intOrNull("
            SELECT COUNT(*) FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id
            WHERE jv.status = 'completed' AND jv.is_invoiced = 0 AND jv.invoice_id IS NULL
              AND jp.pricing_model = 'per_visit' AND jp.is_recurring = 0
              AND jv.scheduled_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND jv.scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY)", []);

        $c['expenses_30d']   = $this->intOrNull("SELECT COUNT(*) FROM expenses WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND status IN ('draft','approved','forwarded')", []);
        $c['expenses_total'] = $this->intOrNull("SELECT COUNT(*) FROM expenses WHERE expense_date BETWEEN ? AND ? AND status IN ('draft','approved','forwarded')", [$from, $to]);
        if ($c['expenses_total'] !== null) {
            $c['expenses_categorised'] = $this->intOrNull("SELECT COUNT(*) FROM expenses WHERE expense_date BETWEEN ? AND ? AND status IN ('draft','approved','forwarded') AND accounting_category IS NOT NULL AND accounting_category <> ''", [$from, $to]);
        }
        return $c;
    }

    private function intOrNull(string $sql, array $params): ?int
    {
        $v = $this->one($sql, $params, null);
        return $v === null ? null : (int)$v;
    }
}
