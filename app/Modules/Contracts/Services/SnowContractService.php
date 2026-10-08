<?php
/**
 * SnowContractService — snow & salt contracts that run themselves once signed.
 *
 * When a snow/salt contract quote is signed online (customer/quote.php), this
 * creates, without anyone touching the office:
 *   1. a per-visit contract carrying the online signature, Nov 1 → Mar 31;
 *   2. ONE daily route plan for the building — a stop every day of the season
 *      (owner decision 2026-10-08: the crew drive the route daily and he checks
 *      weather apps too; a day nobody salts is recorded as "nothing needed").
 *
 * Every rate on the quote (Salt, Arctic -5°C salt, Snow clearing, Top-of-list)
 * becomes a line on that one plan, and snow_route_rates records which is which.
 * A plan's price_per_visit is the SUM of its lines, so billing never uses it:
 * the crew record what they actually did at each stop (visit_service_choices)
 * and the run is invoiced at that rate alone. A stop with no recorded choice is
 * refused rather than billed — billing all four rates for one salt run is the
 * failure this exists to prevent.
 *
 * Global-namespace service (no production autoloader): require_once the file.
 * The pure rules are static and unit-tested; setupFromSignedQuote() is the DB path.
 */
declare(strict_types=1);

class SnowContractService
{
    public const ROLE_SALT     = 'salt';
    public const ROLE_ARCTIC   = 'arctic';
    public const ROLE_SNOW     = 'snow';
    public const ROLE_PRIORITY = 'priority';
    public const ROLE_AREAS    = 'areas';

    /** What the crew can record at a stop. */
    public const CHOICES = ['salt', 'arctic', 'snow', 'none'];

    public const SEASON_START = '11-01';
    public const SEASON_END   = '03-31';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PURE RULES (unit-tested)
    // ══════════════════════════════════════════════════════════════════════

    /** A signed quote this service should set up: a contract quote for snow/salt. */
    public static function isSnowContractQuote(array $quote): bool
    {
        return !empty($quote['is_contract'])
            && in_array((string)($quote['service_type'] ?? ''), ['snow_removal', 'salt_application'], true);
    }

    /**
     * Which rate a quote line is, from its service name. Order matters: the
     * Top-of-list and Arctic lines both contain words from the plainer lines.
     */
    public static function roleForLine(string $name): ?string
    {
        $n = strtolower($name);
        if (strpos($n, 'area') === 0) {
            return self::ROLE_AREAS;
        }
        if (strpos($n, 'top of the list') !== false || strpos($n, 'priority') !== false) {
            return self::ROLE_PRIORITY;
        }
        if (strpos($n, 'arctic') !== false || preg_match('/-\s*5\s*(°|deg)/u', $n)) {
            return self::ROLE_ARCTIC;
        }
        if (strpos($n, 'salt') !== false) {
            return self::ROLE_SALT;
        }
        if (strpos($n, 'snow') !== false) {
            return self::ROLE_SNOW;
        }
        return null;
    }

    /**
     * The plan-line service_type for a rate. The weather guard, salt dashboard and
     * Winter Service Report all select plan lines by these two codes, so a line
     * typed "Salt Only Service" would be invisible to every one of them.
     */
    public static function planLineCode(string $role): string
    {
        switch ($role) {
            case self::ROLE_SALT:
            case self::ROLE_ARCTIC:
                return 'salt_application';
            case self::ROLE_SNOW:
            case self::ROLE_PRIORITY:
                return 'snow_removal';
            default:
                return 'areas';
        }
    }

    /** Which rates a crew choice bills. Snow clearing carries the Top-of-list charge. */
    public static function rolesForChoice(string $choice): array
    {
        switch ($choice) {
            case 'salt':   return [self::ROLE_SALT];
            case 'arctic': return [self::ROLE_ARCTIC];
            case 'snow':   return [self::ROLE_SNOW, self::ROLE_PRIORITY];
            default:       return [];
        }
    }

    /** Plain label for a choice, as the crew and the invoice see it. */
    public static function choiceLabel(string $choice): string
    {
        switch ($choice) {
            case 'salt':   return 'Salted';
            case 'arctic': return 'Arctic salt (-5°C)';
            case 'snow':   return 'Snow cleared';
            case 'none':   return 'Checked - nothing needed';
            default:       return $choice;
        }
    }

    /**
     * Season the contract covers, from the signing date. Signed before the season
     * → the whole season; signed during it → from that day. Uses the same
     * end-anchored season maths as the snow terms template (ContractTermsService).
     *
     * @return array{start:string,end:string}
     */
    public static function seasonFor(string $signedDate): array
    {
        $ts      = strtotime($signedDate);
        $year    = (int)date('Y', $ts);
        $endThis = sprintf('%d-%s', $year, self::SEASON_END);
        $startYr = $signedDate <= $endThis ? $year - 1 : $year;

        $start = sprintf('%d-%s', $startYr, self::SEASON_START);
        $end   = sprintf('%d-%s', $startYr + 1, self::SEASON_END);
        return ['start' => max($start, date('Y-m-d', $ts)), 'end' => $end];
    }

    /**
     * The rate lines of a quote, mapped to roles. Lines that are neither a rate
     * nor the Areas description are skipped (and reported).
     *
     * @param array $quoteLines quote_line_items rows (service_type = the service name)
     * @return array{rates: array<int,array>, unknown: string[]}
     */
    public static function ratesFromQuoteLines(array $quoteLines): array
    {
        $rates   = [];
        $unknown = [];
        foreach ($quoteLines as $line) {
            $name = trim((string)($line['service_type'] ?? ''));
            $role = self::roleForLine($name);
            if ($role === null) {
                $unknown[] = $name;
                continue;
            }
            $rates[] = [
                'role'               => $role,
                'label'              => $name,
                'description'        => (string)($line['description'] ?? ''),
                'unit_price'         => round((float)($line['unit_price'] ?? 0), 2),
                'quote_line_item_id' => isset($line['id']) ? (int)$line['id'] : null,
                'product_id'         => !empty($line['product_id']) ? (int)$line['product_id'] : null,
            ];
        }
        return ['rates' => $rates, 'unknown' => $unknown];
    }

    /**
     * Invoice lines for one run.
     *
     * @param array  $rates  snow_route_rates rows (role, label, unit_price)
     * @param string|null $choice visit_service_choices.choice, or null when not recorded
     * @return array{ok:bool, code?:string, error?:string, lines?:array}
     */
    public static function billingLines(array $rates, ?string $choice, string $visitDate): array
    {
        if ($choice === null || $choice === '') {
            return ['ok' => false, 'code' => 'SERVICE_CHOICE_REQUIRED',
                    'error' => 'Record what was done at this stop (salted, arctic salt, snow cleared or nothing needed) before invoicing it.'];
        }
        if ($choice === 'none') {
            return ['ok' => false, 'code' => 'NOTHING_DONE',
                    'error' => 'This stop was checked and nothing was needed, so there is nothing to invoice.'];
        }
        $roles = self::rolesForChoice($choice);
        $day   = $visitDate ? date('D M j, Y', strtotime($visitDate)) : '';
        $lines = [];
        foreach ($rates as $r) {
            if (in_array($r['role'], $roles, true) && (float)$r['unit_price'] > 0) {
                $lines[] = [
                    'description' => trim($r['label'] . ($day ? ' - ' . $day : '')),
                    'quantity'    => 1,
                    'unit_price'  => round((float)$r['unit_price'], 2),
                    'line_total'  => round((float)$r['unit_price'], 2),
                ];
            }
        }
        if (!$lines) {
            return ['ok' => false, 'code' => 'NO_RATE',
                    'error' => 'This contract has no rate for "' . self::choiceLabel($choice) . '".'];
        }
        return ['ok' => true, 'lines' => $lines];
    }

    // ══════════════════════════════════════════════════════════════════════
    // DB: route plans, choices, billing
    // ══════════════════════════════════════════════════════════════════════

    /** snow_route_rates for a plan; empty when the plan is not a snow route. */
    public function ratesForPlan(int $planId): array
    {
        try {
            $stmt = $this->db->prepare("SELECT role, label, unit_price, plan_line_item_id FROM snow_route_rates WHERE plan_id = ? ORDER BY id");
            $stmt->execute([$planId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return []; // migration 1310 not run here
        }
    }

    public function isRoutePlan(int $planId): bool
    {
        return $planId > 0 && (bool)$this->ratesForPlan($planId);
    }

    public function choiceForVisit(int $visitId): ?string
    {
        try {
            $stmt = $this->db->prepare("SELECT choice FROM visit_service_choices WHERE visit_id = ?");
            $stmt->execute([$visitId]);
            $c = $stmt->fetchColumn();
            return $c === false ? null : (string)$c;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Record (or correct) what was done at a stop. */
    public function recordChoice(int $visitId, string $choice, ?int $userId, string $source = 'crew'): array
    {
        if (!in_array($choice, self::CHOICES, true)) {
            return ['success' => false, 'error' => 'Unknown choice.'];
        }
        $stmt = $this->db->prepare("SELECT jv.id, jv.plan_id, jv.invoice_id FROM job_visits jv WHERE jv.id = ?");
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$visit || !$this->isRoutePlan((int)$visit['plan_id'])) {
            return ['success' => false, 'error' => 'Not a snow & salt route stop.'];
        }
        if (!empty($visit['invoice_id'])) {
            return ['success' => false, 'error' => 'This run is already invoiced; change the invoice instead.'];
        }
        $this->db->prepare("
            INSERT INTO visit_service_choices (visit_id, choice, chosen_by, source, chosen_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE choice = VALUES(choice), chosen_by = VALUES(chosen_by),
                                    source = VALUES(source), updated_at = NOW()
        ")->execute([$visitId, $choice, $userId, substr($source, 0, 20)]);
        return ['success' => true, 'choice' => $choice, 'label' => self::choiceLabel($choice)];
    }

    /**
     * Billing for a visit, or null when the visit is not on a snow route plan
     * (the caller then bills it the normal way).
     */
    public function billingForVisit(int $visitId, int $planId, string $visitDate): ?array
    {
        $rates = $this->ratesForPlan($planId);
        if (!$rates) {
            return null;
        }
        return self::billingLines($rates, $this->choiceForVisit($visitId), $visitDate);
    }

    /**
     * A cancelled snow contract takes its daily route off the schedule: the route
     * plan is cancelled and every future scheduled stop with it. Cancelling a
     * contract only flips contracts.status, so without this the building kept a
     * stop on the crew's schedule every day of the season.
     *
     * @return int number of route plans stopped
     */
    public function stopRoutesForContract(int $contractId): int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT DISTINCT jp.id FROM job_plans jp
                JOIN snow_route_rates r ON r.plan_id = jp.id
                WHERE jp.contract_id = ? AND jp.status IN ('active', 'paused')
            ");
            $stmt->execute([$contractId]);
            $planIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return 0;
        }

        foreach ($planIds as $planId) {
            $stops = $this->db->prepare("
                SELECT DISTINCT stop_id FROM job_visits
                WHERE plan_id = ? AND status = 'scheduled' AND scheduled_date >= CURDATE() AND stop_id IS NOT NULL
            ");
            $stops->execute([$planId]);
            $stopIds = $stops->fetchAll(PDO::FETCH_COLUMN);

            $this->db->prepare("
                UPDATE job_visits SET status = 'cancelled', status_changed_at = NOW()
                WHERE plan_id = ? AND status = 'scheduled' AND scheduled_date >= CURDATE()
            ")->execute([$planId]);
            $this->db->prepare("UPDATE job_plans SET status = 'cancelled', status_changed_at = NOW() WHERE id = ?")
                     ->execute([$planId]);

            if (class_exists('VisitLifecycleService')) {
                foreach ($stopIds as $stopId) {
                    VisitLifecycleService::propagateStopStatus((int)$stopId);
                }
            }
        }
        return count($planIds);
    }

    // ══════════════════════════════════════════════════════════════════════
    // DB: set up a signed quote
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Create the contract + daily route plan for a signed snow contract quote.
     * Idempotent per quote. Never throws: failures are recorded in
     * snow_contract_setups and returned, so the signing page always succeeds.
     *
     * @return array{status:string, contract_id?:int, plan_id?:int, detail?:string}
     */
    public function setupFromSignedQuote(int $quoteId): array
    {
        try {
            $done = $this->db->prepare("SELECT status, contract_id, plan_id FROM snow_contract_setups WHERE quote_id = ?");
            $done->execute([$quoteId]);
            $prev = $done->fetch(PDO::FETCH_ASSOC);
            if ($prev && $prev['status'] === 'done') {
                return ['status' => 'already', 'contract_id' => (int)$prev['contract_id'], 'plan_id' => (int)$prev['plan_id']];
            }
        } catch (Throwable $e) {
            return ['status' => 'unavailable', 'detail' => 'Migration 1310 has not run.'];
        }

        if ($this->setting('snow_contract_autosetup_enabled', '1') !== '1') {
            return ['status' => 'disabled'];
        }

        $stmt = $this->db->prepare("
            SELECT q.*, p.address, p.city, p.site_contact_id
            FROM quotes q JOIN properties p ON p.id = q.property_id
            WHERE q.id = ?
        ");
        $stmt->execute([$quoteId]);
        $quote = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$quote || ($quote['status'] ?? '') !== 'accepted' || !self::isSnowContractQuote($quote)) {
            return ['status' => 'skipped'];
        }

        $contractId = null;
        $planId     = null;
        try {
            $existing = $this->db->prepare("SELECT id FROM contracts WHERE quote_id = ? LIMIT 1");
            $existing->execute([$quoteId]);
            if ($existing->fetchColumn()) {
                return $this->record($quoteId, 'skipped', null, null, 'A contract already exists for this quote; set up by hand.');
            }

            $lines = $this->db->prepare("SELECT * FROM quote_line_items WHERE quote_id = ? ORDER BY sort_order, id");
            $lines->execute([$quoteId]);
            $mapped = self::ratesFromQuoteLines($lines->fetchAll(PDO::FETCH_ASSOC));
            $rates  = $mapped['rates'];
            $byRole = [];
            foreach ($rates as $r) {
                $byRole[$r['role']] = $r;
            }
            if (empty($byRole[self::ROLE_SALT]) && empty($byRole[self::ROLE_SNOW])) {
                return $this->record($quoteId, 'failed', null, null, 'No Salt or Snow Removal rate on the quote.');
            }

            // The person the quote went to is the person who signed it.
            $contactId = (int)($quote['contact_id'] ?: $quote['site_contact_id']);
            $signedOn  = substr((string)($quote['accepted_at'] ?: date('Y-m-d')), 0, 10);
            $season    = self::seasonFor($signedOn);
            $address   = trim((string)$quote['address']);

            $rateList = [];
            foreach ($rates as $r) {
                if ($r['role'] !== self::ROLE_AREAS) {
                    $rateList[] = $r['label'] . ' $' . number_format($r['unit_price'], 2);
                }
            }
            $perVisit = (float)($byRole[self::ROLE_SALT]['unit_price'] ?? $byRole[self::ROLE_SNOW]['unit_price']);

            $result = createContract([
                'property_id'       => (int)$quote['property_id'],
                'contact_id'        => $contactId,
                'quote_id'          => $quoteId,
                'title'             => 'Salt & Snow ' . substr($season['start'], 0, 4) . '-' . substr($season['end'], 2, 2) . ' - ' . $address,
                'billing_cycle'     => 'per_visit',
                // Per-visit value = the salt rate, the run that happens most. A forecast
                // figure only: each run is billed at the rate the crew recorded.
                'billing_amount'    => $perVisit,
                'invoice_timing'    => 'after_visit',
                'start_date'        => $season['start'],
                'end_date'          => $season['end'],
                'notes'             => "Signed online on {$quote['quote_number']}. Billed per run at the rate recorded by the crew: " . implode('; ', $rateList) . '.',
                'terms_template_id' => $this->snowTermsTemplateId(),
                'auto_renew'        => 0,
            ], (int)$quote['created_by']);
            if (empty($result['success'])) {
                return $this->record($quoteId, 'failed', null, null, 'Contract: ' . implode(' ', $result['errors'] ?? []));
            }
            $contractId = (int)$result['contract_id'];

            require_once APP_ROOT . '/Modules/Contracts/Services/ContractService.php';
            (new ContractService($this->db))->adoptQuoteSignature($contractId, $quoteId, (int)$quote['created_by']);

            $areas = $byRole[self::ROLE_AREAS]['description'] ?? '';
            $planLines = [];
            foreach ($rates as $i => $r) {
                $planLines[] = [
                    'quote_line_item_id' => $r['quote_line_item_id'],
                    'product_id'         => $r['product_id'],
                    'service_type'       => self::planLineCode($r['role']),
                    'description'        => $r['role'] === self::ROLE_AREAS ? $r['description'] : $r['label'],
                    'quantity'           => 1,
                    'unit_type'          => 'visit',
                    'unit_price'         => $r['unit_price'],
                    'line_total'         => $r['unit_price'],
                    'sort_order'         => $i,
                ];
            }

            $plan = createJobPlan([
                'property_id'              => (int)$quote['property_id'],
                'company_id'               => $quote['company_id'] ?: null,
                'quote_id'                 => $quoteId,
                'contract_id'              => $contractId,
                'title'                    => 'Salt & Snow route',
                'description'              => trim('Daily stop. Record what was done: Salted, Arctic salt, Snow cleared, or Checked - nothing needed.'
                                                . ($areas !== '' ? "\nAreas: {$areas}" : '')),
                'service_type'             => 'snow_removal',
                'pricing_model'            => 'per_visit',
                'invoice_timing'           => 'after_visit',
                'is_recurring'             => 1,
                'recurrence_pattern'       => 'custom',
                'recurrence_interval'      => 1,
                'recurrence_interval_unit' => 'days',
                'plan_start_date'          => $season['start'],
                'plan_end_date'            => $season['end'],
                'estimated_duration_minutes' => 15,
                'horizon_days'             => 42,
                'line_items'               => $planLines,
            ], (int)$quote['created_by']);
            if (empty($plan['success'])) {
                return $this->record($quoteId, 'failed', $contractId, null, 'Plan: ' . implode(' ', $plan['errors'] ?? []));
            }
            $planId = (int)$plan['plan_id'];

            // Which plan line is which rate.
            $pli = $this->db->prepare("SELECT id, quote_line_item_id FROM plan_line_items WHERE plan_id = ?");
            $pli->execute([$planId]);
            $pliByQuoteLine = [];
            foreach ($pli->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pliByQuoteLine[(int)$row['quote_line_item_id']] = (int)$row['id'];
            }
            $ins = $this->db->prepare("INSERT INTO snow_route_rates (plan_id, role, label, plan_line_item_id, unit_price) VALUES (?, ?, ?, ?, ?)");
            foreach ($rates as $r) {
                $ins->execute([$planId, $r['role'], $r['label'], $pliByQuoteLine[(int)$r['quote_line_item_id']] ?? null, $r['unit_price']]);
            }

            // price_per_visit was set to the sum of every rate; show the salt rate instead
            // so schedules and forecasts read sensibly. Billing never reads this field.
            $this->db->prepare("UPDATE job_plans SET price_per_visit = ?, estimated_amount = ? WHERE id = ?")
                     ->execute([$perVisit, $perVisit, $planId]);

            $note = $mapped['unknown'] ? 'Lines not recognised as a rate: ' . implode(', ', $mapped['unknown']) : null;
            return $this->record($quoteId, 'done', $contractId, $planId, $note);
        } catch (Throwable $e) {
            error_log("[SnowContractService] setup for quote {$quoteId} failed: " . $e->getMessage());
            return $this->record($quoteId, 'failed', $contractId, $planId, $e->getMessage());
        }
    }

    private function record(int $quoteId, string $status, ?int $contractId, ?int $planId, ?string $detail): array
    {
        try {
            $this->db->prepare("
                INSERT INTO snow_contract_setups (quote_id, status, contract_id, plan_id, detail)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), contract_id = VALUES(contract_id),
                                        plan_id = VALUES(plan_id), detail = VALUES(detail), updated_at = NOW()
            ")->execute([$quoteId, $status, $contractId, $planId, $detail]);
        } catch (Throwable $e) {
            error_log('[SnowContractService] could not record setup: ' . $e->getMessage());
        }
        return array_filter(['status' => $status, 'contract_id' => $contractId, 'plan_id' => $planId, 'detail' => $detail],
            static fn($v) => $v !== null);
    }

    private function snowTermsTemplateId(): ?int
    {
        try {
            $id = $this->db->query("
                SELECT id FROM contract_terms_templates
                WHERE is_active = 1 AND scope = 'service_type' AND service_type = 'snow_removal'
                ORDER BY sort_order, id LIMIT 1
            ")->fetchColumn();
            return $id ? (int)$id : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function setting(string $key, string $default): string
    {
        try {
            $stmt = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $v = $stmt->fetchColumn();
            return $v === false ? $default : (string)$v;
        } catch (Throwable $e) {
            return $default;
        }
    }
}
