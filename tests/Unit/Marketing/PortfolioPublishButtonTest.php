<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The Portfolio view page's Publish / Unpublish button and its handler (same safety rules as delete.php). */
final class PortfolioPublishButtonTest extends TestCase
{
    private function src(string $f): string { return (string)file_get_contents(__DIR__ . '/../../../public/crm/portfolio/' . $f); }

    public function testTheHandlerNeedsPermissionPostAndCsrfAndOnlyAcceptsTwoStatuses(): void
    {
        $s = $this->src('status.php');
        $this->assertStringContainsString("requirePermission('portfolio.edit')", $s);
        $this->assertStringContainsString("\$_SERVER['REQUEST_METHOD'] !== 'POST'", $s);
        $this->assertStringContainsString('verifyCSRFToken(', $s);
        $this->assertStringContainsString("in_array(\$target, ['draft', 'published'], true)", $s);
        $this->assertStringContainsString("updatePortfolioProject(\$projectId, ['status' => \$target])", $s);
        $this->assertStringContainsString('logActivityExtended(', $s);
        $this->assertDoesNotMatchRegularExpression('/Location:[^;]*\$_(GET|POST|REQUEST)\[/', $s, 'it never redirects to a user-supplied address');
    }

    public function testTheViewPageShowsPublishForDraftsAndUnpublishForPublishedWithAConfirm(): void
    {
        $v = $this->src('view.php');
        $this->assertStringContainsString("setProjectStatus(<?php echo (int)\$project['id']; ?>, 'published')", $v);
        $this->assertStringContainsString("setProjectStatus(<?php echo (int)\$project['id']; ?>, 'draft')", $v);
        $this->assertStringContainsString("=== 'published'): ?>", $v);
        $this->assertStringContainsString("action = 'status.php'", $v);
        $this->assertStringContainsString('confirm(msg)', $v);
        $this->assertStringContainsString("window.MW_CSRF_TOKEN", $v);
    }
}
