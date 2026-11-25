<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Fable review 2026-10-12: every commercial email is gated and carries address + unsubscribe. */
class CaslRulesTest extends TestCase
{
    private array $co = ['name' => 'Mowology', 'address' => '123 Main St, Vancouver BC'];
    private string $unsub = 'https://mowology.ca/unsubscribe.php?email=a%40b.c&token=abc&sid=0';

    private function wrap(): callable
    {
        return static fn(string $h, string $u): string =>
            '<html>' . $h . '<p>123 Main St, Vancouver BC</p><a href="' . htmlspecialchars($u, ENT_QUOTES, 'UTF-8') . '">Unsubscribe</a></html>';
    }

    public function test_requested_quotes_and_bills_are_transactional_the_rest_commercial(): void
    {
        foreach (['quote_followup', 'quote_viewed', 'invoice_overdue'] as $t) $this->assertFalse(CaslRules::isCommercialTrigger($t), $t);
        foreach (['client_inactive', 'tagged', 'season_change', 'product_not_purchased', 'job_completed', '', 'something_new'] as $t) {
            $this->assertTrue(CaslRules::isCommercialTrigger($t), $t);
        }
    }

    public function test_compose_keeps_body_address_and_unsubscribe(): void
    {
        $html = CaslRules::composeHtml('<p>Hi Sam</p>', $this->unsub, $this->co, $this->wrap());
        $this->assertNotNull($html);
        $this->assertStringContainsString('Hi Sam', $html);
    }

    public function test_compose_refuses_without_address_unsubscribe_or_body(): void
    {
        $this->assertNull(CaslRules::composeHtml('<p>Hi</p>', $this->unsub, ['name' => 'Mowology', 'address' => ''], $this->wrap()));
        $this->assertNull(CaslRules::composeHtml('<p>Hi</p>', 'https://mowology.ca/quote', $this->co, $this->wrap()));
        $this->assertNull(CaslRules::composeHtml('<p> </p>', $this->unsub, $this->co, $this->wrap()));
        // A wrapper that drops the footer is refused too.
        $this->assertNull(CaslRules::composeHtml('<p>Hi</p>', $this->unsub, $this->co, static fn($h, $u) => $h));
    }

    public function test_unsubscribe_links_are_not_tracked(): void
    {
        $this->assertTrue(CaslRules::isUnsubscribeLink($this->unsub));
        $this->assertFalse(CaslRules::isUnsubscribeLink('https://mowology.ca/quote?src=campaign-4'));
    }
}
