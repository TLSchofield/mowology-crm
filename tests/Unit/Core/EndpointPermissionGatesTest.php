<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** #15, #16, #17 (Fable audit 2026-10-12): endpoint permission gates stay in place. */
final class EndpointPermissionGatesTest extends TestCase
{
    private function src(string $f): string
    {
        return (string)file_get_contents(__DIR__ . '/../../../' . $f);
    }

    /** The start case checks crew-on-visit before anything else runs. */
    public function testStartTimerNeedsCrewOrOffice(): void
    {
        foreach (['app/Modules/Schedule/Api/timer.php', 'app/Modules/Jobs/Api/job-timer.php'] as $f) {
            $s = $this->src($f);
            $start = strpos($s, "case 'start':");
            $this->assertNotFalse($start, $f);
            $gate  = strpos($s, 'userMayActOnVisit($visitId', $start);
            $idem  = strpos($s, 'idempotencyCheck(', $start);
            $timer = strpos($s, 'startVisitTimer(', $start);
            $this->assertNotFalse($gate, $f);
            $this->assertLessThan($idem, $gate, $f);
            $this->assertLessThan($timer, $gate, $f);
        }
    }

    public function testCostFactorWritesNeedProductsEdit(): void
    {
        $s = $this->src('app/Modules/Products/Api/api-cost-factors.php');
        $this->assertStringContainsString("userHasPermission('products.edit')", $s);
        $this->assertStringContainsString("'link-expense-to-overhead'];", $s);
    }

    public function testMobileBookkeeperNeedsExpensesApprove(): void
    {
        $s = $this->src('app/Modules/Expenses/Api/bookkeeper-mobile.php');
        $this->assertStringContainsString("if (!jwtUserHasPermission(\$jwtUser, 'expenses.approve')) {", $s);
        $this->assertStringNotContainsString("!jwtIsAdmin(\$jwtUser['role']) && !jwtUserHasPermission", $s);
    }
}
