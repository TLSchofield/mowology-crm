<?php
declare(strict_types=1);

/**
 * Loads QA crawler credentials + resolves the target base URL.
 * Mirrors ApiTestCase's MOWOLOGY_TEST_URL convention (tests/Integration/ApiTestCase.php).
 */
class QaCrawlerConfig
{
    public string $baseUrl;
    public string $email;
    public string $password;

    public function __construct(?string $baseUrlOverride = null)
    {
        $credsFile = dirname(__DIR__, 2) . '/public/app_config/qa-test-credentials.php';
        if (!is_file($credsFile)) {
            fwrite(STDERR, "Missing {$credsFile}\n");
            fwrite(STDERR, "Run public/crm/run-migration-1109.php first, then create this file with QA_TEST_EMAIL / QA_TEST_PASSWORD.\n");
            exit(1);
        }
        require_once $credsFile;

        if (!defined('QA_TEST_EMAIL') || !defined('QA_TEST_PASSWORD')) {
            fwrite(STDERR, "{$credsFile} did not define QA_TEST_EMAIL / QA_TEST_PASSWORD.\n");
            exit(1);
        }

        $this->email    = QA_TEST_EMAIL;
        $this->password = QA_TEST_PASSWORD;
        $this->baseUrl  = rtrim($baseUrlOverride ?: (getenv('MOWOLOGY_TEST_URL') ?: 'https://mowology.ca'), '/');
    }
}
