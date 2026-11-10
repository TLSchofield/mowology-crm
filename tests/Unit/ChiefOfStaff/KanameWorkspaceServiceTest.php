<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class KanameWorkspaceServiceTest extends TestCase
{
    private int $now;

    protected function setUp(): void
    {
        $this->now = strtotime('2026-11-10 08:00:00');
    }

    private function item(string $k, string $head, bool $yes, ?string $url = '/x'): array
    {
        return ['key' => $k, 'head' => $head, 'text' => "Text $k", 'url' => $url, 'yes' => $yes];
    }

    public function testSplitsAndOrder(): void
    {
        $b = KanameWorkspaceService::brief(['items' => [
            $this->item('a', 'penny', true), $this->item('b', 'otto', false), $this->item('c', 'yui', true),
        ]], '2026-11-10 07:45:10', [], $this->now);
        $this->assertSame(['a', 'c'], array_column($b['need_you'], 'key'));
        $this->assertSame(['b'], array_column($b['also'], 'key'));
        $this->assertSame('Penny', $b['need_you'][0]['name']);
        $this->assertSame('2 need you', $b['count_line']);
        $this->assertFalse($b['empty']);
    }

    public function testSingularAndNone(): void
    {
        $one = KanameWorkspaceService::brief(['items' => [$this->item('a', 'otto', true)]], null, [], $this->now);
        $this->assertSame('1 needs you', $one['count_line']);
        $none = KanameWorkspaceService::brief(['items' => [$this->item('a', 'otto', false)]], null, [], $this->now);
        $this->assertSame('', $none['count_line']);
    }

    public function testCaps(): void
    {
        $items = [];
        for ($i = 0; $i < 12; $i++) $items[] = $this->item("y$i", 'otto', true);
        for ($i = 0; $i < 12; $i++) $items[] = $this->item("n$i", 'otto', false);
        $handled = array_fill(0, 9, ['head' => 'mia', 'text' => 'Done']);
        $b = KanameWorkspaceService::brief(['items' => $items], null, $handled, $this->now);
        $this->assertCount(8, $b['need_you']);
        $this->assertCount(8, $b['also']);
        $this->assertCount(6, $b['handled']);
        $this->assertSame('Mia', $b['handled'][0]['name']);
    }

    public function testHouseIsCharlie(): void
    {
        $b = KanameWorkspaceService::brief(['items' => [$this->item('a', 'house', true), $this->item('b', '', true)]], null, [], $this->now);
        $this->assertSame('charlie', $b['need_you'][0]['head']);
        $this->assertSame('charlie', $b['need_you'][1]['head']);
        $this->assertNotSame('', $b['need_you'][0]['name']);
    }

    public function testUrlFallback(): void
    {
        $b = KanameWorkspaceService::brief(['items' => [$this->item('a', 'otto', true, null), $this->item('b', 'otto', true, '')]], null, [], $this->now);
        $this->assertSame('/crm/dashboard_appstack.php', $b['need_you'][0]['url']);
        $this->assertSame('/crm/dashboard_appstack.php', $b['need_you'][1]['url']);
    }

    public function testFormats(): void
    {
        $b = KanameWorkspaceService::brief(null, '2026-11-10 07:45:10', [], $this->now);
        $this->assertSame('Morning brief · Tue 10 Nov', $b['heading']);
        $this->assertSame('as of 7:45', $b['as_of']);
        $this->assertSame('', KanameWorkspaceService::brief(null, null, [], $this->now)['as_of']);
    }

    public function testEmptyFlag(): void
    {
        $this->assertTrue(KanameWorkspaceService::brief(null, null, [], $this->now)['empty']);
        $this->assertTrue(KanameWorkspaceService::brief(['items' => []], null, [], $this->now)['empty']);
        $this->assertFalse(KanameWorkspaceService::brief(null, null, [['head' => 'otto', 'text' => 'x']], $this->now)['empty']);
    }
}
