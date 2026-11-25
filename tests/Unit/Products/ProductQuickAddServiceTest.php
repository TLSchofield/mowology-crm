<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ProductQuickAddServiceTest extends TestCase
{
    private function db(bool $withActive = true): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $active = $withActive ? ', active INTEGER DEFAULT 1' : '';
        $db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT, base_price NUMERIC, category_id INTEGER, unit_type_id INTEGER, is_archived INTEGER DEFAULT 0, display_order INTEGER DEFAULT 0' . $active . ')');
        $db->exec('CREATE TABLE product_categories (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE unit_types (id INTEGER PRIMARY KEY, name TEXT, abbreviation TEXT)');
        return $db;
    }

    public function test_creates_product_and_returns_catalog_row(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO product_categories VALUES (1, 'Grounds')");
        $db->exec("INSERT INTO unit_types VALUES (2, 'Hours', 'hr')");
        $result = (new ProductQuickAddService($db))->create(['name' => 'Garden care', 'price' => '24.567', 'category_id' => 1, 'unit_type_id' => 2, 'description' => 'Care visit']);
        $this->assertTrue($result['ok']);
        $this->assertSame('Grounds', $result['item']['category_name']);
        $this->assertSame('hr', $result['item']['unit_abbreviation']);
        $row = $db->query('SELECT * FROM products')->fetch();
        $this->assertEquals(24.57, $row['base_price']);
        $this->assertSame(0, (int)$row['is_archived']);
        $this->assertSame(1, (int)$row['active']);
    }

    public function test_sets_taxable_and_created_by_only_where_the_columns_exist(): void
    {
        $db = $this->db();
        $db->exec('ALTER TABLE products ADD COLUMN taxable INTEGER DEFAULT 0');
        $db->exec('ALTER TABLE products ADD COLUMN created_by INTEGER');
        $result = (new ProductQuickAddService($db))->create(['name' => 'Hedge trim', 'price' => 40], 7);
        $this->assertTrue($result['ok']);
        $row = $db->query('SELECT taxable, created_by FROM products')->fetch();
        $this->assertSame(1, (int)$row['taxable']);
        $this->assertSame(7, (int)$row['created_by']);

        // Without those columns (the default test table) the insert still works.
        $plain = $this->db();
        $this->assertTrue((new ProductQuickAddService($plain))->create(['name' => 'Hedge trim', 'price' => 40], 7)['ok']);
    }

    public function test_duplicate_check_collapses_spaces_in_stored_names_too(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO products (name, base_price, is_archived) VALUES ('Garden   Care', 10, 0)");
        $result = (new ProductQuickAddService($db))->create(['name' => 'garden care', 'price' => 12]);
        $this->assertTrue($result['duplicate']);
        $this->assertSame(1, (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn());
    }

    public function test_duplicate_name_is_case_and_space_insensitive(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO products (name, base_price, is_archived) VALUES ('Garden Care', 10, 0)");
        $result = (new ProductQuickAddService($db))->create(['name' => '  garden   care ', 'price' => 12]);
        $this->assertFalse($result['ok']);
        $this->assertTrue($result['duplicate']);
        $this->assertSame('Garden Care', $result['item']['name']);
        $this->assertSame(1, (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn());
    }

    public function test_archived_product_does_not_block_creation(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO products (name, base_price, is_archived) VALUES ('Garden Care', 10, 1)");
        $result = (new ProductQuickAddService($db))->create(['name' => 'garden care', 'price' => 12]);
        $this->assertTrue($result['ok']);
        $this->assertSame(2, (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn());
    }

    public function test_invalid_name_and_prices_insert_nothing(): void
    {
        $db = $this->db();
        $service = new ProductQuickAddService($db);
        $this->assertSame('A service needs a name.', $service->create(['name' => " \t ", 'price' => 1])['message']);
        $this->assertSame('Enter a price of 0 or more.', $service->create(['name' => 'A', 'price' => -1])['message']);
        $this->assertSame('Enter a price of 0 or more.', $service->create(['name' => 'B', 'price' => 'nope'])['message']);
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn());
    }

    public function test_unknown_category_and_unit_are_rejected(): void
    {
        $db = $this->db();
        $service = new ProductQuickAddService($db);
        $this->assertSame('That category no longer exists.', $service->create(['name' => 'A', 'price' => 1, 'category_id' => 99])['message']);
        $this->assertSame('That unit no longer exists.', $service->create(['name' => 'B', 'price' => 1, 'unit_type_id' => 99])['message']);
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn());
    }

    public function test_works_without_active_column(): void
    {
        $db = $this->db(false);
        $result = (new ProductQuickAddService($db))->create(['name' => 'Garden care', 'price' => 10]);
        $this->assertTrue($result['ok']);
        $this->assertSame('Garden care', $db->query('SELECT name FROM products')->fetchColumn());
    }

    public function test_form_options_are_sorted_and_missing_tables_return_empty(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO product_categories VALUES (1, 'Zeta'), (2, 'Alpha')");
        $db->exec("INSERT INTO unit_types VALUES (1, 'Visits', 'visit'), (2, 'Hours', 'hr')");
        $result = (new ProductQuickAddService($db))->formOptions();
        $this->assertSame(['Alpha', 'Zeta'], array_column($result['categories'], 'name'));
        $this->assertSame(['Hours', 'Visits'], array_column($result['units'], 'name'));

        $missing = new PDO('sqlite::memory:');
        $missing->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->assertSame(['categories' => [], 'units' => []], (new ProductQuickAddService($missing))->formOptions());
    }
}
