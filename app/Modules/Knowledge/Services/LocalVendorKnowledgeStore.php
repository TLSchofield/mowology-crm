<?php
/**
 * LocalVendorKnowledgeStore — the hub's own commons tables (migration 1330).
 *
 * Promotion rule lives here (one place):
 *   - receipt_layout, sku, kind      → 'shared' as soon as one tenant confirms (describe the paper)
 *   - category_default, tax_treatment → 'shared' when >= 2 distinct tenants confirm, or curated
 *   - a 'blocked' fact never comes back; a 'curated' vendor's facts are not auto-demoted
 * A tenant can confirm the same fact many times; `tenants` only counts distinct tenant hashes.
 */
declare(strict_types=1);

require_once __DIR__ . '/VendorKnowledgeStore.php';
require_once __DIR__ . '/VendorIdentity.php';

final class LocalVendorKnowledgeStore implements VendorKnowledgeStore
{
    public const SHARE_AT_ONE = ['receipt_layout', 'sku', 'kind'];
    public const SHARE_AT     = 2;     // distinct tenants for business-flavoured facts

    private PDO $db;
    private ?bool $ready = null;
    /** Hub mode sees candidate facts of its own; remote callers get shared only. */
    private bool $includeCandidates;

    public function __construct(PDO $db, bool $includeCandidates = true)
    {
        $this->db = $db;
        $this->includeCandidates = $includeCandidates;
    }

    public function ready(): bool
    {
        if ($this->ready !== null) return $this->ready;
        try {
            $this->ready = $this->db->query("SHOW TABLES LIKE 'kb_vendors'")->rowCount() > 0;
        } catch (Throwable $e) {
            $this->ready = false;
        }
        return $this->ready;
    }

    // ── Resolve ───────────────────────────────────────────────────────────────

    public function resolve(array $identity): ?array
    {
        if (!$this->ready()) return null;
        $id = $this->findVendorId($identity);
        return $id ? $this->load($id) : null;
    }

    /** Strongest evidence first: GST number, phone, domain, then exact name key / alias. */
    public function findVendorId(array $identity): ?int
    {
        $tries = [
            ['gst_number', $identity['gst_number'] ?? null],
            ['phone_digits', $identity['phone_digits'] ?? null],
            ['domain', $identity['domain'] ?? null],
            ['name_key', $identity['name_key'] ?? null],
        ];
        foreach ($tries as [$col, $val]) {
            if ($val === null || $val === '') continue;
            $s = $this->db->prepare("SELECT id FROM kb_vendors WHERE {$col} = ? AND status <> 'blocked' ORDER BY tenant_count DESC, id LIMIT 1");
            $s->execute([$val]);
            $id = $s->fetchColumn();
            if ($id) return (int)$id;
        }
        if (!empty($identity['name_key'])) {
            $s = $this->db->prepare("SELECT a.kb_vendor_id FROM kb_vendor_aliases a JOIN kb_vendors v ON v.id = a.kb_vendor_id WHERE a.alias_key = ? AND v.status <> 'blocked' LIMIT 1");
            $s->execute([$identity['name_key']]);
            $id = $s->fetchColumn();
            if ($id) return (int)$id;
        }
        return null;
    }

    public function load(int $id): ?array
    {
        $s = $this->db->prepare("SELECT id, display_name, name_key, gst_number, phone_digits, domain, country, region, kind, status, tenant_count FROM kb_vendors WHERE id = ?");
        $s->execute([$id]);
        $v = $s->fetch(PDO::FETCH_ASSOC);
        if (!$v) return null;
        $v['id'] = (int)$v['id'];
        $v['tenant_count'] = (int)$v['tenant_count'];
        $v['facts'] = [];
        $statuses = $this->includeCandidates ? "('shared','candidate')" : "('shared')";
        $f = $this->db->prepare("SELECT fact, value_json, status, tenants, confirmations FROM kb_vendor_facts WHERE kb_vendor_id = ? AND status IN {$statuses} ORDER BY FIELD(status,'shared','candidate'), tenants DESC, confirmations DESC, id");
        $f->execute([$id]);
        foreach ($f->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $v['facts'][$row['fact']][] = [
                'value'         => json_decode($row['value_json'], true),
                'status'        => $row['status'],
                'tenants'       => (int)$row['tenants'],
                'confirmations' => (int)$row['confirmations'],
            ];
        }
        return $v;
    }

    // ── Contribute ────────────────────────────────────────────────────────────

    public function contribute(array $lesson, string $tenantKey)
    {
        if (!$this->ready()) return false;
        $identity = $lesson['identity'] ?? [];
        $display  = trim((string)($lesson['display_name'] ?? ($identity['display_name'] ?? '')));
        $nameKey  = $identity['name_key'] ?? VendorIdentity::nameKey($display);
        if ($nameKey === null && empty($identity['gst_number']) && empty($identity['phone_digits']) && empty($identity['domain'])) {
            return false;   // nothing identifying — refuse rather than pollute
        }
        $tenantHash = VendorIdentity::tenantHash($tenantKey);
        try {
            $this->db->beginTransaction();
            $id = $this->findVendorId($identity + ['name_key' => $nameKey]);
            if ($id === null) {
                $this->db->prepare("INSERT INTO kb_vendors (name_key, display_name, gst_number, phone_digits, domain, kind, status, tenant_count) VALUES (?,?,?,?,?,?, 'candidate', 1)")
                    ->execute([$nameKey ?? 'unknown', $display !== '' ? $display : ($nameKey ?? 'Unknown'), $identity['gst_number'] ?? null, $identity['phone_digits'] ?? null, $identity['domain'] ?? null, $lesson['kind'] ?? null]);
                $id = (int)$this->db->lastInsertId();
            } else {
                // Fill identity gaps the existing row is missing; never overwrite a filled value.
                $this->db->prepare("UPDATE kb_vendors SET gst_number = COALESCE(gst_number, ?), phone_digits = COALESCE(phone_digits, ?), domain = COALESCE(domain, ?), kind = COALESCE(kind, ?) WHERE id = ?")
                    ->execute([$identity['gst_number'] ?? null, $identity['phone_digits'] ?? null, $identity['domain'] ?? null, $lesson['kind'] ?? null, $id]);
                if ($nameKey !== null) {
                    $this->db->prepare("INSERT IGNORE INTO kb_vendor_aliases (kb_vendor_id, alias_key) VALUES (?, ?)")->execute([$id, $nameKey]);
                }
            }
            foreach ($lesson['facts'] ?? [] as $f) {
                $fact = (string)($f['fact'] ?? '');
                if (!in_array($fact, ['receipt_layout', 'sku', 'category_default', 'tax_treatment', 'kind'], true)) continue;
                $this->upsertFact($id, $fact, $f['value'], $tenantHash);
            }
            // Distinct tenants on the vendor = distinct tenant hashes across its facts.
            $this->db->prepare("UPDATE kb_vendors v SET tenant_count = (SELECT COUNT(DISTINCT t.tenant_hash) FROM kb_vendor_facts f JOIN kb_fact_tenants t ON t.fact_id = f.id WHERE f.kb_vendor_id = v.id), status = CASE WHEN status IN ('curated','blocked') THEN status WHEN (SELECT COUNT(DISTINCT t.tenant_hash) FROM kb_vendor_facts f JOIN kb_fact_tenants t ON t.fact_id = f.id WHERE f.kb_vendor_id = v.id) >= ? THEN 'shared' ELSE status END WHERE v.id = ?")
                ->execute([self::SHARE_AT, $id]);
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('kb contribute: ' . $e->getMessage());
            return false;
        }
    }

    private function upsertFact(int $vendorId, string $fact, $value, string $tenantHash): void
    {
        $hash = VendorIdentity::valueHash($value);
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        $this->db->prepare("INSERT INTO kb_vendor_facts (kb_vendor_id, fact, value_hash, value_json, confirmations, tenants, status) VALUES (?,?,?,?,1,1,'candidate') ON DUPLICATE KEY UPDATE confirmations = confirmations + 1, value_json = VALUES(value_json)")
            ->execute([$vendorId, $fact, $hash, $json]);
        $s = $this->db->prepare("SELECT id, status FROM kb_vendor_facts WHERE kb_vendor_id = ? AND fact = ? AND value_hash = ?");
        $s->execute([$vendorId, $fact, $hash]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;
        $factId = (int)$row['id'];
        $this->db->prepare("INSERT INTO kb_fact_tenants (fact_id, tenant_hash, confirmations) VALUES (?,?,1) ON DUPLICATE KEY UPDATE confirmations = confirmations + 1")
            ->execute([$factId, $tenantHash]);
        $tenants = (int)$this->db->query("SELECT COUNT(*) FROM kb_fact_tenants WHERE fact_id = " . $factId)->fetchColumn();
        $status = self::promotedStatus($fact, $tenants, (string)$row['status']);
        $this->db->prepare("UPDATE kb_vendor_facts SET tenants = ?, status = ? WHERE id = ?")->execute([$tenants, $status, $factId]);
    }

    /** The promotion rule, pure, unit-tested. */
    public static function promotedStatus(string $fact, int $distinctTenants, string $current): string
    {
        if ($current === 'blocked') return 'blocked';
        if ($current === 'shared') return 'shared';
        if (in_array($fact, self::SHARE_AT_ONE, true) && $distinctTenants >= 1) return 'shared';
        return $distinctTenants >= self::SHARE_AT ? 'shared' : 'candidate';
    }

    // ── Curation (hub owner) ──────────────────────────────────────────────────

    public function curateVendor(int $id, bool $share): void
    {
        $this->db->prepare("UPDATE kb_vendors SET status = ? WHERE id = ?")->execute([$share ? 'curated' : 'blocked', $id]);
        if ($share) {
            $this->db->prepare("UPDATE kb_vendor_facts SET status = 'shared' WHERE kb_vendor_id = ? AND status = 'candidate'")->execute([$id]);
        }
    }

    public function stats(): array
    {
        if (!$this->ready()) return ['vendors' => 0, 'shared' => 0, 'facts' => 0, 'tenants' => 0];
        return [
            'vendors' => (int)$this->db->query("SELECT COUNT(*) FROM kb_vendors WHERE status <> 'blocked'")->fetchColumn(),
            'shared'  => (int)$this->db->query("SELECT COUNT(*) FROM kb_vendors WHERE status IN ('shared','curated')")->fetchColumn(),
            'facts'   => (int)$this->db->query("SELECT COUNT(*) FROM kb_vendor_facts WHERE status = 'shared'")->fetchColumn(),
            'tenants' => (int)$this->db->query("SELECT COUNT(DISTINCT tenant_hash) FROM kb_fact_tenants")->fetchColumn(),
        ];
    }
}
