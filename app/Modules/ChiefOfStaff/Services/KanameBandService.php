<?php
/**
 * KanameBandService — Kaname's band across the top of every office page in the Ittai desktop shell
 * (Tim, 2026-11-10: the search goes top right, her line runs beside it, fixed, "where important alerts and
 * heads' notifications can land").
 *
 * What lands there is already decided elsewhere; nothing here judges urgency:
 *   - her count and line: HeadNavService::shape() rows (the sidebar's own copy, owner-gated there);
 *   - alerts: the last ALERT_HOURS of charlie_alerts — the only things Kaname interrupts the owner for
 *     (CharlieUrgentService decides what gets written there);
 *   - heads: a head whose ready state is approval or blocked gets a chip to her first page.
 *
 * build() is pure and tested. Global-namespace service: require_once the file.
 */
declare(strict_types=1);

class KanameBandService
{
    public const ALERT_HOURS = 24;
    public const ALERT_CAP = 3;
    public const HEAD_CAP = 4;
    public const SHOW_STATES = ['approval', 'blocked'];
    /** Where an alert of each kind takes the owner; anything else opens the brief. */
    public const ALERT_URLS = [
        'payment'     => '/crm/invoices/',
        'invoice_pdf' => '/crm/invoices/',
        'weather'     => '/crm/jobs/schedule.php',
        'dispute'     => '/crm/ledger/',
        'books'       => '/crm/ledger/',
        'security'    => '/crm/settings.php',
    ];
    public const BRIEF_URL = '/crm/kaname_appstack.php';

    /**
     * @param array $heads  HeadNavService::shape() output (slug → state, word, needs_you, line)
     * @param array $alerts rows of ['kind' => , 'text' => , 'created_at' => ], newest first
     * @param array $blocks mwIttaiNavBlocks() output (slug, name, items[href]) — the heads' order and first pages
     */
    public static function build(array $heads, array $alerts, array $blocks, int $now, string $chiefName = 'Kaname'): array
    {
        $c = (array)($heads['charlie'] ?? []);
        $word = (string)($c['word'] ?? '');
        $state = (string)($c['state'] ?? '');
        $line = trim((string)($c['line'] ?? ''));
        if ($line === '' && $word === '') $line = 'Nothing is waiting on you.';

        $outAlerts = [];
        foreach ($alerts as $a) {
            if (!is_array($a)) continue;
            $text = trim((string)($a['text'] ?? ''));
            if ($text === '') continue;
            $kind = (string)($a['kind'] ?? '');
            $at = isset($a['created_at']) ? strtotime((string)$a['created_at']) : false;
            $outAlerts[] = [
                'kind' => $kind,
                'text' => $text,
                'url'  => self::ALERT_URLS[$kind] ?? self::BRIEF_URL,
                'age'  => $at ? self::age($now - $at) : '',
            ];
            if (count($outAlerts) >= self::ALERT_CAP) break;
        }

        $outHeads = [];
        foreach ($blocks as $b) {
            $slug = (string)($b['slug'] ?? '');
            if ($slug === '' || $slug === 'charlie') continue;
            $h = (array)($heads[$slug] ?? []);
            if (!in_array((string)($h['state'] ?? ''), self::SHOW_STATES, true)) continue;
            $first = (array)(($b['items'] ?? [])[0] ?? []);
            $outHeads[] = [
                'slug'  => $slug,
                'name'  => (string)($b['name'] ?? ucfirst($slug)),
                'state' => (string)$h['state'],
                'word'  => (string)($h['word'] ?? ''),
                'url'   => (string)($first['href'] ?? self::BRIEF_URL),
            ];
            if (count($outHeads) >= self::HEAD_CAP) break;
        }

        // One subject at a time (Tim, 2026-11-10): the newest alert if there is one, else her line. The rest is a count.
        $subject = $outAlerts ? ['kind' => 'alert', 'text' => $outAlerts[0]['text'], 'url' => $outAlerts[0]['url'], 'age' => $outAlerts[0]['age']]
                              : ['kind' => 'line', 'text' => $line, 'url' => self::BRIEF_URL, 'age' => ''];
        $more = max(0, count($outAlerts) - 1);

        return [
            'subject' => $subject,
            'more'    => $more,
            'name'   => $chiefName,
            'state'  => $state,
            'word'   => $word,
            'count'  => (int)($c['needs_you'] ?? 0),
            'line'   => $line,
            'alerts' => $outAlerts,
            'heads'  => $outHeads,
            'quiet'  => !$outAlerts && !$outHeads,
        ];
    }

    /** "just now", "40 min", "3 h", "yesterday". Pure. */
    public static function age(int $seconds): string
    {
        if ($seconds < 120) return 'just now';
        if ($seconds < 3600) return intdiv($seconds, 60) . ' min';
        if ($seconds < 86400) return intdiv($seconds, 3600) . ' h';
        return 'yesterday';
    }

    /** The last ALERT_HOURS of alerts, newest first; [] when the table is missing or anything fails. */
    public static function alerts(PDO $db, int $now): array
    {
        try {
            $s = $db->prepare('SELECT kind, text, created_at FROM charlie_alerts WHERE created_at >= ? ORDER BY created_at DESC LIMIT ' . (self::ALERT_CAP + 2));
            $s->execute([date('Y-m-d H:i:s', $now - self::ALERT_HOURS * 3600)]);
            return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function load(PDO $db, array $nav, array $blocks, int $now, string $chiefName): array
    {
        return self::build((array)($nav['heads'] ?? []), self::alerts($db, $now), $blocks, $now, $chiefName);
    }
}
