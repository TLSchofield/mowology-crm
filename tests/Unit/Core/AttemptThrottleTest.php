<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Core/Auth/AttemptThrottle.php';

/** #9 reset throttle and #10 PIN lockout (Fable audit 2026-10-12). */
final class AttemptThrottleTest extends TestCase
{
    public function testResetLimitsPerEmailAndIp(): void
    {
        $this->assertTrue(AttemptThrottle::resetAllowed(0, 0));
        $this->assertTrue(AttemptThrottle::resetAllowed(2, 9));
        $this->assertFalse(AttemptThrottle::resetAllowed(3, 0));
        $this->assertFalse(AttemptThrottle::resetAllowed(0, 10));
    }

    public function testKeysNeverCollideWithALoginEmail(): void
    {
        $this->assertSame('reset:tim@mowology.ca', AttemptThrottle::resetKey(' Tim@Mowology.ca '));
        $this->assertSame('pin:9', AttemptThrottle::pinKey(9));
        $this->assertFalse(filter_var(AttemptThrottle::resetKey('a@b.ca'), FILTER_VALIDATE_EMAIL));
    }

    public function testPinLocksAfterFive(): void
    {
        $this->assertFalse(AttemptThrottle::pinLocked(4));
        $this->assertTrue(AttemptThrottle::pinLocked(5));
        $this->assertSame(600, AttemptThrottle::PIN_WINDOW);
    }

    public function testResetPagesNoLongerUseTheLoginThrottle(): void
    {
        foreach (['public/forgot-password.php', 'public/loginAuth/forgot_password.php'] as $f) {
            $src = file_get_contents(__DIR__ . '/../../../' . $f);
            $this->assertStringNotContainsString('recordFailedLogin(', $src, $f);
            $this->assertStringNotContainsString('isLoginRateLimited(', $src, $f);
            $this->assertStringContainsString('AttemptThrottle::takeResetSlot(', $src, $f);
            $this->assertStringContainsString('ResetCaptcha::passes(', $src, $f);
        }
    }

    public function testResetSlotFailsClosedOnDbError(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willThrowException(new PDOException('down'));
        $this->assertFalse(AttemptThrottle::takeResetSlot($db, 'a@b.ca', '1.2.3.4'));
        $this->assertTrue(AttemptThrottle::pinIsLocked($db, 9));
    }
}
