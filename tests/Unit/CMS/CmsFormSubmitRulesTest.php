<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/CMS/Services/CmsFormSubmitRules.php';

/** Fable review 2026-10-12: public CMS form — rate limit, REMOTE_ADDR only, clipped fields, notice fires. */
class CmsFormSubmitRulesTest extends TestCase
{
    public function test_forwarded_headers_are_ignored(): void
    {
        $this->assertSame('203.0.113.9', CmsFormSubmitRules::clientIp(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_CF_CONNECTING_IP' => '5.6.7.8']));
        $this->assertSame('', CmsFormSubmitRules::clientIp(['HTTP_X_FORWARDED_FOR' => '1.2.3.4']));
    }

    public function test_fields_are_clipped_and_stripped(): void
    {
        $f = CmsFormSubmitRules::fields(['name' => '<b>' . str_repeat('a', 500) . '</b>', 'message' => str_repeat('m', 20000), 'phone' => ['x'], 'email' => ' a@b.co ']);
        $this->assertSame(100, mb_strlen($f['name']));
        $this->assertStringNotContainsString('<b>', $f['name']);
        $this->assertSame(5000, mb_strlen($f['message']));
        $this->assertSame('', $f['phone']);
        $this->assertSame('a@b.co', $f['email']);
        $this->assertSame('cms-contact', $f['form_id']);
    }

    public function test_three_per_ip_per_day(): void
    {
        $this->assertFalse(CmsFormSubmitRules::overLimit(2));
        $this->assertTrue(CmsFormSubmitRules::overLimit(3));
    }

    public function test_endpoint_uses_the_rules_and_loads_messaging(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../../app/Modules/CMS/Api/cms-form-submit.php');
        $this->assertStringContainsString('CmsFormSubmitRules::clientIp($_SERVER)', $src);
        $this->assertStringContainsString('CmsFormSubmitRules::overLimit(', $src);
        $this->assertStringNotContainsString('HTTP_X_FORWARDED_FOR', $src);
        $this->assertStringContainsString("require_once CRM_INCLUDES . '/messaging.php'", $src);
    }
}
