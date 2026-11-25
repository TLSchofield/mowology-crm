<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Quote colours: green accepted, red declined, orange opened, none = sent and unseen. */
class QuoteColourStateTest extends TestCase
{
    public function test_accepted_and_declined_win_over_views(): void
    {
        $this->assertSame('accepted', QuoteService::colourState(['status' => 'accepted', 'view_count' => 4]));
        $this->assertSame('declined', QuoteService::colourState(['status' => 'declined', 'view_count' => 2]));
    }

    public function test_opened_by_portal_view_or_email_open(): void
    {
        $this->assertSame('opened', QuoteService::colourState(['status' => 'viewed']));
        $this->assertSame('opened', QuoteService::colourState(['status' => 'sent', 'view_count' => 1]));
        $this->assertSame('opened', QuoteService::colourState(['status' => 'sent', 'email_opened_at' => '2026-10-08 16:00:00']));
    }

    public function test_sent_and_unseen_has_no_colour(): void
    {
        $this->assertSame('', QuoteService::colourState(['status' => 'sent', 'view_count' => 0]));
        $this->assertSame('', QuoteService::colourState(['status' => 'draft']));
    }
}
