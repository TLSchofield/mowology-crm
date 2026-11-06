<?php
declare(strict_types=1);

use App\Core\CronLock;
use PHPUnit\Framework\TestCase;

class CronLockTest extends TestCase
{
    protected function tearDown(): void
    {
        Tenant::forTests(null);
    }

    public function test_lock_path_keeps_default_and_separates_businesses(): void
    {
        Tenant::forTests(['key' => 'default', 'cfg' => []]);
        $this->assertSame(sys_get_temp_dir() . '/mowology_cron_generate_visits.lock', CronLock::lockPath('generate_visits'));
        Tenant::forTests(['key' => 'green-leaf', 'cfg' => []]);
        $this->assertStringContainsString('generate_visits_green-leaf.lock', CronLock::lockPath('generate_visits'));
    }
}
