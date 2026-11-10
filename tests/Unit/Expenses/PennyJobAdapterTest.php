<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** PennyJobAdapter::fromGroup — a presentGroup() group becomes the job strip's shape, with bookkeeper.php's exact requests. */
class PennyJobAdapterTest extends TestCase
{
    private function group(array $over = []): array
    {
        return $over + [
            'members' => [
                ['id' => 278, 'status' => 'approved', 'total' => '43.17', 'receipt_path' => '/crm/api/serve-receipt.php?id=91', 'submitted_by' => 'Nigel Carter'],
                ['id' => 301, 'status' => 'pending_approval', 'total' => '43.17', 'receipt_path' => null, 'submitted_by' => 'Trevor'],
            ],
            'pairs' => [[278, 301]],
            'verdict' => 'Same ticket number.',
            'keep_id' => 278,
            'copy_ids' => [301],
            'merge_label' => 'into #278',
            'confidence' => "With Home Hardware I've been right 3 of 3 times.",
            'facts' => [
                ['label' => 'Vendor', 'values' => ['Home Hardware', 'Home Hardware'], 'state' => 'same'],
                ['label' => 'Time', 'values' => ['10:02', null], 'state' => 'missing'],
                ['label' => 'Total', 'values' => ['$43.17', '$43.71'], 'state' => 'differs'],
                ['label' => 'Status', 'values' => ['in the books', 'waiting'], 'state' => 'info'],
                ['label' => 'Sent in by', 'values' => ['Nigel Carter', 'Trevor'], 'state' => 'info'],
            ],
        ];
    }

    public function testMapsTheGroupToTheJobShape(): void
    {
        $j = PennyJobAdapter::fromGroup($this->group(), 2, 5);
        $this->assertSame('penny', $j['head']);
        $this->assertSame('duplicate', $j['kind']);
        $this->assertSame('dup:278:301', $j['key']);
        $this->assertSame(2, $j['index']);
        $this->assertSame(5, $j['total']);
        $this->assertSame(['id' => 278, 'label' => '#278 · in the books', 'who' => 'Nigel C.', 'image' => '/crm/api/serve-receipt.php?id=91'], $j['keep']);
        $this->assertSame(['id' => 301, 'label' => '#301 · waiting', 'who' => 'Trevor', 'image' => null], $j['copy']);
        $this->assertSame("With Home Hardware I've been right 3 of 3 times.", $j['confidence']);
        $this->assertSame('/crm/ledger/', $j['anchor']['href']);
    }

    public function testColumnsCarryKeptAndCopyValuesAndStates(): void
    {
        $cols = PennyJobAdapter::fromGroup($this->group(), 1, 1)['columns'];
        $this->assertSame(['Vendor', 'Time', 'Total'], array_column($cols, 'label'));   // status + sender live under the photos
        $this->assertSame(['label' => 'Time', 'top' => '10:02', 'bottom' => '–', 'state' => 'missing'], $cols[1]);
        $this->assertSame(['label' => 'Total', 'top' => '$43.17', 'bottom' => '$43.71', 'state' => 'differs'], $cols[2]);
    }

    public function testRequestsUseBookkeeperPhpParamNames(): void
    {
        $j = PennyJobAdapter::fromGroup($this->group(), 1, 1);
        $this->assertSame('Merge', $j['primary']['label']);
        $this->assertSame('into #278', $j['primary']['sub']);
        $this->assertSame(['mode' => 'dupe_merge', 'keep_id' => 278, 'copy_ids' => [301]], $j['primary']['request']);
        $this->assertSame('Different', $j['secondary']['label']);
        $this->assertSame(['mode' => 'not_dupe', 'pairs' => [[278, 301]]], $j['secondary']['request']);
        $this->assertSame([['mode' => 'unmerge', 'copy_id' => 301, 'kind' => 'undo']], $j['undo']['requests']);
    }

    public function testMissingConfidenceAndImageAreEmpty(): void
    {
        $g = $this->group(['confidence' => null, 'verdict' => '']);
        unset($g['members'][0]['receipt_path'], $g['members'][0]['submitted_by']);
        $j = PennyJobAdapter::fromGroup($g, 1, 1);
        $this->assertSame('', $j['confidence']);
        $this->assertSame('Same total, dates a few days apart.', PennyJobAdapter::fromGroup($this->group(['confidence' => null, 'verdict' => 'Same total, dates a few days apart.']), 1, 1)['confidence'], 'the verdict stands in when Penny has no track record');
        $this->assertNull($j['keep']['image']);
        $this->assertSame('', $j['keep']['who']);
    }

    public function testSeveralCopiesUndoEachAndMergeAll(): void
    {
        $g = $this->group(['copy_ids' => [301, 302], 'merge_label' => 'all into #278']);
        $j = PennyJobAdapter::fromGroup($g, 1, 1);
        $this->assertSame([301, 302], $j['primary']['request']['copy_ids']);
        $this->assertSame('all into #278', $j['primary']['sub']);
        $this->assertCount(2, $j['undo']['requests']);
        $this->assertSame('dup:278:301-302', $j['key']);
    }
}
