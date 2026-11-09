<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test tenant (2026-10-21): the choke point must not erode — structurally, from the token stream.
 *
 * EVERY PHP file under app/ and public/ is scanned. Exits are found as tokens, so line breaks,
 * whitespace or comments between a name and its "(" change nothing:
 *   mail(...)                        a direct mail() call with arguments
 *   $mailer->send()                  PHPMailer's send, in any file that mentions PHPMailer
 *   curl_init / curl_exec / fsockopen / stream_socket_client (…)
 *                                    inside a function that targets a Twilio / Stripe / FCM / APNs host
 *   file_get_contents('https://…')   to one of those hosts
 *   \Stripe\Stripe::setApiKey(…), new \Stripe\StripeClient(…)      ("key" exits)
 *   \Stripe\Anything::call(…)        ("api" exits, incl. the webhook's constructEvent)
 *   'api.twilio.com'                 any string literal
 *
 * Rules:
 *   adapter exits may only live inside the explicitly listed adapter methods below, and the
 *   method must USE the result of an OutboundGuard call before the exit (assigned, tested in a
 *   condition, returned, passed as an argument) — a guard merely called and ignored does not count;
 *   key exits must pass the key through OutboundGuard::stripeKey() inside the call's own arguments
 *   and also live in a listed method;
 *   api exits need an earlier used OutboundGuard::stripeKey() / ::webhookRefusal() in the file
 *   (the Stripe key is process-global, set once per request).
 * Anything else fails with file:line [label] scope.
 */
final class OutboundExitsScanTest extends TestCase
{
    private const HOSTS = '/api\.twilio\.com|api\.stripe\.com|fcm\.googleapis\.com|api(?:\.sandbox)?\.push\.apple\.com/i';

    /**
     * The only places an adapter / key exit may exist. "file::scope"; scope is Class::method,
     * function, {closure} or (top-level). One comment per entry says what it sends.
     */
    private const ALLOWED_ADAPTERS = [
        // MessagingService — the sanctioned email/SMS module (CLAUDE.md rule 9)
        'app/Services/Messaging/MessagingService.php::_sendViaPhpMailer'          => 'PHPMailer SMTP send for sendEmail()',
        'app/Services/Messaging/MessagingService.php::_sendEmailMultiAttach'      => 'PHPMailer send with several attachments / Cc',
        'app/Services/Messaging/MessagingService.php::_sendSimpleHtmlEmail'       => 'native mail() fallback, no attachment',
        'app/Services/Messaging/MessagingService.php::_sendEmailWithAttachment'   => 'native mail() fallback, one attachment',
        'app/Services/Messaging/MessagingService.php::_sendEmailWithAttachments'  => 'native mail() fallback, several attachments',
        'app/Services/Messaging/MessagingService.php::sendSms'                    => 'mail() to the ten carrier SMS gateways',
        'app/Services/Messaging/MessagingService.php::testSmsGateway'             => 'mail() to one carrier gateway (admin test)',
        // SmtpMailer — legacy twin, loaded only via public/crm/includes/smtp_mailer.php
        'app/Services/Messaging/SmtpMailer.php::_sendViaPhpMailer'                => 'legacy PHPMailer SMTP send',
        'app/Services/Messaging/SmtpMailer.php::sendSimpleHtmlEmail'              => 'legacy native mail() fallback',
        'app/Services/Messaging/SmtpMailer.php::sendEmailWithAttachment'          => 'legacy native mail() with attachment',
        // EmailHelper — legacy, loaded only via public/crm/includes/email_helper.php
        'app/Services/Messaging/EmailHelper.php::sendCrmEmail'                    => 'legacy mail() with optional PDF',
        // Notifications — owner alerts (quote requests); the live twin is public/includes/notifications.php
        'app/Services/Notifications.php::sendEmailNotification'                   => 'mail() to the owner inbox',
        'app/Services/Notifications.php::sendSMSNotification'                     => 'mail() to the owner SMS gateway',
        'public/includes/notifications.php::sendEmailNotification'                => 'live twin: mail() to the owner inbox',
        'public/includes/notifications.php::sendSMSNotification'                  => 'live twin: mail() to the owner SMS gateway',
        // Push adapters
        'app/Services/Push/FcmService.php::FcmService::send'                      => 'curl to fcm.googleapis.com',
        'app/Services/Push/ApnsService.php::ApnsService::send'                    => 'curl to api.push.apple.com',
        // Stripe key sites (the key itself is wrapped on the line; these are the only places it is set)
        'app/Modules/Accounting/Services/StripePayoutService.php::StripePayoutService::stripe' => 'StripeClient for payout matching (read-only)',
        'app/Modules/Invoices/Services/InvoiceRefundService.php::InvoiceRefundService::stripe' => 'StripeClient for refunds',
        'app/Modules/Invoices/Services/InvoiceService.php::InvoiceService::stripe'             => 'StripeClient for customers / payment intents',
        'app/Services/Payments/AutopayService.php::AutopayService::__construct'                => 'global key for autopay charges',
        'public/api/stripe/webhook.php::handlePaymentSucceeded'                   => 'global key to read the payment method',
        'public/api/stripe/webhook.php::handleSetupIntentSucceeded'               => 'global key to read the saved card',
        'public/crm/api/backfill-stripe-cards.php::(top-level)'                   => 'admin tool: global key to list saved cards',
        'public/crm/api/stripe-payout-probe.php::(top-level)'                     => 'admin probe: StripeClient to read payouts',
        'public/crm/api/stripe/create-payment-intent.php::(top-level)'            => 'CRM: global key for a payment intent (set twice, both top-level)',
        'public/crm/companies/api-save-company-card.php::(top-level)'             => 'CRM: global key to save a card',
        'public/customer/api/autopay-setup.php::(top-level)'                      => 'portal: global key for autopay setup',
        'public/customer/api/confirm-saved-card.php::(top-level)'                 => 'portal: global key to confirm a card',
        'public/customer/api/invoice-payment-intent.php::(top-level)'             => 'portal: global key for a payment intent',
    ];

    /** Files in which the scan MUST find at least one exit (so a rewrite cannot hide one). */
    private const MUST_HAVE_EXITS = [
        'app/Services/Messaging/MessagingService.php', 'app/Services/Messaging/SmtpMailer.php',
        'app/Services/Messaging/EmailHelper.php', 'app/Services/Notifications.php', 'public/includes/notifications.php',
        'app/Services/Push/FcmService.php', 'app/Services/Push/ApnsService.php',
        'app/Modules/Accounting/Services/StripePayoutService.php', 'app/Modules/Invoices/Services/InvoiceRefundService.php',
        'app/Modules/Invoices/Services/InvoiceService.php', 'app/Services/Payments/AutopayService.php',
        'public/api/stripe/webhook.php', 'public/crm/api/backfill-stripe-cards.php', 'public/crm/api/stripe-payout-probe.php',
        'public/crm/api/stripe/create-payment-intent.php', 'public/crm/companies/api-save-company-card.php',
        'public/customer/api/autopay-setup.php', 'public/customer/api/confirm-saved-card.php',
        'public/customer/api/invoice-payment-intent.php',
    ];

    private const STATEMENT_START = [';', '{', '}'];

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

    // ── Token helpers ──────────────────────────────────────────────────────────

    /** @return array<int, array{t:int|null, x:string, o:int, l:int}> */
    private static function tokens(string $src): array
    {
        $out = [];
        $off = 0;
        $line = 1;
        foreach (token_get_all($src) as $t) {
            $x = is_array($t) ? $t[1] : $t;
            $out[] = ['t' => is_array($t) ? $t[0] : null, 'x' => $x, 'o' => $off, 'l' => $line];
            $off += strlen($x);
            $line += substr_count($x, "\n");
        }
        return $out;
    }

    private static function isNoise(array $tok): bool
    {
        return in_array($tok['t'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /** Index of the next (dir=1) / previous (dir=-1) significant token, or null. */
    private static function sig(array $tokens, int $i, int $dir): ?int
    {
        for ($j = $i + $dir; $j >= 0 && $j < count($tokens); $j += $dir) {
            if (!self::isNoise($tokens[$j])) {
                return $j;
            }
        }
        return null;
    }

    private static function isNamePart(array $tok): bool
    {
        if ($tok['t'] === T_STRING || $tok['t'] === T_NS_SEPARATOR) {
            return true;
        }
        foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $c) {
            if (defined($c) && $tok['t'] === constant($c)) {
                return true;
            }
        }
        return false;
    }

    /** A (possibly namespaced) name starting at $i: [name without leading "\", last index], or null. */
    private static function nameAt(array $tokens, int $i): ?array
    {
        if (!self::isNamePart($tokens[$i])) {
            return null;
        }
        $name = '';
        $j = $i;
        while ($j < count($tokens) && self::isNamePart($tokens[$j])) {
            $name .= $tokens[$j]['x'];
            $j++;
        }
        return [ltrim($name, '\\'), $j - 1];
    }

    /** Index of the ")" matching the "(" at $open. */
    private static function closeParen(array $tokens, int $open): int
    {
        $depth = 0;
        for ($k = $open; $k < count($tokens); $k++) {
            if ($tokens[$k]['x'] === '(') {
                $depth++;
            } elseif ($tokens[$k]['x'] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            }
        }
        return count($tokens) - 1;
    }

    /**
     * Function / method / closure bodies and class bodies, brace-matched from the token stream
     * (braces in strings and comments never count; arrow functions have no body).
     *
     * @return array{fns: array<int, array{s:int, e:int, name:string}>, classes: array<int, array{s:int, e:int, name:string}>}
     */
    public static function scopes(string $src, ?array $tokens = null): array
    {
        $tokens = $tokens ?? self::tokens($src);
        $fns = [];
        $classes = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i]['t'];
            if ($t !== T_FUNCTION && $t !== T_CLASS) {
                continue;
            }
            $prev = self::sig($tokens, $i, -1);
            if ($t === T_CLASS && $prev !== null && in_array($tokens[$prev]['t'], [T_DOUBLE_COLON, T_NEW], true)) {
                continue; // ::class, or an anonymous class (its methods are still found)
            }
            $name = $t === T_FUNCTION ? '{closure}' : '(anonymous)';
            $j = self::sig($tokens, $i, 1);
            if ($j !== null && $tokens[$j]['x'] === '&') {
                $j = self::sig($tokens, $j, 1);
            }
            if ($j !== null && $tokens[$j]['t'] === T_STRING) {
                $name = $tokens[$j]['x'];
            }
            $open = null;
            for ($k = $i + 1; $k < $n; $k++) {
                if ($tokens[$k]['x'] === ';') {
                    break;
                }
                if ($tokens[$k]['x'] === '{') {
                    $open = $k;
                    break;
                }
            }
            if ($open === null) {
                continue;
            }
            $depth = 0;
            for ($k = $open; $k < $n; $k++) {
                $x = $tokens[$k]['x'];
                $tt = $tokens[$k]['t'];
                if ($x === '{' || $tt === T_CURLY_OPEN || $tt === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                } elseif ($x === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $entry = ['s' => $tokens[$open]['o'], 'e' => $tokens[$k]['o'], 'name' => $name];
                        if ($t === T_FUNCTION) {
                            $fns[] = $entry;
                        } else {
                            $classes[] = $entry;
                        }
                        break;
                    }
                }
            }
        }
        return ['fns' => $fns, 'classes' => $classes];
    }

    /** Backwards-compatible: function bodies as [open, close] offsets. @return array<int, array{0:int,1:int}> */
    public static function functionBodies(string $src): array
    {
        return array_map(static fn(array $f) => [$f['s'], $f['e']], self::scopes($src)['fns']);
    }

    /** Innermost entry containing $offset. */
    private static function innermost(array $entries, int $offset): ?array
    {
        $best = null;
        foreach ($entries as $b) {
            if ($offset > $b['s'] && $offset < $b['e'] && ($best === null || $b['s'] > $best['s'])) {
                $best = $b;
            }
        }
        return $best;
    }

    private static function scopeName(array $scopes, int $offset): string
    {
        $fn = self::innermost($scopes['fns'], $offset);
        if ($fn === null) {
            return '(top-level)';
        }
        $cls = self::innermost($scopes['classes'], $fn['s']);
        return ($cls !== null && $cls['name'] !== '(anonymous)' ? $cls['name'] . '::' : '') . $fn['name'];
    }

    // ── The scan ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, string>|null $allowed adapter allow-list (null = the real one)
     * @return array{exits:int, bad:string[]}
     */
    public static function scanFile(string $rel, string $src, ?array $allowed = null): array
    {
        $allowed = $allowed ?? self::ALLOWED_ADAPTERS;
        $hasHosts = preg_match(self::HOSTS, $src) === 1;
        $hasPhpMailer = strpos($src, 'PHPMailer') !== false;
        // Lossless pre-filter (keeps the suite's memory peak down): every exit kind needs one of
        // these in the raw text — the word "mail" (as a token it cannot hide in "email"), "Stripe\",
        // "PHPMailer" for ->send(), the Twilio host, or a push/Stripe host for curl/socket/HTTP reads.
        if (!$hasHosts && !$hasPhpMailer && !preg_match('/\bmail\b|Stripe\\\\|api\.twilio\.com/i', $src)) {
            return ['exits' => 0, 'bad' => []];
        }
        $tokens  = self::tokens($src);
        $scopes  = self::scopes($src, $tokens); // one token stream per file, not two
        $n = count($tokens);

        // Class constants whose value names one of the hosts (e.g. SEND_ENDPOINT).
        $hostConsts = [];
        if ($hasHosts && preg_match_all('/const\s+([A-Z_][A-Z0-9_]*)\s*=\s*([\'"])([^\'"]*)\2/', $src, $cm, PREG_SET_ORDER)) {
            foreach ($cm as $c) {
                if (preg_match(self::HOSTS, $c[3])) {
                    $hostConsts[] = $c[1];
                }
            }
        }
        $isAdapterRegion = static function (int $offset) use ($src, $scopes, $hostConsts): bool {
            $fn = self::innermost($scopes['fns'], $offset);
            $region = $fn === null ? $src : substr($src, $fn['s'], $fn['e'] - $fn['s']);
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

        // Every OutboundGuard::method( call: offset, method, whether its result is USED.
        $guards = [];
        $exits  = [];
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];

            // string literals: Twilio host
            if ($tok['t'] === T_CONSTANT_ENCAPSED_STRING && stripos($tok['x'], 'api.twilio.com') !== false) {
                $exits[] = ['label' => 'Twilio', 'rule' => 'adapter', 'o' => $tok['o'], 'l' => $tok['l']];
                continue;
            }

            // PHPMailer ->send()
            if ($hasPhpMailer && $tok['t'] === T_OBJECT_OPERATOR) {
                $a = self::sig($tokens, $i, 1);
                $b = $a === null ? null : self::sig($tokens, $a, 1);
                $c = $b === null ? null : self::sig($tokens, $b, 1);
                if ($a !== null && $b !== null && $c !== null && $tokens[$a]['x'] === 'send' && $tokens[$b]['x'] === '(' && $tokens[$c]['x'] === ')') {
                    $exits[] = ['label' => 'PHPMailer send()', 'rule' => 'adapter', 'o' => $tok['o'], 'l' => $tok['l']];
                }
                continue;
            }

            $nm = self::nameAt($tokens, $i);
            if ($nm === null) {
                continue;
            }
            [$name, $last] = $nm;
            $prev = self::sig($tokens, $i, -1);
            $prevTok = $prev === null ? null : $tokens[$prev];
            $afterName = self::sig($tokens, $last, 1);
            $i = $last; // skip the rest of the name

            // OutboundGuard::method(
            if (substr($name, -13) === 'OutboundGuard' && $afterName !== null && $tokens[$afterName]['t'] === T_DOUBLE_COLON) {
                $m = self::sig($tokens, $afterName, 1);
                $p = $m === null ? null : self::sig($tokens, $m, 1);
                if ($m !== null && $p !== null && $tokens[$m]['t'] === T_STRING && $tokens[$p]['x'] === '(') {
                    $used = $prevTok !== null && $prevTok['t'] !== T_OPEN_TAG && !in_array($prevTok['x'], self::STATEMENT_START, true);
                    $guards[] = ['o' => $tok['o'], 'method' => $tokens[$m]['x'], 'used' => $used];
                }
                continue;
            }

            // Stripe static calls / client
            if (preg_match('/^Stripe\\\\/', $name)) {
                if ($prevTok !== null && $prevTok['t'] === T_NEW && $name === 'Stripe\\StripeClient' && $afterName !== null && $tokens[$afterName]['x'] === '(') {
                    $exits[] = ['label' => 'new StripeClient', 'rule' => 'key', 'o' => $tok['o'], 'l' => $tok['l'], 'open' => $afterName];
                } elseif ($afterName !== null && $tokens[$afterName]['t'] === T_DOUBLE_COLON) {
                    $m = self::sig($tokens, $afterName, 1);
                    $p = $m === null ? null : self::sig($tokens, $m, 1);
                    if ($m !== null && $p !== null && $tokens[$m]['t'] === T_STRING && $tokens[$p]['x'] === '(') {
                        if ($name === 'Stripe\\Stripe' && $tokens[$m]['x'] === 'setApiKey') {
                            $exits[] = ['label' => 'Stripe::setApiKey', 'rule' => 'key', 'o' => $tok['o'], 'l' => $tok['l'], 'open' => $p];
                        } else {
                            $exits[] = ['label' => 'Stripe API call ' . $name . '::' . $tokens[$m]['x'], 'rule' => 'api', 'o' => $tok['o'], 'l' => $tok['l']];
                        }
                    }
                }
                continue;
            }

            // plain function calls: mail(), network sends, file_get_contents(url)
            $isCall = $afterName !== null && $tokens[$afterName]['x'] === '('
                && ($prevTok === null || !in_array($prevTok['t'], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true))
                && strpos($name, '\\') === false;
            if (!$isCall) {
                continue;
            }
            $lname = strtolower($name);
            $firstArg = self::sig($tokens, $afterName, 1);
            $hasArgs = $firstArg !== null && $tokens[$firstArg]['x'] !== ')';
            if ($lname === 'mail' && $hasArgs) {
                $exits[] = ['label' => 'mail()', 'rule' => 'adapter', 'o' => $tok['o'], 'l' => $tok['l']];
            } elseif (in_array($lname, ['curl_init', 'curl_exec', 'fsockopen', 'stream_socket_client'], true) && $hasHosts && $isAdapterRegion($tok['o'])) {
                $exits[] = ['label' => 'network send ' . $lname . '()', 'rule' => 'adapter', 'o' => $tok['o'], 'l' => $tok['l']];
            } elseif ($lname === 'file_get_contents' && $hasArgs && $tokens[$firstArg]['t'] === T_CONSTANT_ENCAPSED_STRING
                && preg_match(self::HOSTS, $tokens[$firstArg]['x'])) {
                $exits[] = ['label' => 'file_get_contents(url)', 'rule' => 'adapter', 'o' => $tok['o'], 'l' => $tok['l']];
            }
        }

        $usedGuardBefore = static function (int $offset, ?array $methods = null) use ($guards, $scopes): bool {
            $fn = self::innermost($scopes['fns'], $offset);
            foreach ($guards as $g) {
                if ($g['o'] >= $offset || !$g['used'] || ($methods !== null && !in_array($g['method'], $methods, true))) {
                    continue;
                }
                if ($fn !== null) {
                    if ($g['o'] > $fn['s'] && $g['o'] < $fn['e']) {
                        return true;
                    }
                } elseif (self::innermost($scopes['fns'], $g['o']) === null) {
                    return true;
                }
            }
            return false;
        };
        $usedKeyGuardAnywhereBefore = static function (int $offset) use ($guards): bool {
            foreach ($guards as $g) {
                if ($g['o'] < $offset && $g['used'] && in_array($g['method'], ['stripeKey', 'webhookRefusal'], true)) {
                    return true;
                }
            }
            return false;
        };

        $bad = [];
        foreach ($exits as $e) {
            $scope = self::scopeName($scopes, $e['o']);
            $where = "{$rel}:{$e['l']} [{$e['label']}] in {$scope}";
            if ($e['rule'] === 'api') {
                if (!$usedKeyGuardAnywhereBefore($e['o'])) {
                    $bad[] = $where . ' — no used OutboundGuard::stripeKey()/webhookRefusal() earlier in the file';
                }
                continue;
            }
            if (!isset($allowed[$rel . '::' . $scope])) {
                $bad[] = $where . ' — not an allow-listed adapter method';
                continue;
            }
            if ($e['rule'] === 'key') {
                $close = self::closeParen($tokens, $e['open']);
                $args = '';
                for ($k = $e['open']; $k <= $close; $k++) {
                    $args .= $tokens[$k]['x'];
                }
                if (strpos($args, 'OutboundGuard::stripeKey') === false) {
                    $bad[] = $where . ' — the key is not wrapped in OutboundGuard::stripeKey() in the call arguments';
                }
                continue;
            }
            if (!$usedGuardBefore($e['o'])) {
                $bad[] = $where . ' — no OutboundGuard call whose result is used before the exit in this function';
            }
        }
        return ['exits' => count($exits), 'bad' => $bad];
    }

    // ── Tests on the real tree ─────────────────────────────────────────────────

    public function test_every_outbound_exit_is_an_allow_listed_adapter_that_uses_the_guard(): void
    {
        $bad = [];
        $exitsPerFile = [];
        foreach (self::phpFiles() as $rel) {
            $r = self::scanFile($rel, (string)file_get_contents(self::root() . '/' . $rel));
            $exitsPerFile[$rel] = $r['exits'];
            $bad = array_merge($bad, $r['bad']);
        }
        gc_mem_caches(); // hand the token streams' freed chunks back, so later tests keep their headroom
        $this->assertSame([], $bad,
            "Outbound exit(s) outside the allow-listed adapters, or without a USED OutboundGuard result before them. Send through MessagingService, or guard first and list the method in OutboundExitsScanTest::ALLOWED_ADAPTERS:\n" . implode("\n", $bad));

        $missing = [];
        foreach (self::MUST_HAVE_EXITS as $rel) {
            if (($exitsPerFile[$rel] ?? 0) < 1) {
                $missing[] = $rel;
            }
        }
        $this->assertSame([], $missing, 'the scan found no exit in these adapters — the patterns or the files changed: ' . implode(', ', $missing));
        $this->assertGreaterThanOrEqual(40, array_sum($exitsPerFile));
    }

    public function test_every_allow_listed_adapter_still_exists_and_has_an_exit(): void
    {
        $byFile = [];
        foreach (array_keys(self::ALLOWED_ADAPTERS) as $key) {
            [$rel, $scope] = explode('::', $key, 2);
            $byFile[$rel][] = $scope;
        }
        foreach ($byFile as $rel => $scopesWanted) {
            $path = self::root() . '/' . $rel;
            $this->assertFileExists($path, "{$rel} is on the adapter allow-list but gone — remove its entries");
            $src = (string)file_get_contents($path);
            $scopes = self::scopes($src);
            foreach ($scopesWanted as $scope) {
                if ($scope === '(top-level)') {
                    continue;
                }
                $found = false;
                foreach ($scopes['fns'] as $f) {
                    if (self::scopeName($scopes, $f['s'] + 1) === $scope) {
                        $found = true;
                        break;
                    }
                }
                $this->assertTrue($found, "{$rel}: allow-listed adapter {$scope} no longer exists — remove it from the list");
            }
        }
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
        $this->assertStringContainsString('STRIPE_WEBHOOK_SECRET_IS_TEST', $src);
        $this->assertStringContainsString('$event->livemode ?? true', $src, 'an event without livemode is treated as live');
        $scopes = self::scopes($src);
        $this->assertSame('(top-level)', self::scopeName($scopes, $gate1));
        $this->assertSame('(top-level)', self::scopeName($scopes, $gate2));
        $this->assertSame('(top-level)', self::scopeName($scopes, $construct));
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
            $this->assertSame('(top-level)', self::scopeName(self::scopes($src), $gate), "{$rel}: the gate must be top-level");
        }
    }

    // ── Self-tests of the scanner ──────────────────────────────────────────────

    private const SELF_ALLOW = ['x.php::a' => 'test', 'x.php::K::m' => 'test', 'x.php::(top-level)' => 'test', 'x.php::P::send' => 'test'];

    public function test_the_brace_matcher_handles_methods_closures_strings_and_nesting(): void
    {
        $src = '<?php class A { function m() { $f = function () { if (1) { return "}"; } }; $s = "{$x}"; } } function top() { }';
        $s = self::scopes($src);
        $this->assertCount(3, $s['fns']);
        $this->assertCount(1, $s['classes']);
        $this->assertSame('A', $s['classes'][0]['name']);
        $this->assertSame('A::m', self::scopeName($s, $s['fns'][0]['s'] + 1));
        $this->assertSame('A::{closure}', self::scopeName($s, $s['fns'][1]['s'] + 1));
        $this->assertSame('top', self::scopeName($s, $s['fns'][2]['s'] + 1));
        $this->assertSame('(top-level)', self::scopeName($s, strlen($src) - 1));
    }

    public function test_split_line_and_commented_calls_are_still_exits(): void
    {
        $split = "<?php function a(\$to) { return mail\n   /* why not */\n(\$to, 's', 'b'); }";
        $r = self::scanFile('x.php', $split, self::SELF_ALLOW);
        $this->assertSame(1, $r['exits']);
        $this->assertCount(1, $r['bad']);
        $this->assertStringContainsString(':1 [mail()] in a', $r['bad'][0]);
        $stripe = "<?php \\Stripe\\Stripe\n::\nsetApiKey\n(STRIPE_SECRET_KEY);";
        $this->assertCount(1, self::scanFile('x.php', $stripe, self::SELF_ALLOW)['bad']);
        $this->assertSame(0, self::scanFile('x.php', '<?php // mail($a, $b, $c) in a comment', self::SELF_ALLOW)['exits']);
        $this->assertSame(0, self::scanFile('x.php', '<?php $s = "call mail(x) in a string"; $o->mail($a); Foo::mail($a); function mail_it() {}', self::SELF_ALLOW)['exits']);
    }

    public function test_a_guard_whose_result_is_ignored_does_not_count(): void
    {
        $ignored = '<?php function a($to) { \App\Core\OutboundGuard::email($to, "s", "b"); return mail($to, "s", "b"); }';
        $r = self::scanFile('x.php', $ignored, self::SELF_ALLOW);
        $this->assertCount(1, $r['bad']);
        $this->assertStringContainsString('result is used', $r['bad'][0]);
        foreach ([
            '$g = \App\Core\OutboundGuard::email($to, "s", "b"); if (!$g["send"]) return true;',
            'if (!\App\Core\OutboundGuard::sms($to, "b")) { return true; }',
            'return \App\Core\OutboundGuard::sms($to, "b") && mail($to, "s", "b");',
        ] as $usage) {
            $good = '<?php function a($to) { ' . $usage . ' return mail($to, "s", "b"); }';
            $this->assertSame([], self::scanFile('x.php', $good, self::SELF_ALLOW)['bad'], $usage);
        }
    }

    public function test_a_guard_in_another_method_or_after_the_exit_does_not_count(): void
    {
        $other = '<?php class K { function g() { $x = \App\Core\OutboundGuard::email("a", "s", "b"); } function m($to) { return mail($to, "s", "b"); } }';
        $r = self::scanFile('x.php', $other, self::SELF_ALLOW);
        $this->assertCount(1, $r['bad']);
        $this->assertStringContainsString('in K::m', $r['bad'][0]);
        $late = '<?php function a($to) { $r = mail($to, "s", "b"); $g = \App\Core\OutboundGuard::email($to, "s", "b"); return $r; }';
        $this->assertCount(1, self::scanFile('x.php', $late, self::SELF_ALLOW)['bad']);
        $closure = '<?php function a($to) { $g = \App\Core\OutboundGuard::email($to, "s", "b"); $f = function () use ($to) { return mail($to, "s", "b"); }; }';
        $this->assertCount(1, self::scanFile('x.php', $closure, self::SELF_ALLOW)['bad'], 'a closure is its own scope');
    }

    public function test_an_exit_outside_the_allow_list_fails_even_when_guarded(): void
    {
        $src = '<?php function notListed($to) { $g = \App\Core\OutboundGuard::email($to, "s", "b"); if (!$g["send"]) return true; return mail($g["to"], "s", "b"); }';
        $r = self::scanFile('x.php', $src, self::SELF_ALLOW);
        $this->assertCount(1, $r['bad']);
        $this->assertStringContainsString('not an allow-listed adapter', $r['bad'][0]);
    }

    public function test_stripe_key_api_push_and_top_level_rules(): void
    {
        // a Stripe key must be wrapped inside the call's arguments
        $this->assertCount(1, self::scanFile('x.php', '<?php \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);', self::SELF_ALLOW)['bad']);
        $this->assertSame([], self::scanFile('x.php', '<?php \Stripe\Stripe::setApiKey(\App\Core\OutboundGuard::stripeKey(STRIPE_SECRET_KEY));', self::SELF_ALLOW)['bad']);
        $this->assertCount(1, self::scanFile('x.php', '<?php class K { function m() { return new \Stripe\StripeClient(STRIPE_SECRET_KEY); } }', self::SELF_ALLOW)['bad']);
        // an API call needs a used key gate earlier in the file; the webhook's constructEvent needs the webhook gate
        $this->assertCount(1, self::scanFile('x.php', '<?php $pi = \Stripe\PaymentIntent::create(["amount" => 1]);', self::SELF_ALLOW)['bad']);
        $ok = "<?php\n\\Stripe\\Stripe::setApiKey(\\App\\Core\\OutboundGuard::stripeKey(STRIPE_SECRET_KEY));\n\$pi = \\Stripe\\PaymentIntent::create(['amount' => 1]);\n";
        $this->assertSame([], self::scanFile('x.php', $ok, self::SELF_ALLOW)['bad']);
        $this->assertCount(1, self::scanFile('x.php', '<?php $e = \Stripe\Webhook::constructEvent($p, $s, $k);', self::SELF_ALLOW)['bad']);
        $this->assertCount(1, self::scanFile('x.php', "<?php\n\\App\\Core\\OutboundGuard::webhookRefusal(true, null);\n\$e = \\Stripe\\Webhook::constructEvent(\$p, \$s, \$k);", self::SELF_ALLOW)['bad'], 'the gate result must be used');
        $this->assertSame([], self::scanFile('x.php', "<?php\nif (\\App\\Core\\OutboundGuard::webhookRefusal(true, null) !== null) { exit; }\n\$e = \\Stripe\\Webhook::constructEvent(\$p, \$s, \$k);", self::SELF_ALLOW)['bad']);
        // a curl in a method that targets a push host is an exit; a token exchange to another host is not
        $push = "<?php class P { const SEND = 'https://fcm.googleapis.com/x'; const TOK = 'https://oauth2.googleapis.com/t';\n"
              . "static function send() { \$ch = curl_init(); curl_setopt(\$ch, CURLOPT_URL, self::SEND); }\n"
              . "static function token() { \$ch = curl_init(); curl_setopt(\$ch, CURLOPT_URL, self::TOK); } }";
        $r = self::scanFile('x.php', $push, self::SELF_ALLOW);
        $this->assertSame(1, $r['exits']);
        $this->assertStringContainsString(':2 [network send curl_init()] in P::send', $r['bad'][0]);
        // a fresh PHPMailer send in a new file is caught
        $pm = '<?php function a() { $m = new \PHPMailer\PHPMailer\PHPMailer(true); $m->addAddress("x"); return $m->send(); }';
        $this->assertCount(1, self::scanFile('x.php', $pm, self::SELF_ALLOW)['bad']);
        // both the Twilio host literal and the HTTP read to it are flagged
        $this->assertCount(2, self::scanFile('x.php', '<?php function a() { return file_get_contents("https://api.twilio.com/x"); }', self::SELF_ALLOW)['bad']);
    }
}
