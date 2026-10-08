<?php
/**
 * QuoteViewNotifier — "Sam: Linda Nimmerrichter just opened QUO-2026-0073 ($1,758.75)".
 *
 * When a customer opens their quote link (public/customer/quote.php) for the first time,
 * Sam pushes it to the owner's iPhone. The rules (decide(), pure and unit-tested):
 *   - never for a viewer with a logged-in CRM session (Tim/staff checking the page) —
 *     quote.php doesn't even count those as views;
 *   - never within SELF_CHECK_MINUTES of the quote being sent (Tim clicking his own link);
 *   - only for a quote that is out with the customer (status sent/viewed);
 *   - only the first counted view: a quote already viewed earlier never pushes again,
 *     unless that earlier "view" fell inside the self-check window (it may have been Tim);
 *   - one push per quote, ever — recorded in activity_log (action ACTION, quote_id), and
 *     claimed under a MySQL named lock so two simultaneous opens can't both send.
 *
 * The send runs after the customer's page has been flushed (quote.php shutdown hook) and
 * never throws — a push problem must not touch the customer. It sends straight to APNs
 * (ApnsService) instead of push_queue, so it doesn't depend on the push-drain cron;
 * 410 Unregistered tokens are deactivated like PushDispatcher::drainQueue does.
 *
 * testPush() backs Sam's "Send me a test push" button: it reports whether APNs is
 * configured, how many active iOS tokens the user has, and APNs' answer per token.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/SalesDeskService.php';

class QuoteViewNotifier
{
    public const ACTION = 'sam_quote_view_push';
    public const SELF_CHECK_MINUTES = 5;
    public const TITLE = 'Sam [Sales]';   // every push names its head and department (PushHeads)

    private PDO $db;
    /** @var callable(string,string,string,array):array */
    private $sender;
    /** @var callable():bool */
    private $configured;

    /**
     * @param callable|null $sender     fn(token, title, body, data): ApnsService::send()-shaped result
     * @param callable|null $configured fn(): bool — APNs credentials present
     */
    public function __construct(PDO $db, ?callable $sender = null, ?callable $configured = null)
    {
        $this->db = $db;
        $this->sender = $sender ?? static function (string $token, string $title, string $body, array $data): array {
            if (!class_exists('ApnsService')) {
                require_once APP_ROOT . '/Services/Push/ApnsService.php';
            }
            return ApnsService::send($token, $title, $body, $data);
        };
        $this->configured = $configured ?? static function (): bool {
            if (!class_exists('ApnsService')) {
                require_once APP_ROOT . '/Services/Push/ApnsService.php';
            }
            return ApnsService::isConfigured();
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The rules (pure)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Should this open push? $quote is the row as it was BEFORE this request's view update
     * (status, sent_at, viewed_at).
     * @return array{notify: bool, reason: string}
     */
    public static function decide(array $quote, bool $staffViewer, int $now): array
    {
        if ($staffViewer) {
            return ['notify' => false, 'reason' => 'staff_viewer'];
        }
        if (!in_array((string)($quote['status'] ?? ''), ['sent', 'viewed'], true)) {
            return ['notify' => false, 'reason' => 'status'];
        }
        $sent = !empty($quote['sent_at']) ? strtotime((string)$quote['sent_at']) : false;
        if ($sent === false) {
            return ['notify' => false, 'reason' => 'not_sent'];
        }
        $window = self::SELF_CHECK_MINUTES * 60;
        if ($now < $sent + $window) {
            return ['notify' => false, 'reason' => 'self_check_window'];
        }
        if (!empty($quote['viewed_at'])) {
            $viewed = strtotime((string)$quote['viewed_at']);
            // An earlier first view inside the self-check window may have been Tim, so it
            // doesn't use up the push. Any other earlier view does.
            $inWindow = $viewed !== false && $viewed >= $sent && $viewed < $sent + $window;
            if (!$inWindow) {
                return ['notify' => false, 'reason' => 'already_viewed'];
            }
        }
        return ['notify' => true, 'reason' => 'first_view'];
    }

    /** The push text for one quote row (contact names, company_name, quote_number, amount). */
    public static function message(array $q): array
    {
        $who = trim(trim((string)($q['first_name'] ?? '')) . ' ' . trim((string)($q['last_name'] ?? '')));
        if ($who === '') $who = trim((string)($q['company_name'] ?? ''));
        if ($who === '') $who = 'A customer';
        $number = trim((string)($q['quote_number'] ?? ''));
        $what = $number !== '' ? $number : 'their quote';
        $amount = (float)($q['amount'] ?? 0);
        $body = $who . ' just opened ' . $what . ($amount > 0 ? ' (' . self::money($amount) . ')' : '');
        return [
            'title' => self::TITLE,
            'body'  => $body,
            'data'  => ['open' => 'team', 'head' => 'sam', 'quote_id' => (int)($q['id'] ?? 0)],
        ];
    }

    public static function money(float $v): string
    {
        return '$' . number_format($v, 2);
    }

    /**
     * Is the browser opening the quote logged in to the CRM? Reads the session only when
     * the CRM session cookie is present (a customer never gets a session started), and
     * closes it straight away so the customer page holds no session lock.
     */
    public static function isStaffViewer(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return !empty($_SESSION['user_id']);
        }
        if (empty($_COOKIE['MOWOSESS']) || headers_sent()) {
            return false;
        }
        try {
            require_once PUBLIC_ROOT . '/app_config/session_config.php';
            $staff = session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id']);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            return $staff;
        } catch (Throwable $e) {
            error_log('[QuoteViewNotifier] staff check failed: ' . $e->getMessage());
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Sending
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Push the first view of $quoteId to the owner(s). Never throws.
     * @return array{sent: int, tokens: int, skipped: ?string}
     */
    public function notifyFirstView(int $quoteId): array
    {
        $out = ['sent' => 0, 'tokens' => 0, 'skipped' => null];
        $locked = false;
        try {
            $locked = $this->lock($quoteId);
            if ($this->alreadyNotified($quoteId)) {
                $out['skipped'] = 'already_notified';
                return $out;
            }
            $q = $this->loadQuote($quoteId);
            if (!$q) {
                $out['skipped'] = 'quote_missing';
                return $out;
            }
            if (stripos(($q['first_name'] ?? '') . ' ' . ($q['last_name'] ?? '') . ' ' . ($q['title'] ?? ''), 'ZZTEST') !== false) {
                $out['skipped'] = 'test_record';
                return $out;
            }
            $logId = $this->claim($quoteId);

            $msg = self::message($q);
            $results = $this->sendToUsers($this->ownerIds(), $msg['title'], $msg['body'], $msg['data']);
            $out['tokens'] = count($results);
            $out['sent'] = count(array_filter($results, fn($r) => $r['success']));
            if (!$results) {
                $out['skipped'] = ($this->configured)() ? 'no_tokens' : 'apns_not_configured';
            }
            $this->finish($logId, $msg['body'], $out);
        } catch (Throwable $e) {
            error_log('[QuoteViewNotifier] quote ' . $quoteId . ': ' . $e->getMessage());
            $out['skipped'] = 'error';
        } finally {
            if ($locked) $this->unlock($quoteId);
        }
        return $out;
    }

    /**
     * Sam's "Send me a test push" — a full diagnosis for the user pressing it.
     */
    public function testPush(int $userId): array
    {
        $configured = (bool)($this->configured)();
        $tokens = $this->iosTokens([$userId]);
        $out = [
            'ok'              => false,
            'apns_configured' => $configured,
            'sandbox'         => defined('APNS_SANDBOX') && APNS_SANDBOX,
            'tokens'          => count($tokens),
            'results'         => [],
        ];
        if (!$configured) {
            $out['message'] = "APNs isn't configured on the server (APNS_TEAM_ID, APNS_KEY_ID, APNS_PRIVATE_KEY in secrets.php).";
            return $out;
        }
        if (!$tokens) {
            $out['message'] = 'No active iPhone registered for you. Open the Mowology app on your phone, allow notifications, then try again.';
            return $out;
        }
        $out['results'] = $this->sendTo($tokens, self::TITLE, 'Test push — this is how I tell you a customer opened their quote.',
            ['open' => 'team', 'head' => 'sam', 'test' => 1]);
        $ok = count(array_filter($out['results'], fn($r) => $r['success']));
        $out['ok'] = $ok > 0;
        $out['message'] = $ok > 0
            ? "Apple accepted the push for {$ok} of " . count($tokens) . ' device' . (count($tokens) === 1 ? '' : 's') . ' — check your phone.'
            : 'Apple refused every device — see the reasons below.';
        return $out;
    }

    /**
     * Send to every active iOS token of $userIds. Deactivates 410 tokens.
     * @return list<array{user_id:int, token:string, success:bool, http_code:int, error:?string, deactivated:bool}>
     */
    public function sendToUsers(array $userIds, string $title, string $body, array $data): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds || !($this->configured)()) {
            return [];
        }
        return $this->sendTo($this->iosTokens($userIds), $title, $body, $data);
    }

    private function sendTo(array $tokens, string $title, string $body, array $data): array
    {
        $results = [];
        foreach ($tokens as $t) {
            $token = (string)$t['device_token'];
            try {
                $r = (array)($this->sender)($token, $title, $body, $data);
            } catch (Throwable $e) {
                $r = ['success' => false, 'http_code' => 0, 'error' => $e->getMessage(), 'unregistered' => false];
            }
            $deactivated = false;
            if (!empty($r['unregistered'])) {
                $this->db->prepare("UPDATE device_tokens SET is_active = 0 WHERE device_token = ?")->execute([$token]);
                $deactivated = true;
            }
            $results[] = [
                'user_id'     => (int)$t['user_id'],
                'token'       => substr($token, 0, 8) . '…',
                'success'     => !empty($r['success']),
                'http_code'   => (int)($r['http_code'] ?? 0),
                'error'       => $r['error'] ?? null,
                'deactivated' => $deactivated,
            ];
        }
        return $results;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data
    // ─────────────────────────────────────────────────────────────────────────

    /** Active admins — the owner. */
    public function ownerIds(): array
    {
        return array_map('intval', array_column(
            $this->db->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_ASSOC), 'id'
        ));
    }

    private function iosTokens(array $userIds): array
    {
        if (!$userIds) return [];
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $s = $this->db->prepare("
            SELECT user_id, device_token FROM device_tokens
            WHERE user_id IN ({$in}) AND is_active = 1 AND platform = 'ios'
            ORDER BY id
        ");
        $s->execute(array_values($userIds));
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The quote with the customer it went to: the chosen recipient (quotes.contact_id),
     * else the property's site contact, else the company's primary contact.
     */
    public function loadQuote(int $quoteId): ?array
    {
        $amt = SalesDeskService::AMOUNT_SQL;
        $s = $this->db->prepare("
            SELECT q.id, q.quote_number, q.title, {$amt} AS amount,
                   c.first_name, c.last_name, co.company_name
            FROM quotes q
            LEFT JOIN properties p ON p.id = q.property_id
            LEFT JOIN companies co ON co.id = q.company_id
            LEFT JOIN contacts c ON c.id = COALESCE(q.contact_id, p.site_contact_id, co.primary_contact_id)
            WHERE q.id = ?
        ");
        $s->execute([$quoteId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function alreadyNotified(int $quoteId): bool
    {
        $s = $this->db->prepare("SELECT 1 FROM activity_log WHERE quote_id = ? AND action = ? LIMIT 1");
        $s->execute([$quoteId, self::ACTION]);
        return (bool)$s->fetchColumn();
    }

    private function claim(int $quoteId): int
    {
        $this->db->prepare("INSERT INTO activity_log (quote_id, action, details, created_at) VALUES (?, ?, ?, ?)")
            ->execute([$quoteId, self::ACTION, 'Sam: first view — sending', date('Y-m-d H:i:s')]);
        return (int)$this->db->lastInsertId();
    }

    private function finish(int $logId, string $body, array $out): void
    {
        $what = $out['skipped'] !== null
            ? "not pushed ({$out['skipped']})"
            : "pushed to {$out['sent']} of {$out['tokens']} device" . ($out['tokens'] === 1 ? '' : 's');
        $this->db->prepare("UPDATE activity_log SET details = ? WHERE id = ?")
            ->execute(['Sam: ' . $body . ' — ' . $what, $logId]);
    }

    private function isMysql(): bool
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    private function lock(int $quoteId): bool
    {
        if (!$this->isMysql()) return false;
        $s = $this->db->prepare("SELECT GET_LOCK(?, 5)");
        $s->execute(['sam_view_push_' . $quoteId]);
        return (int)$s->fetchColumn() === 1;
    }

    private function unlock(int $quoteId): void
    {
        try {
            $s = $this->db->prepare("SELECT RELEASE_LOCK(?)");
            $s->execute(['sam_view_push_' . $quoteId]);
        } catch (Throwable $e) { /* the lock dies with the connection anyway */ }
    }
}
