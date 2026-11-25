<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2026-10-13: "Yui renewals: Illegal mix of collations (utf8mb4_bin,NONE) … for operation 'like'"
 * 98 times a day. Production columns mix utf8mb4_0900_ai_ci (properties, contacts) and
 * utf8mb4_general_ci (contracts); CONCAT_WS over both has no collation, so its LIKE fails.
 * Every multi-column ZZTEST filter must go through YuiDeskService::notTest(), which collates each column.
 */
class YuiDeskCollationTest extends TestCase
{
    public function test_not_test_collates_every_column(): void
    {
        $sql = YuiDeskService::notTest('p.address', 'ct.title');
        $this->assertSame(
            "CONCAT_WS(' ', p.address COLLATE utf8mb4_general_ci, ct.title COLLATE utf8mb4_general_ci) NOT LIKE '%ZZTEST%'",
            $sql
        );
    }

    public function test_not_test_strips_anything_but_column_names(): void
    {
        $this->assertStringNotContainsString(';', YuiDeskService::notTest("p.address; DROP TABLE x"));
    }

    public function test_no_raw_concat_like_left_in_the_desk(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../../app/Modules/Comms/Services/YuiDeskService.php');
        $this->assertDoesNotMatchRegularExpression('/CONCAT_WS\([^)]*\)\s+(NOT\s+)?LIKE/i', $src,
            'Use YuiDeskService::notTest() so mixed-collation columns are collated before LIKE');
        $this->assertStringContainsString("self::notTest('p.address', 'ct.title')", $src);
    }
}
