<?php
declare(strict_types=1);

/**
 * VoiceProfile — how Tim writes, learned from what he has actually sent, and the slop test every draft must pass.
 * Harvested from Anthropic's small-business plugin (shared/voice-profile.md, 2026-11-03) and fitted to Mia's standing rules
 * (operating-rules: first person, short plain sentences, signed Tim, contractions, NO exclamation marks, no hype words,
 * no emoji). Where the plugin and the house rules differ, the house rule wins (the plugin allows "rare" exclamations; we allow none).
 *
 *   learn(samples)   greeting, sign-off, average sentence length, contractions, exclamations, characteristic phrases,
 *                    never-uses (phrases absent from every sample, from the watch list) — from 15–30 sent emails, best first;
 *                    with fewer than MIN_SAMPLES it says so and learns nothing it cannot support
 *   check(text, profile)  the slop test: banned openers and fillers, sentences over MAX_SENTENCE_WORDS, exclamation marks,
 *                    hype words, emoji, unbackable superlatives, promises nobody made ("100% satisfaction guaranteed"),
 *                    a price or percentage that must be confirmed, and a greeting/sign-off that is not his
 * Every owner edit to the profile is appended to 'updates' with the reason, never overwritten. Pure and static.
 */
final class VoiceProfile
{
    public const MIN_SAMPLES = 3;
    /** The plugin says 25; on Tim's own sent mail 25 flagged half of it, so 40 — the rule is for AI run-ons, not his writing (dry run 2026-11-03). */
    public const MAX_SENTENCE_WORDS = 40;
    /** Fillers and openers the plugin's owners banned, plus Mia's hype list. Lower-case. */
    public const BANNED = ['reach out', 'circle back', 'touch base', 'hope this finds you well', 'hope this email finds you well', 'i wanted to', 'excited to', 'thrilled', 'exciting news', 'leverage', 'solution', 'solutions', 'elevate', 'exceptional', 'just following up', 'we pride ourselves', 'sorry you feel this way', 'synergy', 'game-changer', 'unlock', 'delighted to'];
    public const SUPERLATIVES = '/\b(best in (?:the )?(?:city|town|county|vancouver|bc)|#1|number one|world[- ]class|award[- ]winning|guaranteed|100% satisfaction|same[- ]day(?: service)?,? always|fastest|cheapest|lowest price)\b/i';
    public const WATCH = ['cheers', 'thanks', 'thank you', 'best', 'regards', 'kind regards', 'hi', 'hello', 'hey', 'good morning', 'good afternoon'];

    /**
     * A sent message as he typed it: quoted history, signature block, URLs and addresses removed, list lines kept as sentences.
     * Inbox snippets carry "Tim Schofield www.mowology.ca On Oct 3 … wrote:" — none of that is his voice.
     */
    public static function cleanSample(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = html_entity_decode(strip_tags(preg_replace('~<(style|script)\b[^>]*>.*?</\1>~is', ' ', $s)), ENT_QUOTES, 'UTF-8');   // HTML mail: tags and style blocks are not words
        $s = preg_replace('/\{[^}]*\}|\b[a-z-]+\s*:\s*[^;\n]{1,60};|\bmso\w*\b|\bmsonormal\b/i', ' ', $s);                       // stray CSS declarations and Outlook class names
        $s = preg_replace('/^\s*On\s+(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun|\w{3,9}\s+\d{1,2}|\d{1,2}\s+\w{3,9})[^\n]{0,80}wrote:.*$/ims', '', $s);   // quoted history: a header LINE, never prose that happens to say "wrote:"
        $s = preg_replace('/^(from|sent|to|subject):\s.*$/im', '', $s);
        $s = preg_replace('~https?://\S+|www\.\S+|\S+@\S+\.\w+~i', '', $s);
        $s = preg_replace('/\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}/', '', $s);
        // cut at the signature: a sign-off line followed by anything, or the company name line
        $s = preg_replace('/\n\s*(cheers|thanks|thank you|best|regards|kind regards|talk soon|all the best)[,.]?\s*\n.*$/is', "\n$1,", $s);
        $s = preg_replace('/\b(tim schofield|mowology)\b[^\n]*$/im', '', $s);
        return trim(preg_replace('/[ \t]+/', ' ', $s));
    }

    /** Sentences: a newline is a boundary too (list lines and sign-offs are not run-on sentences). */
    private static function sentences(string $s): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/(?<=[.?!])\s+|\n+/', $s)), fn($x) => str_word_count($x) > 0));
    }

    /** @param string[] $samples sent messages, best first @return array profile */
    public static function learn(array $samples, array $ownerNeverUses = []): array
    {
        $samples = array_values(array_filter(array_map(fn($x) => self::cleanSample((string)$x), $samples)));
        $n = count($samples);
        $p = ['built' => date('Y-m-d'), 'samples' => $n, 'greeting' => null, 'signoff' => null, 'avg_sentence_words' => null, 'contractions' => null,
              'exclamations' => 'never', 'phrases' => [], 'never_uses' => array_values(array_unique(array_map('strtolower', $ownerNeverUses))), 'updates' => [], 'enough' => $n >= self::MIN_SAMPLES];
        if ($n < self::MIN_SAMPLES) { $p['note'] = "Only $n sample" . ($n === 1 ? '' : 's') . ' — ask for three real sent emails before trusting the voice; nothing is guessed.'; return $p; }
        $greet = []; $sign = []; $words = 0; $sentences = 0; $contr = 0; $excl = 0; $bigrams = []; $trigrams = [];
        foreach ($samples as $s) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $s))));
            if ($lines && preg_match('/^(hi|hello|hey|good (?:morning|afternoon)|dear)\b[^,]*,?/i', $lines[0], $m)) $greet[] = preg_replace('/\s+[A-Z][a-z]+,?$/', ' <name>,', trim($m[0]));
            for ($i = count($lines) - 1; $i >= max(0, count($lines) - 3); $i--) {
                if (preg_match('/^(cheers|thanks|thank you|best|regards|kind regards|talk soon|all the best)[,.!]?$/i', $lines[$i])) { $sign[] = ucfirst(strtolower(rtrim($lines[$i], ',.!'))); break; }
            }
            foreach (self::sentences($s) as $sent) { $words += str_word_count($sent); $sentences++; }
            $body = preg_replace('/\s+/', ' ', $s);
            $contr += preg_match_all("/\b\w+'(?:t|s|re|ll|ve|d|m)\b/i", $body);
            $excl += substr_count($body, '!');
            $toks = array_values(array_filter(preg_split('/[^a-z\']+/', strtolower($body))));
            for ($i = 0; $i < count($toks) - 2; $i++) { $tg = $toks[$i] . ' ' . $toks[$i + 1] . ' ' . $toks[$i + 2]; $trigrams[$tg] = ($trigrams[$tg] ?? 0) + 1; }
            for ($i = 0; $i < count($toks) - 1; $i++) { $bg = $toks[$i] . ' ' . $toks[$i + 1]; $bigrams[$bg] = ($bigrams[$bg] ?? 0) + 1; }
        }
        $mode = static function (array $xs): ?string { if (!$xs) return null; $c = array_count_values($xs); arsort($c); return (string)array_key_first($c); };
        $p['greeting'] = $mode($greet); $p['signoff'] = $mode($sign);
        $p['avg_sentence_words'] = $sentences ? round($words / $sentences, 1) : null;
        $p['contractions'] = $contr >= $n;   // at least one per message on average
        $p['exclamations'] = $excl === 0 ? 'never' : ($excl <= $n ? 'rare' : 'frequent');
        $stop = ['of the', 'in the', 'to the', 'on the', 'for the', 'and the', 'it is', 'if you', 'we can', 'let me', 'i will', 'i can', 'at the', 'to be', 'is a', 'this is', 'you can', 'we will', 'thank you', 'you have', 'have a', 'with the', 'hi there', 'thanks tim', 'regards tim'];
        $noise = '/\b(tim|schofield|mowology|www|ca|com|oct|nov|dec|jan|feb|mar|apr|may|jun|jul|aug|sep|am|pm|hi|hello|thanks|regards|font|face|family|size|pt|px|aptos|calibri|arial|span|div|style|msonormal|li|p|br|nbsp)\b/';
        arsort($trigrams); arsort($bigrams);
        foreach ($trigrams as $tg => $c) { if ($c >= 2 && !preg_match($noise, $tg) && count($p['phrases']) < 5) $p['phrases'][] = $tg; }   // his turns of phrase, longest first
        foreach ($bigrams as $bg => $c) {
            if ($c < 2 || in_array($bg, $stop, true) || preg_match($noise, $bg) || count($p['phrases']) >= 5) continue;
            $inside = false; foreach ($p['phrases'] as $ph) if (strpos($ph, $bg) !== false) { $inside = true; break; }
            if (!$inside) $p['phrases'][] = $bg;
        }
        $all = strtolower(implode("\n", $samples));
        foreach (self::BANNED as $b) if (strpos($all, $b) === false && !in_array($b, $p['never_uses'], true)) $p['never_uses'][] = $b;
        return $p;
    }

    /** Append an owner edit with its reason — never overwrite the learned profile silently. */
    public static function amend(array $profile, string $field, $value, string $reason, string $today): array
    {
        $profile[$field] = $value;
        $profile['updates'][] = ['on' => $today, 'field' => $field, 'reason' => $reason];
        return $profile;
    }

    /** The slop test. @return string[] problems, in plain words; empty = passes */
    public static function check(string $text, array $profile = []): array
    {
        $w = [];
        $low = strtolower($text);
        $never = array_unique(array_merge(self::BANNED, (array)($profile['never_uses'] ?? [])));
        $hits = [];
        foreach ($never as $b) if ($b !== '' && strpos($low, $b) !== false) $hits[] = $b;
        if ($hits) $w[] = 'Not his words: "' . implode('", "', array_slice($hits, 0, 4)) . '"';
        if (strpos($text, '!') !== false) $w[] = 'An exclamation mark — he never uses them';
        if (preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text)) $w[] = 'Emoji — not in the body of anything he sends';
        if (preg_match(self::SUPERLATIVES, $text, $m)) $w[] = 'A claim nobody can back: "' . $m[0] . '"';
        $long = 0;
        foreach (self::sentences(self::cleanSample($text)) as $sent) if (str_word_count($sent) > self::MAX_SENTENCE_WORDS) $long++;
        if ($long) $w[] = $long . ' sentence' . ($long === 1 ? '' : 's') . ' over ' . self::MAX_SENTENCE_WORDS . ' words — split them';
        if (preg_match('/\$\s?\d|\d+\s?%|\bfrom \$|\bonly \$/i', $text)) $w[] = 'A price or percentage — read it fresh from the quote or rate card before this goes out, never from memory';
        if (!empty($profile['greeting']) && preg_match('/^(hi|hello|hey|dear|good (?:morning|afternoon))\b/i', trim($text), $g)) {
            $his = preg_replace('/\s*<name>,?$/', '', (string)$profile['greeting']);
            if (strcasecmp(trim($g[1]), trim($his)) !== 0) $w[] = 'Greeting is not his (he opens with "' . $profile['greeting'] . '")';
        }
        if (!empty($profile['signoff'])) {   // the last three non-empty lines carry the sign-off, if there is one
            $lines = array_values(array_filter(array_map('trim', explode("\n", $text))));
            $found = null;
            for ($i = count($lines) - 1; $i >= max(0, count($lines) - 3); $i--) if (preg_match('/^(cheers|thanks|thank you|best|regards|kind regards|talk soon|all the best|sincerely|warmly)[,.!]?$/i', $lines[$i], $m)) { $found = ucfirst(strtolower($m[1])); break; }
            if ($found !== null && strcasecmp($found, (string)$profile['signoff']) !== 0) $w[] = 'Sign-off is not his (he signs "' . $profile['signoff'] . '")';
        }
        return $w;
    }
}
