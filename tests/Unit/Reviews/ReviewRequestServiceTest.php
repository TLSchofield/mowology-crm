<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Google review requests: every completed visit asks the same way (no selective
 * solicitation), with the per-contact caps, and carrier-safe text.
 */
class ReviewRequestServiceTest extends TestCase
{
    private function contact(array $over = []): array
    {
        return $over + ['id' => 1, 'email' => 'a@example.com', 'is_active' => 1, 'has_reviewed' => 0,
            'review_request_opted_out' => 0, 'review_request_sent_count' => 0, 'review_request_sent_at' => null];
    }

    public function test_the_crew_heart_is_no_longer_a_gate(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/app/Modules/Reviews/Services/ReviewRequestService.php');
        $this->assertStringNotContainsString('is_flagged FROM job_visits', $src, 'the request must not read the crew heart');
        $this->assertFalse(method_exists('ReviewRequestService', 'isVisitFlagged'));
        // The timer / iOS completion path asks too, not only the web crew's end_visit.
        $life = file_get_contents(dirname(__DIR__, 3) . '/app/Modules/Jobs/Services/VisitLifecycleService.php');
        $this->assertStringContainsString('ReviewRequestService::maybeSend($visitId, $db)', $life);
    }

    public function test_caps_still_hold(): void
    {
        $now = new DateTimeImmutable('2026-10-05');
        $this->assertTrue(ReviewRequestService::isEligible($this->contact(), $now));
        $this->assertFalse(ReviewRequestService::isEligible($this->contact(['has_reviewed' => 1]), $now));
        $this->assertFalse(ReviewRequestService::isEligible($this->contact(['review_request_opted_out' => 1]), $now));
        $this->assertFalse(ReviewRequestService::isEligible($this->contact(['review_request_sent_count' => 3]), $now));
        $this->assertFalse(ReviewRequestService::isEligible($this->contact(['review_request_sent_at' => '2026-09-20']), $now), '30-day cooldown');
        $this->assertTrue(ReviewRequestService::isEligible($this->contact(['review_request_sent_at' => '2026-08-01']), $now));
        $this->assertFalse(ReviewRequestService::isEligible($this->contact(['email' => '']), $now));
        $this->assertFalse(ReviewRequestService::isEligible($this->contact(['is_active' => 0]), $now));
    }

    public function test_the_text_is_carrier_safe_and_offers_nothing_in_return(): void
    {
        foreach (['Jane', '', 'Zoë', str_repeat('Bartholomew', 8)] as $name) {
            $t = ReviewRequestService::smsText($name);
            $this->assertLessThanOrEqual(160, strlen($t));
            $this->assertDoesNotMatchRegularExpression('/[^\x20-\x7E]/', $t);
            $this->assertStringNotContainsString('!', $t);
            $this->assertStringNotContainsString('http', $t);
            $this->assertStringContainsString('(778) 846-9273', $t);
            $this->assertStringContainsString('email', $t);
        }
    }

    public function test_the_email_asks_everyone_the_same_way_with_no_incentive(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/app/Modules/Reviews/Services/ReviewRequestService.php');
        $this->assertStringNotContainsString('If the work was what you expected', $src);
        $this->assertStringNotContainsString('tell us first', $src);
        $this->assertDoesNotMatchRegularExpression('/discount|free |gift|reward|draw|% off|coupon/i', $src);
    }
}
