<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * When VML's first EFT lands, Penny's matcher reads the memo as Vancouver Management Ltd
 * (bank_memo_payers, migration 1284) — "VML" names nobody and "management" is a stop word.
 */
class BankMemoPayersTest extends TestCase
{
    private const ROWS = [
        ['pattern' => 'VML', 'payer_name' => 'Vancouver Management Ltd.'],
        ['pattern' => 'VANCOUVER MANAGEMENT', 'payer_name' => 'Vancouver Management Ltd.'],
        ['pattern' => 'VANCOUVER MGMT', 'payer_name' => 'Vancouver Management Ltd.'],
    ];

    public function test_vml_eft_memos_name_vml(): void
    {
        foreach (['EFT CREDIT VML 000123', 'ELECTRONIC FUNDS TRANSFER VML-PAYMENT', 'MISC PAYMENT VANCOUVER MANAGEMENT LTD', 'vancouver mgmt eft'] as $memo) {
            $this->assertSame(['Vancouver Management Ltd.'], BankInvoiceMatchService::memoPayers($memo, self::ROWS), $memo);
        }
    }

    public function test_a_pattern_must_be_whole_words(): void
    {
        $this->assertSame([], BankInvoiceMatchService::memoPayers('EFT CREDIT VMLX HOLDINGS', self::ROWS));
        $this->assertSame([], BankInvoiceMatchService::memoPayers('NORTH VANCOUVER LAWN', self::ROWS));
        $this->assertSame([], BankInvoiceMatchService::memoPayers('EFT VML', []));
    }
}
