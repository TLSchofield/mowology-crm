<?php
declare(strict_types=1);

namespace App\Core;

/**
 * OutboundGuard — the one gate every outbound side effect passes through.
 *
 * A second copy of this app (a "test tenant" on its own subdomain and database) must be
 * impossible to use to message real clients, charge real cards, or leak into Mowology.
 * Production is unchanged: with TENANT_MODE undefined (or 'live') every method here is an
 * exact pass-through that touches no file and logs nothing.
 *
 * Optional constants (secrets.php of the test tenant):
 *   TENANT_MODE               'live' (default when undefined) or 'test'. Anything other than
 *                             'live' is treated as 'test' — a typo fails safe.
 *   SANDBOX_MAIL_SINK         one email address every test-mode email is redirected to.
 *   SANDBOX_ALLOW_RECIPIENTS  array of exact email addresses / SMS gateway addresses allowed
 *                             to receive REAL messages in test mode (the owner's own inbox).
 *   SANDBOX_ALLOW_CRONS       array of cron names (as passed to cronGate) allowed to run.
 *   SANDBOX_LOG_DIR           where the JSON-lines log goes (default app/Storage/sandbox).
 *
 * In 'test' mode:
 *   EMAIL   redirected to SANDBOX_MAIL_SINK (never the original To/Cc), subject prefixed
 *           "[TEST -> original@recipient]", a banner naming the original recipients prepended;
 *           no sink → nothing is sent, only logged. Allow-listed recipients pass unchanged.
 *   SMS     never sent unless the gateway address is allow-listed; otherwise logged.
 *   PUSH    blocked and logged.
 *   CRON    senders / chargers / campaigns refuse to run unless named in SANDBOX_ALLOW_CRONS.
 *   STRIPE  a key that is not sk_test_* / rk_test_* is refused with a RuntimeException.
 *   TRIPWIRE  TENANT_MODE 'test' on the production database name or the production host
 *           blocks EVERYTHING, allow-list included, and logs loudly.
 *
 * The class has no side effects at load. configure()/reset() exist for unit tests only
 * (constants cannot be redefined inside one PHP process).
 */
final class OutboundGuard
{
    public const MODE_LIVE = 'live';
    public const MODE_TEST = 'test';

    public const PRODUCTION_DB    = 'mowology_landscape_crm';
    public const PRODUCTION_HOSTS = ['mowology.ca', 'www.mowology.ca'];

    /** Longest message preview written to the log. */
    public const PREVIEW_CHARS = 200;

    /** @var array<string, mixed>|null unit-test overrides; null = read constants */
    private static ?array $override = null;

    /** The tripwire is reported loudly once per process. */
    private static bool $tripwireReported = false;

    private function __construct()
    {
    }

    // ── Test hooks ─────────────────────────────────────────────────────────────

    /**
     * Unit tests only: override what would otherwise come from constants.
     * Keys: TENANT_MODE, SANDBOX_MAIL_SINK, SANDBOX_ALLOW_RECIPIENTS, SANDBOX_ALLOW_CRONS,
     * SANDBOX_LOG_DIR, DB_NAME, HTTP_HOST.
     */
    public static function configure(array $settings): void
    {
        self::$override = $settings;
        self::$tripwireReported = false;
    }

    public static function reset(): void
    {
        self::$override = null;
        self::$tripwireReported = false;
    }

    // ── Mode ───────────────────────────────────────────────────────────────────

    public static function mode(): string
    {
        $raw = self::setting('TENANT_MODE');
        if ($raw === null || $raw === '' || $raw === self::MODE_LIVE) {
            return self::MODE_LIVE;
        }
        return self::MODE_TEST;
    }

    public static function isLive(): bool
    {
        return self::mode() === self::MODE_LIVE;
    }

    public static function isTest(): bool
    {
        return !self::isLive();
    }

    /**
     * Why everything is blocked, or null. Only ever non-null in test mode: a test tenant
     * pointed at the production database or served from the production host.
     */
    public static function tripped(): ?string
    {
        if (self::isLive()) {
            return null;
        }
        $db = self::setting('DB_NAME');
        if (is_string($db) && strtolower(trim($db)) === self::PRODUCTION_DB) {
            return self::reportTripwire('TENANT_MODE is test but DB_NAME is the production database');
        }
        $host = self::requestHost();
        if ($host !== '' && in_array($host, self::PRODUCTION_HOSTS, true)) {
            return self::reportTripwire("TENANT_MODE is test but the request host is {$host}");
        }
        return null;
    }

    public static function sink(): ?string
    {
        $sink = self::setting('SANDBOX_MAIL_SINK');
        if (!is_string($sink)) {
            return null;
        }
        $sink = trim($sink);
        return ($sink !== '' && filter_var($sink, FILTER_VALIDATE_EMAIL)) ? $sink : null;
    }

    /**
     * May this exact address (email, or a phone gateway such as 7788469273@msg.telus.com)
     * receive a REAL message in test mode? The mail sink itself always may, which is what
     * makes a redirected message safe to pass through a second guard on a fallback path.
     */
    public static function isAllowedRecipient(string $address): bool
    {
        if (self::isLive()) {
            return true;
        }
        if (self::tripped() !== null) {
            return false;
        }
        $key = strtolower(trim($address));
        if ($key === '') {
            return false;
        }
        $sink = self::sink();
        if ($sink !== null && strtolower($sink) === $key) {
            return true;
        }
        foreach (self::allowList('SANDBOX_ALLOW_RECIPIENTS') as $allowed) {
            if ($allowed === $key) {
                return true;
            }
        }
        return false;
    }

    // ── Email ──────────────────────────────────────────────────────────────────

    /**
     * Decide what an email send may do.
     *
     * @param  string[] $cc
     * @return array{send: bool, to: string, cc: string[], subject: string, body: string, redirected: bool, reason: ?string}
     *         Live mode: the same inputs, send=true, redirected=false — always.
     */
    public static function email(string $to, string $subject, string $body, array $cc = []): array
    {
        $pass = ['send' => true, 'to' => $to, 'cc' => $cc, 'subject' => $subject, 'body' => $body, 'redirected' => false, 'reason' => null];
        if (self::isLive()) {
            return $pass;
        }

        $targets = array_merge([$to], array_values($cc));
        $targetList = implode(', ', $targets);

        if (($why = self::tripped()) !== null) {
            self::log('email', $targetList, 'blocked', $subject, ['reason' => 'tripwire: ' . $why]);
            return ['send' => false, 'to' => '', 'cc' => [], 'subject' => $subject, 'body' => $body, 'redirected' => false, 'reason' => 'tripwire: ' . $why];
        }

        $allAllowed = true;
        foreach ($targets as $t) {
            if (!self::isAllowedRecipient((string)$t)) {
                $allAllowed = false;
                break;
            }
        }
        if ($allAllowed) {
            return $pass;
        }

        $sink = self::sink();
        if ($sink === null) {
            self::log('email', $targetList, 'blocked', $subject, ['reason' => 'no SANDBOX_MAIL_SINK']);
            return ['send' => false, 'to' => '', 'cc' => [], 'subject' => $subject, 'body' => $body, 'redirected' => false, 'reason' => 'no SANDBOX_MAIL_SINK'];
        }

        self::log('email', $targetList, 'redirected', $subject, ['sink' => $sink]);
        return [
            'send'       => true,
            'to'         => $sink,
            'cc'         => [],
            'subject'    => self::testSubject($subject, $targets),
            'body'       => self::banner($to, $cc) . $body,
            'redirected' => true,
            'reason'     => 'redirected to sandbox sink',
        ];
    }

    /** "[TEST -> a@x, b@y] original subject" — a subject already marked is not marked again. */
    public static function testSubject(string $subject, array $targets): string
    {
        if (strpos($subject, '[TEST -> ') === 0) {
            return $subject;
        }
        $targets = array_map(static fn($t) => preg_replace('/[\r\n]+/', ' ', (string)$t), $targets);
        return '[TEST -> ' . implode(', ', $targets) . '] ' . $subject;
    }

    /** The visible banner prepended to a redirected email body (HTML). */
    public static function banner(string $to, array $cc = []): string
    {
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $line = 'TEST TENANT — this message was addressed to ' . $h($to);
        if ($cc) {
            $line .= ' (cc: ' . $h(implode(', ', $cc)) . ')';
        }
        $line .= '. It was redirected to the sandbox inbox and was NOT delivered to them.';
        return '<div style="background:#fff3cd;border:2px solid #e85d04;color:#1a1a1a;padding:12px 16px;'
            . 'margin:0 0 16px 0;font:14px/1.4 Arial,sans-serif"><strong>' . $line . '</strong></div>' . "\n";
    }

    // ── SMS ────────────────────────────────────────────────────────────────────

    /**
     * May this carrier-gateway address be texted? Live: always. Test: only when allow-listed;
     * otherwise the message is logged ("would have texted …") and the caller skips the send.
     */
    public static function sms(string $gatewayAddress, string $message): bool
    {
        if (self::isLive()) {
            return true;
        }
        if (self::isAllowedRecipient($gatewayAddress)) {
            return true;
        }
        $why = self::tripped();
        self::log('sms', $gatewayAddress, 'blocked', $message, ['reason' => $why !== null ? 'tripwire: ' . $why : 'would have texted ' . $gatewayAddress]);
        return false;
    }

    // ── Push ───────────────────────────────────────────────────────────────────

    /** May a push notification go out? Live: always. Test: never (logged). */
    public static function push(string $platform, string $deviceToken, string $title): bool
    {
        if (self::isLive()) {
            return true;
        }
        $why = self::tripped();
        self::log('push', $platform . ':' . substr($deviceToken, 0, 12) . '…', 'blocked', $title,
            ['reason' => $why !== null ? 'tripwire: ' . $why : 'push blocked in test mode']);
        return false;
    }

    // ── Cron ───────────────────────────────────────────────────────────────────

    /**
     * May a sending / charging / campaign cron run? Live: always. Test: only when its name is
     * in SANDBOX_ALLOW_CRONS (never when tripped). The decision is logged when refused.
     *
     * @param string $kind 'sender' | 'charge' | 'campaign' — for the log line only
     */
    public static function cronMayRun(string $name, string $kind = 'sender'): bool
    {
        if (self::isLive()) {
            return true;
        }
        $why = self::tripped();
        if ($why === null && in_array(strtolower(trim($name)), self::allowList('SANDBOX_ALLOW_CRONS'), true)) {
            return true;
        }
        self::log('cron', $name, 'refused', '', ['cron_kind' => $kind, 'reason' => $why !== null ? 'tripwire: ' . $why : 'not in SANDBOX_ALLOW_CRONS']);
        return false;
    }

    /**
     * The one-line gate at the top of a cron: exits (code 0) with a log line when the cron may
     * not run here. Pass-through on production.
     */
    public static function cronGate(string $name, string $kind = 'sender'): void
    {
        if (self::cronMayRun($name, $kind)) {
            return;
        }
        $line = "OutboundGuard: {$kind} cron '{$name}' refused to run on a test tenant (add it to SANDBOX_ALLOW_CRONS to allow it).";
        error_log($line);
        if (PHP_SAPI === 'cli') {
            echo $line, "\n";
        } else {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['success' => false, 'sandbox' => 'refused', 'message' => $line]);
        }
        exit(0);
    }

    // ── Stripe ─────────────────────────────────────────────────────────────────

    /**
     * The Stripe secret key a client may be built with. Live: the key, untouched. Test: only
     * a test-mode key (sk_test_… / rk_test_…) — anything else throws before a client exists.
     *
     * @throws \RuntimeException
     */
    public static function stripeKey(string $key): string
    {
        if (self::isLive()) {
            return $key;
        }
        $why = self::tripped();
        if ($why !== null) {
            self::log('stripe', self::keyLabel($key), 'refused', '', ['reason' => 'tripwire: ' . $why]);
            throw new \RuntimeException('OutboundGuard: Stripe is disabled — ' . $why);
        }
        if (preg_match('/^(sk|rk)_test_/', $key) === 1) {
            return $key;
        }
        self::log('stripe', self::keyLabel($key), 'refused', '', ['reason' => 'not a test-mode key']);
        throw new \RuntimeException('OutboundGuard: a test tenant may only use a Stripe test-mode key (sk_test_…); refusing ' . self::keyLabel($key));
    }

    // ── Log ────────────────────────────────────────────────────────────────────

    public static function logDir(): string
    {
        $dir = self::setting('SANDBOX_LOG_DIR');
        if (is_string($dir) && trim($dir) !== '') {
            return rtrim(trim($dir), '/');
        }
        $appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
        return $appRoot . '/Storage/sandbox';
    }

    /** Today's log file (one JSON object per line). */
    public static function logPath(?string $day = null): string
    {
        return self::logDir() . '/outbound-' . ($day ?? date('Y-m-d')) . '.jsonl';
    }

    /**
     * Read-only summary for an admin page: mode, tripwire, counts per kind/action and the
     * most recent entries (newest first). Never throws.
     *
     * @return array{mode: string, tripped: ?string, log_dir: string, counts: array<string, int>, recent: array<int, array>}
     */
    public static function summary(int $limit = 50): array
    {
        $out = ['mode' => self::mode(), 'tripped' => self::isLive() ? null : self::tripped(), 'log_dir' => self::logDir(), 'counts' => [], 'recent' => []];
        $files = @glob(self::logDir() . '/outbound-*.jsonl') ?: [];
        rsort($files, SORT_STRING);
        $recent = [];
        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $line) {
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }
                $k = ($row['kind'] ?? '?') . ':' . ($row['action'] ?? '?');
                $out['counts'][$k] = ($out['counts'][$k] ?? 0) + 1;
                $recent[] = $row;
            }
        }
        usort($recent, static fn(array $a, array $b): int => strcmp((string)($b['time'] ?? ''), (string)($a['time'] ?? '')));
        $out['recent'] = array_slice($recent, 0, max(0, $limit));
        return $out;
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    /** One JSON line: time, kind, target, action, preview (≤ 200 chars), extras. Never throws. */
    private static function log(string $kind, string $target, string $action, string $preview = '', array $extra = []): void
    {
        try {
            $row = array_merge([
                'time'    => date('c'),
                'kind'    => $kind,
                'target'  => $target,
                'action'  => $action,
                'preview' => self::preview($preview),
            ], $extra);
            $dir = self::logDir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            @file_put_contents(self::logPath(), json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // the log is never the reason a request fails
        }
        error_log("OutboundGuard[{$kind}] {$action}: {$target}" . (isset($extra['reason']) ? ' (' . $extra['reason'] . ')' : ''));
    }

    private static function preview(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        return function_exists('mb_substr') ? mb_substr($text, 0, self::PREVIEW_CHARS) : substr($text, 0, self::PREVIEW_CHARS);
    }

    private static function reportTripwire(string $why): string
    {
        if (!self::$tripwireReported) {
            self::$tripwireReported = true;
            self::log('tripwire', self::requestHost() ?: 'cli', 'blocked-all', '', ['reason' => $why]);
            error_log('OutboundGuard TRIPWIRE: ' . $why . ' — every outbound action is blocked.');
        }
        return $why;
    }

    private static function requestHost(): string
    {
        $host = self::setting('HTTP_HOST');
        if (!is_string($host)) {
            $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        }
        $host = strtolower(trim($host));
        if (($p = strpos($host, ':')) !== false) {
            $host = substr($host, 0, $p);
        }
        return $host;
    }

    /** @return string[] lower-cased, trimmed */
    private static function allowList(string $name): array
    {
        $raw = self::setting($name);
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            $v = strtolower(trim((string)$v));
            if ($v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }

    /** Override → constant → null. */
    private static function setting(string $name)
    {
        if (self::$override !== null) {
            return self::$override[$name] ?? null;
        }
        return defined($name) ? constant($name) : null;
    }

    private static function keyLabel(string $key): string
    {
        return $key === '' ? '(empty key)' : substr($key, 0, 8) . '…';
    }
}
