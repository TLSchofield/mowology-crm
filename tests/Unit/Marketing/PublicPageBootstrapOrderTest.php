<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A public page that finds app/Core/paths.php by walking up folders must look in its OWN folder first.
 * On production app/ sits inside public_html next to the pages; a loop that takes dirname() before
 * the first check starts one level too high, never finds it, and the page dies with a blank 500
 * (unsubscribe.php, found 2026-11-26).
 */
final class PublicPageBootstrapOrderTest extends TestCase
{
    public function testPublicPagesCheckTheirOwnFolderBeforeTheParent(): void
    {
        $bad = [];
        foreach (glob(dirname(__DIR__, 3) . '/public/*.php') ?: [] as $file) {
            $src = (string)file_get_contents($file);
            if (!preg_match('/for \(\$__i = 0;[^{]*\{(.*?)\n\s*\}\s*\n/s', $src, $m)) continue;
            // optin-confirm.php checks __DIR__ explicitly before its loop — that is fine too.
            $loopAt = strpos($src, 'for ($__i = 0;');
            $ownAt  = strpos($src, "is_file(__DIR__ . '/app/Core/paths.php')");
            if ($ownAt !== false && $ownAt < $loopAt) continue;
            $body = $m[1];
            $dirnamePos = strpos($body, 'dirname($__dir)');
            $checkPos   = strpos($body, "is_file(\$__dir . '/app/Core/paths.php')");
            if ($dirnamePos === false || $checkPos === false) continue;
            if ($dirnamePos < $checkPos) $bad[] = basename($file);
        }
        $this->assertSame([], $bad, 'These public pages take dirname() before checking their own folder: ' . implode(', ', $bad));
    }
}
