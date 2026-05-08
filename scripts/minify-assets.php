<?php
/**
 * Mowology Asset Minifier
 * ══════════════════════════════════════════════════════════════════════
 * Walks the CRM JS and CSS directories and produces sibling `.min.js`
 * and `.min.css` files. Skips files that already end in `.min.js` or
 * `.min.css`, files inside vendor directories, and files whose source
 * mtime hasn't changed since the last minification (via a manifest).
 *
 * Run from the project root:
 *   php scripts/minify-assets.php           # minify changed files
 *   php scripts/minify-assets.php --force   # rebuild everything
 *   php scripts/minify-assets.php --clean   # delete all .min.* siblings
 *
 * Outputs a JSON manifest at scripts/.minify-manifest.json so repeat
 * runs only touch files whose source mtime changed.
 *
 * NOTE: The full CSS and JS files stay in place. .min.* siblings are
 * additive — callers choose which to load. Gradual rollout can swap
 * <link href="foo.css"> → <link href="foo.min.css"> per-page.
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MatthiasMullie\Minify;

$root      = dirname(__DIR__);
$jsDir     = $root . '/public/crm/js';
$cssDir    = $root . '/public/crm/css';
$manifest  = __DIR__ . '/.minify-manifest.json';

$force   = in_array('--force', $argv, true);
$clean   = in_array('--clean', $argv, true);
$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);

// ── Clean mode ────────────────────────────────────────
if ($clean) {
    $removed = 0;
    foreach ([$jsDir, $cssDir] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
            if ($f->isFile() && preg_match('/\.min\.(js|css)$/', $f->getFilename())) {
                unlink($f->getPathname());
                $removed++;
            }
        }
    }
    if (is_file($manifest)) unlink($manifest);
    echo "Removed $removed minified files.\n";
    exit(0);
}

// ── Load manifest ─────────────────────────────────────
$state = [];
if (is_file($manifest)) {
    $state = json_decode((string)file_get_contents($manifest), true) ?: [];
}

$processed = 0;
$skipped   = 0;
$errors    = [];

function collect(string $dir, string $ext): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
        if (!$f->isFile()) continue;
        $name = $f->getFilename();
        if (!str_ends_with($name, '.' . $ext)) continue;
        if (str_ends_with($name, '.min.' . $ext)) continue; // already minified
        $path = $f->getPathname();
        if (strpos($path, '/vendor/') !== false) continue;
        if (strpos($path, '/node_modules/') !== false) continue;
        $out[] = $path;
    }
    return $out;
}

function minifyFile(string $source, string $kind, array &$state, bool $force, bool $verbose, int &$processed, int &$skipped, array &$errors): void {
    $rel  = $source;
    $dest = preg_replace('/\.(js|css)$/', '.min.$1', $source);
    $mtime = filemtime($source);

    // Skip if manifest says the source is unchanged AND the min file exists
    if (!$force && isset($state[$rel]) && $state[$rel] === $mtime && is_file($dest)) {
        $skipped++;
        if ($verbose) echo "  skip  $rel\n";
        return;
    }

    try {
        if ($kind === 'js') {
            $min = new Minify\JS($source);
        } else {
            $min = new Minify\CSS($source);
        }
        $min->minify($dest);
        $state[$rel] = $mtime;
        $processed++;
        $origSize = filesize($source);
        $newSize  = filesize($dest);
        $pct = $origSize > 0 ? round((1 - $newSize / $origSize) * 100) : 0;
        echo sprintf("  ✓ %-60s  %6.1fKB → %5.1fKB  (-%d%%)\n",
            substr($rel, strlen(dirname(dirname(__DIR__))) + 1),
            $origSize / 1024,
            $newSize / 1024,
            $pct
        );
    } catch (Throwable $e) {
        $errors[] = "$rel: " . $e->getMessage();
        echo "  ✗ $rel: " . $e->getMessage() . "\n";
    }
}

// ── Process JS ────────────────────────────────────────
echo "Minifying JS in public/crm/js/\n";
foreach (collect($jsDir, 'js') as $js) {
    minifyFile($js, 'js', $state, $force, $verbose, $processed, $skipped, $errors);
}

// ── Process CSS ───────────────────────────────────────
echo "\nMinifying CSS in public/crm/css/\n";
foreach (collect($cssDir, 'css') as $css) {
    minifyFile($css, 'css', $state, $force, $verbose, $processed, $skipped, $errors);
}

// ── Save manifest ─────────────────────────────────────
file_put_contents($manifest, json_encode($state, JSON_PRETTY_PRINT));

// ── Summary ───────────────────────────────────────────
echo "\n══════════════════════════════════════════════\n";
echo "Processed: $processed    Skipped: $skipped    Errors: " . count($errors) . "\n";
if ($errors) {
    echo "\nErrors:\n";
    foreach ($errors as $e) echo "  $e\n";
    exit(1);
}
exit(0);
