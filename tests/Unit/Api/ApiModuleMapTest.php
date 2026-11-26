<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * /api/<module>/<action> is routed by the $moduleMap in public/api/index.php. A module the apps call but the map lacks
 * answers 404 "Unknown module" on production (2026-11-26: the Android quiz asked for /api/quiz/preshift and every call
 * 404'd, so Nigel's hydrangea quiz never started). The map's directory names are also case-sensitive on the Linux
 * server, although macOS would not notice.
 */
final class ApiModuleMapTest extends TestCase
{
    public function testEveryMappedModuleHasItsApiFolderWithExactCase(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/public/api/index.php');
        preg_match('/\$moduleMap\s*=\s*\[(.*?)\n\];/s', $src, $m);
        preg_match_all("/'([a-z0-9-]+)'\s*=>\s*'([A-Za-z0-9]+)'/", $m[1], $pairs, PREG_SET_ORDER);
        $this->assertNotEmpty($pairs);
        $root = dirname(__DIR__, 3) . '/app/Modules';
        $actual = array_map('basename', glob($root . '/*', GLOB_ONLYDIR) ?: []);
        $bad = [];
        foreach ($pairs as $p) {
            if (!in_array($p[2], $actual, true)) $bad[] = "{$p[1]} => {$p[2]}";
        }
        $this->assertSame([], $bad, 'Mapped to a folder that does not exist with that exact case: ' . implode(', ', $bad));
    }

    public function testTheQuizModuleIsRoutedForTheAndroidApp(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/public/api/index.php');
        $this->assertMatchesRegularExpression("/'quiz'\s*=>\s*'Quiz'/", $src);
        foreach (['preshift', 'start', 'answer', 'finish', 'categories', 'stats'] as $file) {
            $this->assertFileExists(dirname(__DIR__, 3) . "/app/Modules/Quiz/Api/{$file}.php");
        }
    }
}
