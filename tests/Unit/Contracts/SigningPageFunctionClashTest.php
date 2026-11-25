<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The client signing page (public/customer/quote.php) loads the CRM function files
 * after a snow contract is signed, to set the contract up (SnowContractService).
 * A function the page declares unconditionally that the CRM files also declare is a
 * "Cannot redeclare" fatal: the signature has already committed, so the client gets
 * an error page and no contract is made. Found 2026-10-08 with formatCurrency().
 */
class SigningPageFunctionClashTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** Functions declared at column 0 (unguarded, top level) in a file. */
    private function topLevelFunctions(string $file): array
    {
        preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', (string)file_get_contents($file), $m);
        return $m[1];
    }

    /** Every function the CRM includes declare, at any indentation. */
    private function crmFunctions(): array
    {
        $files = array_merge(
            [self::ROOT . '/app/Services/CrmFunctions.php', self::ROOT . '/app/Modules/Jobs/Services/PlanFunctions.php'],
            glob(self::ROOT . '/app/Modules/Jobs/Services/Plan/*.php') ?: []
        );
        $names = [];
        foreach ($files as $f) {
            preg_match_all('/^\s*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', (string)file_get_contents($f), $m);
            $names = array_merge($names, $m[1]);
        }
        return array_unique($names);
    }

    public function test_signing_page_declares_nothing_the_crm_functions_declare(): void
    {
        $clash = array_intersect(
            $this->topLevelFunctions(self::ROOT . '/public/customer/quote.php'),
            $this->crmFunctions()
        );
        $this->assertSame([], array_values($clash),
            'Guard these with function_exists() or the snow contract set-up fatals the signing page.');
    }

    public function test_notifications_include_declares_nothing_the_crm_functions_declare(): void
    {
        $clash = array_intersect(
            $this->topLevelFunctions(self::ROOT . '/public/includes/notifications.php'),
            $this->crmFunctions()
        );
        $this->assertSame([], array_values($clash));
    }
}
