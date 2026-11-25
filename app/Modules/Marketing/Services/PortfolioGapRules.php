<?php
/**
 * PortfolioGapRules — which published portfolio projects are visibly unfinished. Pure: rows in, plain words out.
 *
 * Why (2026-11-24): the portfolio page prints a project's description only when the record has one, so a project with an empty
 * description shows as a bare card next to finished ones, and nothing in the product noticed. Mia's website watch reads Search
 * Console only (and has been "not connected" for weeks), so this check does not depend on it.
 *
 * Mia SUGGESTS only: each gap links to the portfolio screen where the owner fixes the record. Nothing here edits anything.
 * Tenant-neutral wording on purpose ("project", "work"), not trade words.
 */
class PortfolioGapRules
{
    /** A description shorter than this reads as unfinished on a card. */
    public const MIN_DESCRIPTION = 20;
    public const URL = '/crm/portfolio/index.php';

    /**
     * @param array<int, array<string, mixed>> $projects rows with id, project_name, description, location (published ones only)
     * @return array<int, array{id: int, name: string, missing: string[]}>
     */
    public static function find(array $projects): array
    {
        $out = [];
        foreach ($projects as $p) {
            $missing = [];
            if (mb_strlen(trim((string)($p['description'] ?? ''))) < self::MIN_DESCRIPTION) $missing[] = 'description';
            if (trim((string)($p['location'] ?? '')) === '') $missing[] = 'location';
            if ($missing) {
                $out[] = ['id' => (int)($p['id'] ?? 0), 'name' => trim((string)($p['project_name'] ?? '')) ?: 'Untitled project', 'missing' => $missing];
            }
        }
        return $out;
    }

    /** One line for the card: names the first few projects and what each lacks. */
    public static function text(array $gaps): string
    {
        $n = count($gaps);
        if ($n === 0) return '';
        $named = array_map(static function (array $g): string { return '"' . $g['name'] . '" (no ' . implode(' or ', $g['missing']) . ')'; }, array_slice($gaps, 0, 3));
        return sprintf('%d portfolio project%s look%s unfinished on the website: %s%s. The page shows a bare card until the record is filled in.',
            $n, $n === 1 ? '' : 's', $n === 1 ? 's' : '', implode(', ', $named), $n > 3 ? ' and ' . ($n - 3) . ' more' : '');
    }

    /** The suggestion in the same shape as the Search Console ones, so the card shows it without new code. */
    public static function suggestions(array $gaps): array
    {
        if (!$gaps) return [];
        return [['type' => 'portfolio', 'path' => '/portfolio', 'text' => self::text($gaps), 'cms_url' => self::URL]];
    }
}
