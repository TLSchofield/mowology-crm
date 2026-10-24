<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Core/Tenant.php';

/** Which business a request is for (Ittai, 2026-10-23): only *.ittai.ca is routed; unknown routed = not found. */
class TenantTest extends TestCase
{
    private array $reg = ['greenleaf' => ['hosts' => ['greenleaf.ittai.ca'], 'db_name' => 'mowology_greenleaf', 'app_url' => 'https://greenleaf.ittai.ca']];

    public function test_the_original_business_is_unchanged_on_every_other_address(): void
    {
        foreach (['mowology.ca', 'www.mowology.ca', 'MOWOLOGY.CA.', 'localhost:8000', '104.193.174.145', 'junkology.ca'] as $h) {
            $this->assertSame(Tenant::DEFAULT, Tenant::resolve($h, null, $this->reg)['key'], $h);
        }
    }

    public function test_a_registered_ittai_address_gets_its_business(): void
    {
        $r = Tenant::resolve('GreenLeaf.Ittai.ca:443', null, $this->reg);
        $this->assertSame('greenleaf', $r['key']);
        $this->assertSame('mowology_greenleaf', $r['cfg']['db_name']);
    }

    public function test_an_unregistered_ittai_address_is_not_found(): void
    {
        $this->assertNull(Tenant::resolve('claudia.ittai.ca', null, $this->reg)['key']);
        $this->assertNull(Tenant::resolve('evil.greenleaf.ittai.ca', null, $this->reg)['key']);
        $this->assertNull(Tenant::resolve('greenleaf.ittai.ca', null, ['greenleaf' => ['hosts' => ['greenleaf.ittai.ca']]])['key'], 'no database named: refused');
    }

    public function test_cron_picks_a_business_by_key(): void
    {
        $this->assertSame('greenleaf', Tenant::resolve(null, 'greenleaf', $this->reg)['key']);
        $this->assertSame(Tenant::DEFAULT, Tenant::resolve(null, 'default', $this->reg)['key']);
        $this->assertNull(Tenant::resolve(null, 'nobody', $this->reg)['key']);
    }

    public function test_each_business_has_its_own_address_for_links(): void
    {
        Tenant::forTests(['key' => 'greenleaf', 'cfg' => $this->reg['greenleaf']]);
        $this->assertSame('https://greenleaf.ittai.ca', Tenant::appUrl());
        $this->assertSame('mowology_greenleaf', Tenant::db()[0]);
        Tenant::forTests(['key' => Tenant::DEFAULT, 'cfg' => []]);
        $this->assertSame('', Tenant::appUrl());
        // Codex 2026-10-23: an app_url that is not one of the business's own hosts is never used (reset links).
        foreach (['https://attacker.example', 'https://mowology.ca', 'https://greenleaf.ittai.ca.evil.test'] as $bad) {
            Tenant::forTests(['key' => 'greenleaf', 'cfg' => ['app_url' => $bad] + $this->reg['greenleaf']]);
            $this->assertSame('', Tenant::appUrl(), $bad);
        }
        Tenant::forTests(null);
    }

    /** Codex 2026-10-23: fail closed — no Host, a malformed one, or Ittai's marketing address never reach the original business. */
    public function test_empty_malformed_and_marketing_addresses_are_not_found(): void
    {
        foreach (['', '   ', 'acme.ittai.ca:bad', 'evil.test/x', 'a b.ca', 'ittai.ca', 'www.ittai.ca', 'ITTAI.CA.', 'xn--', '-bad.ca'] as $h) {
            $this->assertNull(Tenant::resolve($h, null, $this->reg)['key'], var_export($h, true));
        }
    }
}

