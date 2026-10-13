<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Settings/Services/ManagerPin.php';

/** #10 (Fable audit 2026-10-12): manager PIN hashed, lockout, failures logged. */
final class ManagerPinTest extends TestCase
{
    public function testStoredHashed(): void
    {
        $h = ManagerPin::forStorage('4821');
        $this->assertNotSame('4821', $h);
        $this->assertTrue(ManagerPin::isHashed($h));
        $this->assertSame('', ManagerPin::forStorage(''));
        $this->assertSame(['ok' => true, 'rehash' => null], ManagerPin::verify('4821', $h));
        $this->assertFalse(ManagerPin::verify('4822', $h)['ok']);
    }

    public function testBadPinRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ManagerPin::forStorage('12a4');
    }

    public function testLegacyPlaintextAcceptedOnceAndRehashed(): void
    {
        $r = ManagerPin::verify('1234', '1234');
        $this->assertTrue($r['ok']);
        $this->assertNotNull($r['rehash']);
        $this->assertTrue(password_verify('1234', $r['rehash']));
        $this->assertSame(['ok' => false, 'rehash' => null], ManagerPin::verify('9999', '1234'));
        $this->assertFalse(ManagerPin::isHashed('1234'));
    }

    public function testEndpointUsesLockoutHashAndLog(): void
    {
        $src = file_get_contents(__DIR__ . '/../../../public/crm/api/manager-pin-verify.php');
        $this->assertStringContainsString('AttemptThrottle::pinIsLocked(', $src);
        $this->assertStringContainsString('ManagerPin::verify(', $src);
        $this->assertStringContainsString("logActivity(\$userId, null, 'Manager PIN failed'", $src);
        $this->assertStringNotContainsString('hash_equals($storedPin', $src);
        $save = file_get_contents(__DIR__ . '/../../../app/Modules/Settings/Api/ops-settings.php');
        $this->assertStringContainsString('ManagerPin::forStorage(', $save);
    }
}
