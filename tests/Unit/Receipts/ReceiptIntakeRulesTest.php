<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Services/Receipts/ReceiptParser.php';
require_once dirname(__DIR__, 3) . '/app/Services/Receipts/ReceiptIntakeRules.php';
require_once dirname(__DIR__, 3) . '/app/Modules/Expenses/Services/ReceiptIntakeService.php';

final class ReceiptIntakeRulesTest extends TestCase
{


    public function testMissingServerTotalFillsFromPaymentConfirmedDeviceTotal(): void
    {
        $fill = ReceiptIntakeRules::deviceTotalFill(
            ['total' => null],
            ['total' => '14.49', 'gst' => '9.99'],
            'SERVER READ',
            "SHOP\nDebit Purchase 14.49"
        );

        $this->assertSame(['total' => '14.49', 'reason' => 'confirmed by payment line'], $fill);
        $this->assertArrayNotHasKey('gst', $fill);
    }

    public function testMissingServerTotalRejectsUnconfirmedDeviceTotal(): void
    {
        $this->assertNull(ReceiptIntakeRules::deviceTotalFill(
            ['total' => null],
            ['total' => '14.49'],
            'SERVER READ',
            "SHOP\nTOTAL 14.49"
        ));
    }

    public function testConsistentServerTotalIsNeverReplaced(): void
    {
        $this->assertNull(ReceiptIntakeRules::deviceTotalFill(
            ['subtotal' => '10.00', 'gst' => '0.50', 'pst' => '0.70', 'total' => '11.20'],
            ['total' => '14.49'],
            'SERVER READ',
            "SHOP\nDebit Purchase 14.49"
        ));
    }

    public function testImpossibleServerTotalFillsFromDeviceArithmetic(): void
    {
        $fill = ReceiptIntakeRules::deviceTotalFill(
            ['subtotal' => '3.00', 'gst' => '5.00', 'pst' => '0.00', 'total' => '4.00'],
            ['subtotal' => '10.00', 'gst' => '0.50', 'pst' => '0.50', 'total' => '11.00'],
            'SERVER READ',
            'DEVICE READ'
        );

        $this->assertSame('11.00', $fill['total']);
        $this->assertSame('confirmed by device arithmetic', $fill['reason']);
        $this->assertArrayNotHasKey('gst', $fill);
    }

    public function testOneStrongJobIsDecisive(): void
    {
        $this->assertSame(0, ReceiptIntakeRules::decisiveJob(
            [['score' => 110]],
            ReceiptIntakeRules::DECISIVE_JOB_MIN_SCORE,
            ReceiptIntakeRules::DECISIVE_JOB_MIN_MARGIN
        ));
    }

    public function testTwoCloseStrongJobsAreNotDecisive(): void
    {
        $this->assertNull(ReceiptIntakeRules::decisiveJob(
            [['score' => 110], ['score' => 95]],
            ReceiptIntakeRules::DECISIVE_JOB_MIN_SCORE,
            ReceiptIntakeRules::DECISIVE_JOB_MIN_MARGIN
        ));
    }

    public function testOneWeakJobIsNotDecisive(): void
    {
        $this->assertNull(ReceiptIntakeRules::decisiveJob(
            [['score' => 70]],
            ReceiptIntakeRules::DECISIVE_JOB_MIN_SCORE,
            ReceiptIntakeRules::DECISIVE_JOB_MIN_MARGIN
        ));
    }

    public function testNoJobsIsNotDecisive(): void
    {
        $this->assertNull(ReceiptIntakeRules::decisiveJob(
            [],
            ReceiptIntakeRules::DECISIVE_JOB_MIN_SCORE,
            ReceiptIntakeRules::DECISIVE_JOB_MIN_MARGIN
        ));
    }

    public function testCardFormatterUsesHolderAndPaymentMethod(): void
    {
        $this->assertSame([
            'last4' => '5760',
            'user_id' => 3,
            'holder' => 'Tim Schofield',
            'label' => 'Company Visa',
            'payment_method' => 'visa',
        ], ReceiptIntakeRules::formatCard('5760', [
            'user_id' => '3',
            'full_name' => 'Tim Schofield',
            'label' => 'Company Visa',
        ], 'visa'));
    }

    public function testUnknownCardStillReturnsLastFourWithDefaultMethod(): void
    {
        $this->assertSame([
            'last4' => '5760',
            'user_id' => null,
            'holder' => null,
            'label' => null,
            'payment_method' => 'credit_card',
        ], ReceiptIntakeRules::formatCard('5760', null, null));
    }
}
