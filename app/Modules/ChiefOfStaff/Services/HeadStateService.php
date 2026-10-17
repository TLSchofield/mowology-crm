<?php
/** Pure, server-side state for the seven AI heads. */
declare(strict_types=1);

class HeadStateService
{
    public const HEADS = ['charlie', 'yui', 'sam', 'penny', 'otto', 'mia', 'ada'];

    /**
     * @param array<string, array> $itemsByHead Every successfully loaded source, including idle ones.
     * @param array<int|string, mixed> $failedSources Source slugs, either as values or associative keys.
     * @return array<string, array{state:string, reason:string, open:int, needs_you:int}>
     */
    public static function fromBrief(array $itemsByHead, array $failedSources): array
    {
        $failed = [];
        foreach ($failedSources as $key => $value) {
            $slug = is_int($key) ? (string)$value : (string)$key;
            $failed[strtolower($slug)] = true;
        }

        $out = [];
        $totalOpen = 0;
        $totalNeedsYou = 0;
        foreach (self::HEADS as $slug) {
            if ($slug === 'charlie') continue;
            [$open, $needsYou] = self::counts((array)($itemsByHead[$slug] ?? []));
            if (isset($failed[$slug])) {
                $totalOpen += $open;
                $totalNeedsYou += $needsYou;
                $out[$slug] = self::row('blocked', 'Desk did not load', $open, $needsYou);
                continue;
            }
            if (!array_key_exists($slug, $itemsByHead)) {
                $out[$slug] = self::row('unavailable', 'Not set up yet', 0, 0);
                continue;
            }
            $totalOpen += $open;
            $totalNeedsYou += $needsYou;
            $out[$slug] = self::row($needsYou ? 'approval' : ($open ? 'working' : 'idle'), '', $open, $needsYou);
        }

        // Charlie represents the whole brief, including Charlie/house items and successful heads.
        foreach ($itemsByHead as $slug => $items) {
            if (in_array((string)$slug, self::HEADS, true) && $slug !== 'charlie') continue;
            [$open, $needsYou] = self::counts((array)$items);
            $totalOpen += $open;
            $totalNeedsYou += $needsYou;
        }
        $charlieBlocked = isset($failed['charlie']);
        $out['charlie'] = self::row(
            $charlieBlocked ? 'blocked' : ($totalNeedsYou ? 'approval' : ($totalOpen ? 'working' : 'idle')),
            $charlieBlocked ? 'Brief did not load' : '',
            $totalOpen,
            $totalNeedsYou
        );

        $ordered = [];
        foreach (self::HEADS as $slug) $ordered[$slug] = $out[$slug];
        return $ordered;
    }

    private static function counts(array $items): array
    {
        $open = 0;
        $needsYou = 0;
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $open++;
            if (!empty($item['yes'])) $needsYou++;
        }
        return [$open, $needsYou];
    }

    private static function row(string $state, string $reason, int $open, int $needsYou): array
    {
        return ['state' => $state, 'reason' => $reason, 'open' => $open, 'needs_you' => $needsYou];
    }
}
