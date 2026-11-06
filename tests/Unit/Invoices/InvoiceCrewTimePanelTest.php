<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The "Crew time on site" panel has one copy (includes/invoice-crew-time.php),
 * shown on the Create, Edit and View invoice pages (Tim, 2026-11-06).
 */
class InvoiceCrewTimePanelTest extends TestCase
{
    private function src(string $rel): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/public/crm/' . $rel);
    }

    /** @test */
    public function create_edit_and_view_all_draw_the_shared_panel(): void
    {
        foreach (['invoices/create.php', 'invoices/edit.php', 'invoices/view.php'] as $page) {
            $src = $this->src($page);
            $this->assertStringContainsString("includes/invoice-crew-time.php", $src, "$page must include the shared crew time panel");
            $this->assertStringNotContainsString('mw-crew-time-label', $src, "$page must not keep its own copy of the panel");
        }
    }

    /** @test */
    public function edit_and_view_use_the_invoices_own_visit(): void
    {
        foreach (['invoices/edit.php', 'invoices/view.php'] as $page) {
            $this->assertMatchesRegularExpression('/\$crewTimeVisitId\s*=\s*\(int\)\(\$invoice\[\'visit_id\'\]/', $this->src($page), $page);
        }
    }

    /** @test */
    public function the_panel_reads_forVisit_and_never_breaks_the_page(): void
    {
        $src = $this->src('includes/invoice-crew-time.php');
        $this->assertStringContainsString('->forVisit(', $src);
        $this->assertStringContainsString('catch (Throwable', $src);
        $this->assertStringContainsString('htmlspecialchars(implode(', $src);
    }
}
