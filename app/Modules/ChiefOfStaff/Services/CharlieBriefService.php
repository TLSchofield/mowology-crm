<?php
/**
 * CharlieBriefService — asks every department head for its brief.
 *
 * Contract (shared by every head): brief(string $ownerFirstName): array
 *   ['head' => 'sam'|'otto'|'mia'|'yui'|'penny'|'house', 'headline' => string, 'count' => int,
 *    'items' => [['key' => string (required, stable), 'text' => string, 'url' => ?string,
 *                 'priority' => 1|2|3, 'kind' => ?string, 'value' => ?float, 'since' => ?Y-m-d]]]
 * brief() must be read-only and cheap.
 *
 * A head that isn't deployed yet is simply absent (is_file / class_exists / method_exists),
 * and a head that throws is reported in 'failed' — never fatal, and never taken to mean its
 * items were dealt with.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/CharlieRankService.php';

class CharlieBriefService
{
    /** Who's who, in the order the brief lists them. */
    public const HEADS = [
        'charlie' => ['name' => 'Charlie', 'role' => 'Foreman · deadlines'],
        'penny' => ['name' => 'Penny', 'role' => 'Bookkeeper'],
        'sam'   => ['name' => 'Sam',   'role' => 'Sales'],
        'otto'  => ['name' => 'Otto',  'role' => 'Operations'],
        'mia'   => ['name' => 'Mia',   'role' => 'Marketing & relationships'],
        'yui'   => ['name' => 'Yui',   'role' => 'Comms · client relations'],
        'house' => ['name' => 'Everything else', 'role' => 'Work Queue'],
    ];
    /** A head slower than this is logged — brief() is meant to be cheap. */
    public const SLOW_MS = 1500;

    private PDO $db;
    /** @var array<string, callable(string): array> */
    private array $sources;

    /** @param array<string, callable>|null $sources head => fn(string $name): array (tests inject these) */
    public function __construct(PDO $db, ?array $sources = null)
    {
        $this->db = $db;
        $this->sources = $sources ?? $this->defaultSources();
    }

    /** @return array<string, callable> only the heads that are deployed here */
    private function defaultSources(): array
    {
        $db = $this->db;
        $out = [];
        // Charlie's own calendar (migration 1171): deadlines inside their reminder window.
        require_once __DIR__ . '/DeadlineService.php';
        if ((new DeadlineService($db))->ready()) {
            $out['charlie'] = static fn(string $n) => (new DeadlineService($db))->brief($n);
        }
        $out['penny'] = static function (string $n) use ($db) {
            require_once __DIR__ . '/PennyBriefAdapter.php';
            return (new PennyBriefAdapter($db))->brief($n);
        };
        $desks = [
            'sam'  => ['/Modules/Sales/Services/SalesDeskService.php', 'SalesDeskService'],
            'otto' => ['/Modules/Operations/Services/OpsDeskService.php', 'OpsDeskService'],
            'mia'  => ['/Modules/Marketing/Services/MiaDeskService.php', 'MiaDeskService'],
            'yui'  => ['/Modules/Comms/Services/YuiDeskService.php', 'YuiDeskService'],
        ];
        foreach ($desks as $head => [$file, $class]) {
            if (!defined('APP_ROOT') || !is_file(APP_ROOT . $file)) continue;
            $out[$head] = static function (string $n) use ($db, $file, $class) {
                require_once APP_ROOT . $file;
                if (!class_exists($class) || !method_exists($class, 'brief')) {
                    throw new RuntimeException("{$class}::brief() not available");
                }
                return (new $class($db))->brief($n);
            };
        }
        $out['house'] = static function (string $n) {
            require_once __DIR__ . '/HouseBriefAdapter.php';
            return (new HouseBriefAdapter())->brief($n);
        };
        return $out;
    }

    /**
     * @return array{heads: array<string, array>, items: array, ok: string[], failed: array<string, string>}
     *   heads: head => [name, role, headline, count, items (normalized, the head's order)]
     */
    public function collect(string $ownerFirstName): array
    {
        // Asked once per request: the card, the inbox and the cron all reuse the answer.
        if (isset($this->memo[$ownerFirstName])) return $this->memo[$ownerFirstName];
        return $this->memo[$ownerFirstName] = $this->ask($ownerFirstName);
    }

    /** @var array<string, array> */
    private array $memo = [];

    private function ask(string $ownerFirstName): array
    {
        $heads = [];
        $items = [];
        $ok = [];
        $failed = [];
        $seen = [];
        foreach ($this->sources as $head => $fn) {
            $t = microtime(true);
            try {
                $b = $fn($ownerFirstName);
                if (!is_array($b)) throw new RuntimeException('brief() returned nothing');
            } catch (Throwable $e) {
                $failed[$head] = $e->getMessage();
                error_log("Charlie: {$head} brief failed: " . $e->getMessage());
                continue;
            }
            $ms = (int)round((microtime(true) - $t) * 1000);
            if ($ms > self::SLOW_MS) error_log("Charlie: {$head} brief took {$ms} ms");
            $ok[] = $head;
            $mine = [];
            foreach ((array)($b['items'] ?? []) as $raw) {
                if (!is_array($raw)) continue;
                $it = CharlieRankService::normalize($raw, $head);
                if (!$it || isset($seen[$it['key']])) continue;
                $seen[$it['key']] = true;
                $mine[] = $it;
                $items[] = $it;
            }
            $meta = self::HEADS[$head] ?? ['name' => ucfirst($head), 'role' => ''];
            $heads[$head] = $meta + [
                'head'     => $head,
                'headline' => trim((string)($b['headline'] ?? '')),
                'count'    => max(count($mine), (int)($b['count'] ?? 0)),
                'items'    => $mine,
            ];
        }
        return ['heads' => $heads, 'items' => $items, 'ok' => $ok, 'failed' => $failed];
    }
}
