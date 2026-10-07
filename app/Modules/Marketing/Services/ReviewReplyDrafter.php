<?php
/**
 * ReviewReplyDrafter — Mia's draft reply to a Google review, in Tim's voice.
 *
 * Tim's rules for review replies:
 *   - no exclamation marks (ever — they're stripped even from a name);
 *   - thank the reviewer by first name;
 *   - mention the work (from the review's own words, else the job we know about);
 *   - keep it short: at most MAX_WORDS words, signed "Tim".
 * A low rating gets an apology and the office phone (from business_settings, passed in), never
 * an argument. Nothing about the customer's address or job details beyond what they wrote.
 *
 * Mia drafts; Tim copies (or, once the GBP API is approved, clicks Post). Pure, no DB.
 */
class ReviewReplyDrafter
{
    public const MAX_WORDS = 60;

    /** Words that are off-voice in a reply (copy rules). */
    private const BANNED = ['thrilled', 'exceptional', 'elevate', 'leverage', 'solution', 'touching base', 'circling back'];

    /** keyword in the review => how Tim names the work. First match wins (most specific first). */
    private const WORK = [
        'hedge'      => 'hedge trimming',
        'aerat'      => 'aeration',
        'overseed'   => 'overseeding',
        'moss'       => 'moss work',
        'mulch'      => 'mulch and beds',
        'leaf'       => 'leaf cleanup',
        'leaves'     => 'leaf cleanup',
        'snow'       => 'snow clearing',
        'salt'       => 'salting',
        'spring clean' => 'spring cleanup',
        'fall clean' => 'fall cleanup',
        'cleanup'    => 'cleanup',
        'clean up'   => 'cleanup',
        'irrigation' => 'irrigation work',
        'sod'        => 'new lawn',
        'mow'        => 'lawn mowing',
        'lawn'       => 'lawn care',
        'garden'     => 'garden work',
        'strata'     => 'grounds maintenance',
        'maintenance' => 'maintenance',
    ];

    /** Phrasings, picked by review so two replies on the same page don't read the same. */
    private const GOOD = [
        "Thank you, {name}. Glad the {work} turned out the way you wanted. I'll pass this on to the crew.",
        "Thanks, {name}. It's good to hear the {work} came out well. The crew will be glad to read this.",
        "Thank you for this, {name}. We're glad the {work} did the job. I'll share it with the crew.",
    ];
    private const MIXED = "Thank you, {name}. Glad parts of the {work} worked for you. If anything wasn't right, call me at {phone} and I'll sort it out.";
    private const POOR  = "{name}, thank you for telling us. I'm sorry the {work} wasn't what you expected. Please call me at {phone} so I can put it right.";

    /**
     * @param array{id?: int|string, reviewer_name?: string, rating?: int, comment?: string} $review
     * @param array{owner?: string, phone?: string, service?: string} $ctx  service = what we know was done
     */
    public static function draft(array $review, array $ctx = []): string
    {
        $name   = self::firstName((string)($review['reviewer_name'] ?? ''));
        $rating = max(1, min(5, (int)($review['rating'] ?? 5)));
        $work   = self::work((string)($review['comment'] ?? ''), $ctx['service'] ?? null);
        $phone  = trim((string)($ctx['phone'] ?? ''));
        $owner  = self::firstName((string)($ctx['owner'] ?? 'Tim')) ?: 'Tim';

        if ($rating >= 4) {
            $tpl = self::GOOD[abs(crc32((string)($review['id'] ?? $name . $rating))) % count(self::GOOD)];
        } else {
            $tpl = $rating === 3 ? self::MIXED : self::POOR;
            if ($phone === '') {
                $tpl = str_replace(['call me at {phone} and', 'Please call me at {phone} so'], ['get in touch and', 'Please get in touch so'], $tpl);
            }
        }
        if ($name === '') {
            // Anonymous reviewer: thank them without a name, still about the work.
            $tpl = str_replace(['Thank you, {name}.', 'Thanks, {name}.', 'Thank you for this, {name}.', '{name}, thank you'],
                               ['Thank you.', 'Thanks for this.', 'Thank you for this.', 'Thank you'], $tpl);
        }
        $text = strtr($tpl, ['{name}' => $name, '{work}' => $work, '{phone}' => $phone]) . "\n\n" . $owner;
        return self::clean($text);
    }

    /**
     * Which of Tim's rules a reply breaks (for an edited reply, and for tests).
     * @return string[] subset of: exclamation, no_name, too_long, banned_word, empty
     */
    public static function violations(string $reply, string $reviewerName = ''): array
    {
        $out = [];
        $t = trim($reply);
        if ($t === '') return ['empty'];
        if (strpos($t, '!') !== false) $out[] = 'exclamation';
        $first = self::firstName($reviewerName);
        if ($first !== '' && stripos($t, $first) === false) $out[] = 'no_name';
        if (str_word_count($t) > self::MAX_WORDS) $out[] = 'too_long';
        foreach (self::BANNED as $w) {
            if (stripos($t, $w) !== false) { $out[] = 'banned_word'; break; }
        }
        return $out;
    }

    /** "Jane D." → "Jane"; "A Google User" / "Anonymous" → "". Never carries a "!". */
    public static function firstName(string $display): string
    {
        $d = trim(str_replace('!', '', $display));
        if ($d === '' || preg_match('/^(a google user|anonymous|google user|customer)$/i', $d)) return '';
        $first = (string)strtok($d, " \t");
        $first = trim($first, " .,;:");
        if (mb_strlen($first) < 2) return '';
        return mb_strtoupper(mb_substr($first, 0, 1)) . mb_substr($first, 1);
    }

    /** What the reply calls the work: from the review's words, else the known service, else "work". */
    public static function work(string $comment, ?string $service = null): string
    {
        $c = strtolower($comment);
        foreach (self::WORK as $kw => $label) {
            if (strpos($c, $kw) !== false) return $label;
        }
        $s = strtolower(trim(str_replace(['_', '-'], ' ', (string)$service)));
        if ($s !== '') {
            foreach (self::WORK as $kw => $label) {
                if (strpos($s, $kw) !== false) return $label;
            }
            return $s;
        }
        return 'work';
    }

    private static function clean(string $t): string
    {
        $t = str_replace('!', '.', $t);
        $t = preg_replace('/\.{2,}/', '.', $t);
        return trim(preg_replace('/[ \t]+/', ' ', $t));
    }
}
