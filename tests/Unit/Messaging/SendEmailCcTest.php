<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * sendEmail()'s optional Cc list (quote recipient / property-manager CC, 2026-10-06).
 * Existing callers pass nothing and keep exactly the original path.
 */
class SendEmailCcTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../app/Services/Messaging/MessagingService.php';
    }

    public function test_cc_is_a_trailing_optional_parameter(): void
    {
        $params = (new ReflectionFunction('sendEmail'))->getParameters();
        $this->assertSame(['to', 'subject', 'htmlBody', 'attachmentPath', 'fromName', 'attachments', 'cc'], array_map(fn($p) => $p->getName(), $params));
        $this->assertTrue($params[6]->isOptional());
        $this->assertSame([], $params[6]->getDefaultValue());
    }

    public function test_no_cc_means_the_original_path(): void
    {
        // sendEmail() only leaves the original single-attachment path when this is non-empty.
        $this->assertSame([], _normaliseCcList([], 'a@example.com'));
        $this->assertSame('', _ccHeaderLine([]));
        // The multi-attach helpers keep their old call shape (cc defaults to none).
        $this->assertSame([], (new ReflectionFunction('_sendEmailMultiAttach'))->getParameters()[5]->getDefaultValue());
        $this->assertSame([], (new ReflectionFunction('_sendEmailWithAttachments'))->getParameters()[5]->getDefaultValue());
    }

    public function test_invalid_duplicate_and_to_addresses_are_dropped(): void
    {
        $out = _normaliseCcList([
            'pm@example.com', 'PM@example.com', 'to@example.com', 'not-an-email',
            "evil@example.com\r\nBcc: x@example.com", 'a@example.com, b@example.com', '',
        ], 'To@Example.com');
        $this->assertSame(['pm@example.com'], $out);
    }

    public function test_fallback_header_line(): void
    {
        $this->assertSame("Cc: a@example.com, b@example.com\r\n", _ccHeaderLine(['a@example.com', 'b@example.com']));
    }

    public function test_sms_has_no_cc(): void
    {
        $names = array_map(fn($p) => $p->getName(), (new ReflectionFunction('sendSms'))->getParameters());
        $this->assertNotContains('cc', $names);
    }
}
