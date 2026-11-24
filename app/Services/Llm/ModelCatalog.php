<?php
declare(strict_types=1);

/**
 * ModelCatalog — the one place that knows model ids and list prices (Ittai audit 2026-10-27).
 *
 * Before this, a model id and a price constant lived in each of the 11 call sites; changing a model
 * was an 11-file edit and nothing could total the cost. Sites still pass their own model id to
 * LlmGateway (behaviour-preserving); the catalog prices whatever id they used, and an alias
 * ('sonnet', 'opus', 'haiku') resolves to the current default for new code.
 *
 * Prices are USD per million tokens [input, output]. Sonnet and Opus 5.5 are the figures already
 * used in BankGuidanceService / BookkeeperDeskService. 'claude-opus-5' and the haiku ids are
 * ESTIMATES (same as the nearest listed model) — an unknown id is priced null, never guessed.
 * Cache reads are billed at 0.1x input, cache writes at 1.25x input.
 *
 * Pure. Global namespace; require_once it.
 */
final class ModelCatalog
{
    public const ALIASES = [
        'sonnet' => 'claude-sonnet-5-5',
        'opus'   => 'claude-opus-5-5',
        'haiku'  => 'claude-haiku-4-5',
    ];

    public const PRICES = [
        'claude-sonnet-5-5'         => [3.0, 15.0],
        'claude-sonnet-4-6'         => [3.0, 15.0],
        'claude-opus-5-5'           => [4.0, 20.0],
        'claude-opus-5'             => [4.0, 20.0],   // estimate: priced as Opus 5.5
        'claude-haiku-4-5'          => [1.0, 5.0],    // estimate
        'claude-haiku-4-5-20251001' => [1.0, 5.0],    // estimate
    ];

    public const CACHE_READ_FACTOR  = 0.1;
    public const CACHE_WRITE_FACTOR = 1.25;

    /** An alias becomes its model id; anything else is returned as given. */
    public static function id(string $aliasOrId): string
    {
        return self::ALIASES[strtolower($aliasOrId)] ?? $aliasOrId;
    }

    /** Cost in USD for one call, or null when the model has no known price. */
    public static function cost(string $model, int $in, int $out, int $cacheRead = 0, int $cacheWrite = 0): ?float
    {
        $p = self::PRICES[$model] ?? null;
        if ($p === null) {
            return null;
        }
        $usd = $in / 1e6 * $p[0]
             + $out / 1e6 * $p[1]
             + $cacheRead / 1e6 * $p[0] * self::CACHE_READ_FACTOR
             + $cacheWrite / 1e6 * $p[0] * self::CACHE_WRITE_FACTOR;
        return round($usd, 6);
    }
}
