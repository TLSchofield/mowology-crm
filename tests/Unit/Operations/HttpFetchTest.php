<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * HttpFetch reads a web page without allow_url_fopen (cron's PHP has it off: "https:// wrapper is disabled"
 * ended Otto's auto-place nine runs in a row, 2026-10-19 to 22) and never throws.
 */
class HttpFetchTest extends TestCase
{
    public function testUnreachableHostIsNullNotAnException(): void
    {
        $this->assertNull(HttpFetch::get('http://127.0.0.1:9/nothing', 2));
    }

    public function testReadsABodyWithoutAllowUrlFopen(): void
    {
        if (!function_exists('curl_init')) $this->markTestSkipped('cURL not available');
        $dir = sys_get_temp_dir() . '/httpfetch-' . getmypid();
        @mkdir($dir);
        file_put_contents($dir . '/index.php', '<?php echo "ok:" . ($_SERVER["HTTP_USER_AGENT"] ?? "");');
        $port = random_int(20000, 40000);
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $dir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($proc)) $this->markTestSkipped('cannot start a local web server');
        try {
            $body = null;
            for ($i = 0; $i < 20 && $body === null; $i++) {
                usleep(150000);
                $body = $this->fetchWithoutUrlFopen("http://127.0.0.1:$port/");
            }
            $this->assertSame('ok:HttpFetchTest', $body);
        } finally {
            proc_terminate($proc);
            @unlink($dir . '/index.php');
            @rmdir($dir);
        }
    }

    /** @return array<string, array{string}> */
    public static function fetchingServices(): array
    {
        return ['CityParcelService' => ['CityParcelService'], 'ParcelLotService' => ['ParcelLotService'],
                'MarketPriceService' => ['MarketPriceService'], 'SupplierDirectoryService' => ['SupplierDirectoryService']];
    }

    /**
     * Production has no autoloader and no test bootstrap: a service that fetches must load HttpFetch itself.
     * (A require_once that sat inside a doc comment passed every test because bootstrap.php loads the class.)
     * @dataProvider fetchingServices
     */
    public function testServiceLoadsHttpFetchOnItsOwn(string $service): void
    {
        $file = __DIR__ . '/../../../app/Modules/Operations/Services/' . $service . '.php';
        $code = 'require ' . var_export($file, true) . '; echo class_exists("HttpFetch", false) ? "yes" : "no";';
        $this->assertSame('yes', trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1')));
    }

    /** Runs HttpFetch::get in a child PHP started with allow_url_fopen=0, the way cron runs it. */
    private function fetchWithoutUrlFopen(string $url): ?string
    {
        $code = 'require ' . var_export(__DIR__ . '/../../../app/Modules/Operations/Services/HttpFetch.php', true) . ';'
              . '$b = HttpFetch::get(' . var_export($url, true) . ', 3, ["User-Agent: HttpFetchTest"]); echo $b === null ? "__null__" : $b;';
        $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d allow_url_fopen=0 -r ' . escapeshellarg($code) . ' 2>/dev/null');
        return $out === '__null__' || $out === '' ? null : $out;
    }
}
