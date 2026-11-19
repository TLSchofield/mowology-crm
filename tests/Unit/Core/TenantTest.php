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

    public function test_only_explicit_boolean_true_marks_test_config_and_default_is_never_test(): void
    {
        $this->assertTrue(Tenant::isTestCfg(['test' => true]));
        foreach ([[], ['test' => false], ['test' => 1], ['test' => 'true']] as $cfg) {
            $this->assertFalse(Tenant::isTestCfg($cfg));
        }

        Tenant::forTests(['key' => 'rehearsal', 'cfg' => ['test' => true]]);
        $this->assertTrue(Tenant::isTest());
        Tenant::forTests(['key' => Tenant::DEFAULT, 'cfg' => ['test' => true]]);
        $this->assertFalse(Tenant::isTest());
        Tenant::forTests(null);
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

    /** The phone sign-in directory (2026-11-11): every business, the original one first, with its address and database. */
    public function test_businesses_lists_the_original_business_first_then_each_registered_one(): void
    {
        $reg = $this->reg + [
            'claudia' => ['hosts' => ['claudia.ittai.ca'], 'db_name' => 'mowology_claudia', 'app_url' => 'https://claudia.ittai.ca/', 'test' => true, 'db_user' => 'cu', 'db_pass' => 'cp'],
            'broken'  => ['hosts' => ['broken.ittai.ca']],   // no database: not a business
        ];
        $list = Tenant::businesses($reg, 'https://mowology.ca/');
        $this->assertSame(['default', 'claudia', 'greenleaf'], array_column($list, 'key'));
        $this->assertSame(['key', 'app_url', 'db', 'test'], array_keys($list[0]));
        $this->assertSame('https://mowology.ca', $list[0]['app_url'], 'from $ITTAI_DEFAULT_APP_URL');
        $this->assertFalse($list[0]['test']);
        $this->assertSame(['mowology_claudia', 'cu', 'cp'], $list[1]['db']);
        $this->assertSame('https://claudia.ittai.ca', $list[1]['app_url']);
        $this->assertTrue($list[1]['test']);
        $this->assertSame('mowology_greenleaf', $list[2]['db'][0]);
        $this->assertFalse($list[2]['test']);
    }

    public function test_businesses_blanks_an_address_that_is_missing_or_not_its_own(): void
    {
        $this->assertSame('', Tenant::businesses($this->reg, '')[0]['app_url'], 'no $ITTAI_DEFAULT_APP_URL');
        $this->assertSame('', Tenant::businesses($this->reg, 'http://mowology.ca')[0]['app_url'], 'not https');
        foreach (['https://attacker.example', 'https://greenleaf.ittai.ca.evil.test', ''] as $bad) {
            $reg = ['greenleaf' => ['app_url' => $bad] + $this->reg['greenleaf']];
            $this->assertSame('', Tenant::businesses($reg, 'https://mowology.ca')[1]['app_url'], $bad);
        }
    }

    public function test_directory_mode_answers_only_for_the_marketing_address(): void
    {
        foreach (['ittai.ca', 'WWW.ittai.ca:443', 'ittai.ca.'] as $h) {
            $this->assertSame(['key' => Tenant::DIRECTORY, 'cfg' => []], Tenant::directoryFor($h, false), $h);
        }
        $this->assertSame(Tenant::DIRECTORY, Tenant::directoryFor('localhost:8000', true)['key'], 'local dev');
        // Every other address resolves as it always has.
        foreach (['localhost:8000' => false, 'greenleaf.ittai.ca' => true, 'mowology.ca' => true, 'claudia.ittai.ca' => true] as $h => $dev) {
            $this->assertNull(Tenant::directoryFor($h, $dev), $h);
        }
    }

    public function test_the_directory_has_no_database_of_its_own(): void
    {
        Tenant::forTests(['key' => Tenant::DIRECTORY, 'cfg' => []]);
        $this->assertSame('directory', Tenant::key());
        $this->assertFalse(Tenant::isDefault());
        $this->assertFalse(Tenant::isTest());
        $this->assertSame('', Tenant::appUrl());
        try {
            Tenant::db();
            $this->fail('db() must throw in directory mode');
        } catch (RuntimeException $e) {
            $this->assertSame('The directory has no database of its own', $e->getMessage());
        } finally {
            Tenant::forTests(null);
        }
    }
}
