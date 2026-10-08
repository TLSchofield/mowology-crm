<?php
/**
 * QboPushService — the one place that writes to QuickBooks (2026-10-07).
 *
 * Phase 2, behind two ops_settings switches (migration 1240):
 *   qbo_push_enabled  0 = OFF (default). preview() still works — it writes nothing anywhere.
 *   qbo_dry_run       1 = ON (default). With push enabled and dry-run on, pushExpense() builds
 *                     the payload, records it in qbo_sync as 'dry_run' and stops.
 *
 * Rules:
 *   - one way, CRM → QBO; approved records only (QboPushPlanner refuses the rest)
 *   - idempotent: qbo_sync keeps qbo_id + SyncToken + content hash + requestid per CRM record;
 *     an unchanged record is skipped, a changed one is an UPDATE with the SyncToken, the
 *     requestid is stable per (record, content) so a retry can never create a duplicate
 *   - never delete in QBO; never write on/before the file's BookCloseDate or before FY2026
 *   - every request is logged in qbo_sync_log with Intuit's intuit_tid
 *
 * Expense push = Purchase, then the receipt image as an Attachable linked to it.
 */
declare(strict_types=1);

require_once __DIR__ . '/QboConfig.php';
require_once __DIR__ . '/QboTransport.php';
require_once __DIR__ . '/QboOAuthService.php';
require_once __DIR__ . '/QboConnectionService.php';
require_once __DIR__ . '/QboApiClient.php';
require_once __DIR__ . '/QboAccountMapService.php';
require_once __DIR__ . '/QboDiscoveryService.php';
require_once __DIR__ . '/QboPushPlanner.php';

class QboPushService
{
    public const FLAG_ENABLED = 'qbo_push_enabled';
    public const FLAG_DRY_RUN = 'qbo_dry_run';
    /** ops_settings keys for the choices Tim makes on the settings page. */
    public const SETTING_TAX_GST     = 'qbo_tax_gst';
    public const SETTING_TAX_GST_PST = 'qbo_tax_gst_pst';
    public const SETTING_TAX_EXEMPT  = 'qbo_tax_exempt';
    public const SETTING_ITEM        = 'qbo_item_service';
    public const SETTING_PAID_FROM   = 'qbo_paid_from';   // JSON: {cash: Account.Id, credit_card: Account.Id, ...}

    private PDO $db;
    private QboConfig $config;
    private QboTransport $transport;
    private QboConnectionService $connections;
    private QboAccountMapService $maps;
    private ?array $settings = null;

    public function __construct(PDO $db, ?QboConfig $config = null, ?QboTransport $transport = null)
    {
        $this->db        = $db;
        $this->config    = $config ?? QboConfig::fromConstants();
        $this->transport = $transport ?? new QboCurlTransport();
        $this->connections = new QboConnectionService($db, $this->config, new QboOAuthService($this->config, $this->transport));
        $this->maps      = new QboAccountMapService($db);
    }

    public function isEnabled(): bool
    {
        return $this->setting(self::FLAG_ENABLED, '0') === '1';
    }

    public function isDryRun(): bool
    {
        return $this->setting(self::FLAG_DRY_RUN, '1') !== '0';
    }

    /** Is the API the live route for receipts? (flag ON, not dry-run, connected) */
    public function isLive(): bool
    {
        return $this->isEnabled() && !$this->isDryRun() && $this->connections->active() !== null;
    }

    public function connections(): QboConnectionService { return $this->connections; }

    public function client(?callable $logger = null): QboApiClient
    {
        $conn = $this->connections->active();
        if ($conn === null) throw new RuntimeException('QuickBooks is not connected.');
        return new QboApiClient($this->config, $this->transport, $this->connections, $conn, $logger ?? [$this, 'log']);
    }

    /** qbo_sync_log writer (also used as the client's logger). */
    public function log(array $row): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO qbo_sync_log (connection_id, sync_id, direction, qbo_type, operation, http_status, request_id, intuit_tid, summary, payload_hash, error, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                (int)($row['connection_id'] ?? 0), $row['sync_id'] ?? null, $row['direction'] ?? 'push', $row['qbo_type'] ?? null,
                $row['operation'] ?? 'create', $row['http_status'] ?? null, $row['request_id'] ?? null, $row['intuit_tid'] ?? null,
                isset($row['summary']) ? substr((string)$row['summary'], 0, 255) : null, $row['payload_hash'] ?? null,
                isset($row['error']) ? substr((string)$row['error'], 0, 1000) : null, $row['created_by'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('[qbo] sync log: ' . $e->getMessage());
        }
    }

    /**
     * Dry-run preview: the next $limit approved expenses and exactly what each would become.
     * Writes nothing (not even qbo_sync). Works with the flag OFF.
     */
    public function preview(int $limit = 25): array
    {
        $conn = $this->connections->active();
        $ctxBase = $this->context($conn);
        $rows = [];
        $stmt = $this->db->prepare(
            "SELECT e.*, v.name AS vendor_name FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id
             WHERE e.status = 'approved' AND e.expense_date >= ? ORDER BY e.expense_date DESC, e.id DESC LIMIT " . max(1, min(200, $limit))
        );
        $stmt->execute([QboPushPlanner::PUSH_FROM]);
        $synced = $conn ? $this->syncRows((int)$conn['id'], 'expense') : [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $e) {
            $lines = $this->lines((int)$e['id']);
            $ctx = $ctxBase;
            $ctx['paid_from_qbo_id'] = $this->paidFrom($e['payment_method'] ?? null, $ctxBase);
            $ctx['vendor_qbo_id']    = $this->vendorTwin($conn, (int)($e['vendor_id'] ?? 0), (string)($e['vendor_name'] ?? $e['vendor_name_raw'] ?? ''), false);
            $plan = QboPushPlanner::purchaseFromExpense($e, $lines, $ctx);
            $prior = $synced[(int)$e['id']] ?? null;
            $action = 'create';
            if (!$plan['ok']) $action = 'blocked';
            elseif ($prior && $prior['status'] === 'synced' && $prior['content_hash'] === ($plan['hash'] ?? '')) $action = 'skip';
            elseif ($prior && $prior['status'] === 'synced') $action = 'update';
            $rows[] = [
                'crm_type' => 'expense', 'crm_id' => (int)$e['id'], 'qbo_type' => 'Purchase',
                'date' => $e['expense_date'], 'vendor' => $e['vendor_name'] ?? $e['vendor_name_raw'], 'total' => (float)$e['total'],
                'action' => $action, 'reason' => $plan['blocked'] ?? null, 'warnings' => $plan['warnings'],
                'qbo_id' => $prior['qbo_id'] ?? null, 'payload' => $plan['payload'] ?? null,
                'receipt' => !empty($e['receipt_media_id']),
            ];
        }
        $tally = ['create' => 0, 'update' => 0, 'skip' => 0, 'blocked' => 0];
        foreach ($rows as $r) $tally[$r['action']]++;
        return ['enabled' => $this->isEnabled(), 'dry_run' => $this->isDryRun(), 'connected' => $conn !== null,
                'book_close_date' => $ctxBase['book_close_date'], 'push_from' => QboPushPlanner::PUSH_FROM, 'rows' => $rows, 'tally' => $tally,
                'missing' => $this->missingChoices($ctxBase)];
    }

    /**
     * Push one approved expense as a Purchase (+ receipt Attachable). Honours the flags.
     * @return array{success:bool,message:string,qbo_id:?string,dry_run:bool}
     */
    public function pushExpense(int $expenseId, ?int $userId = null): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => 'QuickBooks push is switched off (qbo_push_enabled = 0).', 'qbo_id' => null, 'dry_run' => false];
        }
        $conn = $this->connections->active();
        if ($conn === null) {
            return ['success' => false, 'message' => 'QuickBooks is not connected.', 'qbo_id' => null, 'dry_run' => false];
        }
        $stmt = $this->db->prepare("SELECT e.*, v.name AS vendor_name FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id = ?");
        $stmt->execute([$expenseId]);
        $e = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$e) return ['success' => false, 'message' => "Expense #{$expenseId} not found", 'qbo_id' => null, 'dry_run' => false];

        $ctx = $this->context($conn);
        $ctx['paid_from_qbo_id'] = $this->paidFrom($e['payment_method'] ?? null, $ctx);
        $dry = $this->isDryRun();
        $ctx['vendor_qbo_id'] = $this->vendorTwin($conn, (int)($e['vendor_id'] ?? 0), (string)($e['vendor_name'] ?? $e['vendor_name_raw'] ?? ''), !$dry);
        $plan = QboPushPlanner::purchaseFromExpense($e, $this->lines($expenseId), $ctx);
        $connId = (int)$conn['id'];
        if (!$plan['ok']) {
            $this->upsertSync($connId, 'expense', $expenseId, 'Purchase', ['status' => 'blocked', 'error' => $plan['blocked']]);
            return ['success' => false, 'message' => $plan['blocked'], 'qbo_id' => null, 'dry_run' => $dry];
        }
        $prior = $this->syncRows($connId, 'expense')[$expenseId] ?? null;
        if ($prior && $prior['status'] === 'synced' && $prior['content_hash'] === $plan['hash']) {
            return ['success' => true, 'message' => 'Already in QuickBooks and unchanged (Purchase ' . $prior['qbo_id'] . ').', 'qbo_id' => $prior['qbo_id'], 'dry_run' => $dry];
        }
        $requestId = QboPushPlanner::requestId('expense', $expenseId, $plan['hash']);
        if ($dry) {
            $this->upsertSync($connId, 'expense', $expenseId, 'Purchase', ['status' => 'dry_run', 'content_hash' => $plan['hash'], 'request_id' => $requestId, 'error' => null]);
            $this->log(['connection_id' => $connId, 'direction' => 'dry_run', 'qbo_type' => 'Purchase', 'operation' => 'create', 'request_id' => $requestId,
                        'summary' => 'DRY RUN expense #' . $expenseId, 'payload_hash' => $plan['hash'], 'created_by' => $userId]);
            return ['success' => true, 'message' => 'Dry run: the Purchase was built and checked, nothing was written to QuickBooks.', 'qbo_id' => null, 'dry_run' => true];
        }
        $api = $this->client();
        $payload = $plan['payload'];
        try {
            if ($prior && !empty($prior['qbo_id'])) {
                $payload['Id'] = (string)$prior['qbo_id'];
                $payload['SyncToken'] = (string)($prior['sync_token'] ?? '0');
                $payload['sparse'] = false;
            }
            $res = $api->post('purchase', $payload, $requestId);
            $purchase = $res['Purchase'] ?? [];
            $qboId = (string)($purchase['Id'] ?? '');
            $this->upsertSync($connId, 'expense', $expenseId, 'Purchase', [
                'status' => 'synced', 'qbo_id' => $qboId, 'sync_token' => (string)($purchase['SyncToken'] ?? '0'),
                'content_hash' => $plan['hash'], 'request_id' => $requestId, 'last_synced_at' => date('Y-m-d H:i:s'), 'error' => null,
            ]);
            $attached = $this->attachReceipt($api, $connId, $e, $qboId);
            // Through the expense gate (migration 1233): the audited 'forward' transition, same as the email route.
            require_once dirname(__DIR__, 3) . '/Expenses/Services/ExpenseGate.php';
            (new ExpenseGate($this->db))->apply($expenseId, [
                'forwarded_to_accounting' => 1, 'forwarded_at' => 'now', 'status' => 'forwarded',
            ], ['id' => $userId ?: null, 'kind' => 'user'], 'send_to_accounting', ['transition' => 'forward', 'allow_locked' => true]);
            return ['success' => true, 'message' => 'Purchase ' . $qboId . ' created in QuickBooks' . ($attached ? ' with the receipt attached' : '') . '.', 'qbo_id' => $qboId, 'dry_run' => false];
        } catch (Throwable $ex) {
            $this->upsertSync($connId, 'expense', $expenseId, 'Purchase', ['status' => 'error', 'content_hash' => $plan['hash'], 'request_id' => $requestId, 'error' => $ex->getMessage()]);
            return ['success' => false, 'message' => $ex->getMessage(), 'qbo_id' => null, 'dry_run' => false];
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function attachReceipt(QboApiClient $api, int $connId, array $e, string $purchaseId): bool
    {
        if (empty($e['receipt_media_id']) || $purchaseId === '') return false;
        if (!function_exists('resolveMediaFilePath')) {
            $rs = APP_ROOT . '/Services/Receipts/ReceiptService.php';
            if (is_file($rs)) require_once $rs;
        }
        if (!function_exists('resolveMediaFilePath')) return false;
        $path = resolveMediaFilePath((int)$e['receipt_media_id'], $this->db);
        if (!$path || !is_file($path)) return false;
        $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream';
        $existing = $this->syncRows($connId, 'expense_receipt')[(int)$e['id']] ?? null;
        if ($existing && $existing['status'] === 'synced') return true;
        try {
            $att = $api->upload($path, $mime, basename($path), 'Purchase', $purchaseId, QboPushPlanner::requestId('receipt', (int)$e['id'], sha1_file($path) ?: ''));
            $this->upsertSync($connId, 'expense_receipt', (int)$e['id'], 'Attachable', ['status' => 'synced', 'qbo_id' => (string)($att['Id'] ?? ''), 'last_synced_at' => date('Y-m-d H:i:s'), 'error' => null]);
            return true;
        } catch (Throwable $ex) {
            $this->upsertSync($connId, 'expense_receipt', (int)$e['id'], 'Attachable', ['status' => 'error', 'error' => $ex->getMessage()]);
            return false;
        }
    }

    /** Vendor twin: a qbo_sync row, else query QBO by DisplayName, else (when $create) create it. */
    private function vendorTwin(?array $conn, int $vendorId, string $name, bool $create): ?string
    {
        if ($conn === null) return null;
        $connId = (int)$conn['id'];
        $key = $vendorId > 0 ? $vendorId : 0;
        $name = trim($name);
        if ($key > 0) {
            $row = $this->syncRows($connId, 'vendor')[$key] ?? null;
            if ($row && !empty($row['qbo_id'])) return (string)$row['qbo_id'];
        }
        if (!$create || $name === '') return null;
        $api = $this->client();
        $safe = str_replace("'", "\\'", $name);
        $found = $api->query("SELECT * FROM Vendor WHERE DisplayName = '{$safe}'");
        $qboId = isset($found['Vendor'][0]['Id']) ? (string)$found['Vendor'][0]['Id'] : null;
        if ($qboId === null) {
            $res = $api->post('vendor', ['DisplayName' => substr($name, 0, 500)], QboPushPlanner::requestId('vendor', $key ?: crc32($name), sha1($name)));
            $qboId = (string)($res['Vendor']['Id'] ?? '');
        }
        if ($qboId !== '' && $key > 0) {
            $this->upsertSync($connId, 'vendor', $key, 'Vendor', ['status' => 'synced', 'qbo_id' => $qboId, 'last_synced_at' => date('Y-m-d H:i:s')]);
        }
        return $qboId ?: null;
    }

    private function lines(int $expenseId): array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM expense_line_allocations WHERE expense_id = ? ORDER BY sort_order, id");
            $stmt->execute([$expenseId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];   // migration 1233 not run → header-only expense
        }
    }

    private function syncRows(int $connId, string $crmType): array
    {
        $stmt = $this->db->prepare("SELECT * FROM qbo_sync WHERE connection_id = ? AND crm_type = ?");
        $stmt->execute([$connId, $crmType]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[(int)$r['crm_id']] = $r;
        return $out;
    }

    private function upsertSync(int $connId, string $crmType, int $crmId, string $qboType, array $fields): void
    {
        $cols = ['connection_id' => $connId, 'crm_type' => $crmType, 'crm_id' => $crmId, 'qbo_type' => $qboType] + $fields;
        $names = array_keys($cols);
        $sets = [];
        foreach ($fields as $k => $v) $sets[] = "`$k` = VALUES(`$k`)";
        $sql = "INSERT INTO qbo_sync (`" . implode('`, `', $names) . "`) VALUES (" . implode(', ', array_fill(0, count($names), '?')) . ")"
             . ($sets ? " ON DUPLICATE KEY UPDATE " . implode(', ', $sets) : '');
        $this->db->prepare($sql)->execute(array_values($cols));
    }

    /** Everything the planner needs that does not depend on the record. */
    private function context(?array $conn): array
    {
        $report = (new QboDiscoveryService($this->db))->cached()['report'] ?? null;
        $catAccounts = [];
        try {
            foreach ($this->db->query("SELECT id, expense_category_alias, name, code FROM chart_of_accounts WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $a) {
                if (!empty($a['expense_category_alias'])) {
                    foreach (explode(',', (string)$a['expense_category_alias']) as $alias) {
                        $alias = trim($alias);
                        if ($alias !== '') { $catAccounts[$alias] = (int)$a['id']; $catAccounts[strtolower($alias)] = (int)$a['id']; }
                    }
                }
            }
        } catch (Throwable $e) {
            // chart missing → everything blocks with "map it first"
        }
        $paidFrom = json_decode((string)$this->setting(self::SETTING_PAID_FROM, '{}'), true) ?: [];
        return [
            'book_close_date'    => $conn['book_close_date'] ?? ($report['company']['book_close_date'] ?? null),
            'account_map'        => $conn ? $this->maps->lookup((int)$conn['id']) : [],
            'category_accounts'  => $catAccounts,
            'tax_gst'            => $this->setting(self::SETTING_TAX_GST) ?: null,
            'tax_gst_pst'        => $this->setting(self::SETTING_TAX_GST_PST) ?: null,
            'tax_exempt'         => $this->setting(self::SETTING_TAX_EXEMPT) ?: null,
            'item_qbo_id'        => $this->setting(self::SETTING_ITEM) ?: null,
            'paid_from'          => $paidFrom,
            'multicurrency'      => (bool)($report['company']['multicurrency'] ?? false),
            'currency'           => $report['company']['home_currency'] ?? 'CAD',
            'custom_txn_numbers' => (bool)($report['company']['custom_txn_numbers'] ?? false),
        ];
    }

    private function paidFrom(?string $method, array $ctx): ?string
    {
        $type = QboPushPlanner::paymentType($method);
        $map  = $ctx['paid_from'] ?? [];
        $key  = $type === 'CreditCard' ? 'credit_card' : ($type === 'Check' ? 'cheque' : 'bank');
        return isset($map[$key]) && $map[$key] !== '' ? (string)$map[$key] : null;
    }

    private function missingChoices(array $ctx): array
    {
        $m = [];
        if (empty($ctx['tax_gst']))     $m[] = 'GST tax code';
        if (empty($ctx['tax_gst_pst'])) $m[] = 'GST + PST tax code';
        if (empty($ctx['tax_exempt']))  $m[] = 'exempt / zero-rated tax code';
        if (empty($ctx['paid_from']['bank']))        $m[] = 'bank account purchases are paid from';
        if (empty($ctx['paid_from']['credit_card'])) $m[] = 'credit-card account purchases are paid from';
        if (empty($ctx['item_qbo_id'])) $m[] = 'service Item for invoice lines';
        return $m;
    }

    public function setting(string $key, ?string $default = null): ?string
    {
        if ($this->settings === null) {
            $this->settings = [];
            try {
                $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM ops_settings WHERE setting_key LIKE 'qbo_%'");
                $stmt->execute();
                $this->settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            } catch (Throwable $e) {
                // no ops_settings → defaults
            }
        }
        $v = $this->settings[$key] ?? null;
        return ($v === null || $v === '') ? $default : (string)$v;
    }

    public function saveSetting(string $key, string $value, string $desc = ''): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute([$key, $value, $desc]);
        $this->settings = null;
    }
}
