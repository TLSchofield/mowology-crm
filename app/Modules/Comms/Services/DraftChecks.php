<?php
declare(strict_types=1);

/**
 * DraftChecks — what to look at twice in a drafted reply (small-business inbox-manager: "never state a price, date or
 * number the owner has not given"; draft-response: reply length), 2026-11-03. Flags only: Tim reads, edits and sends.
 * DraftSafety already flags emails, links and bank details; this adds:
 *   money    a dollar amount that appears nowhere in the conversation or the item
 *   date     a month-and-day, a weekday, "tomorrow", "next week", "today" the conversation never mentioned (a crew commitment)
 *   length   more than MAX_WORDS words (the prompt asks for under 120)
 * Pure and static.
 */
final class DraftChecks
{
    public const MAX_WORDS = 120;
    private const MONTHS = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';
    private const DAYS = 'monday|tuesday|wednesday|thursday|friday|saturday|sunday';

    /** The body without the greeting line and the sign-off ("Hi Joe," … "Thanks,\nTim"). */
    public static function body(string $draft): string
    {
        $lines = preg_split('/\R/', trim($draft)) ?: [];
        if ($lines && preg_match('/^\s*(?:hi|hello|hey|dear)\b[^\n]{0,40},?\s*$/i', $lines[0])) array_shift($lines);
        for ($i = count($lines) - 1; $i >= 0 && $i >= count($lines) - 3; $i--) {
            if (preg_match('/^\s*(?:thanks|thank you|regards|best|cheers|sincerely)[,!.]?\s*$/i', $lines[$i])) { $lines = array_slice($lines, 0, $i); break; }
        }
        return trim(implode("\n", $lines));
    }

    /** "$1,500.00" → "1500", "$15" → "15": whole-number comparison, never a substring ("15" is not in "150"). */
    public static function norm(string $n): string
    {
        $n = str_replace([',', '$', ' '], '', $n);
        if (strpos($n, '.') !== false) $n = rtrim(rtrim($n, '0'), '.');
        return ltrim($n, '0') === '' ? '0' : ltrim($n, '0');
    }

    public static function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** @return string[] short flags, empty when nothing needs a second look */
    public static function check(string $draft, string $conversation): array
    {
        $flags = [];
        $conv = mb_strtolower($conversation);
        $text = self::body($draft);
        $low = mb_strtolower($text);

        if (preg_match_all('/\$\s?\d(?:[\d,]*\d)?(?:\.\d{1,2})?/', $text, $m)) {
            $known = [];
            if (preg_match_all('/\d(?:[\d,]*\d)?(?:\.\d+)?/', $conv, $cm)) foreach ($cm[0] as $n) $known[self::norm($n)] = true;
            $bad = [];
            foreach (array_unique($m[0]) as $amt) if (!isset($known[self::norm($amt)])) $bad[] = trim($amt);
            if ($bad) $flags[] = 'mentions ' . implode(', ', array_slice($bad, 0, 3)) . ' — not in the conversation';
        }

        $dates = [];
        if (preg_match_all('/\b(?:(?:' . self::MONTHS . ')\.?\s+\d{1,2}(?:st|nd|rd|th)?|\d{1,2}(?:st|nd|rd|th)?\s+(?:' . self::MONTHS . ')|(?:' . self::DAYS . ')|tomorrow|next week|this week|today|tonight|this afternoon|this morning)\b/i', $low, $m)) {
            foreach (array_unique($m[0]) as $d) {
                if (strpos($conv, $d) === false) $dates[] = $d;
            }
        }
        // "today" in "Tim will call today" is the one commitment an upset-client acknowledgement is allowed to make.
        $dates = array_values(array_filter($dates, static fn($d) => !($d === 'today' && preg_match('/\b(?:call|ring|phone)\b[^.\n]{0,25}\btoday\b/', $low))));
        if ($dates) $flags[] = 'promises ' . implode(', ', array_slice($dates, 0, 3)) . ' — check you can keep it';

        $w = self::words($text);
        if ($w > self::MAX_WORDS) $flags[] = "{$w} words — longer than " . self::MAX_WORDS;
        return $flags;
    }
}
