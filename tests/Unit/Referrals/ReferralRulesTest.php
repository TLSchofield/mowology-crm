<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;


/** Fable review 2026-10-12: a referral counts for a different, new person, once. */
class ReferralRulesTest extends TestCase
{
    private array $referrer = ['email' => 'Ann@Example.com', 'phone' => '(778) 846-0000', 'mobile' => ''];

    public function test_self_referral_by_email_or_phone_is_refused(): void
    {
        $this->assertSame('self_referral', ReferralRules::inboundRefusal($this->referrer, ['email' => ' ann@example.com', 'phone' => ''], false, false));
        $this->assertSame('self_referral', ReferralRules::inboundRefusal($this->referrer, ['email' => 'other@x.ca', 'phone' => '+1 778 846 0000'], false, false));
    }

    public function test_existing_clients_and_repeats_are_refused(): void
    {
        $new = ['email' => 'bob@x.ca', 'phone' => ''];
        $this->assertSame('already_a_client', ReferralRules::inboundRefusal($this->referrer, $new, true, false));
        $this->assertSame('already_referred', ReferralRules::inboundRefusal($this->referrer, $new, false, true));
        $this->assertNull(ReferralRules::inboundRefusal($this->referrer, $new, false, false));
        $this->assertSame('no_contact_details', ReferralRules::inboundRefusal($this->referrer, ['email' => '', 'phone' => ''], false, false));
    }

    public function test_award_only_for_someone_else_on_their_first_visit(): void
    {
        $this->assertSame('self_referral', ReferralRules::awardRefusal(7, 7, 0));
        $this->assertSame('not_a_new_client', ReferralRules::awardRefusal(7, 8, 3));
        $this->assertNull(ReferralRules::awardRefusal(7, 8, 0));
    }
}
