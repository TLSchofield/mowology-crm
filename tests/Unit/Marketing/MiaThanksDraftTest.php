<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

class MiaThanksDraftTest extends TestCase
{
    public function test_only_known_real_payment_methods_are_eligible(): void
    {
        foreach (['cash','cheque','e_transfer','credit_card','stripe','debit','E_Transfer '] as $method) {
            $this->assertTrue(MiaThanksService::isPaymentMethod($method),$method);
        }
        // 'other' is how adjustments are recorded; 'yardi_eft' is a property manager's batch payment (2026-11-05).
        foreach (['other','yardi_eft','credit','write_off','write-off','adjustment','refund','reversal','future_method',''] as $method) {
            $this->assertFalse(MiaThanksService::isPaymentMethod($method),$method);
        }
    }

    public function test_exact_core_and_optional_paragraphs(): void
    {
        $today=new DateTimeImmutable('2026-11-05');
        $d=MiaWording::thanksDraft('Jane',1190,true,['date'=>'2025-12-05'],'pay@example.test',$today);
        $this->assertSame('Thank you, Jane',$d['subject']);
        $this->assertStringStartsWith("Hi Jane,\n\nThank you for your payment of $1,190.00. It's received, and your account is up to date.\n\nLast year we did a fall cleanup at your home at the start of December. Most of the leaves are down now, so it's a good time to do it again. Would you like me to put it on the schedule? Just reply to this email.\n\nOne small favour for next time.",$d['body']);
        $this->assertStringNotContainsString('!',$d['body']);
    }
    public function test_no_name_uncleared_plain(): void
    {
        $d=MiaWording::thanksDraft('',232.84,false,null,null,new DateTimeImmutable('2026-10-20'));
        $this->assertSame('Thank you for your payment',$d['subject']);
        $this->assertStringStartsWith("Hi there,\n\nThank you for your payment of $232.84. It's received.\n\n",$d['body']);
        $this->assertStringNotContainsString('fall cleanup',$d['body']);
    }
}
