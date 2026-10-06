<?php
/**
 * CharlieVoice — how Charlie talks: friendly, first name, plain words, no exclamation
 * marks, the thing itself before any explanation. Pure (unit tested).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class CharlieVoice
{
    public static function firstName(array $user): string
    {
        $n = trim((string)($user['first_name'] ?? ''));
        if ($n === '') $n = (string)strtok(trim((string)($user['full_name'] ?? $user['name'] ?? '')), ' ');
        return $n === '' ? '' : mb_strtoupper(mb_substr($n, 0, 1)) . mb_substr($n, 1);
    }

    public static function hey(string $name): string
    {
        return $name !== '' ? "Hey {$name} —" : 'Hey —';
    }

    /**
     * The card's speech bubble (plain text; the card escapes it).
     * @param ?array $one   the top item
     * @param int    $more  other items waiting
     * @param int    $heads heads with something for you
     */
    public static function say(string $name, ?array $one, int $more, int $heads): array
    {
        if (!$one) {
            return ['lead' => self::hey($name) . ' nothing needs you right now.', 'thing' => '',
                    'after' => "I'm keeping an eye on everyone and I'll put anything new here."];
        }
        $after = $more > 0
            ? 'After that, ' . $more . ' more ' . ($more === 1 ? 'thing' : 'things') . ($heads > 1 ? ' from ' . $heads . ' of the team' : '') . ' — they can wait.'
            : "That's the only thing on the list.";
        return ['lead' => self::hey($name) . ' the one thing that needs you today:', 'thing' => $one['text'], 'after' => $after];
    }

    public static function subject(?array $one): string
    {
        if (!$one) return 'Clear morning — nothing needs you today';
        return "Today's one thing: " . self::short($one['text'], 70);
    }

    public static function short(string $s, int $max): string
    {
        $s = trim($s);
        if (mb_strlen($s) <= $max) return $s;
        $cut = mb_substr($s, 0, $max - 1);
        $sp = mb_strrpos($cut, ' ');
        return rtrim($sp > $max * 0.6 ? mb_substr($cut, 0, $sp) : $cut, " ,.;:—-") . '…';
    }

    public static function whichFirst(string $name): string
    {
        return self::hey($name) . " these two are neck and neck and I don't know your order yet. When both are waiting, which comes first?";
    }

    public static function ruleRewrite(string $name, string $newSentence): string
    {
        return self::hey($name) . " you've overridden this rule twice lately. Should it read: \"" . $newSentence . '"?';
    }

    /** Bad news first: one plain line leading the card when something went wrong. */
    public static function badLead(array $bad): string
    {
        $n = count($bad);
        if ($n === 0) return '';
        return $n === 1 ? 'First, the bad news:' : "First, the bad news — {$n} things:";
    }

    public static function mute(string $name, string $text, int $times): string
    {
        return self::hey($name) . ' you\'ve waved away things like "' . self::short($text, 80) . '" ' . $times
            . ' times now. Should I leave these out of your brief?';
    }
}
