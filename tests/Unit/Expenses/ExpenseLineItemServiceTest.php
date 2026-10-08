<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('APP_ROOT')) {
    require_once __DIR__ . '/../../../app/Core/paths.php';
}

/**
 * Tests for ExpenseLineItemService — the correction path for a single
 * expense_line_items row (name/quantity/unit_price/line_total), added
 * because OCR mis-parses (e.g. a discount line swallowing the real
 * product name) previously had no fix short of delete + re-add.
 */
class ExpenseLineItemServiceTest extends TestCase
{
    private function makeStmt(mixed $fetchReturn = false): PDOStatement
    {
        $s = $this->createMock(PDOStatement::class);
        $s->method('execute')->willReturn(true);
        $s->method('fetch')->willReturn($fetchReturn);
        return $s;
    }

    public function test_update_requires_line_item_id(): void
    {
        $db = $this->createMock(PDO::class);
        $svc = new ExpenseLineItemService($db);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Line item ID required');
        $svc->update(0, ['name' => 'Topsoil']);
    }

    public function test_update_throws_when_line_item_not_found(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt(false));
        $svc = new ExpenseLineItemService($db);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Line item not found');
        $svc->update(99, ['name' => 'Topsoil']);
    }

    public function test_update_requires_a_non_blank_name(): void
    {
        $existing = ['id' => 5, 'expense_id' => 1, 'product_id' => null, 'name' => 'Discount', 'quantity' => 1, 'unit_price' => null, 'line_total' => -14.99];
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($this->makeStmt($existing));
        $svc = new ExpenseLineItemService($db);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Item name required');
        $svc->update(5, ['name' => '   ']);
    }

    // The write itself is the expense gate's (migration 1233): the service works the values
    // out and hands the gate one 'line_item' change — asserted on the spy gate here; the
    // gate's own SQL (inventory re-sync, audit, lessons) is covered in ExpenseGateTest.

    public function test_update_saves_corrected_fields_and_returns_joined_row(): void
    {
        $existing = ['id' => 5, 'expense_id' => 1, 'product_id' => null, 'name' => 'Discount', 'quantity' => 1, 'unit_price' => null, 'line_total' => -14.99];
        $joined   = ['id' => 5, 'name' => 'Topsoil x4', 'quantity' => 4, 'unit_price' => 11.24, 'line_total' => 44.97, 'product_name' => null, 'product_sku' => null];

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($this->makeStmt($existing), $this->makeStmt($joined));

        $svc = (new ExpenseLineItemService($db, $gate = new ExpenseGateSpy()))->by(['id' => 6, 'kind' => 'user'], 'desktop_line');
        $result = $svc->update(5, ['name' => 'Topsoil x4', 'quantity' => 4, 'unit_price' => 11.24]);

        $this->assertSame('Topsoil x4', $result['name']);
        $this->assertSame(44.97, $result['line_total']);
        $this->assertCount(1, $gate->calls);
        $this->assertSame(1, $gate->calls[0]['id'], 'the change is made on the line\'s expense');
        $this->assertSame('desktop_line', $gate->calls[0]['source']);
        $this->assertSame('update', $gate->calls[0]['changes']['line_item']['op']);
    }

    public function test_update_computes_line_total_from_unit_price_when_total_omitted(): void
    {
        $existing = ['id' => 5, 'expense_id' => 1, 'product_id' => null, 'name' => 'Topsoil', 'quantity' => 1, 'unit_price' => null, 'line_total' => 0];
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($this->makeStmt($existing), $this->makeStmt(['id' => 5, 'name' => 'Topsoil x4']));

        $svc = new ExpenseLineItemService($db, $gate = new ExpenseGateSpy());
        $svc->update(5, ['name' => 'Topsoil x4', 'quantity' => 4, 'unit_price' => 11.24]);

        // 11.24 × 4 = 44.96 reaches the gate.
        $line = $gate->calls[0]['changes']['line_item'];
        $this->assertSame(['op' => 'update', 'id' => 5, 'name' => 'Topsoil x4', 'quantity' => 4.0, 'unit_price' => 11.24, 'line_total' => 44.96], $line);
    }

    public function test_update_defaults_quantity_to_existing_when_not_provided(): void
    {
        $existing = ['id' => 5, 'expense_id' => 1, 'product_id' => null, 'name' => 'Topsoil', 'quantity' => 4, 'unit_price' => 11.24, 'line_total' => 44.97];
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($this->makeStmt($existing), $this->makeStmt(['id' => 5, 'name' => 'Topsoil (renamed)']));

        $svc = new ExpenseLineItemService($db, $gate = new ExpenseGateSpy());
        // No quantity/unit_price/line_total supplied — should fall back to existing row's values.
        $result = $svc->update(5, ['name' => 'Topsoil (renamed)']);

        $this->assertSame('Topsoil (renamed)', $result['name']);
        $line = $gate->calls[0]['changes']['line_item'];
        $this->assertSame([4.0, 11.24, 44.97], [$line['quantity'], $line['unit_price'], $line['line_total']]);
    }

    public function test_update_adjusts_inventory_when_quantity_changes_on_linked_product(): void
    {
        // Real gate on SQLite: the quantity delta (4 − 2 = 2) reaches the linked product's stock.
        $db = ExpenseGateTestDb::make();
        $db->exec("INSERT INTO products (id, name, track_inventory, current_stock) VALUES (77, 'Topsoil', 1, 10)");
        $db->exec("INSERT INTO expenses (id, expense_date, total, status) VALUES (1, '2026-10-07', 22.48, 'draft')");
        $db->exec("INSERT INTO expense_line_items (id, expense_id, product_id, name, quantity, unit_price, line_total) VALUES (5, 1, 77, 'Topsoil', 2, 11.24, 22.48)");

        $svc = new ExpenseLineItemService($db, new ExpenseGate($db, new ExpenseGateHooksSpy()));
        $result = $svc->update(5, ['name' => 'Topsoil', 'quantity' => 4, 'unit_price' => 11.24]);

        $this->assertSame(4.0, (float)$result['quantity']);
        $this->assertSame(12.0, (float)$db->query("SELECT current_stock FROM products WHERE id = 77")->fetchColumn());
        $this->assertSame('lines', $db->query("SELECT action FROM expense_change_log WHERE expense_id = 1")->fetchColumn());
    }

    public function test_add_and_delete_go_through_the_gate(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls(
            $this->makeStmt(['id' => 78, 'name' => 'Grass seed']),                                       // fetchJoined after add
            $this->makeStmt(['id' => 78, 'expense_id' => 412, 'name' => 'Grass seed', 'quantity' => 1]) // fetchWithVendor before delete
        );
        $gate = new ExpenseGateSpy();
        $gate->extra = ['line_item_id' => 78];
        $svc = (new ExpenseLineItemService($db, $gate))->by(['id' => 6, 'kind' => 'user'], 'ios_line');
        $svc->add(412, ['name' => 'Grass seed', 'quantity' => 1, 'line_total' => 60]);
        $svc->delete(78);
        $this->assertSame(['add', 'delete'], [$gate->calls[0]['changes']['line_item']['op'], $gate->calls[1]['changes']['line_item']['op']]);
        $this->assertSame([412, 412], [$gate->calls[0]['id'], $gate->calls[1]['id']]);
        $this->assertSame('ios_line', $gate->calls[1]['source']);
    }
}
