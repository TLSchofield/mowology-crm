<?php
declare(strict_types=1);

/**
 * CRM QA Crawler
 *
 * Logs into the CRM as a dedicated low-privilege test account and visits
 * every page, checking for HTTP errors, PHP errors, JS console errors, and
 * basic accessibility issues (via axe-core). Optionally exercises a couple
 * of sandboxed create -> verify -> cleanup flows to confirm forms actually
 * submit (see tools/lib/MutationFlows.php).
 *
 * Usage:
 *   php tools/crm-crawl.php [--base-url=https://mowology.ca] [--mutations] [--only=key] [--verbose]
 *
 * Prerequisites (one-time):
 *   composer install
 *   vendor/bin/bdi detect drivers   (or see tools/README if that fails — a
 *   matching chromedriver must exist at drivers/chromedriver)
 *
 * See public/crm/run-migration-1109.php for provisioning the test account,
 * and public/app_config/qa-test-credentials.php (gitignored) for its login.
 */

$options = getopt('', ['base-url:', 'mutations', 'only:', 'verbose']);
$baseUrlOverride = $options['base-url'] ?? null;
$runMutations = array_key_exists('mutations', $options);
$onlyKey = $options['only'] ?? null;
$verbose = array_key_exists('verbose', $options);

$rootDir = dirname(__DIR__);

// ── Prerequisite checks ───────────────────────────────────────────────────
$autoload = $rootDir . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Missing {$autoload} — run `composer install` in the project root first.\n");
    exit(1);
}
require_once $autoload;

$chromedriverPath = $rootDir . '/drivers/chromedriver';
if (!is_file($chromedriverPath) || !is_executable($chromedriverPath)) {
    fwrite(STDERR, "Missing or non-executable chromedriver at {$chromedriverPath}.\n");
    fwrite(STDERR, "Run `vendor/bin/bdi detect drivers` — if that fails, download a chromedriver\n");
    fwrite(STDERR, "matching your installed Chrome version from https://googlechromelabs.github.io/chrome-for-testing/\n");
    fwrite(STDERR, "and place the binary at drivers/chromedriver (chmod +x).\n");
    exit(1);
}
putenv("PANTHER_CHROME_DRIVER_BINARY={$chromedriverPath}");

require_once __DIR__ . '/lib/QaCrawlerConfig.php';
require_once __DIR__ . '/lib/PageInventory.php';
require_once __DIR__ . '/lib/CrawlResult.php';
require_once __DIR__ . '/lib/Report.php';
require_once __DIR__ . '/lib/MutationFlows.php';

// Bootstrap the app's own DB layer so credentials are read from the same
// place (public/app_config/secrets.php) the live app uses — no duplicated
// connection logic here.
require_once $rootDir . '/app/Core/config.php';

use Symfony\Component\Panther\Client as PantherClient;

$config = new QaCrawlerConfig($baseUrlOverride);
$db = getDB();
$report = new Report();

echo "CRM QA Crawler\n";
echo "Target: {$config->baseUrl}\n";
echo "Mutations: " . ($runMutations ? 'ON' : 'off') . "\n\n";

$client = PantherClient::createChromeClient(null, ['--headless=new'], [
    'capabilities' => [
        'goog:loggingPrefs' => ['browser' => 'ALL'],
    ],
]);

try {
    // ── Login (real form-fill, not a scraped POST) ───────────────────────
    $client->request('GET', $config->baseUrl . '/loginAuth/login.php');
    $client->waitFor('#email', 10);
    $crawler = $client->getCrawler();
    $crawler->filter('#email')->sendKeys($config->email);
    $crawler->filter('#password')->sendKeys($config->password);
    $crawler->filter('button.login-button')->click();

    $client->request('GET', $config->baseUrl . '/crm/dashboard_appstack.php');
    try {
        $client->waitFor('#sidebar', 10);
    } catch (Throwable $e) {
        fwrite(STDERR, "Login failed — #sidebar never appeared on dashboard_appstack.php. Check credentials/account status.\n");
        exit(1);
    }
    echo "Logged in as {$config->email}\n\n";

    // ── Static pages from the sidebar/known list ─────────────────────────
    $pages = PageInventory::pages();
    if ($onlyKey) {
        $pages = array_values(array_filter($pages, fn($p) => $p['key'] === $onlyKey));
    }
    foreach ($pages as $page) {
        $result = crawlPage($client, $config->baseUrl, $page['path'], $page['label'], $page['expectGate'], $verbose);
        $report->add($result);
    }

    // ── Dynamic-ID pages discovered by following real links off list pages ──
    if (!$onlyKey) {
        foreach (PageInventory::listPagesForDynamicDiscovery() as $entity => $listPath) {
            $discovered = discoverDynamicLinks($client, $config->baseUrl, $listPath, $entity, 3, $verbose);
            foreach ($discovered as $url => $label) {
                $result = crawlPage($client, $config->baseUrl, $url, $label, false, $verbose, absolute: true);
                $report->add($result);
            }
        }
    }

    // ── Mutation flows (opt-in) ───────────────────────────────────────────
    if ($runMutations) {
        $flows = new MutationFlows($client, $db, $config->baseUrl);
        try {
            foreach ($flows->run() as $m) {
                $report->addMutation($m['name'], $m['status'], $m['note']);
            }
        } finally {
            $flows->finalSweep();
        }
    } else {
        // Even on a read-only run, sweep for any leftovers from a prior
        // crashed --mutations run so they don't linger indefinitely.
        $sweeper = new MutationFlows($client, $db, $config->baseUrl);
        $leftover = $sweeper->finalSweep();
        if ($leftover > 0 && $verbose) {
            echo "Swept {$leftover} leftover QA-tagged row(s) from a prior run.\n";
        }
    }
} finally {
    $client->quit();
}

echo "\n";
$report->printTable();

$jsonPath = $rootDir . '/tools/output/crawl-report-' . date('Y-m-d_His') . '.json';
$report->writeJson($jsonPath);
echo "\nJSON report: {$jsonPath}\n";

exit($report->hasFailures() ? 1 : 0);

// ─────────────────────────────────────────────────────────────────────────

function crawlPage(PantherClient $client, string $baseUrl, string $path, string $label, bool $expectGate, bool $verbose, bool $absolute = false): CrawlResult
{
    $url = $absolute ? $path : $baseUrl . $path;
    $result = new CrawlResult($url, $label);
    $result->expectedGate = $expectGate;

    if ($verbose) echo "Visiting {$url}...\n";

    try {
        $client->request('GET', $url);
    } catch (Throwable $e) {
        $result->httpStatus = 0;
        $result->phpErrors[] = 'request failed: ' . $e->getMessage();
        $result->finalize();
        return $result;
    }

    try {
        $result->httpStatus = $client->getInternalResponse()->getStatusCode();
    } catch (Throwable $e) {
        $result->httpStatus = 0;
    }

    $currentUrl = $client->getCurrentURL();
    $body = $client->getPageSource() ?? '';

    $result->stillLoggedIn = !str_contains($currentUrl, '/loginAuth/login.php');

    foreach (['Parse error', 'Fatal error', 'Warning:', 'Notice:', 'Deprecated:'] as $marker) {
        if (str_contains($body, $marker)) {
            $result->phpErrors[] = $marker;
        }
    }

    try {
        $logs = $client->getWebDriver()->manage()->getLog('browser');
        foreach ($logs as $entry) {
            if (($entry['level'] ?? '') === 'SEVERE') {
                $result->jsConsoleErrors[] = (string)($entry['message'] ?? 'unknown console error');
            }
        }
    } catch (Throwable $e) {
        // Some pages/redirects may not have a log buffer available — not fatal.
    }

    if ($result->stillLoggedIn) {
        $result->a11yIssues = runAxeCheck($client);
    }

    $result->finalize();
    return $result;
}

/** @return string[] */
function runAxeCheck(PantherClient $client): array
{
    static $axeSource = null;
    if ($axeSource === null) {
        $axeSource = file_get_contents(__DIR__ . '/vendor-assets/axe.min.js');
    }

    try {
        $client->executeScript($axeSource);
        $violations = $client->executeScript(
            'var done = arguments[0]; axe.run().then(function(r){ done(r.violations.map(function(v){ return v.id + ": " + v.help; })); }).catch(function(){ done([]); });'
        );
        return is_array($violations) ? $violations : [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Visits a list page and extracts up to $limit same-origin links matching
 * a dynamic-ID pattern (?id=...) that aren't the list page itself.
 *
 * @return array<string,string> absolute URL => label
 */
function discoverDynamicLinks(PantherClient $client, string $baseUrl, string $listPath, string $entity, int $limit, bool $verbose): array
{
    $found = [];
    try {
        $client->request('GET', $baseUrl . $listPath);
        $client->waitFor('#sidebar', 10);
    } catch (Throwable $e) {
        if ($verbose) echo "Could not load list page {$listPath} for {$entity} discovery: {$e->getMessage()}\n";
        return $found;
    }

    $crawler = $client->getCrawler();
    try {
        $links = $crawler->filter('a[href*="view.php?id="], a[href*="id="]')->extract(['href']);
    } catch (Throwable $e) {
        return $found;
    }

    foreach ($links as $href) {
        if (count($found) >= $limit) break;
        if (!str_contains($href, 'id=') || !str_contains($href, 'view.php')) continue;
        $absolute = str_starts_with($href, 'http') ? $href : $baseUrl . (str_starts_with($href, '/') ? $href : '/' . $href);
        $found[$absolute] = ucfirst($entity) . ' — view (discovered)';
    }

    if ($verbose) echo "Discovered " . count($found) . " {$entity} detail page(s) from {$listPath}\n";
    return $found;
}
