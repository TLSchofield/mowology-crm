<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Support/TestIdentity.php';

/**
 * Person or noise, for office@ mail from senders not on file (InboundIdentityService::triage). Conservative:
 * unsure is a person, because a person becomes a question for the owner and noise is only counted.
 */
class InboundTriageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestIdentity::mowology();
    }

    protected function tearDown(): void
    {
        BusinessIdentity::reset();
        parent::tearDown();
    }

    public function test_the_paralegals_email_is_a_person(): void
    {
        $r = InboundIdentityService::triage('Candace Sevigny <csevigny@lotzandco.com>', 'Amount Owing',
            "Can you please give me a total amount owing for our account\n\n1355183 B.C. Ltd.\n1626 West 10th Ave\n\nThank you!");
        $this->assertSame('person', $r['kind']);
    }

    public function test_a_no_reply_sender_is_noise(): void
    {
        $r = InboundIdentityService::triage('Notifications <no-reply@vendor.example>', 'Your statement is ready', 'See the portal.');
        $this->assertSame('noise', $r['kind']);
        $this->assertNotSame('', $r['reason']);
    }

    public function test_an_out_of_office_reply_is_noise(): void
    {
        $r = InboundIdentityService::triage('Pat <pat@clientco.example>', 'Automatic reply: Invoice', 'I am out of the office until Monday.');
        $this->assertSame('noise', $r['kind']);
    }

    public function test_bulk_mail_headers_are_noise(): void
    {
        $r = InboundIdentityService::triage('Sam <sam@shop.example>', 'Spring sale', 'Everything is half price.', "List-Unsubscribe: <mailto:u@shop.example>\r\nSubject: Spring sale\r\n");
        $this->assertSame('noise', $r['kind']);
    }

    public function test_a_marketing_domain_is_noise(): void
    {
        $r = InboundIdentityService::triage('Robyn <robyn@leadgenpros.example>', 'Any upcoming projects?', 'We would love to talk.');
        $this->assertSame('noise', $r['kind']);
    }

    public function test_a_cold_pitch_is_noise(): void
    {
        $r = InboundIdentityService::triage('Robyn <robyn@gmail.com>', 'More leads for you',
            "We offer lead generation and SEO for your business.\nUnsubscribe at any time.");
        $this->assertSame('noise', $r['kind']);
    }

    public function test_a_short_unclear_question_stays_a_person(): void
    {
        $r = InboundIdentityService::triage('Someone <someone@gmail.com>', 'Price....?', 'Hi, what do you charge for this?');
        $this->assertSame('person', $r['kind']);
    }

    public function test_an_address_with_no_name_is_still_judged_on_its_words(): void
    {
        $r = InboundIdentityService::triage('', 'Hello', 'Please call me back.');
        $this->assertSame('person', $r['kind']);
    }
}
