<?php
/**
 * YuiDraftService — Yui's drafts, and what Tim did with them (yui_actions, migration 1191).
 *
 * Yui NEVER sends on her own: send() runs only from Tim's click on her card.
 *   - Inbox replies: Claude drafts on Tim's click (draftReply) — capped per day, the thread
 *     from sales_messages as context, the house copy rules as the system prompt, tokens recorded.
 *   - Renewals, check-ins, arrears: a template in Tim's voice; once Tim edits and sends one,
 *     his version (the client's details put back as {placeholders}) becomes the next draft.
 *   - Sending goes through the CRM's messaging functions only (sendEmail / sendSms — CLAUDE.md
 *     rule 9). Email goes out from office@ via sendEmail (Reply-To office@). Texts are checked
 *     first (rule 11): ≤160 characters, no links, the office number, "check your email".
 *   - Every send is logged to sales_messages (so the reply counts as answered) and yui_actions
 *     (sent unchanged vs edited — Yui's lessons and badges). "Handled" is logged there too.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/YuiRules.php';

class YuiDraftService
{
    public const CLAUDE_DAILY_CAP = 20;
    public const CLAUDE_MODEL = 'claude-sonnet-5-5';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Tim's latest edited version of this template (placeholders kept), if he has taught Yui one. */
    public function learned(string $template, string $channel = 'email'): ?string
    {
        if ($template === 'reply') return null;
        try {
            $s = $this->db->prepare("
                SELECT learned_body FROM yui_actions
                WHERE template_key = ? AND channel = ? AND status = 'edited' AND learned_body IS NOT NULL AND learned_body <> ''
                ORDER BY decided_at DESC, id DESC LIMIT 1
            ");
            $s->execute([$template, $channel]);
            $v = $s->fetchColumn();
            return $v !== false ? (string)$v : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** The latest messages with one contact (office@ email both ways + texts), newest first. */
    public function thread(int $contactId, int $limit = 8): array
    {
        if ($contactId <= 0) return [];
        try {
            $s = $this->db->prepare("
                SELECT direction, channel, subject, snippet, sent_at FROM sales_messages
                WHERE contact_id = ? ORDER BY sent_at DESC, id DESC LIMIT " . max(1, min(30, $limit)) . "
            ");
            $s->execute([$contactId]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function claudeToday(): int
    {
        try {
            return (int)$this->db->query("SELECT COUNT(*) FROM yui_actions WHERE drafted_by = 'claude' AND created_at >= CURDATE()")->fetchColumn();
        } catch (Throwable $e) {
            return self::CLAUDE_DAILY_CAP;
        }
    }

    public function claudeLeft(): int
    {
        return max(0, self::CLAUDE_DAILY_CAP - $this->claudeToday());
    }

    /** Claude drafts a reply to the client's last message — Tim's click only, inbox items only. */
    public function draftReply(array $item, string $owner, int $userId): array
    {
        if (($item['section'] ?? '') !== 'inbox') return ['ok' => false, 'message' => 'I only draft replies to client messages.'];
        if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '') {
            return ['ok' => false, 'message' => 'No Anthropic key set up — write this one yourself.'];
        }
        if ($this->claudeToday() >= self::CLAUDE_DAILY_CAP) {
            return ['ok' => false, 'message' => "That's today's " . self::CLAUDE_DAILY_CAP . " drafts — write this one yourself."];
        }
        $examples = [];
        try {
            $examples = $this->db->query("
                SELECT final_body FROM yui_actions
                WHERE channel = 'email' AND status IN ('sent', 'edited') AND final_body IS NOT NULL
                ORDER BY decided_at DESC LIMIT 3
            ")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { /* no examples yet */ }

        $prompt = YuiRules::replyPrompt($item, $this->thread((int)$item['contact_id'], 8), $owner, $examples);
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['content-type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01'],
            CURLOPT_POSTFIELDS     => json_encode([
                'model'      => self::CLAUDE_MODEL,
                'max_tokens' => 600,
                'system'     => $prompt['system'],
                'messages'   => [['role' => 'user', 'content' => $prompt['user']]],
            ]),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        $text = trim((string)($d['content'][0]['text'] ?? ''));
        $in = (int)($d['usage']['input_tokens'] ?? 0);
        $out = (int)($d['usage']['output_tokens'] ?? 0);

        $this->db->prepare("
            INSERT INTO yui_actions (item_key, kind, template_key, contact_id, channel, drafted_by, suggested_body, status,
                                     input_tokens, output_tokens, error, decided_by)
            VALUES (?, ?, 'reply', ?, 'email', 'claude', ?, 'drafted', ?, ?, ?, ?)
        ")->execute([
            $item['key'], (string)($item['kind'] ?? 'client_reply'), (int)$item['contact_id'] ?: null, $text !== '' ? $text : null,
            $in ?: null, $out ?: null, $code === 200 && $text !== '' ? null : 'HTTP ' . $code, $userId ?: null,
        ]);
        if ($code !== 200 || $text === '') {
            return ['ok' => false, 'message' => "I couldn't draft that one just now — write it yourself or try again later."];
        }
        return ['ok' => true, 'body' => $text, 'drafts_left' => $this->claudeLeft()];
    }

    /**
     * Send Tim's message. $item is re-read on the server (YuiDeskService::find()), so the
     * recipient comes from the CRM, never from the browser.
     * @param array $in channel, subject, body, suggested_body, drafted_by
     */
    public function send(array $item, array $in, array $user, string $owner): array
    {
        $to = $item['to'] ?? null;
        if (!$to || empty($item['template'])) return ['ok' => false, 'message' => 'There is nobody to write to on that one.'];
        $channel = ($in['channel'] ?? 'email') === 'sms' ? 'sms' : 'email';
        $body = trim(str_replace("\r\n", "\n", (string)($in['body'] ?? '')));
        $subject = trim((string)($in['subject'] ?? ''));
        if ($body === '') return ['ok' => false, 'message' => 'The message is empty.'];
        $cid = (int)$to['contact_id'];
        $name = $to['first_name'] !== '' ? $to['first_name'] : $to['name'];

        if ($channel === 'sms') {
            if ($p = YuiRules::smsProblems($body)) return ['ok' => false, 'message' => 'Text not sent: ' . implode('; ', $p) . '.'];
            if ($to['phone'] === '') return ['ok' => false, 'message' => 'No phone number on file for ' . $name . '.'];
            if (!$cid || !function_exists('hasSmConsent') || !hasSmConsent($cid)) {
                return ['ok' => false, 'message' => $name . " hasn't agreed to texts — send an email instead."];
            }
            $r = sendSms($to['phone'], $body);
            $dest = $to['phone'];
        } else {
            $dest = $to['email'];
            if ($dest === '') return ['ok' => false, 'message' => 'No email address on file for ' . $name . '.'];
            if ($subject === '') $subject = (string)(YuiRules::draft($item, $owner)['subject'] ?? 'Mowology');
            $r = sendEmail($dest, $subject, $this->emailHtml($body));
        }
        $ok = !empty($r['success']);
        $suggested = trim(str_replace("\r\n", "\n", (string)($in['suggested_body'] ?? '')));
        $edited = SamFollowupService::isEdited($suggested, $body);
        $learned = $ok && $edited && $channel === 'email' && $item['template'] !== 'reply'
            ? YuiRules::learnFrom($body, $item, $owner) : null;
        $this->record($item, $channel, $ok ? ($edited ? 'edited' : 'sent') : 'failed', (int)($user['id'] ?? 0),
            in_array($in['drafted_by'] ?? '', ['template', 'learned', 'claude'], true) ? $in['drafted_by'] : 'template',
            $suggested ?: null, $channel === 'email' ? $subject : null, $body, $learned, $ok ? null : 'send failed');
        if (!$ok) return ['ok' => false, 'message' => 'That didn\'t go out — check the address and try again.'];

        $this->logMessage($cid, $channel, $dest, $subject, $body);
        if (function_exists('logActivityExtended')) {
            try {
                logActivityExtended((int)($user['id'] ?? 0), 'Client message sent', ($channel === 'sms' ? 'Text' : 'Email') . " to {$dest} (contact #{$cid}) from Yui's card");
            } catch (Throwable $e) { /* the activity log is a bonus */ }
        }
        $hi = $owner !== '' ? ", {$owner}" : '';
        return ['ok' => true, 'message' => $edited ? "Sent{$hi} — and I'll write it your way next time." : "Sent{$hi}. I'll watch for their reply."];
    }

    /** "Handled": Yui stops showing the item (and Charlie's copy is dismissed, if he has it). */
    public function handled(string $key, string $kind, ?int $contactId, array $user): array
    {
        $this->db->prepare("
            INSERT INTO yui_actions (item_key, kind, contact_id, status, decided_by, decided_at)
            VALUES (?, ?, ?, 'handled', ?, NOW())
        ")->execute([$key, mb_substr($kind, 0, 30) ?: null, $contactId ?: null, (int)($user['id'] ?? 0) ?: null]);
        try {
            $file = dirname(__DIR__, 2) . '/ChiefOfStaff/Services/CharlieDeskService.php';
            if (is_file($file)) {
                require_once $file;
                $charlie = new CharlieDeskService($this->db);
                if ($charlie->ready()) $charlie->act($key, 'dismiss');   // "I don't know that one" is fine
            }
        } catch (Throwable $e) { /* Charlie learning is a bonus */ }
        return ['ok' => true, 'message' => 'Marked handled.'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lessons, badges, brain
    // ─────────────────────────────────────────────────────────────────────────

    /** Tim's sends through Yui, newest first: true = sent unchanged. */
    public function decisions(int $limit = 200): array
    {
        try {
            return array_map(fn($s) => $s === 'sent', $this->db->query("
                SELECT status FROM yui_actions WHERE status IN ('sent', 'edited')
                ORDER BY decided_at DESC, id DESC LIMIT " . max(1, min(500, $limit))
            )->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array{unchanged: int, edited: int, handled: int, lessons: int} */
    public function counts(): array
    {
        $out = ['unchanged' => 0, 'edited' => 0, 'handled' => 0, 'lessons' => 0];
        try {
            foreach ($this->db->query("SELECT status, COUNT(*) FROM yui_actions WHERE status IN ('sent', 'edited', 'handled') GROUP BY status")->fetchAll(PDO::FETCH_NUM) as $r) {
                $out[$r[0] === 'sent' ? 'unchanged' : $r[0]] = (int)$r[1];
            }
            $out['lessons'] = (int)$this->db->query("
                SELECT COUNT(DISTINCT CONCAT(template_key, ':', channel)) FROM yui_actions
                WHERE status = 'edited' AND learned_body IS NOT NULL AND learned_body <> ''
            ")->fetchColumn();
        } catch (Throwable $e) { /* not yet */ }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function record(array $item, string $channel, string $status, int $userId, string $draftedBy, ?string $suggested,
                            ?string $subject, ?string $body, ?string $learned, ?string $error): void
    {
        $this->db->prepare("
            INSERT INTO yui_actions (item_key, kind, template_key, contact_id, channel, drafted_by, suggested_body,
                                     final_subject, final_body, learned_body, status, error, decided_by, decided_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $item['key'], mb_substr((string)($item['kind'] ?? ''), 0, 30) ?: null, $item['template'] ?? null,
            (int)($item['to']['contact_id'] ?? 0) ?: null, $channel, $draftedBy, $suggested,
            $subject !== null ? mb_substr($subject, 0, 255) : null, $body, $learned, $status, $error, $userId ?: null,
        ]);
    }

    private function logMessage(int $contactId, string $channel, string $to, string $subject, string $body): void
    {
        try {
            $this->db->prepare("
                INSERT IGNORE INTO sales_messages (mailbox, message_key, direction, channel, contact_id, from_addr, to_addr, subject, snippet, sent_at)
                VALUES ('yui', ?, 'outbound', ?, ?, 'office@mowology.ca', ?, ?, ?, NOW())
            ")->execute(['yui-' . bin2hex(random_bytes(12)), $channel, $contactId ?: null, $to, mb_substr($subject, 0, 255), mb_substr($body, 0, 800)]);
        } catch (Throwable $e) { /* history is a bonus — the send already happened */ }
    }

    /** Tim's words as HTML in the house email wrapper (no links — this is a conversation). */
    private function emailHtml(string $body): string
    {
        require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';
        $html = '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</p>';
        return EmailWrapper::wrap($html, null, null, EmailWrapper::getCompanyInfo());
    }
}
