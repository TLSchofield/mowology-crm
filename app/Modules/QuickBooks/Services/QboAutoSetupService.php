<?php
declare(strict_types=1);

require_once __DIR__ . '/QboConnectionService.php';
require_once __DIR__ . '/QboSyncService.php';
require_once __DIR__ . '/QboExpenseSyncService.php';

/** Safe, once-only defaults for a newly connected non-default business. */
class QboAutoSetupService
{
    private QboConnectionService $conn;
    private PDO $db;
    /** @var callable(): (DateTimeInterface|int) */
    private $clock;
    private bool $isDefaultTenant;

    public function __construct(QboConnectionService $conn, ?callable $clock = null, ?bool $isDefaultTenant = null)
    {
        $this->conn = $conn;
        $this->db = $conn->db();
        $this->clock = $clock ?? fn() => new DateTimeImmutable('now');
        $this->isDefaultTenant = $isDefaultTenant ?? Tenant::isDefault();
    }

    public function eligible(): bool
    {
        return !$this->isDefaultTenant && ($this->conn->status()['connected'] ?? false);
    }

    /** @return array{saved:list<string>, needs:list<array{kind:string,key:string,label:string}>} */
    public function autoMap(): array
    {
        if (!$this->eligible()) return ['saved' => [], 'needs' => []];
        $maps = $this->conn->mappings();
        $saved = [];
        $needs = [];
        $remoteAccounts = $this->conn->remote('account');
        $claimed = [];
        foreach ($maps['account'] as $map) if (!empty($map['qbo_id'])) $claimed[(string)$map['qbo_id']] = true;

        foreach ($this->conn->ittaiAccounts() as $account) {
            $key = (string)$account['code'];
            $label = 'Pick the QuickBooks account for ' . (string)$account['name'] . '.';
            if (!empty($maps['account'][$key]['qbo_id'])) continue;
            $fit = QboConnectionService::fitting((string)$account['type'], $remoteAccounts);
            $id = QboConnectionService::suggestAccount((string)$account['name'], $fit);
            if ($id !== null && empty($claimed[$id]) && $this->conn->setMapping('account', $key, $id, 0)) {
                $saved[] = 'account:' . $key;
                $claimed[$id] = true;
            } else {
                $needs[] = ['kind' => 'account', 'key' => $key, 'label' => $label];
            }
        }

        foreach (['1010' => 'Bank', '2400' => 'Credit Card'] as $key => $type) {
            $key = (string)$key;
            if (!empty($maps['pay_account'][$key]['qbo_id'])) continue;
            $matches = array_values(array_filter($remoteAccounts, function (array $row) use ($type): bool {
                return trim(explode(' ·', (string)($row['detail'] ?? ''))[0]) === $type;
            }));
            if (count($matches) === 1 && $this->conn->setMapping('pay_account', $key, (string)$matches[0]['qbo_id'], 0)) {
                $saved[] = 'pay_account:' . $key;
            } else {
                $needs[] = ['kind' => 'pay_account', 'key' => $key, 'label' => 'Pick the QuickBooks account for ' . $key . ' ' . $type . '.'];
            }
        }

        $remoteTax = $this->conn->remote('tax_code');
        foreach (QboConnectionService::TAX_KEYS as $key => $name) {
            if (!empty($maps['tax_code'][$key]['qbo_id'])) continue;
            $id = QboConnectionService::suggestTaxCode($key, $remoteTax);
            if ($id !== null && $this->conn->setMapping('tax_code', $key, $id, 0)) {
                $saved[] = 'tax_code:' . $key;
            } else {
                $needs[] = ['kind' => 'tax_code', 'key' => $key, 'label' => 'Pick the QuickBooks tax code for ' . $name . '.'];
            }
        }
        return ['saved' => $saved, 'needs' => $needs];
    }

    /** Mapping keys written automatically, as kind:key. @return list<string> */
    public function autoKeys(): array
    {
        $s = $this->db->prepare('SELECT kind, ittai_key FROM qbo_mappings WHERE site_id = ? AND set_by IS NULL');
        $s->execute([$this->conn->siteId()]);
        return array_map(fn($r) => (string)$r['kind'] . ':' . (string)$r['ittai_key'], $s->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Current unmapped choices for the mapping page. @return list<array{kind:string,key:string,label:string}> */
    public function needs(): array
    {
        if (!$this->eligible()) return [];
        $maps = $this->conn->mappings();
        $needs = [];
        foreach ($this->conn->ittaiAccounts() as $account) {
            $key = (string)$account['code'];
            if (empty($maps['account'][$key]['qbo_id'])) $needs[] = ['kind' => 'account', 'key' => $key, 'label' => 'Pick the QuickBooks account for ' . (string)$account['name'] . '.'];
        }
        foreach (['1010' => 'Bank', '2400' => 'Credit Card'] as $key => $type) {
            $key = (string)$key;
            if (empty($maps['pay_account'][$key]['qbo_id'])) $needs[] = ['kind' => 'pay_account', 'key' => $key, 'label' => 'Pick the QuickBooks account for ' . $key . ' ' . $type . '.'];
        }
        foreach (QboConnectionService::TAX_KEYS as $key => $name) {
            if (empty($maps['tax_code'][$key]['qbo_id'])) $needs[] = ['kind' => 'tax_code', 'key' => $key, 'label' => 'Pick the QuickBooks tax code for ' . $name . '.'];
        }
        return $needs;
    }

    public function enableSending(): bool
    {
        if (!$this->eligible()) return false;
        $sync = new QboSyncService($this->conn);
        if ($sync->settingValue('qbo_autosetup_done') !== null || $sync->settingValue('qbo_auto_from') !== null) return false;
        $now = ($this->clock)();
        if (is_int($now)) $now = (new DateTimeImmutable('@' . $now));
        if (!$now instanceof DateTimeImmutable) $now = new DateTimeImmutable($now->format(DateTimeInterface::ATOM));
        $today = QboSyncService::businessToday($this->db, $now);
        $this->db->beginTransaction();
        try {
            if (!$sync->setAuto(true, $today)) { $this->db->rollBack(); return false; }
            (new QboExpenseSyncService($this->conn))->setReceipts(true);
            $sync->setOperationalSetting('qbo_live_sends', '1', 'QuickBooks: owner-approved live sends for this business');
            $sync->setOperationalSetting('qbo_autosetup_done', $today, 'QuickBooks: automatic setup completed once');
            $sync->releaseHeld();
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return true;
    }

    public function run(): array
    {
        if (!$this->eligible()) return ['mapped' => [], 'needs' => [], 'sending' => false, 'message' => ''];
        // Nothing read from QuickBooks yet (a failed pull): do nothing and leave the once-only marker unset, so a later run does it.
        if (!$this->conn->remote('account') || !$this->conn->remote('tax_code')) return ['mapped' => [], 'needs' => [], 'sending' => false, 'message' => ''];
        $mapped = $this->autoMap();
        $sending = $this->enableSending();
        $message = 'Mapped ' . count($mapped['saved']) . ' automatically. ' . count($mapped['needs']) . ' need you.';
        $auto = (new QboSyncService($this->conn))->auto();
        if ($sending && $auto['from'] !== null) $message .= ' Sending to QuickBooks from ' . $auto['from'] . '.';
        return ['mapped' => $mapped['saved'], 'needs' => $mapped['needs'], 'sending' => $sending, 'message' => $message];
    }
}
