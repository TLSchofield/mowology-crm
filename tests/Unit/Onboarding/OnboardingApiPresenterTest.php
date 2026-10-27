<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OnboardingApiPresenterTest extends TestCase
{
    public function test_phone_shape_renames_owner_and_drops_web_fields(): void
    {
        $body = OnboardingApiPresenter::present([[
            'key' => 'name', 'title' => 'About you', 'state' => 'next', 'owner' => 'Penny',
            'skippable' => false, 'pinned' => true, 'summary' => 'hidden', 'first' => true, 'last' => false,
        ]], 'name', false);
        $this->assertSame([
            'ok' => true,
            'steps' => [['key' => 'name', 'title' => 'About you', 'state' => 'next', 'head' => 'Penny', 'skippable' => false]],
            'next' => 'name',
            'ready_to_finish' => false,
        ], $body);
    }
}
