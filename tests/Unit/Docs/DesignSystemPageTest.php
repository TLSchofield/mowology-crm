<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Design System page (public/crm/design-system_appstack.php) reads a copy of the Ittai tokens, because production
 * ships only public/. The copy must stay identical to docs/design/ittai-v1.2/tokens.json, and every screenshot and asset
 * the page names must exist (2026-11-06).
 */
class DesignSystemPageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    public function testTokensCopyMatchesTheSpec(): void
    {
        $this->assertFileEquals(
            $this->root . '/docs/design/ittai-v1.2/tokens.json',
            $this->root . '/public/crm/assets/design-system/tokens.json',
            'Copy docs/design/ittai-v1.2/tokens.json to public/crm/assets/design-system/ after changing the tokens.'
        );
    }

    public function testEveryAssetThePageNamesExists(): void
    {
        $page = (string)file_get_contents($this->root . '/public/crm/design-system_appstack.php');
        preg_match_all("/'([a-z0-9-]+\\.(?:jpg|svg))'/", $page, $m);
        $files = array_unique(array_merge($m[1], ['ittai-symbol.svg']));
        $this->assertNotEmpty($files);
        foreach ($files as $f) {
            $this->assertFileExists($this->root . '/public/crm/assets/design-system/' . $f);
        }
    }
}
