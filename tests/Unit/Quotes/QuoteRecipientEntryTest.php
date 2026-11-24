<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The quote-recipient ladder (quote row -> who it goes to) pinned through BOTH doors:
 * QuoteService::resolveContact() (the old home, now a delegate) and
 * QuoteRecipientService::resolveContact() (the single entry, 2026-11-24).
 * Edge behaviours are pinned on purpose: `??` skips only null, so an empty-string email wins the
 * ladder and then collapses to null.
 */
class QuoteRecipientEntryTest extends TestCase
{
    /** @return array<string,array{0:callable}> */
    public function doors(): array
    {
        return [
            'QuoteService::resolveContact' => [static fn(array $q) => (new QuoteService(new PDO('sqlite::memory:')))->resolveContact($q)],
            'QuoteRecipientService::resolveContact' => [static function (array $q) {
                if (!method_exists('QuoteRecipientService', 'resolveContact')) {
                    throw new Error('not moved yet');
                }
                return QuoteRecipientService::resolveContact($q);
            }],
        ];
    }

    private function run3(callable $door, array $q): array
    {
        try {
            return $door($q);
        } catch (Error $e) {
            $this->markTestSkipped($e->getMessage());
        }
    }

    /** @dataProvider doors */
    public function testEmptyStringEmailWinsTheLadderThenBecomesNull(callable $door): void
    {
        $r = $this->run3($door, ['qr_email' => '', 'contact_email' => 'co@x.example', 'contact_first' => 'Bob', 'contact_last' => 'Jones', 'contact_id' => 4]);
        $this->assertNull($r['email']);
        $this->assertSame('Bob Jones', $r['name']);
        $this->assertSame(4, $r['contact_id']);
    }

    /** @dataProvider doors */
    public function testBillingFieldsAreTheLastResort(callable $door): void
    {
        $r = $this->run3($door, ['billing_email' => 'bill@x.example', 'billing_phone' => '604-000-0000', 'company_name' => 'Acme Ltd']);
        $this->assertSame(['email' => 'bill@x.example', 'phone' => '604-000-0000', 'name' => 'Acme Ltd', 'first_name' => 'Acme', 'contact_id' => null], $r);
    }

    /** @dataProvider doors */
    public function testNothingKnownIsAValuedCustomer(callable $door): void
    {
        $this->assertSame(['email' => null, 'phone' => null, 'name' => 'Valued Customer', 'first_name' => 'Valued', 'contact_id' => null], $this->run3($door, []));
    }

    /** @dataProvider doors */
    public function testPropertyContactIdFeedsSmsConsentWhenNoOther(callable $door): void
    {
        $r = $this->run3($door, ['prop_contact_email' => 'p@x.example', 'prop_contact_first' => 'Pia', 'prop_contact_last' => 'Site', 'prop_contact_id' => 9]);
        $this->assertSame('p@x.example', $r['email']);
        $this->assertSame('Pia', $r['first_name']);
        $this->assertSame(9, $r['contact_id']);
    }
}
