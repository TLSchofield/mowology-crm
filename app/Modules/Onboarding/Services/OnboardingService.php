<?php
/**
 * OnboardingService — the onboarding path: which modules, in what order, which are skipped, and where the
 * owner is (owner, 2026-10-20: modules "moved up or down the step progress"; a map "we can keep on the
 * website in admin"; each screen low information).
 *
 *   order    ops_settings onboarding_order (a json_encode'd list of module keys). Modules missing from it
 *            (newly added) go before Done in their default place; unknown keys are dropped.
 *   skipped  ops_settings onboarding_skipped (list). A skipped module counts as finished on the map.
 *   move     up / down one place; refused when it would put a module before something it needs (Your books
 *            needs QuickBooks — the QuickBooks answer decides whether the books step is needed), or move
 *            a pinned module (Done is always last).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/OnboardingModules.php';

class OnboardingService
{
    public const ORDER = 'onboarding_order';
    public const SKIPPED = 'onboarding_skipped';
    public const OWNERS = ['penny' => 'Penny', 'sam' => 'Sam', 'otto' => 'Otto', 'charlie' => 'Charlie'];

    private OnboardingContext $c;
    /** @var array<string, OnboardingModule> */
    private array $modules = [];

    /** @param list<OnboardingModule>|null $modules */
    public function __construct(OnboardingContext $c, ?array $modules = null)
    {
        $this->c = $c;
        foreach ($modules ?? self::defaultModules() as $m) $this->modules[$m->key()] = $m;
    }

    /** The default path. A new module: one line here (its place is the default order). */
    public static function defaultModules(): array
    {
        return [new OnboardingNameModule(), new OnboardingCompanyModule(), new OnboardingQuickBooksModule(),
                new OnboardingBooksModule(), new OnboardingAccountantModule(), new OnboardingDoneModule()];
    }

    public function context(): OnboardingContext { return $this->c; }

    public function module(string $key): ?OnboardingModule { return $this->modules[$key] ?? null; }

    // ─────────────────────────────────────────────────────────────────────────
    // Order
    // ─────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    public function order(): array
    {
        $saved = json_decode((string)$this->c->setting(self::ORDER), true);
        return self::normalise(is_array($saved) ? $saved : [], array_keys($this->modules), $this->pinnedKeys());
    }

    /**
     * Pure (tested): the saved order made whole — unknown keys dropped, each module once, new modules in
     * their default place, pinned modules where the default puts them (Done last).
     * @param list<string> $saved  @param list<string> $all  default order  @param list<string> $pinned
     */
    public static function normalise(array $saved, array $all, array $pinned): array
    {
        $out = [];
        foreach ($saved as $k) {
            if (is_string($k) && in_array($k, $all, true) && !in_array($k, $pinned, true) && !in_array($k, $out, true)) $out[] = $k;
        }
        foreach ($all as $i => $k) {
            if (in_array($k, $out, true) || in_array($k, $pinned, true)) continue;
            // A module not in the saved order yet: after the module that comes before it by default.
            $prev = null;
            for ($j = $i - 1; $j >= 0; $j--) { if (in_array($all[$j], $out, true)) { $prev = $all[$j]; break; } }
            $at = $prev === null ? 0 : array_search($prev, $out, true) + 1;
            array_splice($out, $at, 0, [$k]);
        }
        foreach ($all as $k) if (in_array($k, $pinned, true)) $out[] = $k;
        return $out;
    }

    /**
     * Pure (tested): the order after moving $key one place, or why not.
     * @param array<string, list<string>> $needs
     * @return array{ok:bool, order?:list<string>, message?:string}
     */
    public static function moved(array $order, string $key, int $dir, array $needs, array $pinned, array $titles = []): array
    {
        $i = array_search($key, $order, true);
        if ($i === false) return ['ok' => false, 'message' => 'No such step.'];
        $j = $i + ($dir < 0 ? -1 : 1);
        if ($j < 0 || $j >= count($order)) return ['ok' => false, 'message' => 'It is already ' . ($dir < 0 ? 'first' : 'last') . '.'];
        if (in_array($key, $pinned, true) || in_array($order[$j], $pinned, true)) return ['ok' => false, 'message' => '"' . ($titles['done'] ?? 'Done') . '" always comes last.'];
        $new = $order;
        [$new[$i], $new[$j]] = [$new[$j], $new[$i]];
        // Nothing may come before a step it needs.
        foreach ($new as $pos => $k) {
            foreach ($needs[$k] ?? [] as $need) {
                $np = array_search($need, $new, true);
                if ($np !== false && $np > $pos) {
                    return ['ok' => false, 'message' => '"' . ($titles[$k] ?? $k) . '" needs "' . ($titles[$need] ?? $need) . '" first.'];
                }
            }
        }
        return ['ok' => true, 'order' => $new];
    }

    /** @return array{ok:bool, message:string} */
    public function move(string $key, int $dir): array
    {
        $needs = []; $titles = [];
        foreach ($this->modules as $k => $m) { $needs[$k] = $m->needs(); $titles[$k] = $m->title(); }
        $r = self::moved($this->order(), $key, $dir, $needs, $this->pinnedKeys(), $titles);
        if (!$r['ok']) return ['ok' => false, 'message' => $r['message']];
        $this->c->saveSetting(self::ORDER, json_encode($r['order']), 'Onboarding: the order of the steps (OnboardingService)');
        return ['ok' => true, 'message' => 'Moved "' . $titles[$key] . '" ' . ($dir < 0 ? 'up' : 'down') . '.'];
    }

    /** @return list<string> */
    public function skipped(): array
    {
        $s = json_decode((string)$this->c->setting(self::SKIPPED), true);
        return is_array($s) ? array_values(array_filter($s, fn($k) => is_string($k) && isset($this->modules[$k]))) : [];
    }

    public function skip(string $key, bool $skip): array
    {
        $m = $this->module($key);
        if (!$m || !$m->skippable()) return ['ok' => false, 'message' => 'That step cannot be skipped.'];
        $s = array_values(array_diff($this->skipped(), [$key]));
        if ($skip) $s[] = $key;
        $this->c->saveSetting(self::SKIPPED, json_encode($s), 'Onboarding: steps the owner skipped (OnboardingService)');
        return ['ok' => true, 'message' => ($skip ? 'Skipped' : 'Brought back') . ' "' . $m->title() . '".'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The map
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The path as the map shows it: each step's place, state (done / next / todo / skipped / not needed),
     * owner and one line. "next" is the first step still to do.
     * @return list<array{key:string, title:string, owner:string, state:string, summary:string, first:bool, last:bool, skippable:bool, pinned:bool}>
     */
    public function map(): array
    {
        $order = $this->order(); $skipped = $this->skipped(); $rows = []; $nextSeen = false;
        foreach ($order as $i => $k) {
            $m = $this->modules[$k];
            $state = in_array($k, $skipped, true) ? 'skipped' : $m->state($this->c);
            if ($k === 'done' && $state !== OnboardingModule::DONE && $this->allBeforeFinished($order, $skipped)) $state = OnboardingModule::TODO;
            if ($state === OnboardingModule::TODO && !$nextSeen) { $state = 'next'; $nextSeen = true; }
            $rows[] = ['key' => $k, 'title' => $m->title(), 'owner' => self::OWNERS[$m->owner()] ?? $m->owner(), 'state' => $state,
                       'summary' => $m->summary($this->c), 'first' => $i === 0, 'last' => $i === count($order) - 1,
                       'skippable' => $m->skippable(), 'pinned' => $m->pinned()];
        }
        return $rows;
    }

    /** The module to show now (first not finished), or null when the path is complete. */
    public function next(): ?string
    {
        foreach ($this->map() as $r) if ($r['state'] === 'next') return $r['key'];
        return null;
    }

    /** "3 of 5 steps done" for the header. @return array{done:int, total:int} */
    public function progress(): array
    {
        $rows = array_filter($this->map(), fn($r) => $r['key'] !== 'done' && $r['state'] !== OnboardingModule::NOT_NEEDED);
        $done = count(array_filter($rows, fn($r) => in_array($r['state'], [OnboardingModule::DONE, 'skipped'], true)));
        return ['done' => $done, 'total' => count($rows)];
    }

    /** Every step before Done is done, skipped or not needed — only then can onboarding be finished. */
    public function readyToFinish(): bool
    {
        return $this->allBeforeFinished($this->order(), $this->skipped());
    }

    private function allBeforeFinished(array $order, array $skipped): bool
    {
        foreach ($order as $k) {
            if ($k === 'done') continue;
            if (in_array($k, $skipped, true)) continue;
            if (!in_array($this->modules[$k]->state($this->c), [OnboardingModule::DONE, OnboardingModule::NOT_NEEDED], true)) return false;
        }
        return true;
    }

    private function pinnedKeys(): array
    {
        return array_values(array_map(fn($m) => $m->key(), array_filter($this->modules, fn($m) => $m->pinned())));
    }
}
