<?php
declare(strict_types=1);

/**
 * UpsetSignal — a client who is unhappy, and what the reply may and may not say (small-business inbox-manager and
 * customer-support draft-response, 2026-11-03). Pure and static.
 *
 * Unhappy = a complaint cue in their message, OR two or more messages from them since our last one (a second email in a
 * sharper tone is the sign). "Cancel" alone is not a cue: "cancel our recurring cutting, we are done for the season" is
 * an ordinary request.
 * An unhappy client goes to the top, and the Claude draft is told to ACKNOWLEDGE ONLY: sorry, that is not right, Tim will
 * call today. No offer, fix, price, compensation or blame; those are the owner's call. Under 60 words.
 */
final class UpsetSignal
{
    public const REPEAT_MESSAGES = 2;
    public const ACK_WORDS = 60;

    public static function cues(): array
    {
        return [
            '/\b(?:not|never|wasn\'?t|isn\'?t|aren\'?t)\s+(?:very |at all |too )?(?:happy|satisfied|pleased|impressed)\b/i',
            '/\b(?:unhappy|disappointed|disappointing|frustrated|frustrating|annoyed|upset|angry|furious|fed up)\b/i',
            '/\b(?:unacceptable|ridiculous|disgraceful|terrible|awful|appalling|worst)\b/i',
            '/\b(?:never|didn\'?t|did not|haven\'?t|has not|hasn\'?t)\s+(?:came|come|show(?:ed)?(?: up)?|arrive[d]?|turn(?:ed)? up|do(?:ne)?|finish(?:ed)?|call(?:ed)?(?: back)?|reply|replied|respond(?:ed)?)\b/i',
            '/\bno[- ]?one\s+(?:came|showed|called|replied|responded|has)\b/i',
            '/\b(?:still|yet)\s+(?:waiting|no (?:reply|response|answer|word))\b/i',
            '/\b(?:third|3rd|second|2nd|fourth|4th)\s+time\b/i',
            '/\b(?:complain|complaint|refund|chargeback|dispute)\b/i',
            '/\bhired\s+(?:someone|somebody|another|a new)\b/i',
            '/\b(?:will|going to|i\'?ll)\s+(?:cancel|leave|switch|take (?:my|our) business)\b[^.\n]{0,30}\b(?:unless|if|because|since|after)\b/i',
            '/\bsame as (?:last time|before)\b[^.\n]{0,40}\b(?:didn\'?t|never|no)\b/i',
        ];
    }

    /** @return ?string the cue that matched (for the card), or null */
    public static function cue(string $text): ?string
    {
        $t = trim($text);
        if ($t === '') return null;
        foreach (self::cues() as $re) {
            if (preg_match($re, $t, $m)) return trim($m[0]);
        }
        return null;
    }

    /**
     * @param string $text            their latest message (subject + snippet)
     * @param int    $sinceOurLast    how many messages they sent after our last one (1 = just this)
     * @param int    $gapWeekdays     weekdays between the first and the last of those messages: texts sent one after another
     *                                are one conversation, not a chase (live 2026-11-03: two plant names in a row)
     * @return array{upset: bool, why: string}
     */
    public static function assess(string $text, int $sinceOurLast, int $gapWeekdays = 0): array
    {
        $cue = self::cue($text);
        if ($cue !== null) return ['upset' => true, 'why' => '“' . $cue . '”'];
        if ($sinceOurLast >= self::REPEAT_MESSAGES && $gapWeekdays >= 1) return ['upset' => true, 'why' => $sinceOurLast . ' messages since we last wrote, the first ' . $gapWeekdays . ' weekday' . ($gapWeekdays === 1 ? '' : 's') . ' ago'];
        return ['upset' => false, 'why' => ''];
    }

    /** The extra instruction for the Claude draft when the client is unhappy. */
    public static function draftRule(string $owner): string
    {
        return "\n\nTHIS CLIENT IS UNHAPPY. Write only an acknowledgement, under " . self::ACK_WORDS . " words: say sorry that this is not right and that {$owner} will call today. "
             . "Do NOT offer a fix, a visit, a price, a discount, a refund or any compensation, do NOT explain or blame anyone, and do NOT promise a date or time other than a call today. {$owner} decides everything else.";
    }
}
