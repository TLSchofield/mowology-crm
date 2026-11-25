<?php
declare(strict_types=1);

/**
 * CharlieAnswerStyle — how Charlie sounds (Ittai, 2026-10-28).
 *
 * The first real answers were a wall of invoice numbers and dates ("INV-2026-0397 for $50.40 (due Oct 17) and
 * INV-2026-0408 …"): correct-looking, impossible to take in on a phone, and nothing a person would say out loud.
 * PROMPT is appended to Charlie's system prompt and replaces its older length/format rules; check() is the same rule
 * set as code so a test (and an eval case) can hold any answer to it.
 *
 * Pure. No namespace / no autoloader: require_once.
 */
final class CharlieAnswerStyle
{
    public const MAX_WORDS = 70;

    public const PROMPT = <<<'TXT'
HOW TO ANSWER (this replaces the length and format rules above)
You are talking to the owner out loud, like a sharp colleague who knows the books. Say it the way a person would say it.
- First sentence: the answer itself ("Not quite.", "Yes, all paid up.", "Two of them."). No lead-in like "Based on the records".
- Then the why, in one or two plain sentences: the thing that actually explains it. When a payment_story is given, its signals are worked out for you from the books: use them, and never state a cause they do not show. If you cannot tell, say so in a few words ("I can't see why").
- Round and group. "Two small ones, about $50 each", "around $450". Do not list invoice numbers and do not read out dates unless the owner asks for the details. A date is fine when it explains something ("paid the big ones on the 23rd").
- Take totals from payment_story.owing, not by counting rows.
- Use the person's name. Never "he", "she", "his" or "her" unless the record says which; "they" is fine.
- 35 to 60 words. Plain sentences: no lists, no markdown, no headings, no sign-off.
- If one next step stands out (no card on file, reminders used up, emails bouncing, a payment on the way), end with it in a short sentence.

Examples (the facts are made up):
Q: has Pat paid all their bills
A: Not quite. Pat is behind on five small ones, about $250 in all, but did pay the two big ones by e-transfer on the 23rd. There's a card on file but no autopay, and most of the small ones have already had all three reminders, so nothing more goes out on its own.
Q: is Lee up to date
A: Yes, all paid up. The last invoice went through by card on the 12th and nothing else is open.
TXT;

    /**
     * What is wrong with an answer, in plain words (empty = fine). $detail = the owner asked for the full list.
     * @return string[]
     */
    public static function check(string $answer, bool $detail = false): array
    {
        $a = trim($answer);
        $bad = [];
        if ($a === '') return ['empty'];
        $words = str_word_count(preg_replace('/[^A-Za-z0-9\' ]+/', ' ', $a) ?? $a);
        if (!$detail && $words > self::MAX_WORDS) $bad[] = "too long ($words words)";
        if (preg_match('/^\s*([-*•]|\d+[.)])\s/m', $a) || strpos($a, '**') !== false || preg_match('/^#/m', $a)) $bad[] = 'markdown or a list';
        if (!$detail && preg_match_all('/\bINV-\d{4}-\d{3,4}\b/i', $a) >= 2) $bad[] = 'reads out invoice numbers';
        if (!$detail && preg_match_all('/\$\s?\d[\d,]*(\.\d\d)?/', $a) > 4) $bad[] = 'reads out too many amounts';
        if (!$detail && preg_match_all('/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.? \d{1,2}\b/', $a) >= 3) $bad[] = 'reads out dates';
        if (preg_match('/^(based on|according to|as per|here is|here\'s a summary)/i', $a)) $bad[] = 'starts with a lead-in';
        if (preg_match('/\n\s*—\s*from /', $a)) $bad[] = 'has a source tag';
        // The CRM never records anyone's pronouns, so any he/she/his/her is a guess.
        if (preg_match('/\b(he|she|his|hers?|him|himself|herself)\b/i', $a)) $bad[] = 'guesses a pronoun';
        return $bad;
    }

    /** True when the owner is asking for the full list ("list", "every", "all the invoices", "which invoices", "details"). */
    public static function wantsDetail(string $question): bool
    {
        return (bool)preg_match('/\b(list|every|each|which invoices|all (the )?invoices|details?|break ?down|numbers?)\b/i', $question);
    }
}
