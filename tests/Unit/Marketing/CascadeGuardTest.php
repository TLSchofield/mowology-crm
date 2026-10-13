<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Fable review 2026-10-12 (LOW): content cascade needs CSRF and a mowology.ca article URL. */
class CascadeGuardTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../app/Modules/Marketing/Services/ContentCascadeService.php';
    }

    public function test_only_https_mowology_ca(): void
    {
        $this->assertTrue(ContentCascadeService::isOwnArticleUrl('https://mowology.ca/blog/fall-cleanup'));
        $this->assertTrue(ContentCascadeService::isOwnArticleUrl('https://MOWOLOGY.ca/'));
        foreach (['http://mowology.ca/x', 'https://evil.com/mowology.ca', 'https://mowology.ca.evil.com/',
                  'https://mowology.ca@evil.com/', 'https://evil.com#https://mowology.ca', 'javascript:alert(1)',
                  '//mowology.ca/x', 'https://mowology.ca:8443/x', ''] as $u) {
            $this->assertFalse(ContentCascadeService::isOwnArticleUrl($u), $u);
        }
    }

    public function test_endpoint_checks_csrf_and_host_before_running(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../../app/Modules/Marketing/Api/cascade.php');
        $run = strpos($src, '->cascade(');
        $this->assertLessThan($run, strpos($src, 'verifyCSRFToken($csrf)'));
        $this->assertLessThan($run, strpos($src, 'ContentCascadeService::isOwnArticleUrl('));
        $this->assertStringContainsString("'X-CSRF-Token': window.MW_CSRF_TOKEN", (string)file_get_contents(__DIR__ . '/../../../public/crm/marketing/content-engine.php'));
    }
}
