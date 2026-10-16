<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Ada reads the PHP error log for crashes (AdaServerErrors) — lines as they appear on the server. */
class AdaServerErrorsTest extends TestCase
{
    private const LOG = <<<'LOG'
[16-Oct-2026 11:54:01 America/Vancouver] PHP Fatal error:  Uncaught Error: Non-static method Stripe\PaymentMethod::attach() cannot be called statically in /home/mowology/public_html/api/stripe/webhook.php:450
Stack trace:
#0 /home/mowology/public_html/api/stripe/webhook.php(68): handlePaymentSucceeded(Object(Stripe\PaymentIntent))
  thrown in /home/mowology/public_html/api/stripe/webhook.php on line 450
[16-Oct-2026 11:13:23 America/Vancouver] ReviewRequestService: unexpected error for visit #2247: SQLSTATE[42S22]: Column not found
[16-Oct-2026 16:22:54 UTC] PHP Warning:  Undefined variable $ua in /home/mowology/public_html/app/Core/session_config.php on line 72
[16-Oct-2026 16:22:54 UTC] PHP Fatal error:  Uncaught TypeError: stripos(): Argument #1 ($haystack) must be of type string, null given in /home/mowology/public_html/app/Core/session_config.php:72
[16-Oct-2026 16:23:14 UTC] PHP Fatal error:  Uncaught TypeError: stripos(): Argument #1 ($haystack) must be of type string, null given in /home/mowology/public_html/app/Core/session_config.php:72
[16-Oct-2026 16:23:20 UTC] PHP Fatal error:  Uncaught TypeError: stripos(): Argument #1 ($haystack) must be of type string, null given in /home/mowology/public_html/app/Core/session_config.php:72
[16-Oct-2026 14:18:09 America/Vancouver] PHP Fatal error:  Uncaught Error: Non-static method Stripe\PaymentMethod::attach() cannot be called statically in /home/mowology/public_html/api/stripe/webhook.php:450
[14-Oct-2026 09:00:00 America/Vancouver] PHP Fatal error:  Uncaught Error: old crash in /home/mowology/public_html/crm/old.php:9
[16-Oct-2026 15:00:00 America/Vancouver] PHP Parse error:  syntax error, unexpected token "}" in /home/mowology/public_html/crm/broken.php on line 12
LOG;

    protected function setUp(): void
    {
        date_default_timezone_set('America/Vancouver');
    }

    public function test_crashes_are_grouped_by_place_in_the_window(): void
    {
        $g = AdaServerErrors::parse(self::LOG, '2026-10-16 15:30:00');
        $this->assertSame(['app/Core/session_config.php:72', 'api/stripe/webhook.php:450', 'crm/broken.php:12'],
            array_map(fn($x) => $x['file'] . ':' . $x['line'], $g));
        $this->assertSame([3, 2, 1], array_column($g, 'count'));
        $this->assertSame('2026-10-16 09:22:54', $g[0]['first'], 'UTC read as UTC, shown in local time');
        $this->assertSame('Non-static method Stripe\PaymentMethod::attach() cannot be called statically', $g[1]['message']);
        $this->assertNotContains('crm/old.php', array_column($g, 'file'), 'two days ago is outside the window');
    }

    public function test_warnings_and_app_messages_are_not_crashes(): void
    {
        $this->assertSame([], AdaServerErrors::parse("[16-Oct-2026 11:13:23 America/Vancouver] ReviewRequestService: unexpected error\n[16-Oct-2026 11:13:23 America/Vancouver] PHP Warning:  x in /a/b.php on line 1", '2026-10-16 12:00:00'));
    }

    public function test_checks(): void
    {
        $c = AdaServerErrors::checksFrom(AdaServerErrors::parse(self::LOG, '2026-10-16 15:30:00'), '2026-10-16 15:30:00');
        $this->assertSame(['red', 'amber', 'amber'], array_column($c, 'state'));
        $this->assertSame('Server errors', $c[0]['group']);
        $this->assertStringContainsString('api/stripe/webhook.php line 450 crashed 2 times in the last 24 hours', $c[1]['evidence']);
        $this->assertSame('developer', $c[1]['handoff_to']);
        $ok = AdaServerErrors::checksFrom([], '2026-10-16 15:30:00');
        $this->assertSame(['green', 'Server crashes'], [$ok[0]['state'], $ok[0]['name']]);
    }

    public function test_reads_only_the_tail_of_a_real_file(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'ada');
        file_put_contents($f, str_repeat("noise\n", 10) . self::LOG);
        $c = (new AdaServerErrors($f, '2026-10-16 15:30:00'))->checks();
        unlink($f);
        $this->assertSame('red', $c[0]['state']);
        $this->assertSame('unknown', (new AdaServerErrors('/no/such/log', '2026-10-16 15:30:00'))->checks()[0]['state']);
    }
}
