<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * QuickBooks step 3 (plan 2026-10-20): an approved receipt goes up as a Purchase exactly as Ittai booked
 * it, with its supplier and photo; meals, personal and odd bookings are held; a mapping fix releases
 * what was waiting on it. A fake QuickBooks answers — nothing reaches Intuit.
 */
class QboExpenseSyncTest extends TestCase
{
    private const NOW = 1792540800;
    private array $sent = [];
    private array $answers = [];

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            "CREATE TABLE qbo_connections (id INTEGER PRIMARY KEY, site_id INT, environment TEXT, realm_id TEXT, company_name TEXT, country TEXT, access_token_enc TEXT, refresh_token_enc TEXT, access_expires_at TEXT, refresh_expires_at TEXT, status TEXT, last_error TEXT, last_pull_at TEXT, connected_by INT, connected_at TEXT, disconnected_at TEXT)",
            "CREATE TABLE qbo_remote_items (id INTEGER PRIMARY KEY, site_id INT, kind TEXT, qbo_id TEXT, name TEXT, detail TEXT, active INT, pulled_at TEXT)",
            "CREATE TABLE qbo_mappings (id INTEGER PRIMARY KEY, site_id INT, kind TEXT, ittai_key TEXT, qbo_id TEXT, qbo_name TEXT, set_by INT, updated_at TEXT)",
            "CREATE TABLE qbo_links (id INTEGER PRIMARY KEY, site_id INT, realm_id TEXT, entity_type TEXT, entity_id TEXT, qbo_id TEXT, sync_token TEXT, request_id TEXT, status TEXT, qbo_total REAL, message TEXT, sent_at TEXT)",
            "CREATE TABLE ops_settings (setting_key TEXT, setting_value TEXT, description TEXT)",
            "CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, code TEXT, name TEXT, type TEXT, is_active INT)",
            "CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT)",
            "CREATE TABLE expenses (id INTEGER PRIMARY KEY, expense_date TEXT, vendor_id INT, vendor_name_raw TEXT, description TEXT, accounting_category TEXT, status TEXT, receipt_media_id INT, updated_at TEXT)",
            "CREATE TABLE journal_entries (id INTEGER PRIMARY KEY, entry_date TEXT, source_type TEXT, source_id INT, status TEXT, reversed_by_entry_id INT)",
            "CREATE TABLE journal_lines (id INTEGER PRIMARY KEY, entry_id INT, account_id INT, debit REAL, credit REAL, description TEXT, pst_amount REAL)",
        ] as $sql) $db->exec($sql);
        $v = new QboTokenVault(str_repeat("\x07", 32));
        $db->prepare("INSERT INTO qbo_connections (site_id, environment, realm_id, access_token_enc, refresh_token_enc, access_expires_at, status)
                      VALUES (1, 'sandbox', '777', ?, ?, ?, 'connected')")->execute([$v->encrypt('A'), $v->encrypt('R'), date('Y-m-d H:i:s', self::NOW + 1800)]);
        $db->exec("INSERT INTO chart_of_accounts VALUES (1, '1010', 'Chequing Account', 'asset', 1), (2, '2210', 'GST/HST Input Tax Credits', 'liability', 1),
                   (3, '5200', 'Materials & Supplies', 'expense', 1), (4, '6850', 'Meals & Entertainment', 'expense', 1), (5, '1300', 'Due from Shareholder', 'asset', 1),
                   (6, '1500', 'Equipment & Tools', 'asset', 1), (7, '4900', 'Other Services', 'revenue', 1)");
        $db->exec("INSERT INTO qbo_remote_items (site_id, kind, qbo_id, name, detail, active, pulled_at) VALUES (1, 'account', '38', 'Chequing', 'Bank', 1, 'x'), (1, 'account', '22', 'Supplies', 'Expense', 1, 'x')");
        $db->exec("INSERT INTO qbo_mappings (site_id, kind, ittai_key, qbo_id, qbo_name) VALUES (1, 'tax_code', 'gst', '7', 'GST'), (1, 'tax_code', 'exempt', '2', 'Exempt'),
                   (1, 'account', '5200', '22', 'Supplies'), (1, 'pay_account', '1010', '38', 'Chequing')");
        $db->exec("INSERT INTO vendors VALUES (9, 'Supply Depot')");
        $db->exec("INSERT INTO expenses VALUES (50, '2026-10-16', 9, 'SUPPLY DEPOT #12', 'Fertilizer', 'Materials', 'approved', 700, '2026-10-16 12:00:00')");
        $this->entry($db, 900, 50, '2026-10-16', [[3, 191.19, 0], [2, 9.56, 0], [1, 0, 200.75]]);
        return $db;
    }

    private function entry(PDO $db, int $id, int $expenseId, string $date, array $lines): void
    {
        $db->exec("INSERT INTO journal_entries VALUES ($id, '$date', 'expense', $expenseId, 'posted', NULL)");
        foreach ($lines as $ln) {
            [$acct, $d, $c] = $ln;
            $p = $ln[3] ?? 0;
            $db->exec("INSERT INTO journal_lines (entry_id, account_id, debit, credit, pst_amount) VALUES ($id, $acct, $d, $c, $p)");
        }
    }

    private function sync(PDO $db, ?string $photo = null): QboExpenseSyncService
    {
        $this->sent = [];
        $get = fn(string $url, array $h) => ['status' => 200, 'body' => '{"QueryResponse":{}}'];
        $post = function (string $url, array $h, string $body) {
            $entity = (string)preg_replace('#^.*/company/777/([a-z]+)\?.*$#', '$1', $url);
            $this->sent[] = ['entity' => $entity, 'url' => $url, 'body' => $entity === 'upload' ? $body : json_decode($body, true), 'headers' => $h];
            if (isset($this->answers[$entity])) return ($this->answers[$entity])();
            $id = (string)(count($this->sent) + 300);
            if ($entity === 'upload') return ['status' => 200, 'body' => json_encode(['AttachableResponse' => [['Attachable' => ['Id' => $id]]]])];
            $obj = ['Id' => $id, 'SyncToken' => '0'];
            if ($entity === 'purchase') $obj['TotalAmt'] = 200.75;
            return ['status' => 200, 'body' => json_encode([ucfirst($entity) => $obj])];
        };
        $conn = new QboConnectionService($db, 1, new QboOAuth('c', 's', fn() => ['status' => 500, 'body' => '']),
                                         new QboTokenVault(str_repeat("\x07", 32)), fn() => self::NOW, $get, $post);
        return new QboExpenseSyncService($conn, fn() => self::NOW, fn(int $id) => $photo);
    }

    // ── translation (pure) ───────────────────────────────────────────────────

    private function l(string $code, string $type, float $d, float $c): array { return ['code' => $code, 'type' => $type, 'debit' => $d, 'credit' => $c]; }

    public function test_a_normal_receipt_translates_to_lines_gst_and_what_paid_it(): void
    {
        $t = QboExpenseSyncService::translate([$this->l('5200', 'expense', 191.19, 0), $this->l('2210', 'liability', 9.56, 0), $this->l('1010', 'asset', 0, 200.75)]);
        $this->assertNull($t['hold']);
        $this->assertSame('1010', $t['pay']);
        $this->assertSame([['code' => '5200', 'amount' => 191.19, 'pst' => 0.0]], $t['lines']);
        $this->assertSame(9.56, $t['gst']);
        $this->assertSame(200.75, $t['total']);
    }

    public function test_what_is_held_and_why(): void
    {
        $meals = QboExpenseSyncService::translate([$this->l('6850', 'expense', 40.00, 0), $this->l('2210', 'liability', 1.00, 0), $this->l('1010', 'asset', 0, 41.00)]);
        $this->assertStringContainsString('meals (50% claim)', (string)$meals['hold']);
        $personal = QboExpenseSyncService::translate([$this->l('5200', 'expense', 50, 0), $this->l('1300', 'asset', 0, 50)]);
        $this->assertStringContainsString('paid personally', (string)$personal['hold']);
        $revenue = QboExpenseSyncService::translate([$this->l('4900', 'revenue', 50, 0), $this->l('1010', 'asset', 0, 50)]);
        $this->assertStringContainsString('booked to 4900 (revenue)', (string)$revenue['hold']);
        $two = QboExpenseSyncService::translate([$this->l('5200', 'expense', 50, 0), $this->l('1010', 'asset', 0, 25), $this->l('2400', 'liability', 0, 25)]);
        $this->assertStringContainsString('paid from 2 accounts', (string)$two['hold']);
        $equip = QboExpenseSyncService::translate([$this->l('1500', 'asset', 1000, 0), $this->l('2210', 'liability', 50, 0), $this->l('1010', 'asset', 0, 1050)]);
        $this->assertNull($equip['hold'], 'equipment (1500) goes to its mapped fixed-asset account');
        $nogst = QboExpenseSyncService::translate([$this->l('5200', 'expense', 12, 0), $this->l('1010', 'asset', 0, 12)]);
        $this->assertSame(0.0, $nogst['gst']);
    }

    public function test_bc_pst_inside_the_expense_line_is_split_out_and_the_total_still_matches(): void
    {
        // Live receipt #433: 6120 debit 19.25 holding PST 1.26, GST 0.90 (= 5% of 17.99), paid 20.15.
        $t = QboExpenseSyncService::translate([$this->l('5200', 'expense', 19.25, 0) + ['pst' => 1.26], $this->l('2210', 'liability', 0.90, 0), $this->l('1010', 'asset', 0, 20.15)]);
        $this->assertNull($t['hold'], (string)$t['hold']);
        $this->assertSame([['code' => '5200', 'amount' => 17.99, 'pst' => 1.26]], $t['lines']);
        $b = QboExpenseSyncService::purchaseBody('38', 'Bank', '9', '2026-10-16', [['account' => '22', 'amount' => 17.99, 'pst' => 1.26, 'description' => 'Fuel']], '7', 'n', '2');
        $this->assertSame([17.99, 1.26], array_column($b['Line'], 'Amount'));
        $this->assertSame(['7', '2'], array_map(fn($l) => $l['AccountBasedExpenseLineDetail']['TaxCodeRef']['value'], $b['Line']), 'GST on the goods, none on the PST');
        $this->assertSame('PST — Fuel', $b['Line'][1]['Description']);
    }

    public function test_pst_left_off_the_receipt_is_said_as_the_fix(): void
    {
        // Live COE Lumber #66: 49.10 + GST 2.46, total 55.00, PST 0 recorded — the 3.44 left is 7% of 49.10.
        $t = QboExpenseSyncService::translate([$this->l('6210', 'expense', 52.54, 0), $this->l('2210', 'liability', 2.46, 0), $this->l('1010', 'asset', 0, 55.00)]);
        $this->assertStringContainsString('PST seems to be missing: the $3.44 left is 7% of $49.10', (string)$t['hold']);
        $meals = QboExpenseSyncService::translate([$this->l('6850', 'expense', 40.00, 0), $this->l('2210', 'liability', 1.00, 0), $this->l('1010', 'asset', 0, 41.00)]);
        $this->assertStringContainsString('meals (50% claim)', (string)$meals['hold'], 'meals still read as meals');
    }

    public function test_a_refund_is_held_with_its_own_reason(): void
    {
        $t = QboExpenseSyncService::translate([$this->l('1010', 'asset', 21.00, 0), $this->l('5200', 'expense', 0, 20.00), $this->l('2210', 'liability', 0, 1.00)]);
        $this->assertStringContainsString('it is a refund', (string)$t['hold']);
    }

    public function test_a_card_purchase_is_marked_credit_card(): void
    {
        $b = QboExpenseSyncService::purchaseBody('40', 'Credit Card', '9', '2026-10-16', [['account' => '22', 'amount' => 10, 'description' => 'x']], '7', 'n');
        $this->assertSame('CreditCard', $b['PaymentType']);
        $this->assertSame('Cash', QboExpenseSyncService::purchaseBody('38', 'Bank', '9', '2026-10-16', [], '7', 'n')['PaymentType']);
    }

    // ── sending ──────────────────────────────────────────────────────────────

    public function test_a_receipt_goes_up_with_supplier_lines_gst_and_photo(): void
    {
        $db = $this->db();
        $photo = tempnam(sys_get_temp_dir(), 'rcpt');
        file_put_contents($photo, "\xFF\xD8\xFF\xE0fakejpeg");
        $r = $this->sync($db, $photo)->sendExpense(50);
        @unlink($photo);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(['vendor', 'purchase', 'upload'], array_column($this->sent, 'entity'));
        $this->assertSame('Supply Depot', $this->sent[0]['body']['DisplayName']);
        $p = $this->sent[1]['body'];
        $this->assertSame('Cash', $p['PaymentType']);
        $this->assertSame('38', $p['AccountRef']['value'], 'paid from the mapped Chequing — what the bank feed matches on');
        $this->assertSame('2026-10-16', $p['TxnDate']);
        $this->assertSame(191.19, $p['Line'][0]['Amount']);
        $this->assertSame('22', $p['Line'][0]['AccountBasedExpenseLineDetail']['AccountRef']['value']);
        $this->assertSame('7', $p['Line'][0]['AccountBasedExpenseLineDetail']['TaxCodeRef']['value']);
        $this->assertStringContainsString('requestid=ittai-1-exp-50', $this->sent[1]['url']);
        $this->assertStringContainsString('name="file_metadata_01"', $this->sent[2]['body']);
        $this->assertStringContainsString('"type":"Purchase"', $this->sent[2]['body']);
        $this->assertStringContainsString('Photo attached', $r['message']);
    }

    public function test_a_photo_format_quickbooks_does_not_take_is_skipped_with_a_note(): void
    {
        $db = $this->db();
        $photo = tempnam(sys_get_temp_dir(), 'rcpt');
        file_put_contents($photo, "RIFF\x00\x00\x00\x00WEBPVP8 fake");
        $r = $this->sync($db, $photo)->sendExpense(50);
        @unlink($photo);
        $this->assertTrue($r['ok']);
        $this->assertSame(['vendor', 'purchase'], array_column($this->sent, 'entity'), 'no upload of a mislabelled file');
        $this->assertStringContainsString('QuickBooks does not take', $r['message']);
    }

    public function test_a_receipt_changed_or_reversed_in_ittai_after_sending_is_listed(): void
    {
        $db = $this->db();
        $this->sync($db)->sendExpense(50);
        $this->assertSame([], $this->sync($db)->expenseProblems());
        $db->exec("UPDATE journal_lines SET credit = 220.75 WHERE credit > 0");
        $this->assertStringContainsString('Changed in Ittai after it was sent ($220.75 now, $200.75 in QuickBooks)', $this->sync($db)->expenseProblems()[0]['why']);
        $db->exec("UPDATE journal_entries SET reversed_by_entry_id = 5");
        $this->assertStringContainsString('Taken out of Ittai', $this->sync($db)->expenseProblems()[0]['why']);
    }

    public function test_paid_from_must_be_a_bank_or_card_account(): void
    {
        $db = $this->db();
        $conn = new QboConnectionService($db, 1, null, new QboTokenVault(str_repeat("\x07", 32)), fn() => self::NOW);
        $this->assertFalse($conn->setMapping('pay_account', '1010', '22', 1), 'Supplies (an expense account) is refused');
        $this->assertTrue($conn->setMapping('pay_account', '1010', '38', 1));
    }

    public function test_releasing_held_items_leaves_overpaying_payments_alone(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO qbo_links (site_id, realm_id, entity_type, entity_id, request_id, status) VALUES (1, '777', 'payment', 'alloc:1', 'x', 'held'), (1, '777', 'expense', '50', 'y', 'held')");
        $this->assertSame(1, $this->sync($db)->releaseHeld());
        $this->assertSame('held', $db->query("SELECT status FROM qbo_links WHERE entity_type = 'payment'")->fetchColumn());
    }

    public function test_sending_again_creates_nothing(): void
    {
        $db = $this->db();
        $this->sync($db)->sendExpense(50);
        $r = $this->sync($db)->sendExpense(50);
        $this->assertSame([], $this->sent);
        $this->assertStringContainsString('already in QuickBooks', $r['message']);
    }

    public function test_an_unapproved_or_unposted_receipt_is_not_sent(): void
    {
        $db = $this->db();
        $db->exec("UPDATE expenses SET status = 'pending_approval'");
        $this->assertStringContainsString('not approved', $this->sync($db)->sendExpense(50)['message']);
        $db->exec("UPDATE expenses SET status = 'approved'");
        $db->exec("UPDATE journal_entries SET reversed_by_entry_id = 999");
        $this->assertStringContainsString('not in Ittai\'s books yet', $this->sync($db)->sendExpense(50)['message']);
        $this->assertSame([], $this->sent);
    }

    public function test_automatic_receipts_need_their_own_switch_and_the_start_date(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('qbo_auto_send', '1'), ('qbo_auto_from', '2026-10-15')");
        $this->assertSame([], $this->sync($db)->dueExpenses(), 'invoices on is not receipts on');
        $this->sync($db)->setReceipts(true);
        $db->exec("INSERT INTO expenses VALUES (51, '2026-09-30', 9, NULL, 'Old', 'Materials', 'approved', NULL, '2026-09-30')");
        $this->entry($db, 901, 51, '2026-09-30', [[3, 10, 0], [1, 0, 10]]);
        $this->assertSame([50], $this->sync($db)->dueExpenses(), 'September is not sent');
        $this->assertSame(1, $this->sync($db)->runExpenses()['sent']);
        $this->assertSame([], $this->sync($db)->dueExpenses());
    }

    public function test_a_receipt_waiting_on_a_mapping_is_released_when_the_mapping_is_saved(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('qbo_auto_send', '1'), ('qbo_auto_from', '2026-10-15'), ('qbo_auto_receipts', '1')");
        $db->exec("DELETE FROM qbo_mappings WHERE kind = 'pay_account'");
        $r = $this->sync($db)->runExpenses();
        $this->assertSame(1, $r['held']);
        $this->assertStringContainsString('map Ittai account 1010', $r['notes'][0]);
        $this->assertSame([], $this->sync($db)->dueExpenses(), 'held: not every run');
        $this->assertStringContainsString('map Ittai account 1010', $this->sync($db)->expenseProblems()[0]['why']);

        $db->exec("INSERT INTO qbo_mappings (site_id, kind, ittai_key, qbo_id, qbo_name) VALUES (1, 'pay_account', '1010', '38', 'Chequing')");
        $this->assertSame(1, $this->sync($db)->releaseHeld());
        $this->assertSame([50], $this->sync($db)->dueExpenses());
        $this->sync($db)->runExpenses();
        $p = array_values(array_filter($this->sent, fn($x) => $x['entity'] === 'purchase'));
        $this->assertStringContainsString('requestid=ittai-1-exp-50-r1', $p[0]['url'], 'a fresh id after a hold');
    }
}
