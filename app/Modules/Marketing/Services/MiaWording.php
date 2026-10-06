<?php
/**
 * MiaWording — the messages Mia drafts for Tim, and how she learns his wording.
 *
 * Written to the house copy rules (app/Services/Copy/mowology-copy-rules.md) and the voice
 * card in .agents/product-marketing-context.md: Tim's voice, first person, short plain
 * sentences, something specific and true about their property or the season, one easy
 * ask, no exclamation marks, never "just checking in" / "we miss you", signed "Thanks, Tim".
 * The CRM's branded footer adds the postal address, phone and unsubscribe link.
 *
 * Learning: when Tim edits a draft and sends it, learnTemplate() swaps the customer's own
 * details back into {tokens}; if what's left is still a personal message (has
 * {first_name}, and the referral link for a referral ask) it becomes Mia's template for
 * that kind, kept in ops_settings as mia_template_<kind>.
 *
 * Pure apart from the ops_settings read/write helpers. No namespace / no autoloader.
 */
class MiaWording
{
    public const PHONE = '(778) 846-9273';
    public const KINDS = ['reconnect', 'seasonal', 'pm_quiet', 'referral'];

    /** @return array{subject: string, body: string} the built-in template for a kind */
    public static function defaults(string $kind): array
    {
        switch ($kind) {
            case 'seasonal':
                return [
                    'subject' => '{service} at {place} this year',
                    'body' => "Hi {first_name},\n\n"
                        . "Our crew did the {service_lower} at {place} {last_when}. That time of year is coming round again.\n\n"
                        . "{season_line}\n\n"
                        . "Want the same again? Reply \"yes\" and I'll book it in and confirm the date with you.\n\n"
                        . "Thanks,\nTim",
                ];
            case 'pm_quiet':
                return [
                    'subject' => "{company}'s properties with Mowology",
                    'body' => "Hi {first_name},\n\n"
                        . "Our crew made {prior_visits} visits to {company}'s properties this time last year, and {recent_visits} in the last three months. I'd rather ask you directly than guess why.\n\n"
                        . "If something we did fell short, I'd like to hear it. If the needs have changed, that's useful to know too.\n\n"
                        . "Every visit still comes with a time-stamped photo report, and you still have one contact for all of it: me.\n\n"
                        . "Would a 10-minute call this week work? Reply with a time that suits you.\n\n"
                        . "Thanks,\nTim",
                ];
            case 'referral':
                return [
                    'subject' => 'A favour, if you\'re happy with the work',
                    'body' => "Hi {first_name},\n\n"
                        . "Thanks for having our crew at {place}. If you know a neighbour or friend who could use the same, here's your own link to pass on:\n\n"
                        . "{referral_link}\n\n"
                        . "When someone books through it and their first visit is done, {reward_line}\n\n"
                        . "No pressure either way. Thank you for trusting us with the property.\n\n"
                        . "Thanks,\nTim",
                ];
            case 'reconnect':
            default:
                return [
                    'subject' => 'Your yard at {place}',
                    'body' => "Hi {first_name},\n\n"
                        . "It's been a while since our crew was at {place}. The last visit was {last_when}, for {service_lower}.\n\n"
                        . "{season_line}\n\n"
                        . "If you'd like us back, reply to this email and I'll find a time that suits you.\n\n"
                        . "Thanks,\nTim",
                ];
        }
    }

    /** One true sentence about what the season needs now (month 1–12). */
    public static function seasonLine(int $month): string
    {
        if ($month >= 3 && $month <= 5) {
            return 'Spring is when lawns and beds set up for the year. A cleanup now saves a lot of catching up in June.';
        }
        if ($month >= 6 && $month <= 8) {
            return "Summer growth doesn't wait. A regular cut keeps it from getting away from you.";
        }
        if ($month >= 9 && $month <= 11) {
            return "Fall is the time to clear the leaves and cut back before the rain settles in. Left alone, it's a spring problem.";
        }
        return "Winter is quiet, which makes it a good time to plan the year and get on the spring schedule early.";
    }

    /** "last October" / "in October 2024" — when the last visit was, in plain words. */
    public static function when(?string $date, DateTimeImmutable $today): string
    {
        if (!$date || !($d = date_create_immutable(substr($date, 0, 10)))) return 'a while ago';
        $years = (int)$today->format('Y') - (int)$d->format('Y');
        if ($years === 0) return 'in ' . $d->format('F');
        if ($years === 1) return 'last ' . $d->format('F');
        return 'in ' . $d->format('F Y');
    }

    /** 'fall_cleanup' → 'Fall cleanup' */
    public static function serviceLabel(?string $type): string
    {
        $t = trim(str_replace(['_', '-'], ' ', (string)$type));
        return $t === '' ? 'Yard maintenance' : ucfirst(strtolower($t));
    }

    /** Fill {tokens}. Unknown tokens are left out rather than shown to a customer. */
    public static function render(string $template, array $vars): string
    {
        $out = $template;
        foreach ($vars as $k => $v) $out = str_replace('{' . $k . '}', (string)$v, $out);
        $out = preg_replace('/\{[a-z_]+\}/', '', $out);
        return trim(preg_replace("/\n{3,}/", "\n\n", $out));
    }

    /**
     * Turn the message Tim actually sent back into a template: his wording, with this
     * customer's details swapped back for {tokens}. Null when it isn't reusable.
     */
    public static function learnTemplate(string $kind, string $sent, array $vars): ?string
    {
        $out = self::tokenise($sent, $vars);
        if (strpos($out, '{first_name}') === false) return null;
        if ($kind === 'referral' && strpos($out, '{referral_link}') === false) return null;
        return $out;
    }

    /** Swap this customer's own details in a text back for {tokens} (longest first). */
    public static function tokenise(string $text, array $vars): string
    {
        $pairs = [];
        foreach (['first_name', 'place', 'company', 'service', 'service_lower', 'last_when',
                  'season_line', 'referral_link', 'reward_line'] as $t) {
            $v = trim((string)($vars[$t] ?? ''));
            if (mb_strlen($v) >= 3 && $v !== 'there' && $v !== 'your property') $pairs[] = [$v, '{' . $t . '}'];
        }
        // Visit counts only as "N visits" — a bare number is too likely to appear by accident.
        foreach (['prior_visits', 'recent_visits'] as $t) {
            $v = trim((string)($vars[$t] ?? ''));
            if ($v !== '' && $v !== '0') $pairs[] = [$v . ' visits', '{' . $t . '} visits'];
        }
        usort($pairs, fn($a, $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));
        $out = $text;
        foreach ($pairs as [$from, $to]) {
            // Whole words only, so "Ann" never eats the middle of "Annual".
            $out = preg_replace('/(?<!\w)' . preg_quote($from, '/') . '(?!\w)/u', $to, $out);
        }
        return trim($out);
    }

    // ── Text messages ─────────────────────────────────────────────────────

    /** The text that goes with an email: points them to it, never carries a link. */
    public static function sms(string $firstName, string $topic): string
    {
        $name = self::asciiOnly($firstName);
        $long = 'Hi ' . $name . ', Tim from Mowology here. I sent you an email about ' . self::asciiOnly($topic)
              . '. Check your email, or call ' . self::PHONE . '.';
        if (self::smsProblems($long) === []) return $long;
        return 'Hi ' . $name . ', Tim from Mowology here. Check your email for a note from me, or call ' . self::PHONE . '.';
    }

    /** Why a text would be dropped by a carrier gateway — empty when it's fine. */
    public static function smsProblems(string $text): array
    {
        $p = [];
        if (mb_strlen($text) > 160) $p[] = 'longer than 160 characters';
        if (preg_match('~https?:|www\.|\b[a-z0-9-]+\.(ca|com|net|org|io|co|info|biz|ly)\b~i', $text)) $p[] = 'has a link or web address';
        if (preg_match('/[^\x20-\x7E]/', $text)) $p[] = 'has special characters';
        if (strpos($text, self::PHONE) === false) $p[] = 'missing ' . self::PHONE;
        if (stripos($text, 'email') === false) $p[] = 'should tell them to check their email';
        return $p;
    }

    private static function asciiOnly(string $s): string
    {
        $s = strtr($s, ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-']);
        return trim(preg_replace('/[^\x20-\x7E]/', '', $s));
    }

    // ── Plain-text body → email HTML ─────────────────────────────────────

    public static function toHtml(string $body): string
    {
        $paras = preg_split("/\n{2,}/", trim($body));
        $out = '';
        foreach ($paras as $p) {
            $p = htmlspecialchars($p, ENT_QUOTES, 'UTF-8');
            $p = preg_replace('~(https?://[^\s<]+)~', '<a href="$1">$1</a>', $p);
            $out .= '<p style="margin:0 0 14px;">' . nl2br($p) . '</p>';
        }
        return $out;
    }

    // ── Learned templates (ops_settings mia_template_<kind>) ─────────────

    /** @return array{subject: string, body: string, learned: bool} */
    public static function template(PDO $db, string $kind): array
    {
        $t = self::defaults($kind) + ['learned' => false];
        try {
            $s = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute(['mia_template_' . $kind]);
            $v = json_decode((string)$s->fetchColumn(), true);
            if (is_array($v) && !empty($v['body'])) {
                $t = ['subject' => (string)($v['subject'] ?? $t['subject']), 'body' => (string)$v['body'], 'learned' => true];
            }
        } catch (Throwable $e) { /* built-in template */ }
        return $t;
    }

    public static function saveTemplate(PDO $db, string $kind, ?string $subject, string $body): void
    {
        $db->prepare("
            INSERT INTO ops_settings (setting_key, setting_value, description)
            VALUES (?, ?, 'Mia: Tim''s own wording for this kind of message, learned from what he sent')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ")->execute(['mia_template_' . $kind, json_encode(['subject' => $subject, 'body' => $body, 'at' => date('Y-m-d')])]);
    }
}
