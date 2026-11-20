<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class QboRecurringServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE qbo_onboarding_snapshot (id INTEGER PRIMARY KEY, site_id INT, kind TEXT, qbo_id TEXT, data TEXT, pulled_at TEXT)');
        $db->exec('CREATE TABLE qbo_links (id INTEGER PRIMARY KEY, site_id INT, entity_type TEXT, entity_id TEXT, qbo_id TEXT)');
        $db->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, legacy_contact_id INT)');
        return $db;
    }

    private function normal(string $id = 'R1', string $customer = 'C1', bool $active = true, string $type = 'Automated'): array
    {
        return ['id' => $id, 'name' => 'Spring care', 'active' => $active, 'recur_type' => $type,
            'customer_id' => $customer, 'customer_name' => 'Ada', 'total' => 105.0, 'subtotal' => 100.0,
            'interval' => 'Monthly', 'every' => 1, 'day_of_month' => 1, 'start_date' => '2026-01-01',
            'end_date' => '', 'next_date' => '2026-12-01', 'lines' => 'Care'];
    }

    private function withSnapshot(PDO $db, array $rows): QboRecurringService
    {
        $s = $db->prepare("INSERT INTO qbo_onboarding_snapshot (site_id,kind,qbo_id,data) VALUES (1,'recurring',?,?)");
        foreach ($rows as $row) $s->execute([$row['id'], json_encode($row)]);
        return new QboRecurringService($db, null);
    }

    public function test_normalise_wrapper_bare_bill_and_subtotals(): void
    {
        $invoice = ['Id' => '9', 'CustomerRef' => ['value' => 'C1', 'name' => 'Ada'], 'TotalAmt' => 110,
            'Line' => [['Amount' => 70, 'Description' => 'Mow', 'SalesItemLineDetail' => []], ['Amount' => 30, 'Description' => 'Edge', 'SalesItemLineDetail' => []]],
            'RecurringInfo' => ['Name' => 'Care', 'Active' => true, 'RecurType' => 'Automated', 'ScheduleInfo' => ['IntervalType' => 'Monthly']]];
        $wrapped = QboRecurringService::normalise(['Invoice' => $invoice]);
        $this->assertSame('9', $wrapped['id']);
        $this->assertSame(100.0, $wrapped['subtotal']);
        $this->assertSame('Mow, Edge', $wrapped['lines']);
        $invoice['Line'] = [];
        $invoice['TxnTaxDetail'] = ['TotalTax' => 10];
        $this->assertSame(100.0, QboRecurringService::normalise($invoice)['subtotal']);
        $this->assertNull(QboRecurringService::normalise(['Bill' => $invoice]));
    }

    public function test_billing_and_cycle_rules(): void
    {
        $n = $this->normal();
        $this->assertTrue(QboRecurringService::bills($n));
        $n['recur_type'] = 'Reminded'; $this->assertTrue(QboRecurringService::bills($n));
        $ended = ['end_date' => '2026-03-31'] + $n;
        $this->assertFalse(QboRecurringService::bills($ended, '2026-11-05'));
        $this->assertTrue(QboRecurringService::bills($ended, '2026-03-31'));
        $n['recur_type'] = 'unscheduled'; $this->assertFalse(QboRecurringService::bills($n));
        $n['active'] = false; $this->assertFalse(QboRecurringService::bills($n));
        $this->assertSame(['monthly', null], QboRecurringService::cycleOf($this->normal()));
        $year = $this->normal(); $year['interval'] = 'Yearly';
        $this->assertSame(['annual', 'Billed once a year in QuickBooks'], QboRecurringService::cycleOf($year));
        $week = $this->normal(); $week['interval'] = 'Weekly'; $week['every'] = 2;
        $this->assertSame(['custom', 'QuickBooks bills every 2 Weekly'], QboRecurringService::cycleOf($week));
    }

    public function test_hold_reason_uses_contact_and_property_links_and_respects_pause(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO qbo_links VALUES (1,1,'client','contact:8','C1')");
        $service = $this->withSnapshot($db, [$this->normal()]);
        $reason = $service->holdReason(['contact_id' => 8, 'property_id' => 4, 'billing_amount' => 100]);
        $this->assertStringContainsString('Spring care', $reason);

        $db2 = $this->db();
        $db2->exec("INSERT INTO qbo_links VALUES (1,1,'client','property:4','C1')");
        $this->assertNotNull($this->withSnapshot($db2, [$this->normal()])->holdReason(['contact_id' => 8, 'property_id' => 4]));
        $paused = $this->normal('R1', 'C1', false);
        $db3 = $this->db(); $db3->exec("INSERT INTO qbo_links VALUES (1,1,'client','contact:8','C1')");
        $this->assertNull($this->withSnapshot($db3, [$paused])->holdReason(['contact_id' => 8, 'property_id' => 4]));
    }

    public function test_read_failure_holds_only_a_linked_client(): void
    {
        $db = $this->db();
        $service = new QboRecurringService($db, null);
        $this->assertNull($service->holdReason(['contact_id' => 8, 'property_id' => 4]));
        $db->exec("INSERT INTO qbo_links VALUES (1,1,'client','contact:8','C1')");
        $linked = new QboRecurringService($db, null);
        $this->assertStringContainsString('could not be read', $linked->holdReason(['contact_id' => 8, 'property_id' => 4]));
    }

    public function test_pause_prompt_limits_names(): void
    {
        $db = $this->db();
        $rows = [];
        for ($i = 1; $i <= 7; $i++) { $row = $this->normal('R' . $i); $row['name'] = 'Plan ' . $i; $rows[] = $row; }
        $prompt = $this->withSnapshot($db, $rows)->pausePrompt();
        $this->assertStringContainsString('Plan 5', $prompt);
        $this->assertStringNotContainsString('Plan 6', $prompt);
        $this->assertStringContainsString('and 2 more', $prompt);
        $this->assertNull((new QboRecurringService($this->db(), null))->pausePrompt());
    }
}
