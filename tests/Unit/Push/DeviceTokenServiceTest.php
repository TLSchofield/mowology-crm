<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Fable review 2026-10-12: the phone belongs to the last user who registered it; sign-out retires it. */
class DeviceTokenServiceTest extends TestCase
{
    public function test_clean_by_platform(): void
    {
        $this->assertSame(str_repeat('ab', 32), DeviceTokenService::clean(' <' . str_repeat('AB', 32) . '> ', 'ios'));
        $this->assertSame('', DeviceTokenService::clean('abc', 'ios'));
        $fcm = str_repeat('aZ9_-:', 20);
        $this->assertSame($fcm, DeviceTokenService::clean($fcm, 'android'));
        $this->assertSame('', DeviceTokenService::clean('short', 'android'));
    }

    public function test_register_switches_off_other_users_and_forget_switches_off_all(): void
    {
        $sql = [];
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(function (string $q) use (&$sql) {
            $sql[] = preg_replace('/\s+/', ' ', trim($q));
            $st = $this->createMock(PDOStatement::class);
            $st->method('execute')->willReturn(true);
            $st->method('rowCount')->willReturn(2);
            return $st;
        });
        DeviceTokenService::register($db, 7, 'tok', 'ios');
        $this->assertStringContainsString('WHERE device_token = ? AND user_id <> ?', $sql[1]);
        $this->assertSame(2, DeviceTokenService::forget($db, 'tok'));
        $this->assertSame(0, DeviceTokenService::forget($db, ''));
    }
}
