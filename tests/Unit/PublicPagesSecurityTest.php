<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Modules/PublicForms/Services/PublicFormSecurity.php';

final class PublicPagesSecurityTest extends TestCase
{
    private function source(string $relativePath): string
    {
        $source = file_get_contents(__DIR__ . '/../../' . $relativePath);
        self::assertNotFalse($source);
        return $source;
    }

    public function testSignatureValidationRequiresImageDataUriWithinLimit(): void
    {
        self::assertTrue(PublicFormSecurity::isValidSignatureData('data:image/png;base64,abc'));
        self::assertFalse(PublicFormSecurity::isValidSignatureData('data:text/plain;base64,abc'));
        self::assertFalse(PublicFormSecurity::isValidSignatureData(
            'data:image/png;base64,' . str_repeat('a', PublicFormSecurity::MAX_SIGNATURE_LENGTH)
        ));
    }

    public function testRecaptchaHostnameAllowsSiteVariantsAndLocalDevelopmentOnly(): void
    {
        self::assertTrue(PublicFormSecurity::isAllowedRecaptchaHostname('mowology.ca', 'https://www.mowology.ca', 'mowology.ca'));
        self::assertTrue(PublicFormSecurity::isAllowedRecaptchaHostname('www.mowology.ca', 'https://mowology.ca', 'mowology.ca'));
        self::assertTrue(PublicFormSecurity::isAllowedRecaptchaHostname('localhost', 'https://mowology.ca', 'localhost:8080'));
        self::assertFalse(PublicFormSecurity::isAllowedRecaptchaHostname('localhost', 'https://mowology.ca', 'mowology.ca'));
        self::assertFalse(PublicFormSecurity::isAllowedRecaptchaHostname('attacker.example', 'https://mowology.ca', 'mowology.ca'));
        // another tenant's own domain: the token reports the host the visitor was on, which is the host of this request
        self::assertTrue(PublicFormSecurity::isAllowedRecaptchaHostname('claudia.ittai.ca', 'https://mowology.ca', 'claudia.ittai.ca'));
        self::assertFalse(PublicFormSecurity::isAllowedRecaptchaHostname('attacker.example', 'https://mowology.ca', 'claudia.ittai.ca'));
        self::assertFalse(PublicFormSecurity::isAllowedRecaptchaHostname('', 'https://mowology.ca', ''));
    }

    public function testPublicQuoteFormDoesNotReturnStoredAddresses(): void
    {
        $source = $this->source('public/jobFlow/jobFlow-getQuote.php');
        self::assertStringNotContainsString('p.address', $source);
        self::assertStringNotContainsString('address_relationship', $source);
        self::assertStringNotContainsString('address_confirmed', $source);
    }

    public function testConsentAndPropertyUpdatesAreAdditiveAndContactScoped(): void
    {
        $source = $this->source('public/jobFlow/jobFlow-confirm.php');
        self::assertStringContainsString('WHERE address = ? AND site_contact_id = ?', $source);
        self::assertStringContainsString("consent_marketing_email = 1", $source);
        self::assertStringNotContainsString("consent_marketing_email = ?", $source);
    }

    public function testQuoteAnswerUpdatesUseTheSameStatusGuard(): void
    {
        $source = $this->source('public/customer/quote.php');
        self::assertSame(2, substr_count($source, "WHERE id = ? AND status IN ('sent','viewed')"));
        self::assertGreaterThanOrEqual(2, substr_count($source, 'This quote has already been answered.'));
    }

    public function testTokenPagesDisableCachingBeforeBootstrapOutput(): void
    {
        foreach (['portal.php', 'invoice.php', 'quote.php', 'visit-report.php', 'contract-sign.php'] as $file) {
            $source = $this->source('public/customer/' . $file);
            $headerAt = strpos($source, "header('Cache-Control: no-store, private');");
            $bootstrapAt = strpos($source, "require_once dirname(__DIR__) . '/app_config/config.php';");
            self::assertNotFalse($headerAt, $file);
            self::assertNotFalse($bootstrapAt, $file);
            self::assertLessThan($bootstrapAt, $headerAt, $file);
        }
    }

    public function testRecaptchaAuditLogIsThrottledByIp(): void
    {
        $source = $this->source('public/jobFlow/recaptcha-helpers.php');
        self::assertStringContainsString("action LIKE 'recaptcha_%'", $source);
        self::assertStringContainsString('DATE_SUB(NOW(), INTERVAL 10 MINUTE)', $source);
    }

    public function testContractDeclineChecksServiceResult(): void
    {
        $source = $this->source('public/customer/contract-sign.php');
        self::assertStringContainsString('if ($svc->declineSignature($token, $reason, $signerIp))', $source);
    }

    public function testExistingPropertyOnlyGetsMissingCoordinates(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/public/jobFlow/jobFlow-confirm.php');
        self::assertStringContainsString('latitude        = COALESCE(latitude, ?)', $src);
        self::assertStringContainsString('longitude       = COALESCE(longitude, ?)', $src);
    }
}
