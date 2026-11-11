<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Marketing/Services/UnsubscribeLink.php';

/** Fable review 2026-10-12: one unsubscribe sign/verify; both handlers accept a generated link. */
class UnsubscribeLinkTest extends TestCase
{
    private const S = 'test-secret-0123456789abcdef0123456789abcdef';

    public function test_generated_link_verifies_with_any_case_and_sid(): void
    {
        $url = UnsubscribeLink::url(' Jane.Doe@Example.com ', 42, self::S);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $this->assertStringStartsWith('https://mowology.ca/unsubscribe.php?', $url);
        $this->assertSame('42', $q['sid']);
        // unsubscribe.php lower-cases the email before verifying; optin.php passes it as given.
        $this->assertTrue(UnsubscribeLink::verify(strtolower(trim($q['email'])), $q['token'], self::S));
        $this->assertTrue(UnsubscribeLink::verify($q['email'], $q['token'], self::S));
    }

    public function test_tampering_and_missing_secret_refused(): void
    {
        $t = UnsubscribeLink::token('a@b.co', self::S);
        $this->assertFalse(UnsubscribeLink::verify('other@b.co', $t, self::S));
        $this->assertFalse(UnsubscribeLink::verify('a@b.co', $t, 'another-secret-0123456789abcdef0123456789'));
        $this->assertFalse(UnsubscribeLink::verify('a@b.co', ['x'], self::S));
        $this->assertFalse(UnsubscribeLink::verify('a@b.co', $t, ''));
        // the old optin.php format (email|sid) is not a valid token
        $this->assertFalse(UnsubscribeLink::verify('a@b.co', hash_hmac('sha256', 'a@b.co|0', self::S), self::S));
    }

    public function test_every_generator_and_handler_uses_the_class(): void
    {
        $root = dirname(__DIR__, 3);
        $this->assertStringContainsString('UnsubscribeLink::url(', (string)file_get_contents($root . '/app/Services/Messaging/TemplateRenderer.php'));
        foreach (['public/unsubscribe.php', 'app/Modules/Marketing/Api/optin.php'] as $f) {
            $src = (string)file_get_contents($root . '/' . $f);
            $this->assertStringContainsString('UnsubscribeLink::verify(', $src, $f);
            $this->assertStringNotContainsString("hash_hmac('sha256', \$email", $src, $f);
        }
    }
}
