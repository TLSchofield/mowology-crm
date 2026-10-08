<?php
declare(strict_types=1);

/**
 * Test kit for the expense gate and the split by line (migration 1233). Not a test case.
 *  - ExpenseGateTestDb: an in-memory SQLite stand-in with the tables the gate, the split, the
 *    ledger and job costing read and write, plus Tim's live case (Lawnboy #412, Oakridge).
 *  - ExpenseGateSpy: a gate that records apply() calls (callers' tests: "this surface uses the gate").
 *  - ExpenseGateHooksSpy: hooks that count each learning / books signal.
 */
final class ExpenseGateTestDb
{
    public static function make(): PDO
    {
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        $db = class_exists('Pdo\Sqlite') ? new \Pdo\Sqlite('sqlite::memory:', null, null, $opts) : new PDO('sqlite::memory:', null, null, $opts);
        $now = static function () { return date('Y-m-d H:i:s'); };
        method_exists($db, 'createFunction') ? $db->createFunction('NOW', $now) : $db->sqliteCreateFunction('NOW', $now);
        $db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY AUTOINCREMENT, expense_date TEXT, vendor_id INT, vendor_name_raw TEXT, description TEXT,
                   amount REAL DEFAULT 0, gst_amount REAL DEFAULT 0, pst_amount REAL DEFAULT 0, total REAL DEFAULT 0, accounting_category TEXT,
                   gbp_category TEXT, payment_method TEXT, receipt_media_id INT, receipt_lat REAL, receipt_lng REAL, match_confidence INT DEFAULT 0,
                   anomaly_flags TEXT, anomaly_score INT DEFAULT 0, raw_ocr_json TEXT, ocr_parsed_json TEXT, line_items_source TEXT, job_id INT,
                   property_id INT, contact_id INT, notes TEXT, status TEXT DEFAULT 'draft', odometer_start INT, odometer_end INT, fuel_litres REAL,
                   fuel_price_per_litre REAL, asset_tag TEXT, source TEXT, created_by INT, approved_by INT, approved_at TEXT, rejection_reason TEXT,
                   forwarded_to_accounting INT DEFAULT 0, forwarded_at TEXT, learning_recorded_at TEXT, created_at TEXT, updated_at TEXT)");
        $db->exec("CREATE TABLE expense_line_items (id INTEGER PRIMARY KEY AUTOINCREMENT, expense_id INT, product_id INT, name TEXT, ocr_name TEXT,
                   quantity REAL DEFAULT 1, unit_price REAL, original_unit_price REAL, is_adjustment INT DEFAULT 0, line_total REAL DEFAULT 0,
                   sku_raw TEXT, sort_order INT DEFAULT 0)");
        $db->exec("CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, sku TEXT, track_inventory INT DEFAULT 0, current_stock REAL DEFAULT 0)");
        $db->exec("CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT, aliases TEXT, is_active INT DEFAULT 1)");
        $db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, property_name TEXT, address TEXT)");
        $db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, plan_number TEXT, title TEXT, property_id INT, quote_id INT, company_id INT,
                   status TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INT, scheduled_date TEXT, status TEXT)");
        $db->exec("CREATE TABLE quote_line_items (id INTEGER PRIMARY KEY, quote_id INT, product_id INT, service_type TEXT, description TEXT,
                   quantity REAL, unit_price REAL, line_total REAL)");
        $db->exec("CREATE TABLE ops_trip_runs (id INTEGER PRIMARY KEY, run_date TEXT, trip_key TEXT, kind TEXT, place_id INT, from_property_id INT,
                   return_property_id INT, labour_cost REAL, truck_cost REAL, receipt_ids TEXT, arrived_at TEXT)");
        $db->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT)");
        $db->exec("CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, code TEXT, name TEXT, type TEXT, expense_category_alias TEXT, is_active INT DEFAULT 1)");
        $db->exec("CREATE TABLE journal_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_date TEXT, memo TEXT, source_type TEXT, source_id INT,
                   period_id INT, status TEXT, is_adjusting INT DEFAULT 0, created_by INT, reversed_by_entry_id INT)");
        $db->exec("CREATE TABLE journal_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INT, account_id INT, debit REAL, credit REAL,
                   gst_amount REAL, pst_amount REAL, description TEXT, job_id INT, contact_id INT, vendor_id INT, crew_user_id INT,
                   cost_type_id INT, service_type TEXT)");
        $db->exec("CREATE TABLE accounting_periods (id INTEGER PRIMARY KEY, year INT, month INT, status TEXT)");
        $db->exec("CREATE TABLE accounting_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_date TEXT, type TEXT, account_id INT,
                   amount REAL, gst_amount REAL DEFAULT 0, pst_amount REAL DEFAULT 0, description TEXT, reference_type TEXT, reference_id INT,
                   job_id INT, status TEXT, matched_expense_id INT)");
        $db->exec("CREATE TABLE cost_types (id INTEGER PRIMARY KEY, name TEXT, is_active INT DEFAULT 1)");
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)");
        // Migration 1233
        $db->exec("CREATE TABLE expense_line_allocations (id INTEGER PRIMARY KEY AUTOINCREMENT, expense_id INT NOT NULL, line_item_id INT,
                   label TEXT NOT NULL DEFAULT '', sort_order INT NOT NULL DEFAULT 0, job_id INT, accounting_category TEXT, asset_tag TEXT,
                   is_stock INT NOT NULL DEFAULT 0, pst_taxable INT, net_amount REAL NOT NULL DEFAULT 0, gst_amount REAL NOT NULL DEFAULT 0,
                   pst_amount REAL NOT NULL DEFAULT 0, source TEXT NOT NULL DEFAULT 'owner', reason TEXT, created_by INT, created_at TEXT NOT NULL,
                   updated_at TEXT)");
        $db->exec("CREATE TABLE expense_change_log (id INTEGER PRIMARY KEY AUTOINCREMENT, expense_id INT, action TEXT NOT NULL, source TEXT NOT NULL,
                   actor_user_id INT, actor_kind TEXT NOT NULL DEFAULT 'user', fields TEXT, before_text TEXT, after_text TEXT, note TEXT, created_at TEXT NOT NULL)");
        $db->exec("CREATE TABLE expense_split_lessons (id INTEGER PRIMARY KEY AUTOINCREMENT, vendor_id INT NOT NULL DEFAULT 0, line_key TEXT NOT NULL,
                   accounting_category TEXT, asset_tag TEXT, is_stock INT NOT NULL DEFAULT 0, to_job INT NOT NULL DEFAULT 0, times_seen INT NOT NULL DEFAULT 1,
                   last_expense_id INT, updated_at TEXT NOT NULL, UNIQUE (vendor_id, line_key))");

        $db->exec("INSERT INTO chart_of_accounts (id, code, name, type, expense_category_alias) VALUES
                   (1, '1010', 'Chequing', 'asset', NULL), (2, '2210', 'GST ITC', 'asset', NULL), (3, '2400', 'Credit Card Payable', 'liability', NULL),
                   (4, '5200', 'Materials & Supplies', 'expense', 'Materials'), (5, '6100', 'Fuel', 'expense', 'Fuel'),
                   (6, '6150', 'Meals & Entertainment', 'expense', 'Meals'), (7, '6900', 'Miscellaneous', 'expense', NULL)");
        $db->exec("INSERT INTO cost_types (id, name) VALUES (1, 'Materials'), (2, 'Fuel')");
        return $db;
    }

    /**
     * Tim's live case, 2026-10-07: Lawnboy #412 $218.40 — 2 × Richardson Sun & Shade seed ($120, stock,
     * PST 7% $8.40) and 2 × Black Composted Bark Mulch ($80, Oakridge Gardens), GST $10 on $200.
     */
    public static function lawnboy412(PDO $db, string $status = 'pending_approval'): void
    {
        $db->exec("INSERT INTO vendors (id, name) VALUES (7, 'LAWNBOY')");
        $db->exec("INSERT INTO products (id, name, sku, track_inventory, current_stock) VALUES
                   (11, 'Black Composted Bark Mulch', 'CBM', 1, 2), (44, 'Richardson Sun & Shade Lawn Seed 5 kg', 'TL02100350', 1, 2)");
        $db->exec("INSERT INTO properties (id, property_name, address) VALUES (31, 'Oakridge Gardens', '650 W 41st Ave')");
        $db->exec("INSERT INTO job_plans (id, plan_number, title, property_id, quote_id, status, created_at) VALUES
                   (501, 'JOB-2026-0501', 'Fall mulch', 31, 77, 'completed', '2026-09-30 08:00:00')");
        $db->exec("INSERT INTO job_visits (id, plan_id, scheduled_date, status) VALUES (9001, 501, '2026-10-07', 'completed')");
        $db->exec("INSERT INTO quote_line_items (quote_id, product_id, service_type, description, quantity, unit_price, line_total) VALUES
                   (77, 11, 'Mulching', 'Black bark mulch, 2 yd installed', 2, 95, 190)");
        $db->prepare("INSERT INTO expenses (id, expense_date, vendor_id, vendor_name_raw, amount, gst_amount, pst_amount, total, accounting_category,
                      payment_method, status, created_by) VALUES (412, '2026-10-07', 7, 'LAWNBOY', 200, 10, 8.40, 218.40, 'Materials', 'credit_card', ?, 6)")
           ->execute([$status]);
        $db->exec("INSERT INTO expense_line_items (id, expense_id, product_id, name, quantity, unit_price, line_total, sort_order) VALUES
                   (229, 412, 44, 'Richardson Sun & Shade Lawn Seed 5 kg', 2, 60, 120, 0),
                   (230, 412, 11, 'Black Composted Bark Mulch', 2, 40, 80, 1)");
    }

    /** Tim's split of #412: mulch → Oakridge (job 501), seed → shop stock. */
    public static function split412(): array
    {
        return [
            ['line_item_id' => 229, 'is_stock' => true, 'accounting_category' => 'Materials'],
            ['line_item_id' => 230, 'job_id' => 501, 'accounting_category' => 'Materials'],
        ];
    }
}

/** A gate that only records what it was asked to do. */
class ExpenseGateSpy extends ExpenseGate
{
    /** @var list<array{id: ?int, changes: array, actor: array, source: string, opts: array}> */
    public array $calls = [];
    public array $extra = [];

    public function __construct()
    {
    }

    public function apply(?int $expenseId, array $changes, array $actor, string $source, array $opts = []): array
    {
        $this->calls[] = ['id' => $expenseId, 'changes' => $changes, 'actor' => $actor, 'source' => $source, 'opts' => $opts];
        return array_merge(['ok' => true, 'expense_id' => $expenseId ?: 900, 'action' => 'update', 'changed' => array_keys($changes),
                            'noop' => false, 'duplicates' => [], 'reposted' => false], $this->extra);
    }
}

/** Hooks that count each signal (and do nothing). */
class ExpenseGateHooksSpy extends ExpenseGateHooks
{
    /** @var array<string, list<array>> */
    public array $calls = [];
    public bool $repostResult = true;

    public function __construct()
    {
    }

    public function count(string $hook): int
    {
        return count($this->calls[$hook] ?? []);
    }

    private function hit(string $hook, array $args): void
    {
        $this->calls[$hook][] = $args;
    }

    public function facts(int $expenseId): void { $this->hit('facts', [$expenseId]); }
    public function duplicates(int $expenseId): array { $this->hit('duplicates', [$expenseId]); return []; }
    public function storeBaseline(int $expenseId, $ocrParsed): void { $this->hit('storeBaseline', [$expenseId]); }
    public function learnLines(int $expenseId, array $row, array $payload): void { $this->hit('learnLines', [$expenseId]); }
    public function learnLineOp(string $op, ?array $before, ?array $after, array $expense): void { $this->hit('learnLineOp', [$op, $before, $after]); }
    public function priceIntel(int $expenseId, int $vendorId, array $lines, string $date): void { $this->hit('priceIntel', [$expenseId]); }
    public function learnConfirmed(int $expenseId, ?array $fallbackBaseline): void { $this->hit('learnConfirmed', [$expenseId, $fallbackBaseline]); }
    public function learnSplit(int $expenseId, array $allocations): void { $this->hit('learnSplit', [$expenseId, $allocations]); }
    public function learnBank(int $expenseId, array $row, ?int $userId): void { $this->hit('learnBank', [$expenseId, $userId]); }
    public function repost(int $expenseId, ?int $userId, string $why): bool { $this->hit('repost', [$expenseId, $why]); return $this->repostResult; }
    public function unpost(int $expenseId, ?int $userId, string $why): bool { $this->hit('unpost', [$expenseId, $why]); return true; }
}
