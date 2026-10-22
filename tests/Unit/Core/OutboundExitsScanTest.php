<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test tenant (2026-10-21): the choke point must not erode. Every place under app/ or public/
 * that can reach the outside world — a direct mail() call, a PHPMailer instance, a Stripe client
 * or API key, a Twilio / FCM / APNs endpoint — must be one of the files below, and each of those
 * files must still call OutboundGuard. A new direct exit anywhere else fails this test: route it
 * through MessagingService (CLAUDE.md rule 9) or add the guard call and the file to the list.
 */
final class OutboundExitsScanTest extends TestCase
{
    /** Files allowed to contain a direct exit — each one is guarded (see docs/test-tenant.md). */
    private const GUARDED = [
        // email + SMS (mail() / PHPMailer)
        'app/Services/Messaging/MessagingService.php',
        'app/Services/Messaging/SmtpMailer.php',
        'app/Services/Messaging/EmailHelper.php',
        'app/Services/Notifications.php',
        'public/includes/notifications.php',
        // push
        'app/Services/Push/FcmService.php',
        'app/Services/Push/ApnsService.php',
        // Stripe
        'app/Modules/Accounting/Services/StripePayoutService.php',
        'app/Modules/Invoices/Services/InvoiceRefundService.php',
        'app/Modules/Invoices/Services/InvoiceService.php',
        'app/Services/Payments/AutopayService.php',
        'public/api/stripe/webhook.php',
        'public/crm/api/backfill-stripe-cards.php',
        'public/crm/api/stripe-payout-probe.php',
        'public/crm/api/stripe/create-payment-intent.php',
        'public/crm/companies/api-save-company-card.php',
        'public/customer/api/autopay-setup.php',
        'public/customer/api/confirm-saved-card.php',
        'public/customer/api/invoice-payment-intent.php',
    ];

    private const PATTERNS = [
        // a real mail() call has arguments and no space before "("; "mail()" in a comment or log
        // string, and prose like "system mail (cron reports)", do not
        'mail()'     => '/(?<![\w>:$\\\\])@?mail\(\s*[^\s)]/',
        'PHPMailer'  => '/new\s+\\\\?(?:PHPMailer\\\\PHPMailer\\\\)?PHPMailer\s*\(/',
        'Stripe'     => '/new\s+\\\\?Stripe\\\\StripeClient\s*\(|Stripe\\\\Stripe::setApiKey\s*\(/',
        'Twilio'     => '/api\.twilio\.com/i',
        'FCM / APNs' => '/fcm\.googleapis\.com|api(?:\.sandbox)?\.push\.apple\.com/',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function test_every_direct_outbound_exit_is_in_a_guarded_file(): void
    {
        $root = self::root();
        $hits = [];
        foreach (['app', 'public'] as $top) {
            $it = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($root . '/' . $top, FilesystemIterator::SKIP_DOTS),
                    static function (SplFileInfo $f) use ($root): bool {
                        $rel = substr($f->getPathname(), strlen($root) + 1);
                        return !preg_match('#(^|/)(vendor|crinum|node_modules|uploads|_media|sessions|Storage)(/|$)#', $rel);
                    }
                )
            );
            foreach ($it as $f) {
                if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php') {
                    continue;
                }
                $rel = substr($f->getPathname(), strlen($root) + 1);
                if (in_array($rel, self::GUARDED, true)) {
                    continue;
                }
                foreach (file($f->getPathname(), FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
                    if (preg_match('/^\s*(\*|\/\/|#|\/\*)/', $line)) {
                        continue; // comment
                    }
                    foreach (self::PATTERNS as $label => $re) {
                        if (preg_match($re, $line)) {
                            $hits[] = "{$rel}:" . ($i + 1) . " [{$label}] " . trim($line);
                        }
                    }
                }
            }
        }
        $this->assertSame([], $hits,
            "New direct outbound exit(s) outside the guarded files — send through MessagingService, or guard with OutboundGuard and add the file to OutboundExitsScanTest::GUARDED:\n" . implode("\n", $hits));
    }

    public function test_every_guarded_file_still_calls_the_guard(): void
    {
        foreach (self::GUARDED as $rel) {
            $path = self::root() . '/' . $rel;
            $this->assertFileExists($path, "{$rel} is on the guarded list but gone — remove it from the list");
            $src = (string)file_get_contents($path);
            $this->assertStringContainsString('OutboundGuard::', $src, "{$rel} lost its OutboundGuard call");
        }
    }

    public function test_each_stripe_key_use_is_wrapped(): void
    {
        // Every Stripe client / API-key line must pass the key through OutboundGuard::stripeKey().
        $bad = [];
        foreach (self::GUARDED as $rel) {
            foreach (file(self::root() . '/' . $rel, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
                if (preg_match(self::PATTERNS['Stripe'], $line) && strpos($line, 'OutboundGuard::stripeKey(') === false) {
                    $bad[] = "{$rel}:" . ($i + 1) . ' ' . trim($line);
                }
            }
        }
        $this->assertSame([], $bad, "Stripe key used without OutboundGuard::stripeKey():\n" . implode("\n", $bad));
    }

    public function test_each_direct_mail_call_sits_below_a_guard_in_its_function(): void
    {
        // In the mail()/PHPMailer files, every function that calls mail() or $mail->send() must
        // call OutboundGuard::email() or ::sms() earlier in the same function body.
        $bad = [];
        foreach (array_slice(self::GUARDED, 0, 5) as $rel) {
            $src = (string)file_get_contents(self::root() . '/' . $rel);
            preg_match_all('/\nfunction\s+(\w+)\s*\(.*?\n\}/s', $src, $fns, PREG_SET_ORDER);
            foreach ($fns as $fn) {
                $body = $fn[0];
                $sendPos = null;
                foreach ([self::PATTERNS['mail()'], '/\$mail->send\(\)/'] as $re) {
                    if (preg_match($re, $body, $m, PREG_OFFSET_CAPTURE)) {
                        $sendPos = $sendPos === null ? $m[0][1] : min($sendPos, $m[0][1]);
                    }
                }
                if ($sendPos === null) {
                    continue;
                }
                $guardPos = null;
                if (preg_match('/OutboundGuard::(email|sms)\(/', $body, $g, PREG_OFFSET_CAPTURE)) {
                    $guardPos = $g[0][1];
                }
                if ($guardPos === null || $guardPos > $sendPos) {
                    $bad[] = "{$rel} {$fn[1]}()";
                }
            }
        }
        $this->assertSame([], $bad, "send without a preceding OutboundGuard call in:\n" . implode("\n", $bad));
    }

    public function test_sending_and_charging_crons_are_gated(): void
    {
        $crons = [
            'app/Modules/Invoices/Cron/autopay_charge.php'           => 'charge',
            'app/Modules/Contracts/Cron/contract_billing.php'        => 'charge',
            'app/Modules/Marketing/Cron/campaign_sender.php'         => 'campaign',
            'app/Modules/Marketing/Cron/automation_runner.php'       => 'campaign',
            'app/Modules/Marketing/Cron/invoice_reminders.php'       => 'campaign',
            'app/Modules/Marketing/Cron/invoice_overdue.php'         => 'campaign',
            'app/Modules/Marketing/Cron/optin_resend_sender.php'     => 'campaign',
            'app/Modules/Marketing/Cron/seasonal_triggers.php'       => 'campaign',
            'app/Modules/Marketing/Cron/mia_email_daily.php'         => 'campaign',
            'app/Modules/Marketing/Cron/mia_channels_weekly.php'     => 'campaign',
            'app/Modules/Team/Cron/forgot_clockout_sms.php'          => 'sender',
            'app/Modules/Team/Cron/auto_clockout.php'                => 'sender',
            'app/Modules/Team/Cron/tracking_silent_alert.php'        => 'sender',
            'app/Modules/ChiefOfStaff/Cron/charlie_morning_brief.php' => 'sender',
            'app/Modules/Quotes/Cron/quote_request_reminder.php'     => 'sender',
            'app/Services/Push/Cron/push-drain.php'                  => 'sender',
        ];
        foreach ($crons as $rel => $kind) {
            $src = (string)file_get_contents(self::root() . '/' . $rel);
            $this->assertSame(1, preg_match("/OutboundGuard::cronGate\('[a-z_]+', '{$kind}'\)/", $src), "{$rel} is not gated as a {$kind} cron");
            // The gate runs before any work: it must come before the first service/function include after config.php.
            $gate = strpos($src, 'OutboundGuard::cronGate(');
            $cfg  = strpos($src, "require_once APP_ROOT . '/Core/config.php';");
            $this->assertNotFalse($cfg, $rel);
            $this->assertLessThan($gate, $cfg, "{$rel}: config.php must load before the gate");
            $this->assertLessThan($cfg + 400, $gate, "{$rel}: the gate must sit right after config.php");
        }
    }
}
