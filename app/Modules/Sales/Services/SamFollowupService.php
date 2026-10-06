<?php
/**
 * SamFollowupService — the follow-up Sam suggests for a waiting customer, and what Tim
 * did with it. Sam NEVER sends on his own: send() runs only from Tim's click on the card.
 *
 * Drafts:
 *   - a template per situation (first_nudge, second_nudge, viewed, last_call, multi, reply);
 *   - once Tim edits and sends one, his version — with the customer's details put back as
 *     {placeholders} — becomes Sam's draft for that situation ("learned"): his words, not Sam's;
 *   - when the customer wrote back, Tim can ask Claude for a reply that fits the thread
 *     (draftReply: on his click only, capped per day, tokens recorded).
 * Sending goes through the CRM's messaging functions only (sendEmail / sendSms — CLAUDE.md
 * rule 9). Texts are checked first: ≤160 characters, plain, no links or domains (rule 11).
 * Every send updates quotes.follow_up_sent_at/count (so nothing else nudges right after),
 * the activity log, sam_followups (Sam's lessons + scorecard) and sales_messages (history).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class SamFollowupService
{
    public const TEMPLATE_KEYS = ['first_nudge', 'second_nudge', 'viewed', 'last_call', 'multi', 'reply'];
    public const OFFICE_PHONE = '(778) 846-9273';
    public const SMS_MAX = 160;
    /** Claude drafts on Tim's click only; this many per day at most. */
    public const CLAUDE_DAILY_CAP = 20;
    public const CLAUDE_MODEL = 'claude-sonnet-5-5';
    public const SNOOZE_DAYS = 7;

    /**
     * subject, email body, text. Written with the copywriting skill and the voice card in
     * .agents/product-marketing-context.md: Tim's voice (short, plain, no exclamation marks,
     * never "just checking in"), lead with the objection most likely holding them back,
     * one easy ask. Placeholders: {first_name} {service} {place} {total} {sent_date}
     * {quote_list} {count} {valid_until} {delay_line} {owner} {phone}.
     */
    public const TEMPLATES = [
        'first_nudge' => [
            'Your {service} quote for {place}',
            "Hi {first_name},\n\nI sent over the {service} quote for {place} ({total}) on {sent_date}. If anything in it doesn't fit, the scope, the timing or the price, tell me and I'll adjust it.\n{delay_line}\nTo go ahead, accept it from the link below or just reply \"yes\" and I'll take it from there.\n\nThanks,\n{owner}",
            "Hi {first_name}, it's {owner} at Mowology. Any questions on your quote? It's in your email, or call me at {phone}.",
        ],
        'second_nudge' => [
            'One more note on your {service} quote',
            "Hi {first_name},\n\nOne more note on the {service} quote for {place} ({total}). If you're comparing quotes, check what each one includes. Every visit we do ends with a photo report, so you can see the work was done without going over to look.\n\nIf the timing isn't right, say so and I'll check back when it is.\n\nThanks,\n{owner}",
            "Hi {first_name}, {owner} from Mowology. Happy to change your quote if it doesn't fit. Details are in your email, or call {phone}.",
        ],
        'viewed' => [
            'Anything to change on your {service} quote?',
            "Hi {first_name},\n\nThanks for taking a look at the {service} quote for {place}. Is there anything you'd like changed, or a question I can answer? A two-line reply is plenty.\n{delay_line}\nWhen you're ready, accept it from the link below or reply \"yes\".\n\nThanks,\n{owner}",
            "Hi {first_name}, it's {owner} at Mowology. Anything to change on your quote? Reply to my email or call {phone}.",
        ],
        'last_call' => [
            'Your {service} quote is good until {valid_until}',
            "Hi {first_name},\n\nThe {service} quote for {place} ({total}) is good until {valid_until}. After that I'll need to re-price it, so if you'd like to keep this price, accept it from the link below or reply here before then.\n\nThanks,\n{owner}",
            "Hi {first_name}, your Mowology quote is good until {valid_until}. Check your email to accept, or call {phone}.",
        ],
        'multi' => [
            'Your {count} {service} quotes',
            "Hi {first_name},\n\nFollowing up on the {count} quotes I sent for:\n{quote_list}\n\nTogether that's {total}. If it helps with your owners or council, I can send one page with all of them side by side, or adjust any site's scope. Accept each one from the links below, or reply with the ones you'd like to start.\n{delay_line}\nThanks,\n{owner}",
            "Hi {first_name}, it's {owner} at Mowology about your {count} quotes. The links are in your email, or call {phone}.",
        ],
        'reply' => [
            'Re: your {service} quote',
            "Hi {first_name},\n\n\n\nThanks,\n{owner}",
            "Hi {first_name}, {owner} at Mowology here. Got your note and I'm on it. Call {phone} anytime.",
        ],
    ];

    /** The one true reason to decide now, by service (said once, no pressure). */
    public const DELAY_LINES = [
        'snow'    => "I'm putting the winter routes together now, so it helps to know before the first snowfall.\n",
        'salting' => "I'm putting the winter routes together now, so it helps to know before the first snowfall.\n",
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Drafts
    // ─────────────────────────────────────────────────────────────────────────

    /** Tim's latest edited version for this situation, if he has taught Sam one. */
    public function learnedBody(string $template, string $channel = 'email'): ?string
    {
        try {
            $s = $this->db->prepare("
                SELECT learned_body FROM sam_followups
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

    /** The suggested follow-up for one card from SalesDeskService::queue(). */
    public function draft(array $card, string $owner): array
    {
        $key = in_array($card['template'] ?? '', self::TEMPLATE_KEYS, true) ? $card['template'] : 'first_nudge';
        $vars = self::vars($card, $owner);
        [$subject, $body, $sms] = self::TEMPLATES[$key];
        $by = 'template';
        if ($key !== 'reply' && ($l = $this->learnedBody($key, 'email')) !== null) { $body = $l; $by = 'learned'; }
        if (($ls = $this->learnedBody($key, 'sms')) !== null) $sms = $ls;
        $smsText = self::fill($sms, $vars);
        if (self::smsProblems($smsText)) $smsText = self::fill(self::TEMPLATES[$key][2], $vars);
        if (self::smsProblems($smsText)) $smsText = self::fill("Hi {first_name}, it's Mowology following up on your quote. Check your email or call {phone}.", $vars);
        return [
            'template'   => $key,
            'drafted_by' => $by,
            'subject'    => self::fill($subject, $vars),
            'body'       => self::fill($body, $vars),
            'sms'        => $smsText,
        ];
    }

    /** Claude drafts a reply to the customer's last message — on Tim's click only. */
    public function draftReply(array $card, string $owner, int $userId): array
    {
        if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '') {
            return ['ok' => false, 'message' => 'No Anthropic key set up — write this one yourself.'];
        }
        if ($this->claudeToday() >= self::CLAUDE_DAILY_CAP) {
            return ['ok' => false, 'message' => "That's today's " . self::CLAUDE_DAILY_CAP . " drafts — write this one yourself."];
        }
        $examples = [];
        try {
            $examples = $this->db->query("
                SELECT final_body FROM sam_followups
                WHERE channel = 'email' AND status IN ('sent', 'edited') AND final_body IS NOT NULL
                ORDER BY decided_at DESC LIMIT 3
            ")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { /* no examples yet */ }

        $prompt = self::replyPrompt($card, $owner, $examples);
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
            INSERT INTO sam_followups (kind, contact_id, quote_ids, channel, template_key, drafted_by, suggested_subject, suggested_body,
                                       status, input_tokens, output_tokens, error, decided_by)
            VALUES ('replied', ?, ?, 'email', 'reply', 'claude', ?, ?, 'pending', ?, ?, ?, ?)
        ")->execute([
            $card['contact_id'] ?: null, implode(',', array_column($card['quotes'], 'id')),
            self::fill(self::TEMPLATES['reply'][0], self::vars($card, $owner)), $text !== '' ? $text : null,
            $in ?: null, $out ?: null, $code === 200 && $text !== '' ? null : 'HTTP ' . $code, $userId,
        ]);
        if ($code !== 200 || $text === '') {
            return ['ok' => false, 'message' => "I couldn't draft that one just now — write it yourself or try again later."];
        }
        return ['ok' => true, 'body' => $text, 'draft_id' => (int)$this->db->lastInsertId()];
    }

    private function claudeToday(): int
    {
        try {
            return (int)$this->db->query("SELECT COUNT(*) FROM sam_followups WHERE drafted_by = 'claude' AND created_at >= CURDATE()")->fetchColumn();
        } catch (Throwable $e) {
            return self::CLAUDE_DAILY_CAP;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tim's decision
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Send Tim's follow-up. $card is re-read on the server (SalesDeskService::queue()), so
     * the address and quotes come from the CRM, never from the browser.
     * @param array $in channel, subject, body, suggested_subject, suggested_body, template, drafted_by
     */
    public function send(array $card, array $in, array $user, string $owner): array
    {
        $channel = ($in['channel'] ?? 'email') === 'sms' ? 'sms' : 'email';
        $body = trim(str_replace("\r\n", "\n", (string)($in['body'] ?? '')));
        $subject = trim((string)($in['subject'] ?? ''));
        if ($body === '') return ['ok' => false, 'message' => 'The message is empty.'];
        $contactId = (int)($card['contact_id'] ?? 0);
        $quoteIds = array_column($card['quotes'], 'id');

        if ($channel === 'sms') {
            if ($p = self::smsProblems($body)) return ['ok' => false, 'message' => 'Text not sent: ' . implode('; ', $p) . '.'];
            $phone = (string)($card['phone'] ?? '');
            if ($phone === '') return ['ok' => false, 'message' => 'No phone number on file for ' . $card['name'] . '.'];
            if (!$contactId || !function_exists('hasSmConsent') || !hasSmConsent($contactId)) {
                return ['ok' => false, 'message' => $card['name'] . " hasn't agreed to texts — send an email instead."];
            }
            $r = sendSms($phone, $body);
            $ok = !empty($r['success']);
            $to = $phone;
        } else {
            $to = (string)($card['email'] ?? '');
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'message' => 'No email address on file for ' . $card['name'] . '.'];
            if ($subject === '') $subject = 'Following up on your quote';
            $html = $this->emailHtml($card, $body);
            $r = sendEmail($to, $subject, $html);
            $ok = !empty($r['success']);
        }
        if (!$ok) {
            $this->record($card, $in, $channel, 'failed', $user, $subject, $body, null, 'send failed');
            return ['ok' => false, 'message' => 'That didn\'t go out — check the address and try again.'];
        }

        $suggested = trim(str_replace("\r\n", "\n", (string)($in['suggested_body'] ?? '')));
        $edited = self::isEdited($suggested, $body);
        $learned = $edited ? self::unfill($body, self::vars($card, $owner)) : null;
        $this->record($card, $in, $channel, $edited ? 'edited' : 'sent', $user, $subject, $body, $learned, null);

        require_once APP_ROOT . '/Modules/Quotes/Services/QuoteService.php';
        $qs = new QuoteService($this->db);
        foreach ($quoteIds as $qid) {
            $qs->recordFollowUp((int)$qid);
            if (function_exists('logActivityExtended')) {
                logActivityExtended((int)$user['id'], 'Follow-up sent', 'Quote follow-up ' . ($channel === 'sms' ? 'text' : 'email') . " sent to {$to} from Sam's card", null, null, (int)$qid);
            }
        }
        $this->logMessage($contactId, $channel, $to, $subject, $body);
        $hi = $owner !== '' ? ", {$owner}" : '';
        return ['ok' => true, 'message' => $edited ? "Sent{$hi} — and I'll write it your way next time." : "Sent{$hi}. I'll watch for their reply."];
    }

    /** Skip ("not now") or snooze — the card comes back after SNOOZE_DAYS. */
    public function park(array $card, string $how, array $user, int $days = self::SNOOZE_DAYS): array
    {
        $status = $how === 'skip' ? 'skipped' : 'snoozed';
        $this->record($card, ['template' => $card['template'] ?? null], 'email', $status, $user, null, null, null, null,
            date('Y-m-d', strtotime('+' . max(1, min(60, $days)) . ' days')));
        return ['ok' => true, 'message' => $status === 'skipped' ? "OK — I'll leave {$card['name']} for a week." : "Snoozed — back in {$days} days."];
    }

    private function record(array $card, array $in, string $channel, string $status, array $user, ?string $subject, ?string $body,
                            ?string $learned, ?string $error, ?string $snooze = null): void
    {
        $sentAt = array_filter(array_column($card['quotes'], 'sent_at'));
        $this->db->prepare("
            INSERT INTO sam_followups
              (kind, contact_id, quote_ids, channel, template_key, drafted_by, suggested_subject, suggested_body,
               final_subject, final_body, learned_body, status, snooze_until, days_since_sent, amount, service_key, error, decided_by, decided_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            ($card['kind'] ?? 'stale') === 'replied' ? 'replied' : 'stale_quote',
            $card['contact_id'] ?: null,
            implode(',', array_map('intval', array_column($card['quotes'], 'id'))),
            $channel,
            in_array($in['template'] ?? '', self::TEMPLATE_KEYS, true) ? $in['template'] : ($card['template'] ?? null),
            in_array($in['drafted_by'] ?? '', ['template', 'learned', 'claude'], true) ? $in['drafted_by'] : 'template',
            isset($in['suggested_subject']) ? mb_substr((string)$in['suggested_subject'], 0, 255) : null,
            $in['suggested_body'] ?? null,
            $subject !== null ? mb_substr($subject, 0, 255) : null,
            $body,
            $learned,
            $status,
            $snooze,
            $sentAt ? (int)floor((time() - strtotime(min($sentAt))) / 86400) : null,
            (float)($card['amount'] ?? 0),
            mb_substr(strtolower((string)($card['quotes'][0]['service'] ?? '')), 0, 60) ?: null,
            $error,
            (int)($user['id'] ?? 0) ?: null,
        ]);
    }

    private function logMessage(int $contactId, string $channel, string $to, string $subject, string $body): void
    {
        try {
            $this->db->prepare("
                INSERT IGNORE INTO sales_messages (mailbox, message_key, direction, channel, contact_id, from_addr, to_addr, subject, snippet, sent_at)
                VALUES ('sam', ?, 'outbound', ?, ?, 'office@mowology.ca', ?, ?, ?, NOW())
            ")->execute(['sam-' . bin2hex(random_bytes(12)), $channel, $contactId ?: null, $to, mb_substr($subject, 0, 255), mb_substr($body, 0, 800)]);
        } catch (Throwable $e) { /* history is a bonus — the send already happened */ }
    }

    /** Tim's words as HTML, plus a link to each quote (links live in the email, never in a text). */
    private function emailHtml(array $card, string $body): string
    {
        require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';
        require_once APP_ROOT . '/Modules/Quotes/Services/QuoteService.php';
        $qs = new QuoteService($this->db);
        $host = $_SERVER['HTTP_HOST'] ?? 'mowology.ca';
        $links = [];
        foreach ($card['quotes'] as $q) {
            $row = $qs->getWithContact((int)$q['id']);
            if (!$row) continue;
            $token = $qs->ensureAccessToken((int)$q['id'], $row);
            $links[] = ['label' => $q['number'] . ($q['address'] !== '' ? ' — ' . $q['address'] : ''), 'url' => 'https://' . $host . '/customer/quote.php?token=' . urlencode($token)];
        }
        $html = '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</p>';
        if (count($links) > 1) {
            $html .= '<ul>' . implode('', array_map(fn($l) => '<li><a href="' . htmlspecialchars($l['url'], ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($l['label'], ENT_QUOTES, 'UTF-8') . '</a></li>', $links)) . '</ul>';
        }
        $company = EmailWrapper::getCompanyInfo();
        return count($links) === 1
            ? EmailWrapper::wrap($html, 'Open the quote', $links[0]['url'], $company)
            : EmailWrapper::wrap($html, null, null, $company);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** The values a draft is filled with — and taken back out of Tim's edits. */
    public static function vars(array $card, string $owner): array
    {
        $qs = $card['quotes'] ?? [];
        $services = array_values(array_unique(array_filter(array_map(fn($q) => strtolower(str_replace('_', ' ', trim((string)$q['service']))), $qs))));
        $list = implode("\n", array_map(fn($q) => '- ' . $q['number'] . ($q['address'] !== '' ? ' for ' . $q['address'] : '')
            . ' (' . SalesDeskService::money((float)$q['amount']) . ')', $qs));
        $first = $qs[0] ?? [];
        $delay = '';
        foreach ($services as $svc) {
            foreach (self::DELAY_LINES as $k => $line) if (strpos($svc, $k) !== false) $delay = $line;
        }
        return [
            '{first_name}'  => (string)($card['first_name'] ?? '') ?: 'there',
            '{place}'       => trim((string)($first['address'] ?? '')) !== '' ? (string)$first['address'] : 'your property',
            '{sent_date}'   => !empty($first['sent_at']) ? date('M j', strtotime((string)$first['sent_at'])) : 'recently',
            '{count}'       => (string)count($qs),
            '{delay_line}'  => $delay,
            '{service}'     => $services ? implode(' and ', array_slice($services, 0, 2)) : 'your',
            '{total}'       => SalesDeskService::money((float)($card['amount'] ?? 0)),
            '{quote_list}'  => $list,
            '{valid_until}' => !empty($card['valid_until']) ? date('M j', strtotime((string)$card['valid_until'])) : 'the date on the quote',
            '{owner}'       => $owner !== '' ? $owner : 'the Mowology team',
            '{phone}'       => self::OFFICE_PHONE,
        ];
    }

    public static function fill(string $text, array $vars): string
    {
        $out = strtr($text, $vars);
        $out = preg_replace('/\byour (quote|quotes)\b/', 'your $1', str_replace(['your your ', 'the your '], ['your ', 'your '], $out));
        return preg_replace("/\n{3,}/", "\n\n", $out);
    }

    /** Put {placeholders} back where Tim's text has this customer's details, longest first. */
    public static function unfill(string $text, array $vars): string
    {
        $pairs = [];
        foreach ($vars as $ph => $val) {
            $val = (string)$val;
            if (mb_strlen(trim($val)) < 3 || in_array($val, ['there', 'your', 'the date on the quote', 'your property', 'recently'], true)) continue;
            if ($ph === '{count}') continue;
            $pairs[$val] = $ph;
        }
        uksort($pairs, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        return strtr($text, $pairs);
    }

    /** Edited = anything more than whitespace changed. */
    public static function isEdited(string $suggested, string $final): bool
    {
        $n = fn($s) => trim(preg_replace('/\s+/', ' ', $s));
        return $suggested !== '' && $n($suggested) !== $n($final);
    }

    /** Why a text would be dropped by the carrier gateways (empty = fine). */
    public static function smsProblems(string $text): array
    {
        $p = [];
        if (mb_strlen($text) > self::SMS_MAX) $p[] = 'over ' . self::SMS_MAX . ' characters (' . mb_strlen($text) . ')';
        if (preg_match('~https?://|www\.|\b[a-z0-9-]+\.(ca|com|net|org|io|co|info|biz|app|ly|me)\b~i', $text)) $p[] = 'it has a link or web address — carriers drop those';
        if (preg_match('/[^\x20-\x7E\n]/', $text)) $p[] = 'it has special characters that may not survive';
        if (trim($text) === '') $p[] = 'it is empty';
        return $p;
    }

    /** The shared house rules for drafted copy (app/Services/Copy/mowology-copy-rules.md). */
    public static function copyRules(): string
    {
        $f = dirname(__DIR__, 3) . '/Services/Copy/mowology-copy-rules.md';
        return is_file($f) ? trim((string)file_get_contents($f)) : '';
    }

    /** What Claude is told: the house rules, the facts, the thread, and how Tim writes. */
    public static function replyPrompt(array $card, string $owner, array $examples): array
    {
        $facts = array_map(fn($q) => $q['number'] . ': ' . ($q['service'] ?: 'service') . ($q['address'] !== '' ? ' at ' . $q['address'] : '')
            . ', ' . SalesDeskService::money((float)$q['amount']) . (!empty($q['valid_until']) ? ', valid until ' . $q['valid_until'] : ''), $card['quotes'] ?? []);
        $thread = array_map(fn($m) => ($m['dir'] === 'inbound' ? 'CUSTOMER' : 'US') . (($m['channel'] ?? '') === 'sms' ? ' by text' : '') . ' (' . $m['at'] . '): ' . trim((string)$m['snippet']),
            array_reverse($card['thread'] ?? []));
        $rules = self::copyRules();
        $system = ($rules !== '' ? $rules . "\n\n---\n\n" : '') . "You draft short, friendly, plain emails for {$owner}, owner of Mowology, a landscaping and snow removal company in Vancouver. "
            . "Write as {$owner}, first person. Answer what the customer actually said; never invent prices, dates or promises that aren't in the facts — "
            . "if something needs checking, say {$owner} will check and get back to them. No subject line, no placeholders, no markdown. "
            . "Start with 'Hi " . ((string)($card['first_name'] ?? '') ?: 'there') . ",' and end with 'Thanks,\\n{$owner}'. Under 120 words.";
        $user = "Quotes:\n" . implode("\n", $facts) . "\n\nConversation (oldest first):\n" . implode("\n", $thread)
            . ($examples ? "\n\nHow {$owner} writes (recent emails he sent):\n---\n" . implode("\n---\n", array_map(fn($e) => mb_substr((string)$e, 0, 600), $examples)) : '')
            . "\n\nDraft {$owner}'s reply to the customer's latest message.";
        return ['system' => $system, 'user' => $user];
    }
}
