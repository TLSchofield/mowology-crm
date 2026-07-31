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

    public function test_update_saves_corrected_fields_and_returns_joined_row(): void
    {
        $existing = ['id' => 5, 'expense_id' => 1, 'product_id' => null, 'name' => 'Discount', 'quantity' => 1, 'unit_price' => null, 'line_total' => -14.99];
        $joined   = ['id' => 5, 'name' => 'Topsoil x4', 'quantity' => 4, 'unit_price' => 11.24, 'line_total' => 44.97, 'product_name' => null, 'product_sku' => null];

        $selectStmt = $this->makeStmt($existing);
        $updateStmt = $this->makeStmt();
        $finalStmt  = $this->makeStmt($joined);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($selectStmt, $updateStmt, $finalStmt);

        $svc = new ExpenseLineItemService($db);
        $result = $svc->update(5, ['name' => 'Topsoil x4', 'quantity' => 4, 'unit_price' => 11.24]);

        $this->assertSame('Topsoil x4', $result['name']);
        $this->assertSame(44.97, $result['line_total']);
    }

    public function test_update_computes_line_total_from_unit_price_when_total_omitted(): void
    {
        $existing = ['id' => 5, 'expense_id' => 1, 'product_id' => null, 'name' => 'Topsoil', 'quantity' => 1, 'unit_price' => null, 'line_total' => 0];

        $selectStmt = $this->makeStmt($existing);
        $updateStmt = $this->createMock(PDOStatement::class);
        // Assert the computed line_total (11.24 * 4 = 44.96) reaches the UPDATE bind params.
        $updateStmt->expects($this->once())->method('execute')->with([
            'Topsoil x4', 4.0, 11.24, 44.96, 5,
        ])->willReturn(true);
        $finalStmt = $this->makeStmt(['id' => 5, 'name' => 'Topsoil x4']);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($selectStmt, $updateStmt, $finalStmt);

        $svc = new ExpenseLineItemService($db);
        $svc->update(5, ['name' => 'Topsoil x4', 'quantity' => 4, 'unit_price' => 11.24]);
    }

    public function test_update_defaults_quantity_to_existing_when_not_provided(): void
    {
        $existing = ['id' => 5, 'expense_id' => 1, 'product_id' => null, 'name' => 'Topsoil', 'quantity' => 4, 'unit_price' => 11.24, 'line_total' => 44.97];

        $selectStmt = $this->makeStmt($existing);
        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->expects($this->once())->method('execute')->with([
            'Topsoil (renamed)', 4.0, 11.24, 44.97, 5,
        ])->willReturn(true);
        $finalStmt = $this->makeStmt(['id' => 5, 'name' => 'Topsoil (renamed)']);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($selectStmt, $updateStmt, $finalStmt);

        $svc = new ExpenseLineItemService($db);
        // No quantity/unit_price/line_total supplied — should fall back to existing row's values.
        $result = $svc->update(5, ['name' => 'Topsoil (renamed)']);

        $this->assertSame('Topsoil (renamed)', $result['name']);
    }

    public function test_update_adjusts_inventory_when_quantity_changes_on_linked_product(): void
    {
        $existing = ['id' => 5, 'expense_id' => 1, 'product_id' => 77, 'name' => 'Topsoil', 'quantity' => 2, 'unit_price' => 11.24, 'line_total' => 22.48];

        $selectStmt = $this->makeStmt($existing);
        $updateStmt = $this->makeStmt();
        $inventoryStmt = $this->createMock(PDOStatement::class);
        // qty delta = 4 - 2 = 2
        $inventoryStmt->expects($this->once())->method('execute')->with([2.0, 77]);
        $finalStmt = $this->makeStmt(['id' => 5, 'name' => 'Topsoil', 'quantity' => 4]);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls($selectStmt, $updateStmt, $inventoryStmt, $finalStmt);

        $svc = new ExpenseLineItemService($db);
        $result = $svc->update(5, ['name' => 'Topsoil', 'quantity' => 4, 'unit_price' => 11.24]);

        $this->assertSame(4, $result['quantity']);
    }
}
