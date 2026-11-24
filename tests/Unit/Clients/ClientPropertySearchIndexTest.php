<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ClientPropertySearchIndexTest extends TestCase
{
    public function testLabelJoinsNameEntityAndAddressWithoutDuplicates(): void
    {
        $this->assertSame(
            'Laburnum Heights — VR738 Laburnum Heights — 1551 West 11th Avenue',
            ClientPropertySearchIndex::label([
                'property_name' => 'Laburnum Heights',
                'billing_entity_name' => 'VR738 Laburnum Heights',
                'address' => '1551 West 11th Avenue',
            ])
        );
        $this->assertSame('1 Main St', ClientPropertySearchIndex::label([
            'property_name' => '', 'billing_entity_name' => null, 'address' => '1 Main St',
        ]));
        $this->assertSame('Same', ClientPropertySearchIndex::label([
            'property_name' => 'Same', 'billing_entity_name' => 'Same', 'address' => '',
        ]));
    }

    public function testAggregateFollowsEveryLink(): void
    {
        $props = [77 => 'Laburnum Heights', 78 => 'Other Place', 79 => ''];
        $r = ClientPropertySearchIndex::aggregate(
            $props,
            [[77, 15], [77, 15], [79, 15], [999, 15]],   // dup link, blank label, unknown property
            [[77, 2126], [78, 2126], [77, 40]],          // contact links
            [[15, 2126]]                                 // company 15's contact is 2126
        );
        $this->assertSame(['Laburnum Heights', 'Other Place'], $r['company'][15]);
        $this->assertSame(['Laburnum Heights', 'Other Place'], $r['contact'][2126]);
        $this->assertSame(['Laburnum Heights'], $r['contact'][40]);
        $this->assertArrayNotHasKey(99, $r['company']);
    }

    public function testAggregateIgnoresZeroIds(): void
    {
        $r = ClientPropertySearchIndex::aggregate([1 => 'A'], [[1, 0]], [[1, 0]], []);
        $this->assertSame([], $r['company']);
        $this->assertSame([], $r['contact']);
    }
}
