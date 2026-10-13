<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PortalProfileAuditTest extends TestCase
{
    public function testDiffListsOnlyChangedFields(): void
    {
        $old = ['email' => 'a@x.ca', 'phone' => '604', 'mobile' => null, 'receive_marketing' => 0, 'receive_sms' => '1'];
        $new = ['email' => 'b@x.ca', 'phone' => '604', 'mobile' => '', 'receive_marketing' => 1, 'receive_sms' => 1];
        $d = PortalProfileAudit::diff($old, $new);
        $this->assertSame(['email' => ['a@x.ca', 'b@x.ca'], 'receive_marketing' => ['0', '1']], $d);
    }

    public function testDescribe(): void
    {
        $this->assertSame('email: a → b; phone: (blank) → 1',
            PortalProfileAudit::describe(['email' => ['a', 'b'], 'phone' => ['', '1']]));
    }

    public function testNoChangeNoDiff(): void
    {
        $this->assertSame([], PortalProfileAudit::diff(['email' => 'a'], ['email' => 'a']));
    }
}
