<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Sales/Services/UnclaimedReplyService.php';

/**
 * InboundAlertService — tell the owner when a client emails (Yui, 2026-11-10).
 *
 * Inbound mail never sent a push, so a law office's balance request sat unseen. Called at the end of the office@ and iCloud
 * polls: client mail stored in the last day that has not alerted yet goes out as one push from Yui, several at once as one
 * push. Each message is claimed in inbound_alerts first (migration 1449), so overlapping polls alert it once. Never automated
 * mail, mail filed by the owner's own answer, or mail already handled. Overnight it waits and arrives as one push in the morning.
 * Switch: ops_settings.inbox_alerts (off by default). Never throws: the mail poll must not fail because of a push.
 */
final class InboundAlertService
{
    public const FRESH_HOURS = 24;
    public const QUIET_FROM = 21;
    public const QUIET_UNTIL = 7;
    private const BATCH = 50;

    private PDO $db;
    /** @var callable(int[], string): void */
    private $send;

    public function __construct(PDO $db, ?callable $send = null)
    {
        $this->db = $db;
        $this->send = $send ?? static function (array $userIds, string $body): void {
            if (!class_exists('PushDispatcher')) require_once dirname(__DIR__, 3) . '/Services/Push/PushDispatcher.php';
            PushDispatcher::notifyUsers($userIds, 'Yui', $body, ['open' => 'team', 'head' => 'yui', 'type' => 'client_email']);
        };
    }

    /** @return array{alerted: int, held: bool} */
    public function run(?string $now = null): array
    {
        $none = ['alerted' => 0, 'held' => false];
        try {
            if (!$this->on()) return $none;
            $t = $now !== null ? (strtotime($now) ?: time()) : time();
            $hour = (int)date('G', $t);
            if ($hour >= self::QUIET_FROM || $hour < self::QUIET_UNTIL) return ['alerted' => 0, 'held' => true];
            $to = array_map('intval', $this->db->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN) ?: []);
            if (!$to) return $none;

            $st = $this->db->prepare("SELECT m.message_key, m.from_addr, m.from_name, m.subject, m.snippet FROM sales_messages m
                                      LEFT JOIN inbound_alerts a ON a.message_key = m.message_key
                                      WHERE a.message_key IS NULL AND m.direction = 'inbound' AND m.channel = 'email' AND m.handled_at IS NULL
                                        AND (m.joined_by IS NULL OR m.joined_by <> 'confirmed') AND m.sent_at >= ?
                                      ORDER BY m.sent_at, m.id LIMIT " . self::BATCH);
            $st->execute([date('Y-m-d H:i:s', $t - self::FRESH_HOURS * 3600)]);
            $claim = $this->db->prepare("INSERT IGNORE INTO inbound_alerts (message_key, alerted_at) VALUES (?, ?)");
            $mine = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (UnclaimedReplyService::isAutomated((string)$r['from_addr'], (string)$r['subject'], (string)$r['snippet'])) continue;
                $claim->execute([(string)$r['message_key'], date('Y-m-d H:i:s', $t)]);
                if ($claim->rowCount() > 0) $mine[] = $r;
            }
            if (!$mine) return $none;
            ($this->send)($to, self::body($mine));
            return ['alerted' => count($mine), 'held' => false];
        } catch (Throwable $e) {
            error_log('[inbound alert] ' . $e->getMessage());
            return $none;
        }
    }

    /** "Pat Lee: What do we owe?", or "3 client emails: Pat Lee, Kim Fox and 1 more". */
    public static function body(array $rows): string
    {
        $who = [];
        foreach ($rows as $r) {
            $n = self::line((string)($r['from_name'] ?? ''));
            $who[$n !== '' ? $n : (string)$r['from_addr']] = true;
        }
        $names = array_keys($who);
        if (count($rows) === 1) {
            $subject = self::line((string)($rows[0]['subject'] ?? ''));
            return mb_substr($names[0] . ($subject !== '' ? ': ' . $subject : ' emailed you'), 0, 140);
        }
        $shown = array_slice($names, 0, 2);
        $rest = count($names) - count($shown);
        $list = implode(', ', $shown);
        if ($rest > 0) $list .= ' and ' . $rest . ' more';
        return mb_substr(count($rows) . ' client emails: ' . $list, 0, 140);
    }

    private static function line(string $s): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', trim($s, " \t\r\n\"'")));
    }

    private function on(): bool
    {
        $st = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'inbox_alerts' LIMIT 1");
        $st->execute();
        if (!in_array(strtolower(trim((string)$st->fetchColumn())), ['1', 'on', 'yes', 'true'], true)) return false;
        $this->db->query('SELECT message_key FROM inbound_alerts LIMIT 0');
        return true;
    }
}
