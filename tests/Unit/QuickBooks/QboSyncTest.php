<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * QuickBooks Online step 2 (plan 2026-10-20): an invoice goes up with its customer, one Service item per
 * income account, the mapped GST code and its payments; a repeat sends nothing twice; a refusal gets a
 * fresh request id once fixed, a timeout keeps the same one; a total that differs is said; a live
 * company is refused. A fake QuickBooks answers every call — nothing reaches Intuit.
 */
class QboSyncTest extends TestCase
{
    private const NOW = 1792540800;

    /** @var list<array{method:string, entity:string, url:string, body:array}> */
    private array $sent = [];
    /** what the fake answers: entity => callable(array $body, string $url): array{status:int, body:string} */
    private array $answers = [];
    private array $queries = [];

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE qbo_connections (id INTEGER PRIMARY KEY, site_id INT, environment TEXT, realm_id TEXT, company_name TEXT, country TEXT,
                   access_token_enc TEXT, refresh_token_enc TEXT, access_expires_at TEXT, refresh_expires_at TEXT, status TEXT, last_error TEXT,
                   last_pull_at TEXT, connected_by INT, connected_at TEXT, disconnected_at TEXT)");
        $db->exec("CREATE TABLE qbo_remote_items (id INTEGER PRIMARY KEY, site_id INT, kind TEXT, qbo_id TEXT, name TEXT, detail TEXT, active INT, pulled_at TEXT)");
        $db->exec("CREATE TABLE qbo_mappings (id INTEGER PRIMARY KEY, site_id INT, kind TEXT, ittai_key TEXT, qbo_id TEXT, qbo_name TEXT, set_by INT, updated_at TEXT)");
        $db->exec("CREATE TABLE qbo_links (id INTEGER PRIMARY KEY, site_id INT, realm_id TEXT, entity_type TEXT, entity_id TEXT, qbo_id TEXT, sync_token TEXT,
                   request_id TEXT, status TEXT, qbo_total REAL, message TEXT, sent_at TEXT)");
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT, setting_value TEXT, description TEXT)");
        $db->exec("CREATE TABLE chart_of_accounts (code TEXT, name TEXT, type TEXT, is_active INT)");
        $db->exec("CREATE TABLE clients (id INTEGER PRIMARY KEY, display_name TEXT, billing_email TEXT)");
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, phone TEXT, mobile TEXT)");
        $db->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT, billing_email TEXT)");
        $db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, city TEXT, province TEXT, postal_code TEXT)");
        $db->exec("CREATE TABLE stripe_payments (id INTEGER PRIMARY KEY, invoice_id INT, amount_cents INT, status TEXT, created_at TEXT, stripe_charge_id TEXT)");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, client_id INT, contact_id INT, company_id INT, property_id INT, amount_paid REAL, invoice_number TEXT, status TEXT, subtotal REAL, tax_amount REAL, tax_rate REAL, total REAL,
                   total_amount REAL, issue_date TEXT, invoice_date TEXT, due_date TEXT, created_at TEXT, bill_to_name TEXT, sent_to_email TEXT, updated_at TEXT)");
        $db->exec("CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT)");
        $db->exec("CREATE TABLE invoice_line_items (id INTEGER PRIMARY KEY, invoice_id INT, title TEXT, description TEXT, quantity REAL, unit_price REAL, line_total REAL, visit_id INT, service_date TEXT, sort_order INT)");
        $db->exec("CREATE TABLE invoice_payment_allocations (id INTEGER PRIMARY KEY, invoice_id INT, amount REAL, payment_date TEXT, created_at TEXT, reference TEXT)");

        $v = new QboTokenVault(str_repeat("\x07", 32));
        $db->prepare("INSERT INTO qbo_connections (site_id, environment, realm_id, company_name, access_token_enc, refresh_token_enc, access_expires_at, status)
                      VALUES (1, 'sandbox', '777', 'CA Co', ?, ?, ?, 'connected')")
           ->execute([$v->encrypt('A'), $v->encrypt('R'), date('Y-m-d H:i:s', self::NOW + 1800)]);
        $db->exec("INSERT INTO qbo_mappings (site_id, kind, ittai_key, qbo_id, qbo_name) VALUES
                   (1, 'tax_code', 'gst', '9', 'GST'), (1, 'tax_code', 'exempt', '2', 'Exempt'),
                   (1, 'account', '4100', '80', 'Services'), (1, 'account', '4200', '81', 'Hedges')");
        $db->exec("INSERT INTO chart_of_accounts VALUES ('4100', 'Lawn Care Services', 'revenue', 1), ('4200', 'Hedge & Shrub Trimming', 'revenue', 1), ('4600', 'Fertilization', 'revenue', 1)");
        $db->exec("INSERT INTO clients VALUES (5, 'Susan O''Lim', 'susan@example.ca')");
        $db->exec("INSERT INTO invoices VALUES (10, 5, NULL, NULL, NULL, 416.06, 'INV-2026-0500', 'sent', 396.25, 19.81, 0.05, 416.06, 416.06, '2026-10-16', NULL, '2026-10-30', '2026-10-16 10:00:00', 'Susan O''Lim', NULL, '2026-10-16 10:00:00')");
        $db->exec("INSERT INTO products VALUES (7, 'Hedge trim')");
        $db->exec("INSERT INTO invoice_line_items VALUES (1, 10, 'Hedge trim', NULL, 2, 198.125, 396.25, NULL, NULL, 1)");
        $db->exec("INSERT INTO invoice_payment_allocations VALUES (100, 10, 416.06, '2026-10-20', '2026-10-20 09:00:00', 'ET-55')");
        return $db;
    }

    private function sync(PDO $db, array $splits = [['4200', 396.25]]): QboSyncService
    {
        $this->sent = []; $this->queries = [];
        $get = function (string $url, array $h) {
            $q = urldecode((string)parse_url($url, PHP_URL_QUERY));
            $this->queries[] = $q;
            if (strpos($q, 'FROM Invoice WHERE Id') !== false) {
                $cust = array_values(array_filter($this->sent, fn($x) => $x['entity'] === 'invoice'));
                $ref = $cust ? end($cust)['body']['CustomerRef']['value'] : '61';
                return ['status' => 200, 'body' => json_encode(['QueryResponse' => ['Invoice' => [['Id' => '1', 'CustomerRef' => ['value' => $ref ?: '61']]]]])];
            }
            return ['status' => 200, 'body' => '{"QueryResponse":{}}'];
        };
        $post = function (string $url, array $h, string $body) {
            $entity = (string)preg_replace('#^.*/company/777/([a-z]+)\?.*$#', '$1', $url);
            $this->sent[] = ['entity' => $entity, 'url' => $url, 'body' => json_decode($body, true)];
            if (isset($this->answers[$entity])) return ($this->answers[$entity])(json_decode($body, true), $url);
            $id = (string)(count($this->sent) + 500);
            $obj = ['Id' => $id, 'SyncToken' => '0'];
            if ($entity === 'invoice') $obj['TotalAmt'] = 416.06;
            if ($entity === 'payment') $obj['TotalAmt'] = json_decode($body, true)['TotalAmt'];
            return ['status' => 200, 'body' => json_encode([ucfirst($entity) => $obj])];
        };
        $conn = new QboConnectionService($db, 1, new QboOAuth('c', 's', fn() => ['status' => 500, 'body' => '']),
                                         new QboTokenVault(str_repeat("\x07", 32)), fn() => self::NOW, $get, $post);
        return new QboSyncService($conn, fn(array $row, float $net) => $splits, fn() => self::NOW);
    }

    public function test_an_invoice_goes_up_with_customer_item_gst_code_and_payment(): void
    {
        $db = $this->db();
        $r = $this->sync($db)->sendInvoice(10);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(['item', 'customer', 'invoice', 'payment'], array_column($this->sent, 'entity'), 'item and customer exist before the invoice');

        $cust = $this->sent[1]['body'];
        $this->assertSame("Susan O'Lim", $cust['DisplayName']);
        $this->assertSame('susan@example.ca', $cust['PrimaryEmailAddr']['Address']);
        $want = "SELECT Id, DisplayName FROM Customer WHERE DisplayName = 'Susan O\\'Lim'";
        $this->assertNotEmpty(array_filter($this->queries, fn($q) => strpos($q, $want) !== false),
            'an existing customer is looked for first, the apostrophe escaped');

        $item = $this->sent[0]['body'];
        $this->assertSame(['Name' => 'Hedge & Shrub Trimming (Ittai)', 'Type' => 'Service', 'IncomeAccountRef' => ['value' => '81']], $item);
        $this->assertSame('Expenses - Fuel (Ittai)', QboSyncService::itemBody('Expenses:Fuel', '1')['Name'], 'no colon: QuickBooks reads it as a sub-item');

        $inv = $this->sent[2]['body'];
        $this->assertSame('INV-2026-0500', $inv['DocNumber']);
        $this->assertSame('2026-10-16', $inv['TxnDate']);
        $this->assertSame('TaxExcluded', $inv['GlobalTaxCalculation']);
        $this->assertSame(396.25, $inv['Line'][0]['Amount']);
        $this->assertSame('9', $inv['Line'][0]['SalesItemLineDetail']['TaxCodeRef']['value'], 'GST charged → the mapped GST code');
        $this->assertSame('Hedge trim', $inv['Line'][0]['Description']);
        $this->assertStringContainsString('requestid=ittai-1-inv-10', $this->sent[2]['url']);

        $pay = $this->sent[3]['body'];
        $this->assertSame(416.06, $pay['TotalAmt']);
        $this->assertSame('Invoice', $pay['Line'][0]['LinkedTxn'][0]['TxnType']);
        $this->assertSame('ET-55', $pay['PaymentRefNum']);
        $this->assertStringContainsString('totals match', $r['message']);
    }

    public function test_product_lines_require_every_mapping_and_the_rounded_subtotal(): void
    {
        $rows = [
            ['quantity'=>2,'unit_price'=>12.345,'line_total'=>24.69,'title'=>'Cut','description'=>'Front'],
            ['quantity'=>1,'unit_price'=>10,'line_total'=>10,'title'=>'','description'=>"Trim\nBack garden"],
        ];
        $lines = QboSyncService::productLines($rows, ['cut'=>'700','trim'=>'800'], 34.69);
        $this->assertSame('700', $lines[0]['item']);
        $this->assertSame('800', $lines[1]['item'], 'an empty title uses only the description first line for matching');
        $this->assertSame(12.35, $lines[0]['unit_price']);
        $this->assertSame(24.69, $lines[0]['amount']);
        $this->assertSame('Cut — Front', $lines[0]['description']);
        $this->assertNull(QboSyncService::productLines($rows, ['cut'=>'700'], 34.69), 'one unmapped line falls back');
        $this->assertNull(QboSyncService::productLines($rows, ['cut'=>'700','trim'=>'800'], 34.68), 'a subtotal mismatch falls back');
    }

    public function test_mapped_invoice_lines_are_loaded_from_the_database_and_sent_as_item_refs(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO qbo_mappings (site_id, kind, ittai_key, qbo_id, qbo_name) VALUES (1, 'item', '7', '700', 'Hedge trim')");
        $r = $this->sync($db)->sendInvoice(10);
        $this->assertTrue($r['ok'], $r['message']);
        $invoice = array_values(array_filter($this->sent, fn($row) => $row['entity'] === 'invoice'))[0]['body'];
        $this->assertSame('700', $invoice['Line'][0]['SalesItemLineDetail']['ItemRef']['value']);
        $this->assertEquals(2.0, $invoice['Line'][0]['SalesItemLineDetail']['Qty']);
        $this->assertSame(198.13, $invoice['Line'][0]['SalesItemLineDetail']['UnitPrice']);
    }

    public function test_distinct_product_mappings_with_the_same_normalized_name_fall_back_to_the_account_item(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO products VALUES (8, 'Mow & Blow'), (9, 'Mow-Blow')");
        $db->exec("INSERT INTO qbo_mappings (site_id, kind, ittai_key, qbo_id, qbo_name) VALUES
                   (1, 'item', '8', '700', 'Mow & Blow'), (1, 'item', '9', '800', 'Mow-Blow')");
        $db->exec("UPDATE invoice_line_items SET title = 'Mow Blow' WHERE invoice_id = 10");

        $r = $this->sync($db)->sendInvoice(10);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(['item', 'customer', 'invoice', 'payment'], array_column($this->sent, 'entity'),
            'a normalized-name collision uses the safe account-split path');
        $this->assertSame('81', $this->sent[0]['body']['IncomeAccountRef']['value']);
        $invoice = $this->sent[2]['body'];
        $this->assertNotContains($invoice['Line'][0]['SalesItemLineDetail']['ItemRef']['value'], ['700', '800'],
            'neither ambiguous product mapping may supply the invoice item');
    }

    public function test_complete_run_summary_covers_counts_failures_and_stops(): void
    {
        $sent=QboSyncService::runSummary(['sent'=>3,'held'=>2,'failed'=>0,'notes'=>[],'stopped'=>null]);
        $this->assertSame('success',$sent['status']); $this->assertSame('QuickBooks: 3 sent, 2 held, 0 failed',$sent['summary']);
        $failed=QboSyncService::runSummary(['sent'=>1,'held'=>0,'failed'=>1,'notes'=>['bad'],'stopped'=>null]);
        $this->assertSame('warning',$failed['status']); $this->assertSame('bad',$failed['notes_text']);
        $stopped=QboSyncService::runSummary(['sent'=>0,'held'=>0,'failed'=>0,'notes'=>[],'stopped'=>'Connect again.']);
        $this->assertSame('warning',$stopped['status']); $this->assertStringContainsString('stopped: Connect again.',$stopped['summary']);
    }

    public function test_run_all_covers_connection_switch_combining_and_invoice_stop(): void
    {
        $db = $this->db();
        $conn = new class($db) extends QboConnectionService {
            public bool $ready = false; public ?array $business = null;
            public function tablesReady(): bool { return $this->ready; }
            public function company(): ?array { return $this->business; }
        };
        $this->assertStringContainsString('not connected', QboSyncService::runAll($conn)['summary']);
        $conn->ready = true; $conn->business = ['realm_id' => '777'];

        $sync = new class {
            public bool $on = false; public array $result = [];
            public function auto(): array { return ['on' => $this->on, 'from' => $this->on ? '2026-10-20' : null]; }
            public function runQueue(): array { return $this->result; }
        };
        $receipts = new class { public int $calls = 0; public array $result = [];
            public function runExpenses(): array { $this->calls++; return $this->result; }
        };
        $makeSync = static fn() => $sync; $makeReceipts = static fn() => $receipts;
        $this->assertStringContainsString('sending is off', QboSyncService::runAll($conn, $makeSync, $makeReceipts)['summary']);

        $sync->on = true;
        $sync->result = ['sent'=>2,'held'=>1,'failed'=>0,'notes'=>['invoice note'],'stopped'=>null];
        $receipts->result = ['sent'=>3,'held'=>0,'failed'=>1,'notes'=>['receipt note'],'stopped'=>null];
        $combined = QboSyncService::runAll($conn, $makeSync, $makeReceipts);
        $this->assertSame([5,1,1], [$combined['sent'],$combined['held'],$combined['failed']]);
        $this->assertSame('invoice note | receipt note', $combined['notes_text']);

        $sync->result = ['sent'=>0,'held'=>0,'failed'=>0,'notes'=>[],'stopped'=>'Connect again.'];
        $before = $receipts->calls;
        $stopped = QboSyncService::runAll($conn, $makeSync, $makeReceipts);
        $this->assertSame($before, $receipts->calls, 'receipts do not run after an invoice connection stop');
        $this->assertSame('Connect again.', $stopped['stopped']);
    }

    public function test_sending_again_sends_nothing_twice(): void
    {
        $db = $this->db();
        $this->sync($db)->sendInvoice(10);
        $r = $this->sync($db)->sendInvoice(10);
        $this->assertTrue($r['ok']);
        $this->assertSame([], $this->sent, 'no second customer, item, invoice or payment');
        $this->assertStringContainsString('already in QuickBooks', $r['message']);
    }

    public function test_a_new_payment_later_is_applied_to_the_invoice_already_there(): void
    {
        $db = $this->db();
        $db->exec("UPDATE invoice_payment_allocations SET amount = 200.00 WHERE id = 100");     // paid in part
        $this->sync($db)->sendInvoice(10);
        $db->exec("INSERT INTO invoice_payment_allocations VALUES (101, 10, 216.06, '2026-10-21', '2026-10-21 09:00:00', NULL)");
        $r = $this->sync($db)->sendInvoice(10);
        $this->assertSame(['payment'], array_column($this->sent, 'entity'));
        $this->assertSame(1, $r['payments']);
    }

    public function test_an_unmapped_account_stops_the_send_with_what_to_do(): void
    {
        $db = $this->db();
        $r = $this->sync($db, [['4600', 396.25]])->sendInvoice(10);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('map Ittai account 4600 Fertilization', $r['message']);
        $this->assertSame([], $this->sent, 'nothing half-sent');
    }

    public function test_a_refused_invoice_gets_a_fresh_request_id_once_fixed(): void
    {
        $db = $this->db();
        $this->answers['invoice'] = fn() => ['status' => 400, 'body' => '{"Fault":{"Error":[{"Message":"Invalid tax code","code":"6000"}]}}'];
        $r = $this->sync($db)->sendInvoice(10);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Invalid tax code', $r['message']);
        $this->assertSame('refused', $db->query("SELECT status FROM qbo_links WHERE entity_type = 'invoice'")->fetchColumn());

        unset($this->answers['invoice']);
        $r = $this->sync($db)->sendInvoice(10);
        $this->assertTrue($r['ok'], $r['message']);
        $inv = array_values(array_filter($this->sent, fn($s) => $s['entity'] === 'invoice'));
        $this->assertStringContainsString('requestid=ittai-1-inv-10-r1', $inv[0]['url'], 'a new id: the old one would replay the refusal');
    }

    public function test_a_timeout_retries_with_the_same_request_id(): void
    {
        $db = $this->db();
        $this->answers['invoice'] = fn() => ['status' => 0, 'body' => ''];
        $this->assertFalse($this->sync($db)->sendInvoice(10)['ok']);
        unset($this->answers['invoice']);
        $this->sync($db)->sendInvoice(10);
        $inv = array_values(array_filter($this->sent, fn($s) => $s['entity'] === 'invoice'));
        $this->assertStringContainsString('requestid=ittai-1-inv-10&', $inv[0]['url'] . '&', 'same id: if the first one landed, Intuit replays it');
    }

    public function test_a_different_total_is_said_not_hidden(): void
    {
        $db = $this->db();
        $this->answers['invoice'] = fn() => ['status' => 200, 'body' => '{"Invoice":{"Id":"55","SyncToken":"0","TotalAmt":416.07}}'];
        $r = $this->sync($db)->sendInvoice(10);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('QuickBooks total $416.07 vs Ittai $416.06', $r['message']);
        $this->assertSame('mismatch', $db->query("SELECT status FROM qbo_links WHERE entity_type = 'invoice'")->fetchColumn());
    }

    public function test_a_name_taken_by_a_supplier_gets_a_customer_suffix(): void
    {
        $db = $this->db();
        $tries = 0;
        $this->answers['customer'] = function (array $b) use (&$tries) {
            $tries++;
            return $tries === 1
                ? ['status' => 400, 'body' => '{"Fault":{"Error":[{"Message":"Duplicate Name Exists Error","code":"6240"}]}}']
                : ['status' => 200, 'body' => json_encode(['Customer' => ['Id' => '61', 'SyncToken' => '0', 'DisplayName' => $b['DisplayName']]])];
        };
        $this->assertTrue($this->sync($db)->sendInvoice(10)['ok']);
        $cust = array_values(array_filter($this->sent, fn($s) => $s['entity'] === 'customer'));
        $this->assertSame("Susan O'Lim (customer)", $cust[1]['body']['DisplayName']);
    }

    public function test_an_invoice_with_no_gst_uses_the_exempt_code_and_drafts_never_go(): void
    {
        $db = $this->db();
        $db->exec("UPDATE invoices SET tax_amount = 0, total = 396.25");
        $this->answers['invoice'] = fn() => ['status' => 200, 'body' => '{"Invoice":{"Id":"56","SyncToken":"0","TotalAmt":396.25}}'];
        $this->sync($db)->sendInvoice(10);
        $inv = array_values(array_filter($this->sent, fn($s) => $s['entity'] === 'invoice'));
        $this->assertSame('2', $inv[0]['body']['Line'][0]['SalesItemLineDetail']['TaxCodeRef']['value']);

        $db->exec("INSERT INTO invoices (id, invoice_number, status, subtotal, total) VALUES (11, 'INV-DRAFT', 'draft', 50, 50)");
        $r = $this->sync($db)->sendInvoice(11);
        $this->assertFalse($r['ok']);
        $this->assertSame([], $this->sent);
    }

    public function test_a_live_company_is_refused_until_switched_on(): void
    {
        $db = $this->db();
        $db->exec("UPDATE qbo_connections SET environment = 'production'");
        $r = $this->sync($db)->sendInvoice(10);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('not switched on', $r['message']);
        $this->assertSame([], $this->sent);
    }

    public function test_payments_wait_while_the_totals_differ(): void
    {
        $db = $this->db();
        $this->answers['invoice'] = fn() => ['status' => 200, 'body' => '{"Invoice":{"Id":"55","SyncToken":"0","TotalAmt":416.07}}'];
        $r = $this->sync($db)->sendInvoice(10);
        $this->assertSame(['item', 'customer', 'invoice'], array_column($this->sent, 'entity'), 'no payment onto a wrong invoice');
        $this->assertStringContainsString('Payments are held', $r['message']);
        unset($this->answers['invoice']);
        $r = $this->sync($db)->sendInvoice(10);
        $this->assertSame([], $this->sent, 'and not on the next try either');
        $this->assertFalse($r['ok']);
    }

    public function test_payments_never_go_past_the_invoice_total(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO invoice_payment_allocations VALUES (101, 10, 416.06, '2026-10-21', '2026-10-21 09:00:00', NULL)");   // a duplicate
        $r = $this->sync($db)->sendInvoice(10);
        $this->assertCount(1, array_filter($this->sent, fn($s) => $s['entity'] === 'payment'));
        $this->assertStringContainsString('1 payment(s) held', $r['message']);
    }

    public function test_invoices_that_are_not_lines_times_one_tax_are_held_before_anything_is_created(): void
    {
        $this->assertNull(QboSyncService::cannotSend(396.25, 19.81, 416.06, [['4200', 396.25]]));
        $this->assertStringContainsString('discount or credit', (string)QboSyncService::cannotSend(396.25, 19.81, 400.00, [['4200', 396.25]]));
        $this->assertStringContainsString('some lines are exempt', (string)QboSyncService::cannotSend(396.25, 10.00, 406.25, [['4200', 396.25]]));
        $this->assertStringContainsString('adds to $1,100.00', (string)QboSyncService::cannotSend(1000, 0, 1000, [['4100', 600], ['4200', 500]]));
        $this->assertNull(QboSyncService::cannotSend(100, 13, 113, [['4100', 100]], 0.13), "Ontario's 13% HST is fine at its own rate");

        $db = $this->db();
        $db->exec("UPDATE invoices SET total = 400.00");
        $r = $this->sync($db)->sendInvoice(10);
        $this->assertFalse($r['ok']);
        $this->assertSame([], $this->sent, 'nothing created in QuickBooks');
    }

    public function test_a_remapped_account_gets_an_item_for_the_new_account(): void
    {
        $db = $this->db();
        $this->sync($db)->sendInvoice(10);
        $db->exec("UPDATE qbo_mappings SET qbo_id = '99', qbo_name = 'Landscaping' WHERE ittai_key = '4200'");
        $db->exec("INSERT INTO invoices VALUES (12, 5, NULL, NULL, NULL, 0, 'INV-2026-0501', 'sent', 100, 5, 5.0, 105, 105, '2026-10-17', NULL, NULL, '2026-10-17', 'Susan O''Lim', NULL, NULL)");
        $this->sync($db, [['4200', 100]])->sendInvoice(12);
        $items = array_values(array_filter($this->sent, fn($s) => $s['entity'] === 'item'));
        $this->assertCount(1, $items, 'a new item, not the one pointing at the old account');
        $this->assertSame('99', $items[0]['body']['IncomeAccountRef']['value']);
    }

    public function test_the_stored_rate_is_read_as_a_fraction_or_a_percent(): void
    {
        $this->assertSame(0.05, QboSyncService::rateOf('0.05'), 'live invoices store 0.05');
        $this->assertSame(0.05, QboSyncService::rateOf(5), 'a percent reads the same');
        $this->assertSame(0.13, QboSyncService::rateOf('13.0'));
        $this->assertSame(0.05, QboSyncService::rateOf(null));
    }

    public function test_a_stripe_payment_is_sent_with_the_invoices_own_customer(): void
    {
        // Live 2026-10-20: INV-2026-0461 was paid by Stripe (stripe_payments only) and went up unpaid.
        $db = $this->db();
        $db->exec("DELETE FROM invoice_payment_allocations");
        $db->exec("INSERT INTO stripe_payments VALUES (277, 10, 41606, 'succeeded', '2026-10-16 14:18:09', 'ch_1')");
        $r = $this->sync($db)->sendInvoice(10);
        $pays = array_values(array_filter($this->sent, fn($s) => $s['entity'] === 'payment'));
        $this->assertCount(1, $pays);
        $this->assertSame(416.06, $pays[0]['body']['TotalAmt']);
        $this->assertSame('Stripe', $pays[0]['body']['PaymentRefNum']);
        $inv = array_values(array_filter($this->sent, fn($s) => $s['entity'] === 'invoice'));
        $this->assertSame($inv[0]['body']['CustomerRef']['value'], $pays[0]['body']['CustomerRef']['value'], 'the payment is for the invoice\'s customer');
        $this->assertSame(1, $r['payments']);
    }

    public function test_paid_without_a_payment_record_is_said(): void
    {
        $db = $this->db();
        $db->exec("DELETE FROM invoice_payment_allocations");
        $r = $this->sync($db)->sendInvoice(10);
        $this->assertStringContainsString('$416.06 marked paid in Ittai has no payment record', $r['message']);
    }

    public function test_the_customer_comes_from_client_then_contact_then_company_then_property(): void
    {
        $base = ['id' => 478, 'client_id' => null, 'contact_id' => 40, 'company_id' => null, 'property_id' => 43,
                 'client_name' => null, 'contact_name' => 'Matthew Collingwood', 'contact_email' => 'm@example.ca',
                 'company_name' => null, 'bill_to_name' => null, 'property_address' => '3523 West 3rd Avenue'];
        $this->assertSame(['contact:40', 'Matthew Collingwood', 'm@example.ca'], QboSyncService::customerOf($base), 'INV-2026-0461: contact only');
        $this->assertSame('client:5', QboSyncService::customerOf(['client_id' => 5, 'client_name' => 'Strata 123'] + $base)[0]);
        $this->assertSame('property:43', QboSyncService::customerOf(['contact_name' => ''] + $base)[0]);
        $a = QboSyncService::customerOf(['id' => 1, 'contact_name' => '', 'property_address' => ''] + $base);
        $b = QboSyncService::customerOf(['id' => 2, 'contact_name' => '', 'property_address' => ''] + $base);
        $this->assertNotSame($a[0], $b[0], 'two nameless invoices never share one customer');
    }

    public function test_the_customer_gets_a_billing_address_and_phone(): void
    {
        $this->assertSame(['Line1' => '3523 West 3rd Avenue', 'City' => 'Vancouver', 'CountrySubDivisionCode' => 'BC', 'PostalCode' => 'V6R 1L6', 'Country' => 'Canada'],
            QboSyncService::billAddr(['property_address' => '3523 West 3rd Avenue', 'property_city' => 'Vancouver', 'property_province' => 'BC', 'property_postal' => 'V6R 1L6']));
        $this->assertSame('1 Billing St', QboSyncService::billAddr(['billing_address' => '1 Billing St', 'property_address' => '2 Site Rd'])['Line1'], 'billing address first');
        $this->assertNull(QboSyncService::billAddr([]));
        $b = QboSyncService::customerBody('A', 'a@x.ca', ['Line1' => '1 St'], '604-555-0101');
        $this->assertSame('604-555-0101', $b['PrimaryPhone']['FreeFormNumber']);
        $this->assertSame('1 St', $b['BillAddr']['Line1']);
    }

    // ── automatic sending ────────────────────────────────────────────────────

    private function autoOn(PDO $db, string $from = '2026-10-01'): void
    {
        $db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('qbo_auto_send', '1'), ('qbo_auto_from', '$from')");
    }

    public function test_nothing_is_sent_automatically_until_switched_on(): void
    {
        $db = $this->db();
        $r = $this->sync($db)->runQueue();
        $this->assertSame(0, $r['sent']);
        $this->assertSame([], $this->sent);
        $this->assertFalse($this->sync($db)->setAuto(true, 'soon'), 'a start date is required');
        $this->assertTrue($this->sync($db)->setAuto(true, '2026-10-01'));
        $this->assertTrue($this->sync($db)->auto()['on']);
    }

    public function test_the_run_sends_issued_invoices_from_the_start_date_only(): void
    {
        $db = $this->db();
        $this->autoOn($db, '2026-10-16');
        $db->exec("INSERT INTO invoices (id, client_id, invoice_number, status, subtotal, tax_amount, tax_rate, total, issue_date, created_at)
                   VALUES (20, 5, 'INV-OLD', 'sent', 100, 5, 0.05, 105, '2026-09-30', '2026-09-30')");
        $r = $this->sync($db)->runQueue();
        $this->assertSame(1, $r['sent']);
        $inv = array_values(array_filter($this->sent, fn($x) => $x['entity'] === 'invoice'));
        $this->assertSame(['INV-2026-0500'], array_map(fn($x) => $x['body']['DocNumber'], $inv), 'September history is not dumped in');
        $this->assertSame(0, $this->sync($db)->runQueue()['sent'], 'nothing twice');
    }

    public function test_a_held_invoice_waits_until_it_changes(): void
    {
        $db = $this->db();
        $this->autoOn($db);
        $db->exec("UPDATE invoices SET total = 400.00");         // a discount: held
        $r = $this->sync($db)->runQueue();
        $this->assertSame(1, $r['held']);
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->sync($db)->due(), 'not picked up again');
        $db->exec("UPDATE invoices SET total = 416.06, updated_at = '2026-10-21 08:00:00'");   // fixed
        $this->assertSame([10], $this->sync($db)->due());
        $this->assertSame(1, $this->sync($db)->runQueue()['sent']);
    }

    public function test_a_refused_invoice_is_held_and_its_retry_gets_a_fresh_id(): void
    {
        $db = $this->db();
        $this->autoOn($db);
        $this->answers['invoice'] = fn() => ['status' => 400, 'body' => '{"Fault":{"Error":[{"Message":"Bad","code":"6000"}]}}'];
        $this->assertSame(1, $this->sync($db)->runQueue()['held']);
        $this->assertSame([], $this->sync($db)->due());
        unset($this->answers['invoice']);
        $db->exec("UPDATE invoices SET updated_at = '2026-10-21 08:00:00'");
        $this->sync($db)->runQueue();
        $inv = array_values(array_filter($this->sent, fn($x) => $x['entity'] === 'invoice'));
        $this->assertStringContainsString('requestid=ittai-1-inv-10-r1', $inv[0]['url']);
    }

    public function test_a_payment_that_came_from_quickbooks_never_goes_back(): void
    {
        $db = $this->db();
        $this->autoOn($db);
        $db->exec("UPDATE invoice_payment_allocations SET amount = 200.00");
        $this->sync($db)->runQueue();
        $db->exec("INSERT INTO invoice_payment_allocations (id, invoice_id, amount, payment_date, created_at) VALUES (101, 10, 216.06, '2026-10-21', '2026-10-21 09:00:00')");
        QboLinkService::save($db, 1, '777', 'payment', 'alloc:101', '88', null, 'r', 'from_qbo', 216.06, 'Paid in QuickBooks', '2026-10-21 09:00:00');
        $this->assertSame([], $this->sync($db)->due());
        $this->sync($db)->sendInvoice(10);
        $this->assertSame([], array_column($this->sent, 'entity'));
    }

    public function test_a_payment_after_the_invoice_is_picked_up_by_the_next_run(): void
    {
        $db = $this->db();
        $this->autoOn($db);
        $db->exec("UPDATE invoice_payment_allocations SET amount = 200.00");
        $this->sync($db)->runQueue();
        $db->exec("INSERT INTO stripe_payments VALUES (300, 10, 21606, 'succeeded', '2026-10-21 09:00:00', 'ch_2')");
        $this->assertSame([10], $this->sync($db)->due());
        $this->sync($db)->runQueue();
        $this->assertSame(['payment'], array_column($this->sent, 'entity'));
        $this->assertSame([], $this->sync($db)->due());
    }

    public function test_voided_after_sending_keeps_its_link_and_is_never_sent_again(): void
    {
        $db = $this->db();
        $this->autoOn($db);
        $db->exec("UPDATE invoice_payment_allocations SET amount = 200.00");
        $this->sync($db)->runQueue();
        $qboId = $db->query("SELECT qbo_id FROM qbo_links WHERE entity_type = 'invoice'")->fetchColumn();
        $db->exec("UPDATE invoices SET status = 'void'");
        $db->exec("INSERT INTO stripe_payments VALUES (300, 10, 21606, 'succeeded', '2026-10-21 09:00:00', 'ch_2')");
        $this->assertSame([], $this->sync($db)->due(), 'no payment onto a voided invoice');
        $r = $this->sync($db)->sendInvoice(10);
        $this->assertStringContainsString('void it in QuickBooks too', $r['message']);
        $this->assertSame($qboId, $db->query("SELECT qbo_id FROM qbo_links WHERE entity_type = 'invoice'")->fetchColumn(), 'the link is never erased');
        $db->exec("UPDATE invoices SET status = 'sent'");      // restored
        $this->sync($db)->runQueue();
        $this->assertSame([], array_values(array_filter($this->sent, fn($x) => $x['entity'] === 'invoice')), 'never a second QuickBooks invoice');
    }

    public function test_a_held_payment_comes_back_when_its_amount_is_corrected(): void
    {
        $db = $this->db();
        $this->autoOn($db);
        $db->exec("INSERT INTO invoice_payment_allocations VALUES (101, 10, 416.06, '2026-10-21', '2026-10-21 09:00:00', NULL)");   // a duplicate
        $this->sync($db)->runQueue();
        $this->assertSame([], $this->sync($db)->due(), 'held: not every run');
        $this->assertStringContainsString('would pay more than the invoice', implode(' ', array_column($this->sync($db)->problems(), 'why')));
        $db->exec("UPDATE invoice_payment_allocations SET amount = 0.00 WHERE id = 100");
        $db->exec("UPDATE invoice_payment_allocations SET amount = 10.00 WHERE id = 101");   // corrected
        $this->assertSame([10], $this->sync($db)->due());
    }

    public function test_changes_in_ittai_after_sending_are_listed(): void
    {
        $db = $this->db();
        $this->sync($db)->sendInvoice(10);
        $this->assertSame([], $this->sync($db)->problems());
        $db->exec("UPDATE invoices SET total = 430.00");
        $p = $this->sync($db)->problems();
        $this->assertStringContainsString('Changed in Ittai after it was sent ($430.00 now, $416.06 in QuickBooks)', $p[0]['why']);
        $db->exec("UPDATE invoices SET total = 416.06, status = 'void'");
        $this->assertStringContainsString('Voided in Ittai', $this->sync($db)->problems()[0]['why']);
    }

    public function test_request_ids_and_names(): void
    {
        $this->assertSame('ittai-1-inv-10', QboSyncService::requestId(1, 'inv', '10'));
        $this->assertSame('base', QboSyncService::nextRequestId('base', null));
        $this->assertSame('base-r1', QboSyncService::nextRequestId('base', ['status' => 'refused', 'request_id' => 'base']));
        $this->assertSame('base-r3', QboSyncService::nextRequestId('base', ['status' => 'refused', 'request_id' => 'base-r2']));
        $this->assertSame('base-r2', QboSyncService::nextRequestId('base', ['status' => 'error', 'request_id' => 'base-r2']), 'timeout: same id');
        $this->assertSame('Ittai customer (invoice 9)', QboSyncService::displayName(null, '  ', 9));
        $this->assertSame('A B', QboSyncService::displayName("A\tB", null, 9));
        $this->assertTrue(QboClient::refused(400));
        $this->assertFalse(QboClient::refused(429));
        $this->assertFalse(QboClient::refused(503));
        $this->assertSame('6240', QboClient::faultCode('{"Fault":{"Error":[{"code":"6240"}]}}'));
    }
}
