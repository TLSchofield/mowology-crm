<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Security audit 2026-10-12 #1/#7: client exports (Jobber CSVs) sat downloadable in public/crm/tmp.
 * - No data export, archive or dump may live under public/ outside uploads/ (and vendor/, which is
 *   deny-all and holds library test fixtures).
 * - public/.htaccess must deny those extensions plus composer.json/lock, php.ini and .user.ini.
 * - public/database/ must be deny-all.
 */
final class WebRootHygieneTest extends TestCase
{
    private const DATA_EXT = '/\.(csv|xlsx|xls|zip|gz|tgz|tar|bak|sql\.gz)$/i';

    private static function publicDir(): string
    {
        return dirname(__DIR__, 3) . '/public';
    }

    public function testNoDataFilesUnderPublicOutsideUploads(): void
    {
        $pub = self::publicDir();
        $found = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($pub, FilesystemIterator::SKIP_DOTS),
                function (SplFileInfo $f) use ($pub): bool {
                    $rel = substr($f->getPathname(), strlen($pub) + 1);
                    return !preg_match('#^(uploads|vendor|sessions)(/|$)#', $rel);
                }
            )
        );
        foreach ($it as $f) {
            if ($f->isFile() && preg_match(self::DATA_EXT, $f->getFilename())) {
                $found[] = substr($f->getPathname(), strlen($pub) + 1);
            }
        }
        $this->assertSame([], $found, 'data files in the web root (move them out of public/): ' . implode(', ', $found));
    }

    public function testHtaccessDeniesDataFilesAndRuntimeConfig(): void
    {
        $ht = (string) file_get_contents(self::publicDir() . '/.htaccess');
        $this->assertSame(1, preg_match('/<FilesMatch "([^"]*csv[^"]*)">\s*Order allow,deny\s*Deny from all/', $ht, $m),
            'public/.htaccess needs a deny FilesMatch for data files');
        $re = '/' . str_replace('/', '\/', $m[1]) . '/';
        foreach (['jobber1.csv', 'export.xlsx', 'backup.zip', 'dump.sql.gz', 'site.tgz', 'x.tar', 'a.bak',
                  'composer.json', 'composer.lock', 'php.ini', '.user.ini'] as $name) {
            $this->assertSame(1, preg_match($re, $name), "{$name} is not denied by public/.htaccess");
        }
        foreach (['index.php', 'robots.txt', 'llms.txt', 'mowology-crew.apk', 'style.css', 'photo.jpg'] as $name) {
            $this->assertSame(0, preg_match($re, $name), "{$name} must stay reachable");
        }
    }

    public function testDatabaseFolderIsDenyAll(): void
    {
        $f = self::publicDir() . '/database/.htaccess';
        $this->assertFileExists($f);
        $this->assertStringContainsString('Require all denied', (string) file_get_contents($f));
    }
}
