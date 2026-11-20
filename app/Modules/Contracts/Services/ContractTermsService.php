<?php
declare(strict_types=1);

/**
 * ContractTermsService
 *
 * Resolves which terms and conditions apply to a contract, and snapshots the
 * wording at the moment it is signed.
 *
 * Why a snapshot and not a foreign key: a template is editable. If a client
 * signs today and the wording is revised in March, a join would show the March
 * wording as though it were what they agreed to. For snow and ice that is not a
 * cosmetic problem — the liability disclaimers are the entire reason the
 * document exists, and the question after an incident is always "what did they
 * actually see". So the body is copied, byte for byte, onto the contract
 * version and hashed onto the signature row.
 *
 * Resolution order (first match wins):
 *   1. contracts.terms_template_id — an explicit choice always beats inference
 *   2. a service_type template matching any job_plan on the contract
 *   3. the global default (is_default = 1)
 *   4. null — caller decides whether that is an error
 *
 * Every read is guarded against the tables/columns not existing yet, because
 * production runs migrations by hand and this file will be deployed before
 * 1119 is applied. Same pattern as contracts/view.php's write-throughs.
 *
 * No namespace — loaded via require_once (consistent with other services).
 */
class ContractTermsService
{
    public function __construct(private PDO $db) {}

    /** Memoised per request — schema checks are cheap but not free. */
    private ?bool $tablePresent = null;

    /**
     * True when migration 1119 has been applied in this environment.
     * Everything else in this class degrades to a no-op when it hasn't.
     */
    public function isAvailable(): bool
    {
        if ($this->tablePresent !== null) {
            return $this->tablePresent;
        }
        try {
            $this->db->query("SELECT 1 FROM contract_terms_templates LIMIT 1")->closeCursor();
            $this->tablePresent = true;
        } catch (Throwable $e) {
            $this->tablePresent = false;
        }
        return $this->tablePresent;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RESOLUTION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The terms that apply to a contract right now, as a template row, or null.
     */
    public function resolveForContract(int $contractId): ?array
    {
        if (!$this->isAvailable() || $contractId <= 0) {
            return null;
        }

        // 1. Explicit choice on the contract.
        try {
            $stmt = $this->db->prepare("
                SELECT t.*
                FROM contracts c
                JOIN contract_terms_templates t ON t.id = c.terms_template_id
                WHERE c.id = ? AND t.is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$contractId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        } catch (Throwable $e) {
            // contracts.terms_template_id not migrated yet — fall through.
        }

        // 2. Service type of any plan on the contract. A contract can span
        //    several service types (service_type lives on job_plans, never on
        //    the contract), so the most specific template wins by sort_order —
        //    snow beats mowing on a mixed contract, which is the safe default:
        //    the stricter liability position should govern the document.
        try {
            $stmt = $this->db->prepare("
                SELECT t.*
                FROM job_plans jp
                JOIN contract_terms_templates t
                  ON t.scope = 'service_type'
                 AND t.service_type = jp.service_type
                 AND t.is_active = 1
                WHERE jp.contract_id = ?
                ORDER BY t.sort_order ASC, t.id ASC
                LIMIT 1
            ");
            $stmt->execute([$contractId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        } catch (Throwable $e) {
            // job_plans.contract_id missing in this environment — fall through.
        }

        return $this->defaultTemplate();
    }

    /** The global fallback template, or null when none is marked default. */
    public function defaultTemplate(): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        try {
            $stmt = $this->db->query("
                SELECT * FROM contract_terms_templates
                WHERE is_active = 1 AND is_default = 1
                ORDER BY sort_order ASC, id ASC
                LIMIT 1
            ");
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SNAPSHOT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Copy the resolved terms onto a contract version row.
     *
     * Called by ContractService::snapshotVersion() immediately after the row is
     * inserted, so the version carries the wording as well as the numbers.
     * Silently does nothing when the columns aren't there — a missing snapshot
     * must never block a signature request going out.
     */
    public function snapshotOntoVersion(int $contractId, int $versionNumber): ?array
    {
        $tpl = $this->resolveForContract($contractId);
        if (!$tpl) {
            return null;
        }
        try {
            $this->db->prepare("
                UPDATE contract_versions
                   SET terms_template_id = ?, terms_template_version = ?, terms_body = ?
                 WHERE contract_id = ? AND version_number = ?
            ")->execute([
                (int)$tpl['id'],
                (int)$tpl['version'],
                (string)$tpl['body'],
                $contractId,
                $versionNumber,
            ]);
        } catch (Throwable $e) {
            error_log('[ContractTermsService] version snapshot failed: ' . $e->getMessage());
            return null;
        }
        return $tpl;
    }

    /**
     * The exact wording a given contract version was sealed with.
     *
     * Prefers the snapshot. Falls back to live resolution ONLY for contracts
     * predating this migration, and says which it gave you so a caller can tell
     * a proven record from a best guess.
     *
     * @return array{body:string, snapshot:bool, template_id:?int, template_version:?int}|null
     */
    public function termsForVersion(int $contractId, int $versionNumber): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT terms_body, terms_template_id, terms_template_version
                  FROM contract_versions
                 WHERE contract_id = ? AND version_number = ?
                 LIMIT 1
            ");
            $stmt->execute([$contractId, $versionNumber]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            // Null-coalesced rather than indexed: a driver that tolerates the
            // missing column (SQLite in tests, and some MySQL setups) returns
            // the row without the key rather than throwing, and a warning here
            // would be noise on every pre-1119 contract.
            $body = (string)($row['terms_body'] ?? '');
            if ($row && trim($body) !== '') {
                return [
                    'body'             => $body,
                    'snapshot'         => true,
                    'template_id'      => isset($row['terms_template_id']) ? (int)$row['terms_template_id'] : null,
                    'template_version' => isset($row['terms_template_version']) ? (int)$row['terms_template_version'] : null,
                ];
            }
        } catch (Throwable $e) {
            // Columns not migrated — fall through to live resolution.
        }

        $tpl = $this->resolveForContract($contractId);
        if (!$tpl) {
            return null;
        }
        return [
            'body'             => (string)$tpl['body'],
            'snapshot'         => false,
            'template_id'      => (int)$tpl['id'],
            'template_version' => (int)$tpl['version'],
        ];
    }

    /**
     * Stable fingerprint of a terms body, stored against the signature so the
     * signed wording can be proven unchanged without a second copy of it.
     * Whitespace is normalised so a reflow doesn't read as a different document.
     */
    public static function hashBody(string $body): string
    {
        return hash('sha256', preg_replace('/\s+/u', ' ', trim($body)) ?? trim($body));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SEASONAL GUARD
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Season boundaries for a template, resolved against a reference date.
     *
     * "November 1st to March 31st" spans a year end, so the end date belongs to
     * the FOLLOWING calendar year whenever the season wraps. Getting this wrong
     * is how a snow contract ends four months before it starts.
     *
     * @return array{start:string, end:string}|null  Y-m-d pair, or null if not seasonal
     */
    public function seasonWindow(array $template, ?string $referenceDate = null): ?array
    {
        $start = (string)($template['season_start'] ?? '');
        $end   = (string)($template['season_end'] ?? '');
        if ($start === '' || $end === '') {
            return null;
        }

        $ref     = $referenceDate ? strtotime($referenceDate) : time();
        $refYear = (int)date('Y', $ref);
        $wraps   = strcmp($end, $start) < 0;

        // A wrapping season ('11-01' → '03-31') belongs to the year it OPENED
        // in. The test is therefore the end date, not the start: on or before
        // this year's end date we are still in the tail of last year's season;
        // after it we are in — or waiting for — this year's.
        //
        //   15 Oct 2026 → past 31 Mar 2026, so the season is 2026/27  ✔
        //   20 Jan 2027 → on or before 31 Mar 2027, so it is 2026/27  ✔
        //
        // Anchoring on the start date instead returns 2025/26 for October, a
        // season that ended six months earlier.
        if ($wraps) {
            $endThisYear = strtotime(sprintf('%d-%s', $refYear, $end) . ' 23:59:59');
            $startYear   = $ref <= $endThisYear ? $refYear - 1 : $refYear;
        } else {
            $startYear = $refYear;
        }
        $endYear = $wraps ? $startYear + 1 : $startYear;

        return [
            'start' => sprintf('%d-%s', $startYear, $start),
            'end'   => sprintf('%d-%s', $endYear, $end),
        ];
    }

    /** True when a contract on this template must never auto-renew. */
    public function forcesNoAutoRenew(array $template): bool
    {
        return !empty($template['forces_no_auto_renew']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function listTemplates(bool $activeOnly = false): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $sql = "SELECT * FROM contract_terms_templates"
             . ($activeOnly ? " WHERE is_active = 1" : "")
             . " ORDER BY sort_order ASC, name ASC";
        try {
            return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getTemplate(int $id): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT * FROM contract_terms_templates WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Create or update a template.
     *
     * Editing the body bumps `version`. That number is what a snapshot records,
     * so it is the only way to answer "which revision did they sign" once a
     * template has been revised more than once.
     *
     * @return int the template id
     */
    public function saveTemplate(array $data, ?int $userId = null): int
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('Contract terms are not available — migration 1119 has not been applied.');
        }

        $id   = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $body = (string)($data['body'] ?? '');
        if ($name === '') {
            throw new InvalidArgumentException('A terms template needs a name.');
        }
        if (trim($body) === '') {
            throw new InvalidArgumentException('A terms template needs a body — an empty one would be worse than none.');
        }

        $scope       = ($data['scope'] ?? 'service_type') === 'global' ? 'global' : 'service_type';
        $serviceType = $scope === 'service_type' ? (trim((string)($data['service_type'] ?? '')) ?: null) : null;
        $seasonStart = $this->normaliseSeasonDate($data['season_start'] ?? null);
        $seasonEnd   = $this->normaliseSeasonDate($data['season_end'] ?? null);
        $noRenew     = !empty($data['forces_no_auto_renew']) ? 1 : 0;
        $isActive    = array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1;
        $isDefault   = !empty($data['is_default']) ? 1 : 0;
        $sortOrder   = (int)($data['sort_order'] ?? 0);

        if ($id > 0) {
            $existing = $this->getTemplate($id);
            if (!$existing) {
                throw new InvalidArgumentException("Terms template {$id} not found.");
            }
            // Only a body change is a new revision. Renaming it, or toggling it
            // active, must not invalidate what previous signatures point at.
            $version = (int)$existing['version'] + (self::hashBody((string)$existing['body']) !== self::hashBody($body) ? 1 : 0);

            $this->db->prepare("
                UPDATE contract_terms_templates
                   SET name = ?, scope = ?, service_type = ?, body = ?, version = ?,
                       season_start = ?, season_end = ?, forces_no_auto_renew = ?,
                       is_active = ?, is_default = ?, sort_order = ?
                 WHERE id = ?
            ")->execute([
                $name, $scope, $serviceType, $body, $version,
                $seasonStart, $seasonEnd, $noRenew,
                $isActive, $isDefault, $sortOrder, $id,
            ]);
        } else {
            $slug = $this->uniqueSlug($name);
            $this->db->prepare("
                INSERT INTO contract_terms_templates
                    (name, slug, scope, service_type, body, version,
                     season_start, season_end, forces_no_auto_renew,
                     is_active, is_default, sort_order, created_by)
                VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $name, $slug, $scope, $serviceType, $body,
                $seasonStart, $seasonEnd, $noRenew,
                $isActive, $isDefault, $sortOrder, $userId,
            ]);
            $id = (int)$this->db->lastInsertId();
        }

        // Exactly one default, always. Two defaults means resolution depends on
        // sort order, which nobody would think to check.
        if ($isDefault) {
            $this->db->prepare(
                "UPDATE contract_terms_templates SET is_default = 0 WHERE id <> ?"
            )->execute([$id]);
        }

        return $id;
    }

    /** 'MM-DD', or null for anything that isn't one. */
    private function normaliseSeasonDate($value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }
        $month = (int)$m[1];
        $day   = (int)$m[2];
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }
        return $value;
    }

    private function uniqueSlug(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-') ?: 'terms';
        $slug = $base;
        $n    = 2;
        while (true) {
            $stmt = $this->db->prepare("SELECT 1 FROM contract_terms_templates WHERE slug = ? LIMIT 1");
            $stmt->execute([$slug]);
            if (!$stmt->fetchColumn()) {
                return $slug;
            }
            $slug = $base . '-' . $n++;
            if ($n > 200) {
                return $base . '-' . bin2hex(random_bytes(3));
            }
        }
    }
}
