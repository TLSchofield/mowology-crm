<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ContactImportServiceTest extends TestCase
{
    public function test_phone_digits_are_normalised_strictly(): void
    {
        $this->assertSame('6045551212', ContactImportService::normalisePhone('+1 (604) 555-1212'));
        $this->assertSame('6045551212', ContactImportService::normalisePhone('604.555.1212'));
        $this->assertNull(ContactImportService::normalisePhone('555-1212'));
        $this->assertNull(ContactImportService::normalisePhone('26045551212'));
    }

    public function test_names_split_at_the_first_space_and_single_words_stay_first(): void
    {
        $this->assertSame(['Ada', 'Lovelace'], ContactImportService::splitName(' Ada Lovelace '));
        $this->assertSame(['Prince', ''], ContactImportService::splitName('Prince'));
    }

    public function test_mobile_and_iphone_labels_win_and_other_numbers_fill_phone(): void
    {
        $this->assertSame(['604-111-2222', '604-333-4444'], ContactImportService::labelNumbers([
            ['label'=>'home','number'=>'604-333-4444'], ['label'=>'iPhone','number'=>'604-111-2222'],
        ]));
        $this->assertSame(['604-333-4444', ''], ContactImportService::labelNumbers([['label'=>'work','number'=>'604-333-4444']]));
        $this->assertSame(['604-333-4444', '604-555-6666'], ContactImportService::labelNumbers([
            ['label'=>'home','number'=>'604-333-4444'], ['label'=>'work','number'=>'604-555-6666'],
        ]));
    }

    public function test_dedupe_keys_and_batch_duplicates_use_email_or_valid_phone(): void
    {
        $a = ['emails'=>[' Owner@Example.com '], 'phones'=>[['number'=>'(604) 555-1212']]];
        $this->assertSame(['email:owner@example.com', 'phone:6045551212'], ContactImportService::dedupeKeys($a));
        $this->assertSame([1, 3], ContactImportService::duplicateIndexes([
            $a,
            ['emails'=>['owner@example.com'], 'phones'=>[]],
            ['emails'=>[], 'phones'=>[['number'=>'7785550000']]],
            ['emails'=>[], 'phones'=>[['number'=>'+1 778 555 0000']]],
        ]));
    }
}
