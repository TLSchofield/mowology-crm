<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class FirstWeekMenuTest extends TestCase
{
    private function item(string $key, string $label = ''): array
    {
        return ['key' => $key, 'label' => $label !== '' ? $label : ucfirst($key), 'href' => "/$key"];
    }

    public function testEightItemsInOrderWithPlainLabels(): void
    {
        $all = [];
        foreach (['payroll', 'settings', 'invoices', 'dashboard', 'schedule', 'jobs', 'quotes', 'clients', 'receipts-review', 'tasks'] as $k) {
            $all[] = $this->item($k);
        }
        $m = FirstWeekMenu::build($all);
        $this->assertSame(['dashboard', 'clients', 'quotes', 'jobs', 'schedule', 'invoices', 'receipts-review', 'settings'], array_column($m['main'], 'key'));
        $this->assertSame(['Today', 'Clients', 'Quotes', 'Jobs', 'Schedule', 'Invoices', 'Receipts', 'Settings'], array_column($m['main'], 'label'));
        $this->assertSame(['payroll', 'tasks'], array_column($m['more'], 'key'));
    }

    public function testItemsTheLoginCannotOpenAreNotShown(): void
    {
        $m = FirstWeekMenu::build([$this->item('dashboard'), $this->item('quotes')]);
        $this->assertSame(['dashboard', 'quotes'], array_column($m['main'], 'key'));
        $this->assertSame([], $m['more']);
        $this->assertSame(['main' => [], 'more' => []], FirstWeekMenu::build([]));
    }

    public function testDuplicateKeysAppearOnce(): void
    {
        $m = FirstWeekMenu::build([$this->item('jobs'), $this->item('jobs'), $this->item('map'), $this->item('map')]);
        $this->assertCount(1, $m['main']);
        $this->assertCount(1, $m['more']);
    }
}
