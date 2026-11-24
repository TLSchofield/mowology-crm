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

    /**
     * SQL for "this job shows under the filter". A job's type is free text (the field form, contract packages), so a
     * type the filter's list doesn't know always shows (Tim, 2026-11-09: a lawn cut saved as "Lawn Cut" vanished under
     * the filter, as did 83 of 1,197 upcoming visits). Spelling and capitals don't count: "Hedge Trimming" is hedge_trimming.
     *
     * @param string   $column SQL column holding the job's type
     * @param string[] $chosen slugs picked in the filter
     * @param array<string,string> $known every active type, slug => label
     * @return array{0:string,1:string[]} SQL fragment and its parameters
     */
    public static function showsClause(string $column, array $chosen, array $known): array
    {
        $norm = static fn (string $s): string => strtolower(str_replace('_', ' ', trim($s)));
        $want = [];
        foreach ($chosen as $slug) {
            $want[] = $norm($slug);
            if (isset($known[$slug])) {
                $want[] = $norm((string)$known[$slug]);
            }
        }
        $want = array_values(array_unique($want));
        $all  = [];
        foreach ($known as $slug => $label) {
            $all[] = $norm((string)$slug);
            $all[] = $norm((string)$label);
        }
        $all  = array_values(array_unique($all));

        $expr = "LOWER(REPLACE(COALESCE($column, ''), '_', ' '))";
        $marks = static fn (array $a): string => implode(',', array_fill(0, count($a), '?'));
        $sql  = "$expr IN (" . $marks($want) . ')';
        if ($all) {
            $sql = "($sql OR $expr NOT IN (" . $marks($all) . '))';
        }
        return [$sql, array_merge($want, $all)];
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
