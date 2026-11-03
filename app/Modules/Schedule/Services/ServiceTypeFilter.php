<?php
declare(strict_types=1);

/**
 * The schedule's job-type filter holds several types at once (Tim, 2026-11-02:
 * "filter needs check boxes"). On the wire it is one comma-separated string, so
 * the old single-type value from apps already in use is still a valid filter.
 */
class ServiceTypeFilter
{
    /**
     * Keep only the active types, in the order asked, without repeats.
     * Returns the normalised comma string, or null for "all job types".
     *
     * @param string[] $validSlugs
     */
    public static function parse(string $raw, array $validSlugs): ?string
    {
        $keep = [];
        foreach (explode(',', $raw) as $slug) {
            $slug = trim($slug);
            if ($slug !== '' && in_array($slug, $validSlugs, true) && !in_array($slug, $keep, true)) {
                $keep[] = $slug;
            }
        }
        return $keep ? implode(',', $keep) : null;
    }

    /** @return string[] the slugs in a normalised filter string (empty for null). */
    public static function slugs(?string $filter): array
    {
        if ($filter === null || trim($filter) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $filter)), 'strlen'));
    }
}
