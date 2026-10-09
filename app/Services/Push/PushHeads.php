<?php
declare(strict_types=1);

/**
 * PushHeads — every notification says which department head it is from (Tim, 2026-10-08):
 * the title becomes "Sam [Sales]", "Otto [Ops]", … and the original title moves into the body.
 *
 * The head is data['head'] when the caller set it; otherwise it is read from data['type'] /
 * data['screen'] / data['open'] (quotes → Sam; jobs, crew, trips, tracking → Otto; receipts → Penny).
 * A notification with no recognisable head is left exactly as it was.
 */
final class PushHeads
{
    public const HEADS = [
        'charlie' => ['Charlie', 'Foreman'],
        'sam'     => ['Sam', 'Sales'],
        'otto'    => ['Otto', 'Ops'],
        'penny'   => ['Penny', 'Books'],
        'mia'     => ['Mia', 'Marketing'],
        'yui'     => ['Yui', 'Comms'],
    ];

    /** "Sam [Sales]" for a head slug; null when unknown. */
    public static function label(?string $head): ?string
    {
        $h = self::HEADS[strtolower((string)$head)] ?? null;
        return $h ? $h[0] . ' [' . $h[1] . ']' : null;
    }

    /** Which head a push belongs to, from its data payload (and title as a last resort). */
    public static function headFor(array $data, string $title = ''): ?string
    {
        $head = strtolower(trim((string)($data['head'] ?? '')));
        if (isset(self::HEADS[$head])) return $head;
        $tag = strtolower(implode(' ', array_filter([(string)($data['type'] ?? ''), (string)($data['screen'] ?? ''), (string)($data['open'] ?? '')])));
        if (preg_match('/quote|lead|sales/', $tag)) return 'sam';
        if (preg_match('/receipt|penny|expense|invoice|payment/', $tag)) return 'penny';
        if (preg_match('/schedule|visit|stop|crew|trip|tracking|job|route|special_request/', $tag)) return 'otto';
        if (preg_match('/campaign|review|social|marketing/', $tag)) return 'mia';
        if (preg_match('/message|reply|comms|client/', $tag)) return 'yui';
        foreach (self::HEADS as $slug => $h) {
            if (stripos($title, $h[0] . ':') === 0 || strcasecmp(trim($title), $h[0]) === 0) return $slug;
        }
        return null;
    }

    /**
     * [title, body] with the head's label as the title. The old title ("Job Assigned",
     * "Penny: got the receipt?") leads the body unless it is just the head's name.
     */
    public static function brand(string $title, string $body, array $data): array
    {
        $head = self::headFor($data, $title);
        if ($head === null) return [$title, $body];
        $label = (string)self::label($head);
        if (strpos($title, $label) === 0) return [$title, $body];            // already branded
        $name = self::HEADS[$head][0];
        $lead = trim((string)preg_replace('/^' . preg_quote($name, '/') . '\s*[:\-–—]\s*/iu', '', $title));
        if (strcasecmp($lead, $name) === 0) $lead = '';
        $newBody = $lead === '' ? $body : ($body === '' ? $lead : $lead . ' — ' . $body);
        return [$label, $newBody];
    }
}
