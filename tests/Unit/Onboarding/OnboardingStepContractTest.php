<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The step list the apps are built against. The Android test OnboardingStepContractTest holds the same keys:
 * change one side and this fails until the other follows (Tim, 2026-11-28: the QuickBooks question left setup).
 */
class OnboardingStepContractTest extends TestCase
{
    public function test_the_phone_step_keys_the_apps_rely_on(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT, setting_value TEXT, description TEXT)");
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, full_name TEXT, preferred_name TEXT, role TEXT, phone TEXT, contact_channel TEXT, quiet_start TEXT, quiet_end TEXT, quiet_weekends INT DEFAULT 0)");
        $db->exec("CREATE TABLE business_identity (id INTEGER PRIMARY KEY, legal_name TEXT, operating_name TEXT, registration_number TEXT)");
        $svc = new OnboardingService(new OnboardingContext($db, 1), null, ['terms']);
        $this->assertSame(['name', 'terms', 'company', 'books', 'accountant', 'done'], $svc->order());
        $this->assertNull($svc->module('quickbooks'), 'asked later by Penny (OwnerQuestionService), not in setup');
    }
}
