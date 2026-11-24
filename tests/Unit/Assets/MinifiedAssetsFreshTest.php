<?php
declare(strict_types=1);

use MatthiasMullie\Minify\CSS;
use MatthiasMullie\Minify\JS;
use PHPUnit\Framework\TestCase;

/**
 * The office CRM loads the minified brand CSS and app.js (appstack_head.php / appstack_footer.php via _amin()).
 * A min file older than its source would silently drop the newest CSS or JS, so each must equal a fresh
 * minification of its source. Fix: `php scripts/minify-assets.php`, then commit the .min file with the source.
 */
class MinifiedAssetsFreshTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        $this->repo = realpath(__DIR__ . '/../../..');
    }

    /** @return array<string, array{0:string,1:string,2:class-string}> */
    public static function loadedMinFiles(): array
    {
        return [
            'brand css' => ['public/crm/css/mowology-brand.css', 'public/crm/css/mowology-brand.min.css', CSS::class],
        ];
    }

    /** @dataProvider loadedMinFiles */
    public function testMinFileMatchesSource(string $src, string $min, string $class): void
    {
        $fresh = (new $class($this->repo . '/' . $src))->minify();
        $this->assertSame(
            md5($fresh),
            md5((string)file_get_contents($this->repo . '/' . $min)),
            "$min is out of date with $src. Run: php scripts/minify-assets.php"
        );
    }

    public function testPagesLoadTheMinFilesThroughTheHelper(): void
    {
        $head = (string)file_get_contents($this->repo . '/public/crm/includes/appstack_head.php');
        $foot = (string)file_get_contents($this->repo . '/public/crm/includes/appstack_footer.php');
        $this->assertStringContainsString("_amin('/crm/css/mowology-brand.css')", $head);
        // app.js must NOT go through _amin(): the minifier truncated it and jQuery vanished from every page.
        $this->assertStringNotContainsString("_amin('/crm/js/app.js')", $foot);
        $this->assertFileDoesNotExist($this->repo . '/public/crm/js/app.min.js');
    }
}
