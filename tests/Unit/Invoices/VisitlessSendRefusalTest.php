<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * H4 (Fable audit 2026-11): a crew login must not email an office draft invoice that has no visit.
 * InvoiceFromVisitService::visitlessSendRefusal() is the one rule; send() passes it the JWT's
 * billing.edit result from app/Modules/Schedule/Api/invoice.php.
 */
class VisitlessSendRefusalTest extends TestCase
{
    /** @test */
    public function crew_cannot_send_an_invoice_with_no_visit(): void
    {
        $refusal = InvoiceFromVisitService::visitlessSendRefusal(null, false);
        $this->assertNotNull($refusal);
        $this->assertStringContainsString('Only the office', (string)$refusal);
    }

    /** @test */
    public function crew_cannot_send_when_visit_id_is_zero(): void
    {
        $this->assertNotNull(InvoiceFromVisitService::visitlessSendRefusal(0, false));
        $this->assertNotNull(InvoiceFromVisitService::visitlessSendRefusal('0', false));
    }

    /** @test */
    public function office_may_send_a_visitless_invoice(): void
    {
        $this->assertNull(InvoiceFromVisitService::visitlessSendRefusal(null, true));
    }

    /** @test */
    public function anyone_may_send_an_invoice_that_came_from_a_visit(): void
    {
        $this->assertNull(InvoiceFromVisitService::visitlessSendRefusal(42, false));
        $this->assertNull(InvoiceFromVisitService::visitlessSendRefusal('42', true));
    }
}
