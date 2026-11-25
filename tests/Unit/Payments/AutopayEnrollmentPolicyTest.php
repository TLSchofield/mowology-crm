<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Payments/AutopayEnrollmentPolicy.php';

/** Security review #13 (2026-10-12): an invoice link alone can't turn autopay on for the saved card. */
class AutopayEnrollmentPolicyTest extends TestCase
{
    public function test_only_a_newly_entered_card_switches_autopay_on(): void
    {
        $this->assertTrue(AutopayEnrollmentPolicy::mayEnableFromPayment(true, 'pm_new', null));
        $this->assertTrue(AutopayEnrollmentPolicy::mayEnableFromPayment(true, 'pm_new', 'pm_old'));
        $this->assertFalse(AutopayEnrollmentPolicy::mayEnableFromPayment(true, 'pm_old', 'pm_old'), 'paid with the card on file');
        $this->assertFalse(AutopayEnrollmentPolicy::mayEnableFromPayment(false, 'pm_new', null));
        $this->assertFalse(AutopayEnrollmentPolicy::mayEnableFromPayment(true, null, null));
    }

    public function test_enable_existing_card_no_longer_enables_autopay(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../../public/customer/api/autopay-setup.php');
        $this->assertDoesNotMatchRegularExpression('/autopay_enabled\s*=\s*1/', $src, 'autopay is switched on only by the webhook after the cardholder confirms');
        $this->assertStringContainsString("'requires_card' => true", $src);
        $this->assertStringContainsString('ip_address', $src);
        $hook = (string)file_get_contents(__DIR__ . '/../../../public/api/stripe/webhook.php');
        $this->assertStringContainsString('AutopayEnrollmentPolicy::mayEnableFromPayment', $hook);
    }

    public function test_actor_names_the_link_and_ip(): void
    {
        $this->assertSame('via invoice link #5 from IP 1.2.3.4', AutopayEnrollmentPolicy::actor('invoice link #5', '1.2.3.4'));
        $this->assertSame('via x from IP unknown', AutopayEnrollmentPolicy::actor('x', ''));
    }
}
