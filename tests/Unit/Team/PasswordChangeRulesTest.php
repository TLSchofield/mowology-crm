<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PasswordChangeRulesTest extends TestCase
{
    private const GOOD = 'correct-horse-battery';

    public function testManagerCanSetStaffPassword(): void
    {
        $this->assertNull(PasswordChangeRules::check(self::GOOD, self::GOOD, false, true, false));
    }

    public function testManagerCannotSetAdminPassword(): void
    {
        $this->assertNotNull(PasswordChangeRules::check(self::GOOD, self::GOOD, true, true, false));
    }

    public function testAdminCanSetAdminPassword(): void
    {
        $this->assertNull(PasswordChangeRules::check(self::GOOD, self::GOOD, true, true, true));
    }

    public function testNeedsUsersManage(): void
    {
        $this->assertNotNull(PasswordChangeRules::check(self::GOOD, self::GOOD, false, false, true));
    }

    public function testMinimumTwelve(): void
    {
        $this->assertNotNull(PasswordChangeRules::check('elevenchars', 'elevenchars', false, true, true));
        $this->assertNull(PasswordChangeRules::check('twelve-chars', 'twelve-chars', false, true, true));
    }

    public function testMustMatch(): void
    {
        $this->assertNotNull(PasswordChangeRules::check(self::GOOD, self::GOOD . 'x', false, true, true));
    }
}
