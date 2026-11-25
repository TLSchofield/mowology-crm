<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class FirstWeekServiceTest extends TestCase
{
    private function db(bool $withTable = true): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($withTable) {
            $db->exec('CREATE TABLE ops_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, setting_key TEXT UNIQUE, setting_value TEXT, description TEXT, updated_by INTEGER NULL)');
        }
        FirstWeekService::reset();
        return $db;
    }

    public function test_off_when_no_row_so_existing_businesses_keep_todays_crm(): void
    {
        $this->assertFalse(FirstWeekService::isOn($this->db()));
    }

    public function test_off_when_the_table_is_missing(): void
    {
        $this->assertFalse(FirstWeekService::isOn($this->db(false)));
    }

    public function test_set_on_then_off_updates_one_row(): void
    {
        $db = $this->db();
        FirstWeekService::set($db, true, 7);
        FirstWeekService::reset();
        $this->assertTrue(FirstWeekService::isOn($db));
        FirstWeekService::set($db, false, 7);
        FirstWeekService::reset();
        $this->assertFalse(FirstWeekService::isOn($db));
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM ops_settings WHERE setting_key = 'first_week_mode'")->fetchColumn());
    }

    public function test_only_the_value_1_means_on(): void
    {
        $db = $this->db();
        foreach (['0', '', 'true', 'yes'] as $v) {
            $db->exec("DELETE FROM ops_settings");
            $db->prepare("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('first_week_mode', ?)")->execute([$v]);
            FirstWeekService::reset();
            $this->assertFalse(FirstWeekService::isOn($db), "value '$v'");
        }
    }

    public function test_each_business_connection_is_separate(): void
    {
        $a = $this->db();
        $b = $this->db();
        FirstWeekService::set($a, true);
        $this->assertTrue(FirstWeekService::isOn($a));
        $this->assertFalse(FirstWeekService::isOn($b));
    }
}
