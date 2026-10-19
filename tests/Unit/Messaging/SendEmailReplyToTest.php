<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * sendEmail()'s optional Reply-To (enquiry alerts, 2026-10-19): Reply goes to the website visitor.
 * Existing callers pass nothing and keep the default office@mowology.ca.
 */
class SendEmailReplyToTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../app/Services/Messaging/MessagingService.php';
    }

    public function test_reply_to_is_a_trailing_optional_parameter_defaulting_to_none(): void
    {
        $params = (new ReflectionFunction('sendEmail'))->getParameters();
        $last = $params[count($params) - 1];
        $this->assertSame('replyTo', $last->getName());
        $this->assertTrue($last->isOptional());
        $this->assertNull($last->getDefaultValue());
        $this->assertNull((new ReflectionFunction('_sendViaPhpMailer'))->getParameters()[5]->getDefaultValue());
        $this->assertNull((new ReflectionFunction('_sendSimpleHtmlEmail'))->getParameters()[4]->getDefaultValue());
    }

    public function test_a_plain_valid_address_is_kept(): void
    {
        $this->assertSame('visitor@example.com', _normaliseReplyTo(' visitor@example.com '));
    }

    public function test_nothing_or_junk_means_keep_the_default(): void
    {
        foreach ([null, '', '   ', 'not-an-email', 'a@b', 'a@example.com, b@example.com', 'a@example.com;b@example.com',
                  'Name <a@example.com>', "a@example.com\r\nBcc: x@example.com", "a@example.com\nSubject: hi"] as $bad) {
            $this->assertNull(_normaliseReplyTo($bad), var_export($bad, true));
        }
    }
}
