<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CompanyPropertyLinksTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!function_exists('mergeCompanyPropertyLinks')) {
            require_once __DIR__ . '/../../app/Services/CrmFunctions.php';
        }
    }

    public function testEachPropertyAppearsOnceAndTheStrongestLinkWins(): void
    {
        $explicit = [['id' => 74, 'link_source' => 'explicit', 'relationship_type' => 'owner']];
        $direct   = [['id' => 74, 'link_source' => 'direct',   'relationship_type' => 'manager'],
                     ['id' => 73, 'link_source' => 'direct',   'relationship_type' => 'manager']];
        $inferred = [['id' => 73, 'link_source' => 'inferred'], ['id' => 28, 'link_source' => 'inferred']];

        $out = mergeCompanyPropertyLinks($explicit, $direct, $inferred);

        $this->assertSame([74, 73, 28], array_column($out, 'id'));
        $this->assertSame(['explicit', 'direct', 'inferred'], array_column($out, 'link_source'));
    }

    public function testRowsWithoutAnIdAreIgnoredAndEmptyInputsAreFine(): void
    {
        $this->assertSame([], mergeCompanyPropertyLinks([], [], []));
        $out = mergeCompanyPropertyLinks([], [['address' => 'no id']], [['id' => '5']]);
        $this->assertSame(['5'], array_column($out, 'id'));
    }
}
