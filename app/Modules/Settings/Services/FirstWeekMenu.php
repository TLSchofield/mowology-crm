<?php
declare(strict_types=1);

/**
 * The short menu a new business sees in its first week: eight daily items, in a fixed order, and a
 * closed "More" holding everything else the login is allowed to open. Pure: it takes the flat list of
 * items the login may see (permissions already applied) and decides what goes where. Whether the
 * short menu is on at all is FirstWeekService::isOn().
 *
 * Items are arrays with at least 'key', 'label', 'href' (and usually 'icon', 'badge').
 */
final class FirstWeekMenu
{
    /** key => label shown in the short menu (null keeps the page's own label). Order is the menu order. */
    public const MAIN = [
        'dashboard'       => 'Today',
        'clients'         => 'Clients',
        'quotes'          => null,
        'jobs'            => null,
        'schedule'        => null,
        'invoices'        => null,
        'receipts-review' => 'Receipts',
        'settings'        => null,
    ];

    /** One plain sentence each head says about herself on the short menu's card (the head the page belongs to). */
    public const INTRO = [
        'charlie' => 'I pull the day together and tell you what needs you.',
        'sam'     => 'I follow up your quotes and keep an eye on your clients.',
        'penny'   => 'I match e-Transfers to your invoices and keep the books.',
        'otto'    => 'I plan the schedule and look after the crews.',
        'mia'     => 'I look after your marketing and your reviews.',
        'yui'     => 'I read your mail and keep client messages in order.',
        'ada'     => 'I keep the systems running.',
    ];

    /**
     * @param array<int, array<string, mixed>> $items every item this login may open
     * @return array{main: array<int, array<string, mixed>>, more: array<int, array<string, mixed>>}
     */
    public static function build(array $items): array
    {
        $byKey = [];
        foreach ($items as $it) {
            $k = (string)($it['key'] ?? '');
            if ($k !== '' && !isset($byKey[$k])) {
                $byKey[$k] = $it;
            }
        }
        $main = [];
        foreach (self::MAIN as $key => $label) {
            if (!isset($byKey[$key])) {
                continue;   // this login can't open it: not shown
            }
            $it = $byKey[$key];
            if ($label !== null) {
                $it['label'] = $label;
            }
            $main[] = $it;
            unset($byKey[$key]);
        }
        return ['main' => $main, 'more' => array_values($byKey)];
    }
}
