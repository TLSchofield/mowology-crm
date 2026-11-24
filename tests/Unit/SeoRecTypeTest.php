<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * selectRecType() must only ever return a member of seo_recommendations.rec_type's ENUM.
 * Anything else is stored by MySQL as '' — which is how 60 typeless recommendations
 * accumulated between May and September 2026.
 */
final class SeoRecTypeTest extends TestCase
{
    private const ENUM = ['create_page', 'improve_page', 'title_meta', 'internal_links', 'add_photos', 'schema', 'seasonal'];

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('selectRecType')) {
            require_once __DIR__ . '/../../app/Modules/Marketing/Services/SeoFunctions.php';
        }
    }

    public function testNoPageMeansCreateOne(): void
    {
        $this->assertSame('create_page', selectRecType(['avg_position' => 5, 'avg_ctr' => 0.1], 80, false));
    }

    public function testExistingPageBranches(): void
    {
        $this->assertSame('title_meta',     selectRecType(['avg_position' => 8,  'avg_ctr' => 0.01], 70, true));
        $this->assertSame('improve_page',   selectRecType(['avg_position' => 35, 'avg_ctr' => 0.01], 70, true));
        $this->assertSame('internal_links', selectRecType(['avg_position' => 8,  'avg_ctr' => 0.05, 'page_count' => 3], 70, true));
        $this->assertSame('improve_page',   selectRecType(['avg_position' => 8,  'avg_ctr' => 0.05, 'page_count' => 1], 70, true));
    }

    public function testEveryBranchReturnsAnEnumMember(): void
    {
        foreach ([[5, 0.1, 1, false], [8, 0.01, 1, true], [35, 0.01, 1, true], [8, 0.05, 3, true], [8, 0.05, 1, true], [0, 0, 0, true]] as [$pos, $ctr, $pages, $has]) {
            $this->assertContains(selectRecType(['avg_position' => $pos, 'avg_ctr' => $ctr, 'page_count' => $pages], 50, $has), self::ENUM);
        }
    }
}
