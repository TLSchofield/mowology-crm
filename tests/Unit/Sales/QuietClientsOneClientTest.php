<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Sam's quiet-client list groups one person's invoices as one client (Ambrosia Vertesi, 2026-11-04). */
final class QuietClientsOneClientTest extends TestCase
{
    public function testInvoicesWithoutClientIdResolveThroughLegacyContact(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/app/Modules/Sales/Services/SalesDeskService.php');
        $start = strpos($src, 'public function quietClients');
        $this->assertNotFalse($start);
        $body = substr($src, $start, 1800);
        $this->assertStringContainsString('COALESCE(i.client_id, (SELECT cl.id FROM clients cl WHERE cl.legacy_contact_id = i.contact_id', $body);
    }
}
