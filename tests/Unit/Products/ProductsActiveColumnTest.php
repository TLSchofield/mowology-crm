<?php

use PHPUnit\Framework\TestCase;

/**
 * The products table marks a live product with `active` (and `is_archived`), not `is_active`.
 * Other tables (contacts, email_templates, recommendation rules) do use `is_active`, so the
 * wrong one is easy to copy across. On 2026-11-01 the seasonal_triggers cron failed with
 * "Unknown column 'is_active'" for this reason, and the referral settings and reward picker
 * carried the same error. This scan fails if a query on products filters by is_active again.
 */
class ProductsActiveColumnTest extends TestCase
{
    public function testNoQueryFiltersProductsByIsActive(): void
    {
        $root = dirname(__DIR__, 3);
        $bad = [];
        foreach (['app', 'public/crm'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') continue;
                $src = file_get_contents($file->getPathname());
                // "FROM products" (no alias) followed within the same statement by an unqualified is_active filter.
                if (preg_match_all('/FROM\s+products\b(?!\s+\w+\s+(?:WHERE|JOIN|LEFT|INNER|ON)\b)(?:(?!;|FROM\s)[\s\S]){0,300}?(?<![\w.])is_active\s*=/i', $src, $m)) {
                    $bad[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        $this->assertSame([], $bad, 'products has no is_active column; use active = 1 AND COALESCE(is_archived, 0) = 0');
    }
}
