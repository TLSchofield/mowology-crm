<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test tenant (2026-10-21): the choke point must not erode — structurally, not by file name.
 *
 * EVERY PHP file under app/ and public/ (guarded ones included) is scanned. Each outbound exit
 * found — a direct mail() call, a PHPMailer instance or ->send(), a curl / socket / HTTP read
 * in a function that targets a Twilio / Stripe / FCM / APNs host, a Stripe key set or client
 * built, any \Stripe\X::call(), the webhook's constructEvent — must be preceded by an
 * OutboundGuard call inside the same function body (brace-matched from the token stream, so
 * methods, closures and nested braces are handled), or, for top-level script code, by an
 * OutboundGuard call earlier at top level. Anything else fails with file:line.
 */
final class OutboundExitsScanTest extends TestCase
{
    private const HOSTS = '/api\.twilio\.com|api\.stripe\.com|fcm\.googleapis\.com|api(?:\.sandbox)?\.push\.apple\.com/i';

    /** Files in which the scan MUST find at least one exit (so a rewrite cannot hide one). */
    private const MUST_HAVE_EXITS = [
        'app/Services/Messaging/MessagingService.php',
        'app/Services/Messaging/SmtpMailer.php',
        'app/Services/Messaging/EmailHelper.php',
        'app/Services/Notifications.php',
        'public/includes/notifications.php',
        'app/Services/Push/FcmService.php',
        'app/Services/Push/ApnsService.php',
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

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return string[] relative paths of every scannable PHP file */
    private static function phpFiles(): array
    {
        $root = self::root();
        $out = [];
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
                if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
                    $out[] = substr($f->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Function / method / closure bodies as [startOffset, endOffset] (byte offsets of the
     * opening and closing brace), from the token stream so braces in strings and comments
     * never count. Arrow functions have no body and are skipped.
     *
     * @return array<int, array{0:int,1:int}>
     */
    public static function functionBodies(string $src): array
    {
        $tokens = token_get_all($src);
        $pos = [];
        $off = 0;
        foreach ($tokens as $i => $t) {
            $pos[$i] = $off;
            $off += strlen(is_array($t) ? $t[1] : $t);
        }
        $bodies = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_FUNCTION) {
                continue;
            }
            // first '{' after the signature (an abstract / interface method hits ';' first)
            $j = $i + 1;
            $open = null;
            for (; $j < $n; $j++) {
                $tj = $tokens[$j];
                if ($tj === ';') {
                    break;
                }
                if ($tj === '{') {
                    $open = $j;
                    break;
                }
            }
            if ($open === null) {
                continue;
            }
            $depth = 0;
            for ($k = $open; $k < $n; $k++) {
                $tk = $tokens[$k];
                if ($tk === '{' || (is_array($tk) && in_array($tk[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($tk === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $bodies[] = [$pos[$open], $pos[$k]];
                        break;
                    }
                }
            }
        }
        return $bodies;
    }

    /** Innermost body containing $offset, or null at top level. @return array{0:int,1:int}|null */
    private static function enclosing(array $bodies, int $offset): ?array
    {
        $best = null;
        foreach ($bodies as $b) {
            if ($offset > $b[0] && $offset < $b[1] && ($best === null || $b[0] > $best[0])) {
                $best = $b;
            }
        }
        return $best;
    }

    private static function insideAny(array $bodies, int $offset): bool
    {
        return self::enclosing($bodies, $offset) !== null;
    }

    /**
     * Scan one file. Returns ['exits' => n, 'bad' => [messages]].
     *
     * @return array{exits:int, bad:string[]}
     */
    public static function scanFile(string $rel, string $src): array
    {
        $bodies   = self::functionBodies($src);
        $hasHosts = preg_match(self::HOSTS, $src) === 1;
        $hasPhpMailer = strpos($src, 'PHPMailer') !== false;

        // Class constants whose value names one of the hosts (e.g. SEND_ENDPOINT) — a function
        // that references them is a network adapter even if the host literal is elsewhere.
        $hostConsts = [];
        if ($hasHosts && preg_match_all('/const\s+([A-Z_][A-Z0-9_]*)\s*=\s*([\'"])([^\'"]*)\2/', $src, $cm, PREG_SET_ORDER)) {
            foreach ($cm as $c) {
                if (preg_match(self::HOSTS, $c[3])) {
                    $hostConsts[] = $c[1];
                }
            }
        }
        $isAdapterRegion = static function (int $start, int $end) use ($src, $hostConsts): bool {
            $region = substr($src, $start, $end - $start);
            if (preg_match(self::HOSTS, $region)) {
                return true;
            }
            foreach ($hostConsts as $name) {
                if (preg_match('/(self|static)::' . $name . '\b/', $region)) {
                    return true;
                }
            }
            return false;
        };

        // Every OutboundGuard call, by offset, with whether it sits at top level.
        $guards = [];
        if (preg_match_all('/OutboundGuard::\w+\s*\(/', $src, $gm, PREG_OFFSET_CAPTURE)) {
            foreach ($gm[0] as $g) {
                $guards[] = ['off' => $g[1], 'top' => !self::insideAny($bodies, $g[1])];
            }
        }
        $guardedBefore = static function (int $offset) use ($bodies, $guards): bool {
            $body = self::enclosing($bodies, $offset);
            foreach ($guards as $g) {
                if ($g['off'] >= $offset) {
                    continue;
                }
                if ($body !== null) {
                    if ($g['off'] > $body[0] && $g['off'] < $body[1]) {
                        return true;
                    }
                } elseif ($g['top']) {
                    return true;
                }
            }
            return false;
        };
        $keyGateBefore = static function (int $offset) use ($src): bool {
            $head = substr($src, 0, $offset);
            return strpos($head, 'OutboundGuard::stripeKey(') !== false || strpos($head, 'OutboundGuard::webhookRefusal(') !== false;
        };

        $exits = 0;
        $bad = [];
        $lineStart = 0;
        foreach (explode("\n", $src) as $i => $line) {
            $lineNo = $i + 1;
            $off = $lineStart;
            $lineStart += strlen($line) + 1;
            if (preg_match('/^\s*(\*|\/\/|#|\/\*)/', $line)) {
                continue; // comment line
            }
            $found = [];

            if (preg_match('/(?<![\w>:$\\\\])@?mail\(\s*[^\s)]/', $line, $m, PREG_OFFSET_CAPTURE)) {
                $found[] = ['mail()', $m[0][1], 'guard'];
            }
            // Constructing a PHPMailer sends nothing; the exit is ->send(), required to be guarded in
            // every file that mentions PHPMailer (so a fresh `new PHPMailer … ->send()` is caught).
            if ($hasPhpMailer && preg_match('/\$\w+->send\(\)/', $line, $m, PREG_OFFSET_CAPTURE)) {
                $found[] = ['PHPMailer send()', $m[0][1], 'guard'];
            }
            if (preg_match('/new\s+\\\\?Stripe\\\\StripeClient\s*\(|\\\\Stripe\\\\Stripe::setApiKey\s*\(/', $line, $m, PREG_OFFSET_CAPTURE)) {
                $found[] = ['Stripe key', $m[0][1], 'key'];
            } elseif (preg_match('/\\\\Stripe\\\\[A-Z]\w*(?:\\\\[A-Z]\w*)*::\w+\s*\(/', $line, $m, PREG_OFFSET_CAPTURE)) {
                $found[] = ['Stripe API call', $m[0][1], 'keygate'];
            }
            if (preg_match('/api\.twilio\.com/i', $line, $m, PREG_OFFSET_CAPTURE)) {
                $found[] = ['Twilio', $m[0][1], 'guard'];
            }
            if ($hasHosts && preg_match('/\b(curl_init|curl_exec|fsockopen|stream_socket_client)\s*\(|file_get_contents\(\s*[\'"]https?:/', $line, $m, PREG_OFFSET_CAPTURE)) {
                $at = $off + $m[0][1];
                $body = self::enclosing($bodies, $at);
                $region = $body ?? [0, strlen($src)];
                if ($isAdapterRegion($region[0], $region[1])) {
                    $found[] = ['network send', $m[0][1], 'guard'];
                }
            }

            foreach ($found as [$label, $col, $rule]) {
                $exits++;
                $at = $off + $col;
                $ok = $rule === 'key' ? strpos($line, 'OutboundGuard::stripeKey(') !== false
                    : ($rule === 'keygate' ? $keyGateBefore($at) : $guardedBefore($at));
                if (!$ok) {
                    $bad[] = "{$rel}:{$lineNo} [{$label}] " . trim($line);
                }
            }
        }
        return ['exits' => $exits, 'bad' => $bad];
    }

    public function test_every_outbound_exit_is_preceded_by_a_guard_in_its_function(): void
    {
        $bad = [];
        $exitsPerFile = [];
        foreach (self::phpFiles() as $rel) {
            $r = self::scanFile($rel, (string)file_get_contents(self::root() . '/' . $rel));
            $exitsPerFile[$rel] = $r['exits'];
            $bad = array_merge($bad, $r['bad']);
        }
        $this->assertSame([], $bad,
            "Outbound exit(s) without an OutboundGuard call earlier in the same function (or at top level before it). Send through MessagingService, or call OutboundGuard first:\n" . implode("\n", $bad));

        $missing = [];
        foreach (self::MUST_HAVE_EXITS as $rel) {
            if (($exitsPerFile[$rel] ?? 0) < 1) {
                $missing[] = $rel;
            }
        }
        $this->assertSame([], $missing, 'the scan found no exit in these adapters — the patterns or the files changed: ' . implode(', ', $missing));
        $this->assertGreaterThanOrEqual(40, array_sum($exitsPerFile));
    }

    public function test_the_webhook_entry_is_gated_before_and_after_signature_verification(): void
    {
        $src = (string)file_get_contents(self::root() . '/public/api/stripe/webhook.php');
        $gate1 = strpos($src, 'OutboundGuard::webhookRefusal(');
        $construct = strpos($src, '\Stripe\Webhook::constructEvent(');
        $gate2 = strpos($src, 'OutboundGuard::webhookRefusal(', $gate1 + 1);
        $autoload = strpos($src, "vendor/autoload.php'");
        $this->assertNotFalse($gate1);
        $this->assertNotFalse($construct);
        $this->assertNotFalse($gate2);
        $this->assertLessThan($autoload, $gate1, 'the first gate must run before the SDK is even loaded');
        $this->assertLessThan($construct, $gate1, 'the first gate must run before signature verification');
        $this->assertLessThan($gate2, $construct, 'the second gate must inspect the verified event');
        $this->assertLessThan(strpos($src, 'switch ($event->type)'), $gate2, 'the second gate must run before routing');
        $this->assertStringContainsString("STRIPE_WEBHOOK_SECRET_IS_TEST", $src);
        $this->assertStringContainsString('$event->livemode ?? true', $src, 'an event without livemode is treated as live');
        $this->assertSame([], self::functionBodies(substr($src, 0, $gate2)) === [] ? [] : ['?'], 'both gates sit at top level, before any function');
    }

    public function test_the_brace_matcher_handles_methods_closures_strings_and_nesting(): void
    {
        $src = '<?php class A { function m() { $f = function () { if (1) { return "}"; } }; $s = "{$x}"; } } function top() { }';
        $bodies = self::functionBodies($src);
        $this->assertCount(3, $bodies);
        foreach ($bodies as [$s, $e]) {
            $this->assertSame('{', $src[$s]);
            $this->assertSame('}', $src[$e]);
        }
        // the closure is nested inside m()
        usort($bodies, static fn($a, $b) => $a[0] <=> $b[0]);
        $this->assertGreaterThan($bodies[0][0], $bodies[1][0]);
        $this->assertLessThan($bodies[0][1], $bodies[1][1]);
        $this->assertGreaterThan($bodies[0][1], $bodies[2][0]);
    }

    public function test_an_unguarded_exit_is_reported_and_a_guarded_one_is_not(): void
    {
        $bad = '<?php function a($to) { return mail($to, "s", "b"); }';
        $this->assertCount(1, self::scanFile('x.php', $bad)['bad']);
        $good = '<?php function a($to) { $g = \App\Core\OutboundGuard::email($to, "s", "b"); if (!$g["send"]) return true; return mail($g["to"], "s", "b"); }';
        $this->assertSame([], self::scanFile('x.php', $good)['bad']);
        // a guard in a DIFFERENT function does not count
        $other = '<?php function g() { \App\Core\OutboundGuard::email("a", "s", "b"); } function a($to) { return mail($to, "s", "b"); }';
        $this->assertCount(1, self::scanFile('x.php', $other)['bad']);
        // a guard AFTER the exit does not count
        $late = '<?php function a($to) { $r = mail($to, "s", "b"); \App\Core\OutboundGuard::email($to, "s", "b"); return $r; }';
        $this->assertCount(1, self::scanFile('x.php', $late)['bad']);
        // top-level script: a top-level guard before the exit counts
        $script = "<?php\n\\App\\Core\\OutboundGuard::webhookRefusal(true, null);\n\$e = \\Stripe\\Webhook::constructEvent(\$p, \$s, \$k);\n";
        $this->assertSame([], self::scanFile('x.php', $script)['bad']);
        // a Stripe key must be wrapped on the very line it is used
        $key = '<?php \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);';
        $this->assertCount(1, self::scanFile('x.php', $key)['bad']);
        // a fresh PHPMailer send in a new file is caught
        $pm = '<?php function a() { $m = new \PHPMailer\PHPMailer\PHPMailer(true); $m->addAddress("x"); return $m->send(); }';
        $this->assertCount(1, self::scanFile('x.php', $pm)['bad']);
        // a curl in a function that targets a push host needs a guard; one that targets another host does not
        $push = "<?php class P { const SEND = 'https://fcm.googleapis.com/x'; const TOK = 'https://oauth2.googleapis.com/t';\n"
              . "static function send() { \$ch = curl_init(); curl_setopt(\$ch, CURLOPT_URL, self::SEND); }\n"
              . "static function token() { \$ch = curl_init(); curl_setopt(\$ch, CURLOPT_URL, self::TOK); } }";
        $r = self::scanFile('x.php', $push);
        $this->assertCount(1, $r['bad']);
        $this->assertStringContainsString(':2 ', $r['bad'][0]);
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
            $gate = strpos($src, 'OutboundGuard::cronGate(');
            $cfg  = strpos($src, "require_once APP_ROOT . '/Core/config.php';");
            $this->assertNotFalse($cfg, $rel);
            $this->assertLessThan($gate, $cfg, "{$rel}: config.php must load before the gate");
            $this->assertLessThan($cfg + 400, $gate, "{$rel}: the gate must sit right after config.php");
            $this->assertFalse(self::insideAny(self::functionBodies($src), $gate), "{$rel}: the gate must be top-level");
        }
    }
}
