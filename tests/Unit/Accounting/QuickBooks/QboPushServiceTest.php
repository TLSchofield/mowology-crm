<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The flag is the fence: with qbo_push_enabled = 0 nothing is sent, and QBService stays on email.
 */
class QboPushServiceTest extends TestCase
{
    private function pdoWithSettings(array $settings): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn($settings);
        $stmt->method('fetch')->willReturn(false);
        $pdo->method('prepare')->willReturn($stmt);
        return $pdo;
    }

    public function test_push_is_off_by_default_and_dry_run_is_on(): void
    {
        $svc = new QboPushService($this->pdoWithSettings([]), new QboConfig('a', 'b', 'sandbox', 'c'), new QboFakeTransport());
        $this->assertFalse($svc->isEnabled());
        $this->assertTrue($svc->isDryRun());
        $this->assertFalse($svc->isLive());
    }

    public function test_with_the_flag_off_push_expense_refuses_before_touching_the_network(): void
    {
        $t = new QboFakeTransport();
        $svc = new QboPushService($this->pdoWithSettings(['qbo_push_enabled' => '0']), new QboConfig('a', 'b', 'sandbox', 'c'), $t);
        $r = $svc->pushExpense(812);
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('switched off', $r['message']);
        $this->assertSame([], $t->sent);
    }

    public function test_the_flag_on_but_no_connection_still_sends_nothing(): void
    {
        $t = new QboFakeTransport();
        $svc = new QboPushService($this->pdoWithSettings(['qbo_push_enabled' => '1', 'qbo_dry_run' => '0']), new QboConfig('a', 'b', 'sandbox', 'c'), $t);
        $this->assertTrue($svc->isEnabled());
        $this->assertFalse($svc->isDryRun());
        $this->assertFalse($svc->isLive(), 'no active connection row → not live');
        $r = $svc->pushExpense(812);
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('not connected', $r['message']);
        $this->assertSame([], $t->sent);
    }

    public function test_the_migration_seeds_the_flag_off_and_creates_the_four_tables_idempotently(): void
    {
        foreach (['database/migrations/1240_quickbooks_api.sql', 'public/database/migrations/1240_quickbooks_api.sql'] as $rel) {
            $sql = file_get_contents(__DIR__ . '/../../../../' . $rel);
            $this->assertNotFalse($sql, $rel);
            foreach (['qbo_connections', 'qbo_account_map', 'qbo_sync', 'qbo_sync_log'] as $tbl) {
                $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS {$tbl}", $sql, $rel);
            }
            $this->assertMatchesRegularExpression("/'qbo_push_enabled', '0'/", $sql, 'flag seeded OFF');
            $this->assertDoesNotMatchRegularExpression('/\bJSON\s+(NULL|NOT NULL|,)/i', $sql, 'MySQL 5.7: no JSON columns');
            $this->assertDoesNotMatchRegularExpression('/\bGENERATED ALWAYS\b/i', $sql);
        }
        $this->assertSame(
            md5_file(__DIR__ . '/../../../../database/migrations/1240_quickbooks_api.sql'),
            md5_file(__DIR__ . '/../../../../public/database/migrations/1240_quickbooks_api.sql'),
            'both migration folders carry the same file'
        );
    }
}
