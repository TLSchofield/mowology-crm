<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Mail/MailAuth.php';

/**
 * Security review #4/#5/#20 (2026-10-12): a payment or price-list email counts only when the exact
 * sender address matches AND the mail is authenticated for its domain. Production adds no
 * Authentication-Results header, so MailAuth verifies DKIM itself. The signed data below is written
 * out BY HAND (RFC 6376 canonical form) and signed with openssl — not produced by MailAuth — so the
 * test checks the canonicalization independently.
 */
class MailAuthTest extends TestCase
{
    private static $key;
    private static string $pub;

    public static function setUpBeforeClass(): void
    {
        self::$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $pem = openssl_pkey_get_details(self::$key)['key'];
        self::$pub = preg_replace('/-----[^-]+-----|\s+/', '', $pem);
    }

    private function dns(): callable
    {
        $pub = self::$pub;
        return fn(string $name) => $name === 'sel1._domainkey.yardi.com' ? ['v=DKIM1; k=rsa; p=' . $pub] : [];
    }

    /** A relaxed/relaxed message, signed over hand-written canonical data. */
    private function signedRelaxed(string $from = 'Yardi Systems <DoNotReply@yardi.com>', string $extraTags = '', string $h = 'from:to:subject'): array
    {
        $body = "Invoice  INV-2026-0001 \t 100.00 \r\n\r\n\r\n";
        $bh = base64_encode(hash('sha256', "Invoice INV-2026-0001 100.00\r\n", true));
        $tags = "v=1; a=rsa-sha256; c=relaxed/relaxed; d=yardi.com; s=sel1;{$extraTags}\r\n\th={$h}; bh={$bh}; b=";
        $canonFrom = 'from:' . preg_replace('/\s+/', ' ', $from) . "\r\n";
        $canon = ['from' => $canonFrom, 'to' => "to:office@mowology.ca\r\n", 'subject' => "subject:EFT Payment\r\n"];
        $data = '';
        foreach (explode(':', $h) as $name) $data .= $canon[$name] ?? '';
        $data .= 'dkim-signature:' . preg_replace('/[ \t]+/', ' ', str_replace("\r\n", '', $tags));
        openssl_sign($data, $sig, self::$key, OPENSSL_ALGO_SHA256);
        $b = chunk_split(base64_encode($sig), 60, "\r\n\t");
        $headers = "DKIM-Signature: {$tags}" . rtrim($b, "\r\n\t") . "\r\n"
                 . "From: {$from}\r\nTo: office@mowology.ca\r\nSubject:  EFT   Payment \r\n\r\n";
        return [$headers, $body];
    }

    public function test_rfc6376_canonicalization_examples(): void
    {
        // RFC 6376 §3.4.6
        $this->assertSame("a:X\r\n", MailAuth::canonHeaderRelaxed('A: X'));
        $this->assertSame("b:Y Z\r\n", MailAuth::canonHeaderRelaxed("B : Y\t\r\n\tZ  "));
        $this->assertSame(" C\r\nD E\r\n", MailAuth::canonBodyRelaxed(" C \r\nD \t E\r\n\r\n\r\n"));
        $this->assertSame(" C \r\nD \t E\r\n", MailAuth::canonBodySimple(" C \r\nD \t E\r\n\r\n\r\n"));
        $this->assertSame("\r\n", MailAuth::canonBodySimple(''));
        $this->assertSame('', MailAuth::canonBodyRelaxed("\r\n\r\n"));
    }

    public function test_genuine_signed_remittance_is_verified(): void
    {
        [$h, $b] = $this->signedRelaxed();
        $r = MailAuth::verify($h, $b, 'DoNotReply@yardi.com', $this->dns(), []);
        $this->assertTrue($r['ok'], $r['reason']);
        $this->assertSame('dkim', $r['method']);
    }

    public function test_changed_body_fails(): void
    {
        [$h, $b] = $this->signedRelaxed();
        $r = MailAuth::verify($h, str_replace('100.00', '900.00', $b), 'DoNotReply@yardi.com', $this->dns(), []);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('body', $r['reason']);
    }

    public function test_unsigned_forgery_with_right_from_fails(): void
    {
        $h = "From: DoNotReply@yardi.com\r\nTo: office@mowology.ca\r\nSubject: EFT Payment\r\n\r\n";
        $r = MailAuth::verify($h, "Invoice INV-2026-0001 100.00\r\n", 'DoNotReply@yardi.com', $this->dns(), []);
        $this->assertFalse($r['ok']);
    }

    public function test_display_name_cannot_pose_as_the_sender(): void
    {
        $this->assertNull(MailAuth::exactAddress('"DoNotReply@yardi.com" <evil@example.com> <x@y.z>'));
        $this->assertSame('evil@example.com', MailAuth::exactAddress('"DoNotReply@yardi.com" <evil@example.com>'));
        $this->assertSame('donotreply@yardi.com', MailAuth::exactAddress('DoNotReply@yardi.com'));
        $h = "From: \"DoNotReply@yardi.com\" <evil@example.com>\r\n\r\n";
        $this->assertFalse(MailAuth::verify($h, '', 'DoNotReply@yardi.com', $this->dns(), [])['ok']);
    }

    public function test_second_from_header_fails(): void
    {
        [$h, $b] = $this->signedRelaxed();
        $h = "From: evil@example.com\r\n" . $h;
        $this->assertFalse(MailAuth::verify($h, $b, 'DoNotReply@yardi.com', $this->dns(), [])['ok']);
    }

    public function test_partial_body_signature_is_refused(): void
    {
        [$h, $b] = $this->signedRelaxed('Yardi Systems <DoNotReply@yardi.com>', ' l=10;');
        $r = MailAuth::verify($h, $b, 'DoNotReply@yardi.com', $this->dns(), []);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('l=', $r['reason']);
    }

    public function test_from_must_be_signed(): void
    {
        [$h, $b] = $this->signedRelaxed('Yardi Systems <DoNotReply@yardi.com>', '', 'to:subject');
        $this->assertFalse(MailAuth::verify($h, $b, 'DoNotReply@yardi.com', $this->dns(), [])['ok']);
    }

    public function test_missing_dns_key_fails_closed(): void
    {
        [$h, $b] = $this->signedRelaxed();
        $r = MailAuth::verify($h, $b, 'DoNotReply@yardi.com', fn($n) => [], []);
        $this->assertFalse($r['ok']);
    }

    public function test_signature_for_another_domain_does_not_count(): void
    {
        $this->assertTrue(MailAuth::aligned('interac.ca', 'payments.interac.ca'));
        $this->assertTrue(MailAuth::aligned('payments.interac.ca', 'payments.interac.ca'));
        $this->assertFalse(MailAuth::aligned('amazonses.com', 'payments.interac.ca'));
        $this->assertFalse(MailAuth::aligned('ca', 'payments.interac.ca'));
        $this->assertFalse(MailAuth::aligned('evilinterac.ca', 'payments.interac.ca'));
    }

    public function test_authentication_results_only_from_a_trusted_server(): void
    {
        $h = "Authentication-Results: mx.evil.com; dmarc=pass header.from=yardi.com\r\nFrom: DoNotReply@yardi.com\r\n\r\n";
        $this->assertFalse(MailAuth::verify($h, '', 'DoNotReply@yardi.com', fn($n) => [], [])['ok'], 'sender-written header ignored');
        $this->assertFalse(MailAuth::verify($h, '', 'DoNotReply@yardi.com', fn($n) => [], ['mail.mowology.ca'])['ok']);
        $h2 = "Authentication-Results: mail.mowology.ca; spf=pass; dmarc=pass (p=reject) header.from=yardi.com\r\nFrom: DoNotReply@yardi.com\r\n\r\n";
        $r = MailAuth::verify($h2, '', 'DoNotReply@yardi.com', fn($n) => [], ['mail.mowology.ca']);
        $this->assertTrue($r['ok']);
        $this->assertSame('auth-results', $r['method']);
        $h3 = "Authentication-Results: mail.mowology.ca; dmarc=fail header.from=yardi.com\r\nFrom: DoNotReply@yardi.com\r\n\r\n";
        $this->assertFalse(MailAuth::verify($h3, '', 'DoNotReply@yardi.com', fn($n) => [], ['mail.mowology.ca'])['ok']);
    }

    public function test_stripB_keeps_bh(): void
    {
        $this->assertSame('DKIM-Signature: v=1; bh=abc; b=', MailAuth::stripB('DKIM-Signature: v=1; bh=abc; b=xyz'));
        $this->assertSame("DKIM-Signature: b=; bh=abc", MailAuth::stripB("DKIM-Signature: b=xy\r\n\tz; bh=abc"));
    }
}
