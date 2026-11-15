<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Core/Tenant.php';

/** "Tenant Sites" is a platform-operator tool: only the original business sees or opens it. */
class TenantSitesGateTest extends TestCase
{
    protected function tearDown(): void
    {
        Tenant::forTests(null);
    }

    public function test_only_the_original_business_passes_the_gate(): void
    {
        Tenant::forTests(['key' => 'greenleaf']);
        $this->assertFalse(Tenant::isDefault());
        Tenant::forTests(['key' => Tenant::DEFAULT]);
        $this->assertTrue(Tenant::isDefault());
    }

    public function test_menu_entries_and_page_are_all_gated(): void
    {
        $root = dirname(__DIR__, 3) . '/public/crm/';
        $side = file_get_contents($root . 'includes/appstack_sidebar.php');
        $this->assertMatchesRegularExpression("/Tenant::isDefault\(\)[^\n]*database\.manage/", $side);
        $nav = file_get_contents($root . 'includes/nav-items.php');
        $this->assertMatchesRegularExpression("/sites_appstack\.php',[^\n]*Tenant::isDefault\(\)/", $nav);
        $page = file_get_contents($root . 'sites_appstack.php');
        $this->assertMatchesRegularExpression("/Tenant::isDefault\(\)\) \{\s*http_response_code\(403\)/", $page);
    }
}
