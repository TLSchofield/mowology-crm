<?php
/**
 * HouseBriefAdapter — the Work Queue's critical lane, which no department head owns yet
 * (overdue invoices, unbilled visits, stuck visits, stale plans). Charlie carries them as
 * head 'house' so they still reach the owner. Read-only: getWorkQueueItems() is SELECTs.
 *
 * When a head takes one of these on, add its kind to SKIP so the owner never hears
 * about it twice.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class HouseBriefAdapter
{
    /** House kinds a head now owns — left out here. */
    public const SKIP = [];

    public function brief(string $ownerFirstName = ''): array
    {
        if (!function_exists('getWorkQueueItems')) {
            require_once APP_ROOT . '/Services/CrmFunctions.php';
        }
        return self::fromWorkQueue(getWorkQueueItems());
    }

    /** Pure: Work Queue rows → the shared brief shape (critical lane only). */
    public static function fromWorkQueue(array $rows): array
    {
        $items = [];
        foreach ($rows as $r) {
            if (($r['category'] ?? '') !== 'critical') continue;
            $title = trim((string)($r['title'] ?? ''));
            $kind = 'house:' . preg_replace('/s$/', '', self::slug(preg_replace('/^\d+\s+/', '', $title)));   // singular: '1 Stuck Visit' and '3 Stuck Visits' are one key
            if ($kind === 'house:' || in_array($kind, self::SKIP, true)) continue;
            $desc = trim((string)($r['description'] ?? ''));
            $value = null;
            if (preg_match('/\$\s?([\d,]+(?:\.\d+)?)/', $desc, $m)) $value = (float)str_replace(',', '', $m[1]);
            $link = (string)($r['link'] ?? '');
            $items[] = [
                'key'      => $kind,
                'kind'     => $kind,
                'text'     => self::sentence($title) . ($desc !== '' ? ' — ' . lcfirst($desc) : ''),
                'url'      => $link === '' ? null : ($link[0] === '/' ? $link : '/crm/' . $link),
                'priority' => max(1, min(3, (int)($r['priority'] ?? 2))),
                'value'    => $value,
            ];
        }
        return [
            'head'     => 'house',
            'headline' => $items ? count($items) . ' thing' . (count($items) === 1 ? '' : 's') . ' nobody else is watching' : 'Nothing slipping through the cracks',
            'items'    => $items,
            'count'    => count($items),
        ];
    }

    public static function slug(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($s)), '_');
    }

    /** "3 Overdue Invoices" → "3 overdue invoices". */
    public static function sentence(string $title): string
    {
        return preg_replace_callback('/\b([A-Z])([a-z])/', static fn($m) => strtolower($m[1]) . $m[2], $title);
    }
}
