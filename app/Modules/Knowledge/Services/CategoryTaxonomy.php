<?php
/**
 * CategoryTaxonomy — the trade-neutral expense taxonomy the commons speaks.
 *
 * Tenants keep their own category lists (Mowology: EXPENSE_ACCOUNTING_CATEGORIES, 14 strings).
 * The commons never stores a tenant's category name; it stores one of these codes, and each
 * install bridges code ↔ local name through kb_category_map (seeded by migration 1330).
 * A plumber, an electrician and a landscaper all map "fuel" to whatever they call fuel.
 *
 * No namespace / no autoloader: require_once and `new CategoryTaxonomy($db)`.
 */
declare(strict_types=1);

final class CategoryTaxonomy
{
    /** code => neutral label. Add codes here only; never a trade's own words. */
    public const CODES = [
        'materials'        => 'Materials & supplies',
        'fuel'             => 'Fuel',
        'tools_equipment'  => 'Tools & equipment',
        'parts_repairs'    => 'Parts & repairs',
        'disposal'         => 'Disposal & recycling',
        'permits_licences' => 'Permits & licences',
        'subcontract'      => 'Subcontractors',
        'marketing'        => 'Marketing',
        'office_admin'     => 'Office & admin',
        'software'         => 'Software & subscriptions',
        'meals'            => 'Meals',
        'vehicle'          => 'Vehicle',
        'safety_uniforms'  => 'Safety & uniforms',
        'insurance'        => 'Insurance',
        'utilities'        => 'Utilities & phone',
        'other'            => 'Other',
        'personal'         => 'Personal (not business)',   // never shared — see PRIVATE_CODES
    ];

    /** Codes that describe this tenant's owner, not the vendor: never sent to the commons (2026-10-13). */
    public const PRIVATE_CODES = ['personal'];

    /** Default bridge for an install that uses Mowology's original 14 categories. */
    public const DEFAULT_MAP = [
        'materials'        => 'Materials',
        'fuel'             => 'Fuel',
        'tools_equipment'  => 'Tools/Equipment',
        'parts_repairs'    => 'Repairs/Maintenance',
        'disposal'         => 'Disposal/Dump',
        'permits_licences' => 'Licenses/Permits',
        'subcontract'      => 'Subcontractors',
        'marketing'        => 'Marketing',
        'office_admin'     => 'Office/Admin',
        'software'         => 'Office/Admin',
        'meals'            => 'Meals',
        'vehicle'          => 'Vehicle',
        'safety_uniforms'  => 'Safety',
        'insurance'        => 'Overhead',
        'utilities'        => 'Overhead',
        'other'            => 'Other',
        'personal'         => 'Personal',
    ];

    private ?PDO $db;
    /** @var array<string,string>|null code => local */
    private ?array $map = null;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
    }

    public static function isCode(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::CODES);
    }

    /** The install's bridge (kb_category_map), falling back to the default map. */
    public function map(): array
    {
        if ($this->map !== null) return $this->map;
        $this->map = self::DEFAULT_MAP;
        if ($this->db) {
            try {
                $rows = $this->db->query("SELECT neutral_code, local_category FROM kb_category_map")->fetchAll(PDO::FETCH_KEY_PAIR);
                if ($rows) $this->map = array_merge($this->map, $rows);
            } catch (Throwable $e) { /* before migration 1330: defaults */ }
        }
        return $this->map;
    }

    /** Neutral code → this install's category name ('other' when unmapped). */
    public function toLocal(?string $code): ?string
    {
        if (!self::isCode($code)) return null;
        $m = $this->map();
        return $m[$code] ?? ($m['other'] ?? null);
    }

    /** This install's category name → neutral code; null when it maps to nothing. */
    public function fromLocal(?string $local): ?string
    {
        if ($local === null || trim($local) === '') return null;
        $needle = mb_strtolower(trim($local));
        $best = null;
        foreach ($this->map() as $code => $name) {
            if (mb_strtolower($name) === $needle) {
                // several codes can share a local name (software/office_admin → Office/Admin):
                // prefer the first, most general, listing order in DEFAULT_MAP.
                if ($best === null) $best = $code;
            }
        }
        return $best;
    }
}
