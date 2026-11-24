<?php
declare(strict_types=1);

/**
 * Link-only access for ittai.ca/g/<code> (2026-11-11).
 *
 * The code is 48 random hex characters. Only its SHA-256 is stored (links.php), so the repository never holds a working link.
 * A code can expire (a date) and is revoked by deleting its line. Anything else (wrong code, expired, throttled) is answered
 * with the same plain 404 as a page that does not exist. No visitor address is stored: the throttle file is keyed by a hash.
 */

/** The links.php entry for this code, or null if the code is malformed, unknown or expired. */
function ittaiLinkLookup(string $code, array $links, int $now): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $code)) return null;
    $hash = hash('sha256', $code);
    $found = null;
    foreach ($links as $stored => $meta) {            // no early exit: every entry is compared
        if (hash_equals((string)$stored, $hash)) $found = (array)$meta;
    }
    if ($found === null) return null;
    $expires = (string)($found['expires'] ?? '');
    if ($expires !== '') {
        $end = strtotime($expires . ' 23:59:59 UTC');
        if ($end === false || $end < $now) return null;
    }
    return $found;
}

/** True when this visitor has missed too often lately; $recordMiss adds one miss. 15 misses in 10 minutes. */
function ittaiThrottled(string $dir, string $visitor, int $now, bool $recordMiss = false, int $limit = 15, int $window = 600): bool
{
    $file = rtrim($dir, '/') . '/ittai-g-' . substr(hash('sha256', $visitor), 0, 24) . '.json';
    $misses = [];
    if (is_file($file)) {
        $raw = json_decode((string)@file_get_contents($file), true);
        foreach (is_array($raw) ? $raw : [] as $t) {
            if (is_int($t) && $t > $now - $window) $misses[] = $t;
        }
    }
    if ($recordMiss) {
        $misses[] = $now;
        @file_put_contents($file, json_encode($misses), LOCK_EX);
    }
    return count($misses) >= $limit;
}

/** The photos this page may serve, by name. Nothing else is ever read from disk. */
function ittaiPageImages(): array
{
    return ['crew-hedge.jpg', 'hedge-before.jpg', 'hedge-after.jpg', 'strata-aerial.jpg'];
}
