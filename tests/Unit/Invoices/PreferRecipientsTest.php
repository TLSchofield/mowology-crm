<?php

use PHPUnit\Framework\TestCase;

/**
 * The invoice screen's recipient list (2026-10-11): the company's live routing first and ticked,
 * the one-time client_contacts copy kept below as unticked CCs, no person listed twice.
 */
class PreferRecipientsTest extends TestCase
{
    private function r(int $id, string $email, bool $pre = true): array
    {
        return ['contact_id' => $id, 'contact_name' => "c$id", 'email_address' => $email, 'preselect' => $pre];
    }

    public function test_routed_people_come_first_and_ticked_the_rest_unticked(): void
    {
        // Macdonald, production 2026-10-11: routed to the accounts inbox (no contact row);
        // the copy listed "Macdonald Realty Accounts" (same inbox) and Monica.
        $routed = [['contact_id' => 0, 'contact_name' => 'MACDONALD REALTY (Accounts)', 'email_address' => 'pminvoices@macrealty.com', 'preselect' => true]];
        $copy = [$this->r(1519, 'PMInvoices@macrealty.com'), $this->r(69, 'mnicule@macdonaldpm.com')];
        $out = preferRecipients($routed, $copy);
        $this->assertCount(2, $out);
        $this->assertSame('pminvoices@macrealty.com', $out[0]['email_address']);
        $this->assertTrue($out[0]['preselect']);
        $this->assertSame(69, $out[1]['contact_id']);
        $this->assertFalse($out[1]['preselect']);
    }

    public function test_a_duplicate_by_contact_id_is_dropped(): void
    {
        $out = preferRecipients([$this->r(573, 'ap@vml.ca')], [$this->r(573, 'old@vml.ca'), $this->r(9, 'x@vml.ca')]);
        $this->assertSame([573, 9], array_column($out, 'contact_id'));
    }

    public function test_nothing_routed_keeps_the_list_but_unticked(): void
    {
        $out = preferRecipients([], [$this->r(1, 'a@b.c')]);
        $this->assertFalse($out[0]['preselect']);
    }
}
