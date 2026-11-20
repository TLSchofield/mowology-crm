<?php
declare(strict_types=1);

/**
 * ReplyClock — how long a client reply has waited, and what each stage means (customer-support/ticket-triage and
 * draft-response, 2026-11-03: an unanswered question is chased after 2–3 business days). Pure and static.
 *
 *   fresh   under 1 weekday   priority 2, nothing said
 *   waiting 1–2 weekdays      priority 2, "waiting N days"
 *   late    3+ weekdays       priority 1, "they have waited N days"
 * Weekdays: Monday to Friday between the message and now (a Friday reply is one weekday old on Monday).
 *
 * Also the two rules that decide whether a reply needs Tim at all (inbox-manager: only what the owner must act on is
 * "needs you"): an acknowledgement and a staff member's own text are not client replies.
 */
final class ReplyClock
{
    public const WAITING_AT = 1;
    public const LATE_AT = 3;

    /** The acknowledgement words that need nothing back. Not "yes": a yes is Sam's and Yui's top item. */
    public const ACK = ['ok', 'okay', 'k', 'kk', 'all right', 'alright', 'sounds good', 'sounds great', 'thanks', 'thank you', 'thx', 'ty', 'great', 'perfect',
                        'cool', 'got it', 'will do', 'no problem', 'np', 'noted', 'awesome', 'nice', 'good', 'fine', 'sure thing', 'thumbs up'];

    /** Whole weekdays (Mon–Fri) after the day of the message up to the day of $now. */
    public static function weekdays(string $at, string $now): int
    {
        $a = strtotime(substr($at, 0, 10) . ' 12:00:00');
        $b = strtotime(substr($now, 0, 10) . ' 12:00:00');
        if ($a === false || $b === false || $b <= $a) return 0;
        $n = 0;
        for ($t = $a + 86400; $t <= $b; $t += 86400) {
            if ((int)date('N', $t) <= 5) $n++;
        }
        return $n;
    }

    /** @return array{stage: string, days: int, priority: int, suffix: string} */
    public static function stage(string $at, string $now): array
    {
        $d = self::weekdays($at, $now);
        if ($d >= self::LATE_AT) return ['stage' => 'late', 'days' => $d, 'priority' => 1, 'suffix' => " — they have waited {$d} days"];
        if ($d >= self::WAITING_AT) return ['stage' => 'waiting', 'days' => $d, 'priority' => 2, 'suffix' => " — waiting {$d} day" . ($d === 1 ? '' : 's')];
        return ['stage' => 'fresh', 'days' => $d, 'priority' => 2, 'suffix' => ''];
    }

    /**
     * A bare acknowledgement ("ok", "All right", "thanks 👍"): at most 4 words, no question, no number or date, nothing
     * but the list above, and OUR last message to them was not a question (an "ok" to "shall we start the 12th?" is an answer).
     */
    public static function ackOnly(string $text, ?string $ourLast): bool
    {
        $t = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
        $had = $t !== '';
        $t = trim(preg_replace('/[\p{So}\p{Sk}\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}\x{1F3FB}-\x{1F3FF}!.,;:~\-]+/u', ' ', $t));
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        // A thumbs-up or a heart on its own is an acknowledgement too (live 2026-11-03: a "👍" waited 7 days in the inbox).
        if ($t === '' && $had && !preg_match('/\p{L}|\p{N}/u', $text)) return !self::asked($ourLast);
        if ($t === '' || mb_strlen($t) > 30 || substr_count($t, ' ') > 3) return false;
        if (preg_match('/[?\d]/', $t)) return false;
        $ok = in_array($t, self::ACK, true);
        if (!$ok) {   // "ok thanks", "all right thanks"
            $parts = preg_split('/\s+/', $t) ?: [];
            $ok = $parts && !array_diff($parts, ['ok', 'okay', 'k', 'thanks', 'thank', 'you', 'thx', 'great', 'perfect', 'cool', 'sounds', 'good', 'all', 'right', 'alright', 'got', 'it', 'will', 'do', 'so', 'much', 'very', 'noted', 'awesome', 'nice']);
        }
        if (!$ok) return false;
        return !self::asked($ourLast);
    }

    /** Was our last message a question or a request for an answer ("Please confirm approval." has no question mark)? */
    public static function asked(?string $ourLast): bool
    {
        if ($ourLast === null) return false;
        return strpos($ourLast, '?') !== false
            || (bool)preg_match('/\b(?:please (?:confirm|let|reply|respond|advise|tell|call)|let (?:me|us) know|can you|could you|would you|do you|shall we|should we|are you|which |what time|get back to|reply with|confirm )/i', $ourLast);
    }

    /** The first 10 digits of a phone, or ''. */
    public static function phoneKey(?string $p): string
    {
        $d = preg_replace('/\D+/', '', (string)$p);
        return strlen($d) >= 10 ? substr($d, -10) : '';
    }

    /**
     * Is this contact one of our own people? An active user's email, or an active user's phone WITH the same first name
     * (a client who shares a family phone number with an employee is still a client — Codex 2026-11-03).
     * @param array $staffEmails email => true
     * @param array $staffPhones phone key => [lowercase first names]
     */
    public static function isStaff(array $contact, array $staffEmails, array $staffPhones): bool
    {
        $e = strtolower(trim((string)($contact['email'] ?? '')));
        if ($e !== '' && isset($staffEmails[$e])) return true;
        $first = strtolower(trim((string)($contact['first_name'] ?? '')));
        foreach (['phone', 'mobile'] as $k) {
            $p = self::phoneKey($contact[$k] ?? '');
            if ($p !== '' && isset($staffPhones[$p]) && $first !== '' && in_array($first, (array)$staffPhones[$p], true)) return true;
        }
        return false;
    }
}
