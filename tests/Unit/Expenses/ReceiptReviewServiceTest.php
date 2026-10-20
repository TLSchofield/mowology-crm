<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The review layer's evidence and its duplicate keeper (ReceiptReviewService, 2026-10-20). */
class ReceiptReviewServiceTest extends TestCase
{
    public function testTheKeeperIsTheCopyInTheBooks(): void
    {
        // COE Lumber 2026-05-25 $257.55: #164 approved + linked to the bank line, #281 a second photo uploaded later.
        $this->assertSame(164, ReceiptReviewService::keeperOf([
            ['id' => 281, 'status' => 'draft', 'linked' => false, 'receipt_media_id' => 747],
            ['id' => 164, 'status' => 'approved', 'linked' => true, 'receipt_media_id' => 526],
        ]));
        $this->assertSame(9, ReceiptReviewService::keeperOf([
            ['id' => 3, 'status' => 'draft', 'linked' => false, 'receipt_media_id' => 1],
            ['id' => 9, 'status' => 'approved', 'linked' => false, 'receipt_media_id' => 2],
        ]), 'approved beats an older draft');
        $this->assertSame(7, ReceiptReviewService::keeperOf([
            ['id' => 7, 'status' => 'draft', 'linked' => true, 'receipt_media_id' => null],
            ['id' => 2, 'status' => 'draft', 'linked' => false, 'receipt_media_id' => 5],
        ]), 'linked beats a photo');
        $this->assertSame(2, ReceiptReviewService::keeperOf([
            ['id' => 5, 'status' => 'draft', 'linked' => false, 'receipt_media_id' => null],
            ['id' => 2, 'status' => 'draft', 'linked' => false, 'receipt_media_id' => null],
        ]), 'else the oldest');
        $sets = ReceiptReviewService::duplicateSets([['members' => [['id' => 281, 'status' => 'draft'], ['id' => 164, 'status' => 'approved', 'linked' => true]], 'why' => ['same items']]]);
        $this->assertSame(164, $sets[0]['keeper']['id']);
        $this->assertSame([281], array_column($sets[0]['copies'], 'id'));
    }

    public function testOnlyFlagsThatNeedALook(): void
    {
        $this->assertSame(['DUPLICATE_DAY', 'GST_WRONG'], ReceiptReviewService::realFlags('DUPLICATE_DAY,RAPID_SUBMISSION, weekend_expense,GST_WRONG'));
        $this->assertSame([], ReceiptReviewService::realFlags('WEEKEND_EXPENSE,RAPID_SUBMISSION'));
    }

    private static function row(array $o = []): array
    {
        return $o + ['total' => 33.60, 'gst_amount' => 1.60, 'pst_amount' => 0, 'accounting_category' => 'Repairs/Maintenance', 'payment_method' => 'debit',
                     'receipt_media_id' => 12, 'status' => 'draft', 'penny_total' => 33.60, 'penny_category' => 'Repairs/Maintenance',
                     'bank' => ['date' => '2026-03-03', 'amount' => -33.60], 'bank_candidates' => 0, 'in_duplicate' => false];
    }

    public function testChecks(): void
    {
        $ok = ReceiptReviewService::checks(self::row());
        $this->assertTrue($ok['all_pass']);
        $this->assertSame('Bank Mar 3 $33.60', $ok['checks'][1]['text']);

        $paper = ReceiptReviewService::checks(self::row(['penny_total' => 11.53, 'total' => 1.03, 'gst_amount' => 0.5, 'pst_amount' => 0.53, 'bank' => null, 'bank_candidates' => 1]));
        $this->assertFalse($paper['all_pass']);
        $this->assertSame('Paper says $11.53, saved $1.03', $paper['checks'][0]['text']);
        $this->assertSame('gst', $paper['checks'][3]['key'], 'taxes bigger than the total');

        $this->assertSame('3 bank lines have this amount — pick one', ReceiptReviewService::checks(self::row(['bank' => null, 'bank_candidates' => 3]))['checks'][1]['text']);
        $this->assertTrue(ReceiptReviewService::checks(self::row(['payment_method' => 'owner_paid', 'bank' => null]))['checks'][1]['ok'], 'paid personally');
        $cat = ReceiptReviewService::checks(self::row(['penny_category' => 'Meals']));
        $this->assertSame('Penny says Meals, saved Repairs/Maintenance', $cat['checks'][2]['text']);
        $this->assertFalse(ReceiptReviewService::checks(self::row(['receipt_media_id' => null]))['all_pass']);
        $this->assertFalse(ReceiptReviewService::checks(self::row(['in_duplicate' => true]))['all_pass']);
        $this->assertSame("Penny hasn't read the print yet", ReceiptReviewService::checks(self::row(['penny_total' => null]))['checks'][0]['text']);
    }
}
