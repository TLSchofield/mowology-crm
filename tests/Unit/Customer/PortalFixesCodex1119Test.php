<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Codex nightly review 2026-11-19 (public-02): source-contract checks for the customer pages. */
class PortalFixesCodex1119Test extends TestCase
{
    private function src(string $f): string
    {
        return (string)file_get_contents(__DIR__ . '/../../../public/customer/' . $f);
    }

    public function test_portal_hides_draft_and_cancelled_invoices(): void
    {
        $this->assertStringContainsString("i.status NOT IN ('draft', 'cancelled')", $this->src('portal.php'));
    }

    public function test_gallery_runs_the_query_inside_the_fallback_try(): void
    {
        $s = $this->src('gallery.php');
        $this->assertSame(2, substr_count($s, '$stmt->execute([$contact[\'id\']]);'));
        $this->assertDoesNotMatchRegularExpression('/\}\s*\$stmt->execute\(\[\$contact\[\'id\'\]\]\);\s*\$rows/', $s);
    }

    public function test_profile_error_is_not_inserted_as_html(): void
    {
        $this->assertStringNotContainsString("Error: ' + (d.error", str_replace("errSpan.textContent = 'Error: ' + (d.error", '', $this->src('profile.php')));
    }

    public function test_quote_acceptance_survives_notification_failure(): void
    {
        $s = $this->src('quote.php');
        $this->assertMatchesRegularExpression('/try \{\s*sendEmailNotification\(NOTIFICATION_EMAIL.*?catch \(Throwable \$notifyError\)/s', $s);
    }
}
