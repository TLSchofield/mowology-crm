<?php
declare(strict_types=1);

/**
 * Sam's automatic pipeline (lifecycle) stages for contacts and companies.
 *
 * Owner-approved rules (Sam owns them):
 *   client      ANY of: an active contract or active job plan where they are the contract
 *               contact, the property's site / billing / quote / strata contact, or the
 *               account (company) contact; a quote accepted in the last 12 months; an invoice
 *               paid (or part-paid) in the last 12 months.
 *   opportunity A quote is open (status sent / viewed) and they are not a client.
 *   inactive    Was a client (any contract, accepted quote or paid invoice, ever) but nothing
 *               in the last 12 months.
 *   lost        Never a client, has had quotes, and every sent quote was declined / expired
 *               (drafts are ignored either way).
 *   lead        In the CRM, nothing quoted yet.
 * Precedence: client > opportunity > inactive > lost > lead. "Exactly 12 months ago" is still
 * inside the window (compared by calendar date).
 *
 * Canonical keys (lifecycle_stages, migration 023): the rules use lead / opportunity / client /
 * inactive / lost as-is for contacts. Companies have no 'lead' in their vocabulary — their
 * column default is 'prospect' — so a company's lead is written as 'prospect'.
 *
 * What the rules may overwrite: only stages a machine could have written (the five above plus
 * the old purchase-history cron's prospect / customer / repeat / at_risk, and blank). Any other
 * key (won, qualified, a custom kanban column) can only have come from a person, so it is left
 * alone and reported as 'custom'. A pinned record (lifecycle_pinned = 1, set by every manual
 * stage change) is never touched.
 *
 * Production has no autoloader: require_once this file and `new PipelineStageService($db)`.
 * All SQL is MySQL 5.7-safe (no window/JSON functions); optional tables and columns are probed.
 */
class PipelineStageService
{
    public const LEAD = 'lead';
    public const OPPORTUNITY = 'opportunity';
    public const CLIENT = 'client';
    public const INACTIVE = 'inactive';
    public const LOST = 'lost';

    public const WINDOW_MONTHS = 12;
    public const SAMPLE_LIMIT = 30;

    /** Stages the rules may replace on a contact (anything else was set by a person). */
    public const CONTACT_MANAGED = ['', 'lead', 'opportunity', 'client', 'inactive', 'lost', 'prospect', 'customer', 'repeat', 'at_risk'];
    /** Same for companies (their "lead" is 'prospect'). */
    public const COMPANY_MANAGED = ['', 'prospect', 'lead', 'opportunity', 'client', 'inactive', 'lost'];
    /** Rule stage → key written on a company. Contacts use the rule stage unchanged. */
    public const COMPANY_KEY_MAP = ['lead' => 'prospect'];
    /** Rule stage → contacts.prospect_status (mirrors CrmFunctions::updateContactLifecycleStage). */
    public const PROSPECT_STATUS_MAP = [
        'lead' => 'prospect', 'opportunity' => 'prospect', 'client' => 'client',
        'inactive' => 'inactive', 'lost' => 'inactive',
    ];

    public const QUOTE_ACCEPTED = ['accepted', 'approved_verbal', 'converted'];
    public const QUOTE_OPEN = ['sent', 'viewed'];
    public const QUOTE_CLOSED = ['declined', 'expired', 'rejected'];
    public const INVOICE_PAID = ['paid', 'partial'];
    /** property_contacts roles that make someone a building's owner / strata / billing contact. */
    public const PROPERTY_ROLES = ['owner', 'manager', 'billing'];

    /** @var PDO */
    protected $db;
    /** @var string Y-m-d H:i:s */
    protected $now;
    /** @var array<string,bool> */
    private $probe = [];
    /** @var array<string,?array> */
    private $allowed = [];

    public function __construct(PDO $db, ?string $now = null)
    {
        $this->db = $db;
        $this->now = $now ?? date('Y-m-d H:i:s');
    }

    // ══════════════════════════════════════════════════════════════════════
    // PURE RULES
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The rule stage for a set of facts. Pure.
     *
     * @param array $facts {
     *   as_of: string (Y-m-d…, default today), active_contract: bool, any_contract: bool,
     *   last_accepted_at: ?string, last_paid_at: ?string, open_quotes: int, closed_quotes: int
     * }
     */
    public static function stageFor(array $facts): string
    {
        $asOf = substr((string)($facts['as_of'] ?? date('Y-m-d')), 0, 10);
        $cutoff = self::cutoff($asOf);
        $accepted = self::day($facts['last_accepted_at'] ?? null);
        $paid = self::day($facts['last_paid_at'] ?? null);
        $active = !empty($facts['active_contract']);

        if ($active || ($accepted !== null && $accepted >= $cutoff) || ($paid !== null && $paid >= $cutoff)) {
            return self::CLIENT;
        }
        if ((int)($facts['open_quotes'] ?? 0) > 0) {
            return self::OPPORTUNITY;
        }
        if (!empty($facts['any_contract']) || $accepted !== null || $paid !== null) {
            return self::INACTIVE;
        }
        if ((int)($facts['closed_quotes'] ?? 0) > 0) {
            return self::LOST;
        }
        return self::LEAD;
    }

    /** First day still inside the 12-month window (inclusive), as Y-m-d. */
    public static function cutoff(string $asOf): string
    {
        return date('Y-m-d', strtotime(substr($asOf, 0, 10) . ' -' . self::WINDOW_MONTHS . ' months'));
    }

    /**
     * Whether to write, and what. Pure. Returns the key to write, or null to leave it.
     *
     * @param string[] $managed  stages the rules may replace (CONTACT_MANAGED / COMPANY_MANAGED)
     */
    public static function decide(?string $current, bool $pinned, string $target, array $managed): ?string
    {
        if ($pinned) return null;
        $cur = trim((string)$current);
        if (!in_array($cur, $managed, true)) return null;   // set by a person — custom key
        // A client only ever moves on to inactive (12 quiet months): the rules can miss how a
        // contract is filed (VR15-40's sits under the building, not the strata company), and
        // demoting a paying client to "prospect" is never right.
        if (in_array($cur, ['client', 'customer', 'repeat'], true) && !in_array($target, ['client', 'inactive'], true)) return null;
        return $cur === $target ? null : $target;
    }

    /** Rule stage → company key. */
    public static function companyKey(string $stage): string
    {
        return self::COMPANY_KEY_MAP[$stage] ?? $stage;
    }

    private static function day($v): ?string
    {
        $s = trim((string)$v);
        if ($s === '' || strpos($s, '0000-00-00') === 0) return null;
        return substr($s, 0, 10);
    }

    // ══════════════════════════════════════════════════════════════════════
    // FACTS (SQL)
    // ══════════════════════════════════════════════════════════════════════

    /** Facts for one contact (see stageFor). */
    public function factsFor(int $contactId): array
    {
        $companyIds = $this->companiesRepresentedBy($contactId);
        $propertyIds = $this->propertiesLinkedTo($contactId, $companyIds);
        return $this->factsForScope([$contactId], $propertyIds, $companyIds);
    }

    /** Facts for one company, rolled up from its buildings, its contacts, contracts and invoices. */
    public function factsForCompany(int $companyId): array
    {
        $contactIds = [];
        foreach (['primary_contact_id', 'billing_contact_id', 'quote_contact_id'] as $col) {
            if (!$this->hasColumn('companies', $col)) continue;
            $s = $this->db->prepare("SELECT {$col} FROM companies WHERE id = ?");
            $s->execute([$companyId]);
            $v = (int)$s->fetchColumn();
            if ($v > 0) $contactIds[] = $v;
        }
        $propertyIds = [];
        if ($this->hasColumn('properties', 'property_manager_id')) {
            $s = $this->db->prepare("SELECT id FROM properties WHERE property_manager_id = ?");
            $s->execute([$companyId]);
            $propertyIds = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        }
        if ($this->hasTable('job_plans') && $this->hasColumn('job_plans', 'company_id')) {
            $s = $this->db->prepare("SELECT DISTINCT property_id FROM job_plans WHERE company_id = ? AND property_id IS NOT NULL");
            $s->execute([$companyId]);
            $propertyIds = array_merge($propertyIds, array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)));
        }
        return $this->factsForScope(self::ids($contactIds), self::ids($propertyIds), [$companyId]);
    }

    /**
     * Facts across a scope: anything addressed to one of the contacts, on one of the
     * properties, or billed / quoted to one of the companies.
     */
    public function factsForScope(array $contactIds, array $propertyIds, array $companyIds): array
    {
        $contactIds = self::ids($contactIds);
        $propertyIds = self::ids($propertyIds);
        $companyIds = self::ids($companyIds);
        $f = [
            'as_of' => substr($this->now, 0, 10),
            'active_contract' => false, 'any_contract' => false,
            'last_accepted_at' => null, 'last_paid_at' => null,
            'open_quotes' => 0, 'closed_quotes' => 0,
            'property_count' => count($propertyIds),
        ];

        // Contracts
        if ($this->hasTable('contracts')) {
            $where = $this->scopeWhere('contracts', 'ct', $contactIds, $propertyIds, $companyIds);
            if ($where) {
                $row = $this->db->query("
                    SELECT COUNT(*) AS n, SUM(CASE WHEN ct.status = 'active' THEN 1 ELSE 0 END) AS active
                    FROM contracts ct WHERE ({$where}) AND COALESCE(ct.status, '') <> 'draft'
                ")->fetch(PDO::FETCH_ASSOC) ?: [];
                $f['any_contract'] = (int)($row['n'] ?? 0) > 0;
                $f['active_contract'] = (int)($row['active'] ?? 0) > 0;
            }
        }

        // Active job plans count as an active contract
        if (!$f['active_contract'] && $this->hasTable('job_plans')) {
            $where = $this->scopeWhere('job_plans', 'jp', [], $propertyIds, $companyIds);
            if ($where) {
                $f['active_contract'] = (int)$this->db->query("
                    SELECT COUNT(*) FROM job_plans jp WHERE ({$where}) AND jp.status = 'active'
                ")->fetchColumn() > 0;
            }
        }

        // Quotes
        if ($this->hasTable('quotes')) {
            $where = $this->scopeWhere('quotes', 'q', $contactIds, $propertyIds, $companyIds);
            if ($where) {
                $acc = $this->hasColumn('quotes', 'accepted_at') ? 'q.accepted_at' : 'NULL';
                $upd = $this->hasColumn('quotes', 'updated_at') ? 'q.updated_at' : 'NULL';
                $rows = $this->db->query("
                    SELECT q.status, {$acc} AS accepted_at, {$upd} AS updated_at, q.created_at
                    FROM quotes q WHERE ({$where})
                ")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $st = strtolower((string)$r['status']);
                    $acceptedAt = self::day($r['accepted_at']);
                    if (in_array($st, self::QUOTE_ACCEPTED, true) || $acceptedAt !== null) {
                        $d = $acceptedAt ?? self::day($r['updated_at']) ?? self::day($r['created_at']);
                        if ($d !== null && ($f['last_accepted_at'] === null || $d > $f['last_accepted_at'])) {
                            $f['last_accepted_at'] = $d;
                        }
                    } elseif (in_array($st, self::QUOTE_OPEN, true)) {
                        $f['open_quotes']++;
                    } elseif (in_array($st, self::QUOTE_CLOSED, true)) {
                        $f['closed_quotes']++;
                    }
                }
            }
        }

        // Paid invoices
        if ($this->hasTable('invoices')) {
            $where = $this->scopeWhere('invoices', 'i', $contactIds, $propertyIds, $companyIds);
            if ($where) {
                $dates = [];
                foreach (['paid_at', 'updated_at', 'created_at'] as $c) {
                    if ($this->hasColumn('invoices', $c)) $dates[] = 'i.' . $c;
                }
                $dateExpr = $dates ? 'COALESCE(' . implode(', ', $dates) . ')' : 'NULL';
                $paid = implode(', ', array_map([$this->db, 'quote'], self::INVOICE_PAID));
                $v = $this->db->query("
                    SELECT MAX({$dateExpr}) FROM invoices i WHERE ({$where}) AND i.status IN ({$paid})
                ")->fetchColumn();
                $f['last_paid_at'] = self::day($v);
            }
        }

        return $f;
    }

    /** Companies this contact speaks for (primary / billing / quote contact). */
    public function companiesRepresentedBy(int $contactId): array
    {
        if (!$this->hasTable('companies')) return [];
        $or = [];
        foreach (['primary_contact_id', 'billing_contact_id', 'quote_contact_id'] as $col) {
            if ($this->hasColumn('companies', $col)) $or[] = "{$col} = " . $contactId;
        }
        if (!$or) return [];
        return self::ids($this->db->query("SELECT id FROM companies WHERE " . implode(' OR ', $or))->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Buildings this contact stands for: site / billing / quote contact, an owner / strata /
     * billing row in property_contacts, or a building managed by a company they speak for.
     */
    public function propertiesLinkedTo(int $contactId, array $companyIds = []): array
    {
        if (!$this->hasTable('properties')) return [];
        $or = [];
        foreach (['site_contact_id', 'billing_contact_id', 'quote_contact_id'] as $col) {
            if ($this->hasColumn('properties', $col)) $or[] = "p.{$col} = " . $contactId;
        }
        $companyIds = self::ids($companyIds);
        if ($companyIds && $this->hasColumn('properties', 'property_manager_id')) {
            $or[] = 'p.property_manager_id IN (' . implode(',', $companyIds) . ')';
        }
        $ids = $or ? $this->db->query("SELECT p.id FROM properties p WHERE " . implode(' OR ', $or))->fetchAll(PDO::FETCH_COLUMN) : [];
        if ($this->hasTable('property_contacts')) {
            $roles = implode(', ', array_map([$this->db, 'quote'], self::PROPERTY_ROLES));
            $s = $this->db->prepare("SELECT property_id FROM property_contacts WHERE contact_id = ? AND contact_role IN ({$roles})");
            $s->execute([$contactId]);
            $ids = array_merge($ids, $s->fetchAll(PDO::FETCH_COLUMN));
        }
        return self::ids($ids);
    }

    /** The reverse: every contact and company a building's events should move. */
    public function linkedToProperty(int $propertyId): array
    {
        $contacts = [];
        $companies = [];
        if ($propertyId <= 0 || !$this->hasTable('properties')) return [$contacts, $companies];
        $cols = [];
        foreach (['site_contact_id', 'billing_contact_id', 'quote_contact_id', 'property_manager_id'] as $c) {
            if ($this->hasColumn('properties', $c)) $cols[] = $c;
        }
        if ($cols) {
            $s = $this->db->prepare("SELECT " . implode(', ', $cols) . " FROM properties WHERE id = ?");
            $s->execute([$propertyId]);
            $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach (['site_contact_id', 'billing_contact_id', 'quote_contact_id'] as $c) {
                if (!empty($row[$c])) $contacts[] = (int)$row[$c];
            }
            if (!empty($row['property_manager_id'])) {
                $firm = (int)$row['property_manager_id'];
                $companies[] = $firm;
                $contacts = array_merge($contacts, $this->companyContacts($firm));
            }
        }
        if ($this->hasTable('property_contacts')) {
            $roles = implode(', ', array_map([$this->db, 'quote'], self::PROPERTY_ROLES));
            $s = $this->db->prepare("SELECT contact_id FROM property_contacts WHERE property_id = ? AND contact_role IN ({$roles})");
            $s->execute([$propertyId]);
            $contacts = array_merge($contacts, $s->fetchAll(PDO::FETCH_COLUMN));
        }
        return [self::ids($contacts), self::ids($companies)];
    }

    private function companyContacts(int $companyId): array
    {
        $cols = array_values(array_filter(['primary_contact_id', 'billing_contact_id', 'quote_contact_id'],
            function ($c) { return $this->hasColumn('companies', $c); }));
        if (!$cols) return [];
        $s = $this->db->prepare("SELECT " . implode(', ', $cols) . " FROM companies WHERE id = ?");
        $s->execute([$companyId]);
        return self::ids(array_values($s->fetch(PDO::FETCH_ASSOC) ?: []));
    }

    /** OR-clause matching a table's rows to the scope (ints only, so inlined safely). */
    private function scopeWhere(string $table, string $alias, array $contactIds, array $propertyIds, array $companyIds): string
    {
        $or = [];
        if ($contactIds && $this->hasColumn($table, 'contact_id')) {
            $or[] = "{$alias}.contact_id IN (" . implode(',', $contactIds) . ')';
        }
        if ($propertyIds && $this->hasColumn($table, 'property_id')) {
            $or[] = "{$alias}.property_id IN (" . implode(',', $propertyIds) . ')';
        }
        if ($companyIds && $this->hasColumn($table, 'company_id')) {
            $or[] = "{$alias}.company_id IN (" . implode(',', $companyIds) . ')';
        }
        return implode(' OR ', $or);
    }

    // ══════════════════════════════════════════════════════════════════════
    // RECOMPUTE / SWEEP
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Re-derive one contact's stage and write it if it changed.
     * @return array{from:string,to:string}|null  null when nothing was written
     */
    public function recompute(int $contactId, bool $dryRun = false): ?array
    {
        $r = $this->evaluateContact($contactId);
        if ($r['outcome'] !== 'change') return null;
        if (!$dryRun && !$this->writeContact($contactId, $r['to'])) return null;
        return ['from' => $r['from'], 'to' => $r['to']];
    }

    /** Same for a company (only if companies.lifecycle_stage exists). */
    public function recomputeCompany(int $companyId, bool $dryRun = false): ?array
    {
        $r = $this->evaluateCompany($companyId);
        if ($r['outcome'] !== 'change') return null;
        if (!$dryRun && !$this->writeCompany($companyId, $r['to'])) return null;
        return ['from' => $r['from'], 'to' => $r['to']];
    }

    /**
     * @return array{outcome:string, from:string, to:?string}
     *   outcome: change | unchanged | pinned | custom | missing | blocked
     */
    public function evaluateContact(int $contactId): array
    {
        if (!$this->hasColumn('contacts', 'lifecycle_stage')) return ['outcome' => 'missing', 'from' => '', 'to' => null];
        $pin = $this->hasColumn('contacts', 'lifecycle_pinned') ? 'lifecycle_pinned' : '0';
        $s = $this->db->prepare("SELECT lifecycle_stage, {$pin} AS pinned FROM contacts WHERE id = ?");
        $s->execute([$contactId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['outcome' => 'missing', 'from' => '', 'to' => null];
        return $this->classify((string)($row['lifecycle_stage'] ?? ''), (bool)(int)$row['pinned'],
            self::stageFor($this->factsFor($contactId)), self::CONTACT_MANAGED, 'contacts');
    }

    public function evaluateCompany(int $companyId): array
    {
        if (!$this->hasTable('companies') || !$this->hasColumn('companies', 'lifecycle_stage')) {
            return ['outcome' => 'missing', 'from' => '', 'to' => null];
        }
        $pin = $this->hasColumn('companies', 'lifecycle_pinned') ? 'lifecycle_pinned' : '0';
        $s = $this->db->prepare("SELECT lifecycle_stage, {$pin} AS pinned FROM companies WHERE id = ?");
        $s->execute([$companyId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['outcome' => 'missing', 'from' => '', 'to' => null];
        return $this->classify((string)($row['lifecycle_stage'] ?? ''), (bool)(int)$row['pinned'],
            self::companyKey(self::stageFor($this->factsForCompany($companyId))), self::COMPANY_MANAGED, 'companies');
    }

    private function classify(string $current, bool $pinned, string $target, array $managed, string $table): array
    {
        $from = trim($current);
        if ($pinned) return ['outcome' => 'pinned', 'from' => $from, 'to' => null];
        if (!in_array($from, $managed, true)) return ['outcome' => 'custom', 'from' => $from, 'to' => null];
        if (self::decide($from, false, $target, $managed) === null) return ['outcome' => 'unchanged', 'from' => $from, 'to' => $target];
        $allowed = $this->allowedKeys($table);
        if ($allowed !== null && !in_array($target, $allowed, true)) return ['outcome' => 'blocked', 'from' => $from, 'to' => $target];
        return ['outcome' => 'change', 'from' => $from, 'to' => $target];
    }

    private function writeContact(int $contactId, string $stage): bool
    {
        $sets = ['lifecycle_stage = ?'];
        $params = [$stage];
        if ($this->hasColumn('contacts', 'prospect_status') && isset(self::PROSPECT_STATUS_MAP[$stage])) {
            $sets[] = 'prospect_status = ?';
            $params[] = self::PROSPECT_STATUS_MAP[$stage];
        }
        $params[] = $contactId;
        $pinGuard = $this->hasColumn('contacts', 'lifecycle_pinned') ? ' AND COALESCE(lifecycle_pinned, 0) = 0' : '';
        $s = $this->db->prepare("UPDATE contacts SET " . implode(', ', $sets) . " WHERE id = ?{$pinGuard}");
        $s->execute($params);
        return $s->rowCount() > 0;
    }

    private function writeCompany(int $companyId, string $stage): bool
    {
        $pinGuard = $this->hasColumn('companies', 'lifecycle_pinned') ? ' AND COALESCE(lifecycle_pinned, 0) = 0' : '';
        $s = $this->db->prepare("UPDATE companies SET lifecycle_stage = ? WHERE id = ?{$pinGuard}");
        $s->execute([$stage, $companyId]);
        return $s->rowCount() > 0;
    }

    /**
     * Every active contact (and company), never touching pinned or hand-set ones.
     * @return array{dry_run:bool, contacts:array, companies:array, column_types:array}
     */
    public function sweep(?int $limit = null, bool $dryRun = false): array
    {
        $out = [
            'dry_run' => $dryRun,
            'as_of' => $this->now,
            'contacts' => $this->emptyTally(),
            'companies' => $this->emptyTally(),
            'column_types' => [
                'contacts' => $this->columnType('contacts', 'lifecycle_stage'),
                'companies' => $this->columnType('companies', 'lifecycle_stage'),
            ],
            'pin_column' => $this->hasColumn('contacts', 'lifecycle_pinned'),
        ];
        $lim = ($limit !== null && $limit > 0) ? ' LIMIT ' . (int)$limit : '';

        if ($this->hasColumn('contacts', 'lifecycle_stage')) {
            $active = $this->hasColumn('contacts', 'is_active') ? ' WHERE COALESCE(is_active, 1) = 1' : '';
            $ids = $this->db->query("SELECT id FROM contacts{$active} ORDER BY id{$lim}")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $this->tally($out['contacts'], (int)$id, function ($id) { return $this->evaluateContact($id); },
                    $dryRun ? null : function ($id, $to) { return $this->writeContact($id, $to); });
            }
        }
        if ($this->hasTable('companies') && $this->hasColumn('companies', 'lifecycle_stage')) {
            $ids = $this->db->query("SELECT id FROM companies ORDER BY id{$lim}")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $this->tally($out['companies'], (int)$id, function ($id) { return $this->evaluateCompany($id); },
                    $dryRun ? null : function ($id, $to) { return $this->writeCompany($id, $to); });
            }
        }
        return $out;
    }

    private function emptyTally(): array
    {
        return ['examined' => 0, 'changed' => 0, 'unchanged' => 0, 'pinned' => 0, 'custom' => 0,
                'blocked' => 0, 'errors' => 0, 'transitions' => [], 'samples' => [], 'custom_stages' => []];
    }

    private function tally(array &$t, int $id, callable $evaluate, ?callable $write): void
    {
        $t['examined']++;
        try {
            $r = $evaluate($id);
            $o = $r['outcome'];
            if ($o === 'change') {
                if ($write !== null && !$write($id, $r['to'])) { $t['unchanged']++; return; }
                $key = ($r['from'] === '' ? '(blank)' : $r['from']) . '→' . $r['to'];
                $t['changed']++;
                $t['transitions'][$key] = ($t['transitions'][$key] ?? 0) + 1;
                if (count($t['samples'][$key] ?? []) < self::SAMPLE_LIMIT) $t['samples'][$key][] = $id;
            } elseif ($o === 'custom') {
                $t['custom']++;
                $t['custom_stages'][$r['from']] = ($t['custom_stages'][$r['from']] ?? 0) + 1;
            } elseif (isset($t[$o])) {
                $t[$o]++;
            }
        } catch (Throwable $e) {
            $t['errors']++;
            error_log('[pipeline] #' . $id . ' ' . $e->getMessage());
        }
    }

    /** One-line summary of a sweep for logs and cron_runs. */
    public static function summarize(array $result): string
    {
        $parts = [];
        foreach (['contacts', 'companies'] as $k) {
            $t = $result[$k] ?? null;
            if (!$t || !$t['examined']) continue;
            $tr = [];
            foreach ($t['transitions'] as $key => $n) $tr[] = "{$key} {$n}";
            $parts[] = sprintf('%s: %d examined, %d %s, %d pinned, %d custom, %d blocked, %d errors%s',
                $k, $t['examined'], $t['changed'], !empty($result['dry_run']) ? 'would change' : 'changed',
                $t['pinned'], $t['custom'], $t['blocked'], $t['errors'], $tr ? ' (' . implode(', ', $tr) . ')' : '');
        }
        return $parts ? implode('; ', $parts) : 'Nothing to examine';
    }

    // ══════════════════════════════════════════════════════════════════════
    // EVENT HOOK
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Fire-and-forget hook for the places a stage can move: 'contract', 'quote', 'invoice',
     * 'job_plan' or 'contact'. Recomputes every contact and company the record touches.
     * Never throws — the main action must never fail because of this.
     */
    public static function onEvent(PDO $db, string $entity, int $id): void
    {
        if ($id <= 0) return;
        try {
            $svc = new self($db);
            [$contacts, $companies] = $svc->affectedBy($entity, $id);
            foreach ($contacts as $c) {
                try { $svc->recompute($c); } catch (Throwable $e) { error_log("[pipeline] {$entity}#{$id} contact#{$c}: " . $e->getMessage()); }
            }
            foreach ($companies as $c) {
                try { $svc->recomputeCompany($c); } catch (Throwable $e) { error_log("[pipeline] {$entity}#{$id} company#{$c}: " . $e->getMessage()); }
            }
        } catch (Throwable $e) {
            error_log("[pipeline] {$entity}#{$id}: " . $e->getMessage());
        }
    }

    /** @return array{0:int[],1:int[]} contacts, companies */
    public function affectedBy(string $entity, int $id): array
    {
        if ($entity === 'contact') return [[$id], $this->companiesRepresentedBy($id)];
        $table = ['contract' => 'contracts', 'quote' => 'quotes', 'invoice' => 'invoices', 'job_plan' => 'job_plans'][$entity] ?? null;
        if ($table === null || !$this->hasTable($table)) return [[], []];
        $cols = array_values(array_filter(['contact_id', 'property_id', 'company_id'],
            function ($c) use ($table) { return $this->hasColumn($table, $c); }));
        if (!$cols) return [[], []];
        $s = $this->db->prepare("SELECT " . implode(', ', $cols) . " FROM {$table} WHERE id = ?");
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        $contacts = [];
        $companies = [];
        if (!empty($row['contact_id'])) {
            $contacts[] = (int)$row['contact_id'];
            $companies = array_merge($companies, $this->companiesRepresentedBy((int)$row['contact_id']));
        }
        if (!empty($row['company_id'])) {
            $companies[] = (int)$row['company_id'];
            if ($this->hasTable('companies')) $contacts = array_merge($contacts, $this->companyContacts((int)$row['company_id']));
        }
        if (!empty($row['property_id'])) {
            [$pc, $pco] = $this->linkedToProperty((int)$row['property_id']);
            $contacts = array_merge($contacts, $pc);
            $companies = array_merge($companies, $pco);
        }
        return [self::ids($contacts), self::ids($companies)];
    }

    // ══════════════════════════════════════════════════════════════════════
    // PROBES
    // ══════════════════════════════════════════════════════════════════════

    public function hasTable(string $t): bool
    {
        $k = 't:' . $t;
        if (!isset($this->probe[$k])) {
            try {
                $this->db->query('SELECT 1 FROM `' . preg_replace('/[^a-z0-9_]/', '', $t) . '` LIMIT 0');
                $this->probe[$k] = true;
            } catch (Throwable $e) {
                $this->probe[$k] = false;
            }
        }
        return $this->probe[$k];
    }

    public function hasColumn(string $table, string $col): bool
    {
        $k = 'c:' . $table . '.' . $col;
        if (!isset($this->probe[$k])) {
            try {
                $this->db->query('SELECT `' . preg_replace('/[^a-z0-9_]/', '', $col) . '` FROM `' . preg_replace('/[^a-z0-9_]/', '', $table) . '` LIMIT 0');
                $this->probe[$k] = true;
            } catch (Throwable $e) {
                $this->probe[$k] = false;
            }
        }
        return $this->probe[$k];
    }

    /** MySQL column type (e.g. "varchar(50)" or "enum(...)"), or null when unknown. */
    public function columnType(string $table, string $col): ?string
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return null;
        try {
            $r = $this->db->query('SHOW COLUMNS FROM `' . preg_replace('/[^a-z0-9_]/', '', $table) . '` LIKE '
                . $this->db->quote(preg_replace('/[^a-z0-9_]/', '', $col)))->fetch(PDO::FETCH_ASSOC);
            return $r ? (string)$r['Type'] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Keys a write may use: active lifecycle_stages keys (contacts/companies carry an FK to it
     * after migration 023) intersected with the column's ENUM values if it is still an ENUM.
     * null = no restriction known.
     */
    public function allowedKeys(string $table): ?array
    {
        if (array_key_exists($table, $this->allowed)) return $this->allowed[$table];
        $keys = null;
        if ($this->hasTable('lifecycle_stages')) {
            try {
                $keys = array_map('strval', $this->db->query("SELECT stage_key FROM lifecycle_stages")->fetchAll(PDO::FETCH_COLUMN));
                if (!$keys) $keys = null;
            } catch (Throwable $e) {
                $keys = null;
            }
        }
        $type = $this->columnType($table, 'lifecycle_stage');
        if ($type !== null && stripos($type, 'enum(') === 0 && preg_match_all("/'((?:[^']|'')*)'/", $type, $m)) {
            $enum = array_map(function ($v) { return str_replace("''", "'", $v); }, $m[1]);
            $keys = $keys === null ? $enum : array_values(array_intersect($keys, $enum));
        }
        return $this->allowed[$table] = $keys;
    }

    /** @return int[] unique positive ints */
    private static function ids(array $ids): array
    {
        $out = [];
        foreach ($ids as $v) {
            $i = (int)$v;
            if ($i > 0) $out[$i] = $i;
        }
        return array_values($out);
    }
}
