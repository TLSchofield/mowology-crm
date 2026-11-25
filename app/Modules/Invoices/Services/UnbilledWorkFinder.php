<?php
/**
 * UnbilledWorkFinder — "while you're invoicing this address, here is other work nobody billed".
 *
 * Real case (2026-10-08, TIM LOUIS, 2526 West 5th Ave, property 29): the crew ran a 22-minute
 * timer on Tue Sep 29 against visit #2112 (scheduled Fri Oct 2). #2112 was later marked
 * SKIPPED, so the cut was never invoiced; the owner only noticed when he invoiced the next one.
 * The Sep 24 aeration (#2412) was completed priced $0 and never billed either.
 *
 * One service, three surfaces: iOS Complete Visit (schedule/invoice.php → InvoiceFromVisitService),
 * desktop Complete & Invoice (pow-actions.php complete_stop) and invoices/create.php. Each asks
 * find() for the list, shows it as optional lines, and calls claim() INSIDE its own invoice
 * transaction with what the person ticked.
 *
 * What counts (last LOOKBACK_DAYS days, same property, same payer, per-visit billing only):
 *   completed      completed visit, not on any invoice                      (ticked by default when priced and nothing looks off)
 *   possibly_done  skipped / scheduled / cancelled / weather visit with real work evidence that day:
 *                  a job timer, or Otto's "done that day, not the scheduled day" suggestion   (never pre-ticked)
 *   zero_price     completed visit whose price resolves to $0                (never pre-ticked; needs a price)
 *
 * Never offered: visits linked to an invoice (job_visits.invoice_id / is_invoiced), visits that sit on
 * a line or as the source of a non-cancelled invoice, contract-billed plans
 * (ContractService::getContractBilledPlanIds), plans not billed per visit (monthly_flat / seasonal / custom).
 *
 * Warnings (stop pre-ticking, shown to the person): a hand-made invoice for this property dated on or
 * after the work with no visit linked ("may already cover this" — INV-2026-0441 is exactly that), or
 * another visit of the same plan already done that day (a timer started on the wrong visit).
 *
 * claim() re-runs the same classification with the rows locked (FOR UPDATE on MySQL) and throws
 * UnbilledWorkConflict if anything changed — callers roll back the whole invoice. A possibly_done
 * visit is marked completed on the real day, quietly (no customer email, no review request, no
 * push — same as Otto's completeQuietly), with an audit note on the visit and a row in
 * invoice_unbilled_claims (migration 1290).
 *
 * No namespace / no autoloader in production: require_once and `new`. SQL is MySQL 5.7-safe and
 * also runs on SQLite (the unit tests): no <=>, no CONCAT, no NOW() — dates are passed in.
 */
declare(strict_types=1);

if (!class_exists('ContractService')) {
    require_once dirname(__DIR__, 2) . '/Contracts/Services/ContractService.php';
}

class UnbilledWorkConflict extends RuntimeException {}

class UnbilledWorkFinder
{
    public const LOOKBACK_DAYS = 60;

    public const KIND_COMPLETED     = 'completed';
    public const KIND_POSSIBLY_DONE = 'possibly_done';
    public const KIND_ZERO_PRICE    = 'zero_price';

    /** Statuses whose visit may have been done anyway when there is evidence. */
    public const EVIDENCE_STATUSES = ['scheduled', 'in_progress', 'skipped', 'weather', 'cancelled'];

    private PDO $db;
    private string $today;
    /** @var callable|null fn(int $visitId, int $propertyId, string $day, ?int $crewId, ?int $oldStopId): void */
    private $stopMover;

    public function __construct(PDO $db, ?string $today = null, ?callable $stopMover = null)
    {
        $this->db = $db;
        $this->today = $today ?? date('Y-m-d');
        $this->stopMover = $stopMover;
    }

    public function since(): string
    {
        return date('Y-m-d', strtotime($this->today . ' -' . self::LOOKBACK_DAYS . ' days'));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Anchor — the visit being invoiced tells us the property and payer
    // ═════════════════════════════════════════════════════════════════════════

    /** @return array{visit_id:int, plan_id:int, property_id:int, company_id:?int}|null */
    public function anchorForVisit(int $visitId): ?array
    {
        if ($visitId < 1) return null;
        $s = $this->db->prepare("
            SELECT jv.id, jv.plan_id, jp.property_id, jp.company_id
            FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id
            WHERE jv.id = ?
        ");
        $s->execute([$visitId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r || empty($r['property_id'])) return null;
        return [
            'visit_id'    => (int)$r['id'],
            'plan_id'     => (int)$r['plan_id'],
            'property_id' => (int)$r['property_id'],
            'company_id'  => !empty($r['company_id']) ? (int)$r['company_id'] : null,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // FIND
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * @param array $opts exclude_visit_ids int[]  — the visit(s) this invoice is already for
     *                    company_id ?int          — the payer; visits on another company's plan are left out
     *                    invoice_id ?int          — the invoice being built (its own lines don't count as "billed elsewhere")
     *                    lock bool                — FOR UPDATE on the visit rows (claim())
     * @return array{property_id:int, since:string, today:string, items:list<array>, hints:list<array>, subtotal_preselected:float}
     */
    public function find(int $propertyId, array $opts = []): array
    {
        $out = ['property_id' => $propertyId, 'since' => $this->since(), 'today' => $this->today, 'items' => [], 'hints' => [], 'subtotal_preselected' => 0.0];
        if ($propertyId < 1) return $out;

        $exclude   = array_map('intval', (array)($opts['exclude_visit_ids'] ?? []));
        $companyId = !empty($opts['company_id']) ? (int)$opts['company_id'] : null;
        $invoiceId = (int)($opts['invoice_id'] ?? 0);
        $lock      = !empty($opts['lock']) && $this->isMysql();

        // Scheduled dates reach past the window on both sides: a visit scheduled Oct 2 can carry a
        // Sep 29 timer, and a visit scheduled next week can have been pulled forward and timed.
        $rows = $this->candidateRows($propertyId, date('Y-m-d', strtotime($this->since() . ' -14 days')),
            date('Y-m-d', strtotime($this->today . ' +14 days')), $lock);

        $rows = array_values(array_filter($rows, function ($r) use ($exclude, $companyId) {
            if (in_array((int)$r['id'], $exclude, true)) return false;
            if ((string)(($r['pricing_model'] ?? '') ?: 'per_visit') !== 'per_visit') return false;   // monthly / seasonal bill elsewhere
            if ($companyId !== null && !empty($r['company_id']) && (int)$r['company_id'] !== $companyId) return false;
            return true;
        }));
        if (!$rows) {
            $out['hints'] = $this->ottoHints($propertyId);
            return $out;
        }

        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $planIds = array_values(array_unique(array_map(fn($r) => (int)$r['plan_id'], $rows)));

        $contractBilled = (new ContractService($this->db))->getContractBilledPlanIds($planIds);
        $billedElsewhere = $this->billedElsewhere($ids, $invoiceId);
        $timers = $this->timerDays($ids);
        $otto = $this->ottoDays($ids);
        $planTotals = $this->planLineTotals($planIds);
        $handMade = $this->handMadeInvoices($propertyId, $invoiceId);

        $items = [];
        foreach ($rows as $r) {
            $vid = (int)$r['id'];
            if (!empty($contractBilled[(int)$r['plan_id']])) continue;
            if (isset($billedElsewhere[$vid])) continue;

            $item = self::classify($r, $timers[$vid] ?? [], $otto[$vid] ?? null, $planTotals[(int)$r['plan_id']] ?? 0.0, $this->since(), $this->today);
            if ($item === null) continue;
            $items[] = $item;
        }

        // Same plan already done that day → the timer was probably started on the wrong visit.
        $siblings = $this->doneDaysByPlan($planIds);
        foreach ($items as &$it) {
            foreach ($siblings[$it['plan_id']] ?? [] as $s) {
                if ($s['day'] === $it['service_date'] && $s['visit_id'] !== $it['visit_id']) {
                    $it['warnings'][] = 'Visit #' . $s['visit_id'] . ' of this plan was already done that day'
                        . ($s['invoice_number'] ? ' (' . $s['invoice_number'] . ')' : '') . '.';
                }
            }
            foreach ($handMade as $inv) {
                if ($inv['invoice_date'] >= $it['service_date']) {
                    $it['warnings'][] = $inv['invoice_number'] . ' (' . self::dayLabel($inv['invoice_date']) . ', $'
                        . number_format((float)$inv['subtotal'], 2) . ' + GST) was made by hand with no visit linked — it may already cover this.';
                }
            }
            if ($it['kind'] === self::KIND_ZERO_PRICE) {
                $it['suggested_amount'] = $this->suggestPrice((int)$it['plan_id'], (string)$it['service_type']);
            }
            $it['preselect'] = $it['kind'] === self::KIND_COMPLETED && $it['amount'] > 0 && !$it['warnings'];
            if ($it['preselect']) $out['subtotal_preselected'] += $it['amount'];
        }
        unset($it);

        usort($items, fn($a, $b) => strcmp($a['service_date'], $b['service_date']) ?: ($a['visit_id'] <=> $b['visit_id']));
        $out['items'] = $items;
        $out['subtotal_preselected'] = round($out['subtotal_preselected'], 2);
        $out['hints'] = $this->ottoHints($propertyId);
        return $out;
    }

    /**
     * PURE: one visit row + its evidence → an offer, or null when it is not unbilled work.
     *
     * @param array      $r       job_visits ⨝ job_plans row
     * @param array      $timers  [day => {start, end, minutes, people}] (job timers by start day)
     * @param array|null $otto    {day, status} — Otto's "done that day" suggestion
     */
    public static function classify(array $r, array $timers, ?array $otto, float $planLineTotal, string $since, string $today): ?array
    {
        $status = (string)$r['status'];
        $amount = self::resolveAmount($r, $planLineTotal);
        $label  = trim((string)(($r['title'] ?? '') ?: ($r['service_type'] ?? ''))) ?: 'Service';
        $evidence = [];
        $start = $end = null;
        $minutes = null;

        if ($status === 'completed') {
            $day = !empty($r['completed_at']) ? substr((string)$r['completed_at'], 0, 10) : (string)$r['scheduled_date'];
            $kind = $amount > 0 ? self::KIND_COMPLETED : self::KIND_ZERO_PRICE;
            $evidence[] = 'Completed ' . self::dayLabel($day) . ', not on any invoice.';
            if (isset($timers[$day])) {
                [$start, $end, $minutes] = [$timers[$day]['start'], $timers[$day]['end'], $timers[$day]['minutes']];
            }
        } elseif (in_array($status, self::EVIDENCE_STATUSES, true)) {
            $day = null;
            if ($timers) {
                $days = array_keys($timers);
                sort($days);
                // The latest timed day inside the window — the day the work was really done.
                foreach (array_reverse($days) as $d) {
                    if ($d >= $since && $d <= $today) { $day = $d; break; }
                }
            }
            if ($day !== null) {
                [$start, $end, $minutes] = [$timers[$day]['start'], $timers[$day]['end'], $timers[$day]['minutes']];
                $evidence[] = 'Job timer ran ' . self::dayLabel($day)
                    . ($start && $end ? ' ' . self::hm($start) . '–' . self::hm($end) : '')
                    . ($minutes !== null ? ' (' . $minutes . ' min)' : '') . '.';
            }
            if ($otto && $otto['day'] >= $since && $otto['day'] <= $today) {
                if ($day === null) $day = $otto['day'];
                $evidence[] = 'Otto: done ' . self::dayLabel($otto['day']) . ', not the scheduled day.';
            }
            if ($day === null) return null;
            // Today's own in-progress visit is the one being invoiced, never "possibly done".
            if ($status === 'in_progress' && $day === $today) return null;
            $kind = self::KIND_POSSIBLY_DONE;
            $evidence[] = 'Visit is ' . $status . ($r['scheduled_date'] !== $day ? ' (scheduled ' . self::dayLabel((string)$r['scheduled_date']) . ')' : '') . '.';
        } else {
            return null;
        }

        if ($day < $since || $day > $today) return null;

        $desc = $label . ' — ' . self::dayLabel($day);
        if ($start && $end) $desc .= ' (crew on site ' . self::hm($start) . '–' . self::hm($end) . ')';

        return [
            'visit_id'         => (int)$r['id'],
            'visit_number'     => (string)($r['visit_number'] ?? ''),
            'plan_id'          => (int)$r['plan_id'],
            'plan_title'       => $label,
            'service_type'     => (string)($r['service_type'] ?? ''),
            'kind'             => $kind,
            'status'           => $status,
            'scheduled_date'   => (string)$r['scheduled_date'],
            'service_date'     => $day,
            'service_day_label'=> self::dayLabel($day),
            'amount'           => round($amount, 2),
            'suggested_amount' => null,
            'needs_price'      => $kind === self::KIND_ZERO_PRICE,
            'on_site_start'    => $start ? self::hm($start) : null,
            'on_site_end'      => $end ? self::hm($end) : null,
            'on_site_minutes'  => $minutes,
            'on_site_start_at' => $start,
            'on_site_end_at'   => $end,
            'description'      => $desc,
            'badge'            => [self::KIND_COMPLETED => 'Completed, not invoiced', self::KIND_POSSIBLY_DONE => 'Possibly done', self::KIND_ZERO_PRICE => 'Priced $0 — add price?'][$kind],
            'evidence'         => $evidence,
            'warnings'         => [],
            'preselect'        => false,
            'crew_id'          => isset($r['assigned_crew_id']) && $r['assigned_crew_id'] !== null ? (int)$r['assigned_crew_id'] : null,
            'stop_id'          => isset($r['stop_id']) && $r['stop_id'] !== null ? (int)$r['stop_id'] : null,
        ];
    }

    /** PURE: the visit's own price, else the plan's line items, else the plan price. Same order as InvoiceFromVisitService. */
    public static function resolveAmount(array $r, float $planLineTotal): float
    {
        $actual = (float)($r['actual_amount'] ?? 0);
        if ($actual > 0) return $actual;
        if ($planLineTotal > 0) return $planLineTotal;
        $ppv = (float)($r['price_per_visit'] ?? 0);
        if ($ppv > 0) return $ppv;
        return max(0.0, (float)($r['estimated_amount'] ?? 0));
    }

    public static function dayLabel(string $date): string
    {
        $t = strtotime($date);
        return $t ? date('D M j', $t) : $date;
    }

    /** "2026-09-29 09:59:12" → "9:59" */
    public static function hm(string $dt): string
    {
        $t = strtotime($dt);
        return $t ? date('G:i', $t) : '';
    }

    // ═════════════════════════════════════════════════════════════════════════
    // CLAIM — inside the caller's invoice transaction
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Put the ticked visits on $invoiceId and mark them billed. Must run inside the caller's open
     * transaction; any problem throws UnbilledWorkConflict and the caller rolls the invoice back.
     *
     * @param array $selections list of {visit_id:int, amount?:float}
     * @param array $opts property_id (required), company_id, exclude_visit_ids, insert_lines (default true),
     *                    can_mark_done (default false), invoice_number, actor_name, sort_start (default 500)
     * @return array{lines: list<array>, subtotal_added: float, marked_done: int[]}
     */
    public function claim(int $invoiceId, array $selections, int $actorId, array $opts): array
    {
        $result = ['lines' => [], 'subtotal_added' => 0.0, 'marked_done' => []];
        $selections = self::normaliseSelections($selections);
        if (!$selections) return $result;
        if (!$this->db->inTransaction()) {
            throw new LogicException('UnbilledWorkFinder::claim() must run inside the invoice transaction.');
        }
        $propertyId = (int)($opts['property_id'] ?? 0);
        if ($propertyId < 1) throw new UnbilledWorkConflict('No property on this invoice — extra visits cannot be added.');

        $found = $this->find($propertyId, [
            'exclude_visit_ids' => $opts['exclude_visit_ids'] ?? [],
            'company_id'        => $opts['company_id'] ?? null,
            'invoice_id'        => $invoiceId,
            'lock'              => true,
        ]);
        $byId = [];
        foreach ($found['items'] as $it) $byId[$it['visit_id']] = $it;

        $insertLines = $opts['insert_lines'] ?? true;
        $canMarkDone = !empty($opts['can_mark_done']);
        $sort = (int)($opts['sort_start'] ?? 500);
        $invoiceNumber = (string)($opts['invoice_number'] ?? ('invoice #' . $invoiceId));
        $actorName = trim((string)($opts['actor_name'] ?? '')) ?: ('user #' . $actorId);
        $now = date('Y-m-d H:i:s');

        $li = $insertLines ? $this->db->prepare("
            INSERT INTO invoice_line_items (invoice_id, description, quantity, unit_price, line_total, visit_id, service_date, sort_order)
            VALUES (?, ?, 1, ?, ?, ?, ?, ?)
        ") : null;

        foreach ($selections as $sel) {
            $vid = $sel['visit_id'];
            $it = $byId[$vid] ?? null;
            if ($it === null) {
                throw new UnbilledWorkConflict('Visit #' . $vid . ' is no longer unbilled at this address (already invoiced, contract-billed or changed). Nothing was saved — reload and try again.');
            }
            if ($it['kind'] === self::KIND_POSSIBLY_DONE && !$canMarkDone) {
                throw new UnbilledWorkConflict('Only the office can mark visit #' . $vid . ' as done on ' . $it['service_day_label'] . '. Nothing was saved.');
            }
            $amount = $sel['amount'] !== null && $sel['amount'] > 0 ? round($sel['amount'], 2) : $it['amount'];
            if ($amount <= 0) {
                throw new UnbilledWorkConflict($it['plan_title'] . ' on ' . $it['service_day_label'] . ' is priced $0 — enter a price to bill it. Nothing was saved.');
            }

            if ($it['kind'] === self::KIND_POSSIBLY_DONE) {
                $note = '[' . $this->today . '] Marked done ' . $it['service_day_label'] . ' while invoicing ' . $invoiceNumber
                    . ' (' . $actorName . '): ' . implode(' ', $it['evidence']) . ' Was ' . $it['status']
                    . ', scheduled ' . self::dayLabel($it['scheduled_date']) . '.';
                $cur = $this->db->prepare("SELECT completion_notes FROM job_visits WHERE id = ?");
                $cur->execute([$vid]);
                $prev = trim((string)$cur->fetchColumn());
                $startAt = $it['on_site_start_at'] ? substr((string)$it['on_site_start_at'], 0, 19) : null;
                $endAt = $it['on_site_end_at'] ? substr((string)$it['on_site_end_at'], 0, 19) : $it['service_date'] . ' 12:00:00';
                $u = $this->db->prepare("
                    UPDATE job_visits
                    SET invoice_id = ?, is_invoiced = 1, status = 'completed', status_changed_at = ?,
                        started_at = COALESCE(?, started_at), completed_at = ?,
                        actual_duration_minutes = COALESCE(actual_duration_minutes, ?),
                        actual_amount = CASE WHEN COALESCE(actual_amount, 0) > 0 THEN actual_amount ELSE ? END,
                        completion_notes = ?
                    WHERE id = ? AND invoice_id IS NULL AND status <> 'completed'
                ");
                $u->execute([$invoiceId, $now, $startAt, $endAt, $it['on_site_minutes'], $amount,
                    $prev !== '' ? $prev . "\n" . $note : $note, $vid]);
            } elseif ($it['kind'] === self::KIND_ZERO_PRICE) {
                $u = $this->db->prepare("
                    UPDATE job_visits SET invoice_id = ?, is_invoiced = 1, actual_amount = ?
                    WHERE id = ? AND invoice_id IS NULL AND status = 'completed'
                ");
                $u->execute([$invoiceId, $amount, $vid]);
            } else {
                $u = $this->db->prepare("
                    UPDATE job_visits SET invoice_id = ?, is_invoiced = 1
                    WHERE id = ? AND invoice_id IS NULL AND status = 'completed'
                ");
                $u->execute([$invoiceId, $vid]);
            }
            if ($u->rowCount() !== 1) {
                throw new UnbilledWorkConflict('Visit #' . $vid . ' changed while this invoice was being saved. Nothing was saved — reload and try again.');
            }

            if ($it['kind'] === self::KIND_POSSIBLY_DONE) {
                $this->moveToRealDay($vid, $propertyId, $it);
                $result['marked_done'][] = $vid;
            }

            $desc = $it['description'];
            if ($li) {
                $li->execute([$invoiceId, $desc, $amount, $amount, $vid, $it['service_date'], $sort++]);
            }
            $this->audit($invoiceId, $vid, $it, $amount, $actorId, $now);

            $result['lines'][] = ['visit_id' => $vid, 'kind' => $it['kind'], 'description' => $desc,
                'amount' => $amount, 'service_date' => $it['service_date']];
            $result['subtotal_added'] += $amount;
        }
        $result['subtotal_added'] = round($result['subtotal_added'], 2);
        return $result;
    }

    /**
     * Recompute an invoice's totals from its line items at its own stored tax rate (GST is added on
     * top, never included) and drop any cached PDF. @return array{subtotal:float, tax_amount:float, total:float}
     */
    public function retotalInvoice(int $invoiceId): array
    {
        $s = $this->db->prepare("SELECT tax_rate FROM invoices WHERE id = ?");
        $s->execute([$invoiceId]);
        $rate = (float)$s->fetchColumn();
        if ($rate > 1) $rate = $rate / 100;   // tolerate a percentage stored by an older path
        $s = $this->db->prepare("SELECT COALESCE(SUM(line_total), 0) FROM invoice_line_items WHERE invoice_id = ?");
        $s->execute([$invoiceId]);
        $sub = round((float)$s->fetchColumn(), 2);
        $tax = round($sub * $rate, 2);
        $total = round($sub + $tax, 2);
        $this->db->prepare("
            UPDATE invoices SET subtotal = ?, tax_amount = ?, total_amount = ?, total = ?, balance_due = ?, pdf_path = NULL
            WHERE id = ?
        ")->execute([$sub, $tax, $total, $total, $total, $invoiceId]);
        return ['subtotal' => $sub, 'tax_amount' => $tax, 'total' => $total];
    }

    /** PURE: [{visit_id, amount?}] from JSON/form input; drops junk and duplicates. */
    public static function normaliseSelections($raw): array
    {
        $out = [];
        foreach ((array)$raw as $k => $v) {
            if (is_array($v)) {
                $vid = (int)($v['visit_id'] ?? 0);
                $amt = isset($v['amount']) && $v['amount'] !== '' && $v['amount'] !== null ? (float)$v['amount'] : null;
            } else {
                $vid = (int)$v;
                $amt = null;
            }
            if ($vid > 0 && !isset($out[$vid])) $out[$vid] = ['visit_id' => $vid, 'amount' => $amt];
        }
        return array_values($out);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Loaders
    // ═════════════════════════════════════════════════════════════════════════

    private function isMysql(): bool
    {
        try {
            return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        } catch (Throwable $e) {
            return false;
        }
    }

    private function candidateRows(int $propertyId, string $from, string $to, bool $lock): array
    {
        $statuses = array_merge(['completed'], self::EVIDENCE_STATUSES);
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $sql = "
            SELECT jv.id, jv.visit_number, jv.plan_id, jv.status, jv.scheduled_date, jv.completed_at, jv.started_at,
                   jv.actual_amount, jv.actual_duration_minutes, jv.assigned_crew_id, jv.stop_id,
                   jp.title, jp.service_type, jp.price_per_visit, jp.estimated_amount, jp.pricing_model,
                   jp.company_id, jp.property_id
            FROM job_visits jv
            JOIN job_plans jp ON jp.id = jv.plan_id
            WHERE jp.property_id = ?
              AND jv.invoice_id IS NULL AND COALESCE(jv.is_invoiced, 0) = 0
              AND jv.status IN ({$in})
              AND jv.scheduled_date BETWEEN ? AND ?
            ORDER BY jv.scheduled_date, jv.id" . ($lock ? ' FOR UPDATE' : '');
        $s = $this->db->prepare($sql);
        $s->execute(array_merge([$propertyId], $statuses, [$from, $to]));
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** [visit_id => invoice_number] for visits already on another (non-cancelled) invoice. */
    private function billedElsewhere(array $ids, int $invoiceId): array
    {
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        $s = $this->db->prepare("
            SELECT li.visit_id, i.invoice_number
            FROM invoice_line_items li JOIN invoices i ON i.id = li.invoice_id
            WHERE li.visit_id IN ({$in}) AND li.invoice_id <> ? AND i.status NOT IN ('cancelled', 'void')
        ");
        $s->execute(array_merge($ids, [$invoiceId]));
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['visit_id']] = (string)$r['invoice_number'];
        $s = $this->db->prepare("
            SELECT i.visit_id, i.invoice_number FROM invoices i
            WHERE i.visit_id IN ({$in}) AND i.id <> ? AND i.status NOT IN ('cancelled', 'void')
        ");
        $s->execute(array_merge($ids, [$invoiceId]));
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['visit_id']] = (string)$r['invoice_number'];
        return $out;
    }

    /** [visit_id => [day => {start, end, minutes}]] from job timers (void ignored), keyed by the day each started. */
    private function timerDays(array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        try {
            $s = $this->db->prepare("
                SELECT visit_id, start_time, end_time, duration_minutes
                FROM job_time_entries
                WHERE visit_id IN ({$in}) AND status <> 'void'
                ORDER BY start_time
            ");
            $s->execute($ids);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $vid = (int)$r['visit_id'];
                $day = substr((string)$r['start_time'], 0, 10);
                $st = (string)$r['start_time'];
                $en = $r['end_time'] !== null ? (string)$r['end_time'] : null;
                $min = $r['duration_minutes'] !== null ? (int)$r['duration_minutes']
                    : ($en ? (int)round((strtotime($en) - strtotime($st)) / 60) : null);
                $cur = $out[$vid][$day] ?? ['start' => $st, 'end' => $en, 'minutes' => null];
                if ($st < $cur['start']) $cur['start'] = $st;
                if ($en !== null && ($cur['end'] === null || $en > $cur['end'])) $cur['end'] = $en;
                if ($min !== null) $cur['minutes'] = ($cur['minutes'] ?? 0) + $min;
                $out[$vid][$day] = $cur;
            }
        } catch (Throwable $e) {
            error_log('UnbilledWorkFinder timers: ' . $e->getMessage());
        }
        return $out;
    }

    /** [visit_id => {day, status}] — Otto's "visit #N was done D, not the scheduled day" (kind visit_date). */
    private function ottoDays(array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        try {
            $s = $this->db->prepare("
                SELECT subject_id, for_date, status FROM otto_suggestions
                WHERE kind = 'visit_date' AND subject_type = 'visit' AND subject_id IN ({$in})
                  AND status IN ('open', 'accepted', 'edited')
                ORDER BY for_date
            ");
            $s->execute($ids);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['subject_id']] = ['day' => substr((string)$r['for_date'], 0, 10), 'status' => (string)$r['status']];
            }
        } catch (Throwable $e) { /* Otto not installed here */ }
        return $out;
    }

    /**
     * Otto's open "crew was here with nothing scheduled / did more than scheduled" items for this
     * property. Not billable from an invoice (there is no visit yet) — shown so the person adds the
     * visit on Otto's card first.
     */
    private function ottoHints(int $propertyId): array
    {
        $out = [];
        try {
            $s = $this->db->prepare("
                SELECT id, kind, for_date, suggestion_json FROM otto_suggestions
                WHERE subject_type = 'property' AND subject_id = ? AND kind IN ('unscheduled', 'extra_work')
                  AND status = 'open' AND for_date BETWEEN ? AND ?
                ORDER BY for_date
            ");
            $s->execute([$propertyId, $this->since(), $this->today]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $p = json_decode((string)$r['suggestion_json'], true) ?: [];
                $when = self::dayLabel((string)$r['for_date']);
                $span = !empty($p['start']) && !empty($p['end']) ? ' ' . $p['start'] . '–' . $p['end'] : '';
                $out[] = [
                    'suggestion_id' => (int)$r['id'],
                    'date' => (string)$r['for_date'],
                    'text' => 'Otto: crew here ' . $when . $span
                        . ($r['kind'] === 'extra_work' ? ', longer than the scheduled visit' : ' with nothing scheduled')
                        . ' — add the visit on Otto\'s card, then it can be billed.',
                ];
            }
        } catch (Throwable $e) { /* Otto not installed here */ }
        return $out;
    }

    /** [plan_id => sum(plan_line_items.line_total)] */
    private function planLineTotals(array $planIds): array
    {
        if (!$planIds) return [];
        $in = implode(',', array_fill(0, count($planIds), '?'));
        $out = [];
        try {
            $s = $this->db->prepare("SELECT plan_id, SUM(line_total) AS t FROM plan_line_items WHERE plan_id IN ({$in}) GROUP BY plan_id");
            $s->execute($planIds);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['plan_id']] = (float)$r['t'];
        } catch (Throwable $e) { /* no plan_line_items here */ }
        return $out;
    }

    /** [plan_id => list{visit_id, day, invoice_number}] — completed visits, for the "already done that day" check. */
    private function doneDaysByPlan(array $planIds): array
    {
        if (!$planIds) return [];
        $in = implode(',', array_fill(0, count($planIds), '?'));
        $out = [];
        $s = $this->db->prepare("
            SELECT jv.id, jv.plan_id, jv.scheduled_date, jv.completed_at, i.invoice_number
            FROM job_visits jv LEFT JOIN invoices i ON i.id = jv.invoice_id
            WHERE jv.plan_id IN ({$in}) AND jv.status = 'completed' AND jv.scheduled_date >= ?
        ");
        $s->execute(array_merge($planIds, [date('Y-m-d', strtotime($this->since() . ' -14 days'))]));
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $day = !empty($r['completed_at']) ? substr((string)$r['completed_at'], 0, 10) : (string)$r['scheduled_date'];
            $out[(int)$r['plan_id']][] = ['visit_id' => (int)$r['id'], 'day' => $day, 'invoice_number' => $r['invoice_number'] ?? null];
        }
        return $out;
    }

    /** Invoices for the property made by hand (no visit on the header or any line) in the window. */
    private function handMadeInvoices(int $propertyId, int $invoiceId): array
    {
        $s = $this->db->prepare("
            SELECT i.id, i.invoice_number, i.invoice_date, i.subtotal
            FROM invoices i
            WHERE i.property_id = ? AND i.id <> ? AND i.status NOT IN ('cancelled', 'void')
              AND i.invoice_date >= ? AND i.visit_id IS NULL
              AND NOT EXISTS (SELECT 1 FROM invoice_line_items li WHERE li.invoice_id = i.id AND li.visit_id IS NOT NULL)
            ORDER BY i.invoice_date
        ");
        $s->execute([$propertyId, $invoiceId, $this->since()]);
        return array_map(fn($r) => $r + ['invoice_date' => substr((string)$r['invoice_date'], 0, 10)], $s->fetchAll(PDO::FETCH_ASSOC));
    }

    /** A price for a $0 visit: the last price this plan was billed at, else the catalog product of the same name. */
    private function suggestPrice(int $planId, string $serviceType): ?float
    {
        try {
            $s = $this->db->prepare("
                SELECT li.unit_price FROM invoice_line_items li
                JOIN job_visits jv ON jv.id = li.visit_id
                JOIN invoices i ON i.id = li.invoice_id
                WHERE jv.plan_id = ? AND li.unit_price > 0 AND i.status NOT IN ('cancelled', 'void')
                ORDER BY li.id DESC LIMIT 1
            ");
            $s->execute([$planId]);
            $p = $s->fetchColumn();
            if ($p !== false && (float)$p > 0) return round((float)$p, 2);
        } catch (Throwable $e) { /* fall through */ }
        if ($serviceType === '') return null;
        try {
            $s = $this->db->prepare("SELECT base_price FROM products WHERE LOWER(name) = LOWER(?) AND base_price > 0 LIMIT 1");
            $s->execute([$serviceType]);
            $p = $s->fetchColumn();
            if ($p !== false && (float)$p > 0) return round((float)$p, 2);
        } catch (Throwable $e) { /* products schema differs */ }
        return null;
    }

    /**
     * The visit really happened on $it['service_date']: move its date there (and its calendar stop),
     * like Otto's moveVisitDate. Best effort — a date clash (uk_plan_date_seq) or a missing plan
     * library leaves the date alone; the completion, the audit note and the invoice line still stand.
     */
    private function moveToRealDay(int $visitId, int $propertyId, array $it): void
    {
        if ($it['scheduled_date'] === $it['service_date']) return;
        try {
            $this->db->prepare("UPDATE job_visits SET scheduled_date = ? WHERE id = ?")->execute([$it['service_date'], $visitId]);
        } catch (Throwable $e) {
            error_log('UnbilledWorkFinder: kept the scheduled date of visit #' . $visitId . ': ' . $e->getMessage());
            return;
        }
        try {
            $mover = $this->stopMover ?? [$this, 'defaultStopMover'];
            $mover($visitId, $propertyId, $it['service_date'], $it['crew_id'], $it['stop_id']);
        } catch (Throwable $e) {
            error_log('UnbilledWorkFinder: stop not moved for visit #' . $visitId . ': ' . $e->getMessage());
        }
    }

    /** ensureCalendarStop() for the real day, then both stops' statuses recomputed. Same PDO (getDB()), so inside the transaction. */
    public function defaultStopMover(int $visitId, int $propertyId, string $day, ?int $crewId, ?int $oldStopId): void
    {
        if (!function_exists('ensureCalendarStop')) {
            $f = dirname(__DIR__, 2) . '/Jobs/Services/Plan/CalendarStops.php';
            if (is_file($f)) require_once $f;
        }
        if (!function_exists('ensureCalendarStop') || !function_exists('getDB')) return;
        $stop = ensureCalendarStop($propertyId, $day, $crewId);
        if ($stop > 0) {
            $this->db->prepare("UPDATE job_visits SET stop_id = ? WHERE id = ?")->execute([$stop, $visitId]);
        }
        if (!class_exists('VisitLifecycleService')) {
            $f = dirname(__DIR__, 2) . '/Jobs/Services/VisitLifecycleService.php';
            if (is_file($f)) require_once $f;
        }
        if (class_exists('VisitLifecycleService')) {
            foreach (array_unique(array_filter([(int)$oldStopId, (int)$stop])) as $sid) {
                VisitLifecycleService::propagateStopStatus($sid);
            }
        }
    }

    /** invoice_unbilled_claims (migration 1290). Best effort: the visit note is the primary audit. */
    private function audit(int $invoiceId, int $visitId, array $it, float $amount, int $actorId, string $now): void
    {
        try {
            $this->db->prepare("
                INSERT INTO invoice_unbilled_claims
                    (invoice_id, visit_id, kind, previous_status, scheduled_date, service_date, amount, evidence, claimed_by, claimed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$invoiceId, $visitId, $it['kind'], $it['status'], $it['scheduled_date'], $it['service_date'],
                $amount, mb_substr(implode(' ', array_merge($it['evidence'], $it['warnings'])), 0, 1000), $actorId ?: null, $now]);
        } catch (Throwable $e) {
            error_log('UnbilledWorkFinder audit (run migration 1290): ' . $e->getMessage());
        }
    }
}
