<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Services/BusinessIdentity.php';

/** One source for who the business is (Ittai founding businesses, 2026-10-22). */
class BusinessIdentityTest extends TestCase
{
    public function test_mowology_reads_as_the_code_used_to_hard_code_it(): void
    {
        $b = BusinessIdentity::from(
            ['company_name' => 'Mowology Landscaping', 'company_phone' => '778 846 9273', 'company_email' => 'office@mowology.ca', 'company_website' => 'mowology.ca'],
            ['entered_name' => 'Mowology', 'legal_name' => 'MOWOLOGY LAWNS & LANDSCAPES LTD.', 'jurisdiction' => 'BC'],
            ['business_trade' => 'landscaping and property-maintenance', 'business_city' => 'Vancouver'],
            ['id' => 1, 'first_name' => 'TIM', 'preferred_name' => 'Timmo'], 3);
        $this->assertSame('Mowology', $b['name']);
        $this->assertSame('Mowology Landscaping', $b['display_name']);
        $this->assertSame('(778) 846-9273', $b['phone']);
        $this->assertSame('no-reply@mowology.ca', $b['mail_from'], 'the sender SmtpMailer hard-coded');
        $this->assertSame('office@mowology.ca', $b['reply_to']);
        $this->assertSame('Mowology, a 3-person landscaping and property-maintenance business in Vancouver, BC (owner: Timmo)', BusinessIdentity::describe($b));
    }

    public function test_a_new_business_never_inherits_mowologys_details(): void
    {
        $b = BusinessIdentity::from([], ['entered_name' => 'Greenleaf Gardens'], [], ['first_name' => 'CLAUDIA']);
        $this->assertSame('Greenleaf Gardens', $b['name']);
        $this->assertSame('', $b['phone']);
        $this->assertSame('no-reply@ittai.ca', $b['mail_from'], 'no website yet: Ittai sends on her behalf');
        $this->assertSame('Claudia', $b['owner_name']);
        $this->assertSame('Greenleaf Gardens, a business (owner: Claudia)', BusinessIdentity::describe($b));
        $this->assertStringNotContainsString('owology', json_encode($b) . BusinessIdentity::describe($b));
    }

    public function test_the_install_can_set_its_own_sender(): void
    {
        $b = BusinessIdentity::from(['company_website' => 'https://www.greenleaf.ca/about'], [], [], [], 0, 'hello@ittai.ca');
        $this->assertSame('hello@ittai.ca', $b['mail_from']);
        $this->assertSame('no-reply@greenleaf.ca', BusinessIdentity::from(['company_website' => 'https://www.greenleaf.ca/about'], [], [], [])['mail_from']);
    }
}
