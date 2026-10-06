<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Bank rules learn only from lines someone really decided on, and switch on after 2.
 */
class BankRuleLearningTest extends TestCase
{
    public function test_default_accounts_and_empty_keys_never_teach(): void
    {
        $this->assertFalse(BankRuleLearning::teaches('point sale shell', 69, [49, 69]), 'Miscellaneous');
        $this->assertFalse(BankRuleLearning::teaches('abc', 12, [49, 69]), 'key too short');
        $this->assertTrue(BankRuleLearning::teaches('point sale shell', 31, [49, 69]));
    }

    public function test_a_rule_acts_alone_only_after_fifty_confirmations(): void
    {
        $this->assertFalse(BankRuleLearning::isTrusted(49));
        $this->assertTrue(BankRuleLearning::isTrusted(50));
    }

    public function test_description_key_is_shared_with_the_import(): void
    {
        $this->assertSame(BankImportService::descriptionKey('POINT OF SALE SHELL C12345 VANCOUVER BC'),
                          BankImportService::descriptionKey('Point of Sale - Shell C99881 Vancouver BC'));
    }
}
