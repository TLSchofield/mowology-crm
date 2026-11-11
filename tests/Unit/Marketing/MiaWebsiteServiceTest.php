<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** MiaWebsiteService: connect + proof-of-control. No network: DNS, HTTP and TXT lookups are faked. */
class MiaWebsiteServiceTest extends TestCase
{
    private PDO $db;
    private const TOKEN = 'aabbccddeeff00112233445566778899';
    /** @var array<string,array> url => response */
    private array $pages = [];
    /** @var string[] */
    private array $txt = [];
    private array $dns = ['mowology.ca' => ['93.184.216.34'], 'www.mowology.ca' => ['93.184.216.34'], 'evil.example' => ['10.0.0.9'], 'other.example' => ['8.8.8.8']];

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE mia_websites (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, base_url TEXT, host TEXT,
            mode TEXT DEFAULT 'existing', connector TEXT DEFAULT 'none', capabilities TEXT, status TEXT DEFAULT 'pending', verify_token TEXT,
            verified_via TEXT, verified_at TEXT, last_verify_attempt_at TEXT, last_verify_error TEXT, crawl_cap INT DEFAULT 200, robots_hash TEXT,
            created_by INT, created_at TEXT, UNIQUE(site_id, host))");
        $this->db = $db;
        $this->pages = [];
        $this->txt = [];
    }

    private function svc(int $site = 1): MiaWebsiteService
    {
        $fetcher = new SafeHttpFetcher(
            [],
            function (string $host) { return $this->dns[$host] ?? []; },
            function (string $url) {
                return $this->pages[$url] ?? ['status' => 404, 'headers' => [], 'body' => 'not found'];
            }
        );
        return new MiaWebsiteService($this->db, $site, $fetcher, function () { return $this->txt; }, function () { return self::TOKEN; });
    }

    private function connected(string $addr = 'mowology.ca'): int
    {
        $r = $this->svc()->connect($addr, 7);
        $this->assertTrue($r['ok'], (string)$r['error']);
        return (int)$r['id'];
    }

    // ---- normalizeOrigin ----

    /** @dataProvider origins */
    public function testNormalizeOrigin(string $in, ?string $base): void
    {
        $o = MiaWebsiteService::normalizeOrigin($in);
        $this->assertSame($base, $o ? $o['base_url'] : null, $in);
    }

    public function origins(): array
    {
        return [
            ['mowology.ca', 'https://mowology.ca'],
            ['  Mowology.CA/services?x=1#a ', 'https://mowology.ca'],
            ['http://www.mowology.ca/', 'http://www.mowology.ca'],
            ['https://mowology.ca:443/', 'https://mowology.ca'],
            ['https://mowology.ca:8443', 'https://mowology.ca:8443'],
            ['mowology.ca.', 'https://mowology.ca'],
            ['', null], ['localhost', null], ['http://127.0.0.1/', null], ['http://[::1]/', null],
            ['http://2130706433/', null], ['https://user:pw@mowology.ca', null], ['ftp://mowology.ca', null],
            ['file:///etc/passwd', null], ['mowology ca', null], ['https://-bad.example', null],
            ['javascript:alert(1)', null], ['http://169.254.169.254/', null],
        ];
    }

    // ---- connect ----

    public function testConnectCreatesPendingSiteWithTokenAndInstructions(): void
    {
        $r = $this->svc()->connect('Mowology.ca/about', 7);
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['existing']);
        $this->assertSame(self::TOKEN, $r['token']);
        $this->assertStringContainsString('content="' . self::TOKEN . '"', $r['instructions']['meta']['snippet']);
        $this->assertSame('ittai-mia=' . self::TOKEN, $r['instructions']['dns']['value']);
        $w = $this->svc()->get((int)$r['id']);
        $this->assertSame('pending', $w['status']);
        $this->assertSame('https://mowology.ca', $w['base_url']);
        $this->assertSame('canReadPages', $w['capabilities'], 'Phase 1 can only read');
        $this->assertFalse($this->svc()->isVerified((int)$r['id']));
        $this->assertNull($this->svc()->requireVerified((int)$r['id']));
    }

    public function testConnectingTwiceReturnsTheSameRecord(): void
    {
        $a = $this->connected();
        $b = $this->svc()->connect('https://www.mowology.ca', 7);          // different host spelling = different record
        $c = $this->svc()->connect('mowology.ca', 7);
        $this->assertTrue($c['existing']);
        $this->assertSame($a, $c['id']);
        $this->assertNotSame($a, $b['id']);
    }

    /** @dataProvider refusedAddresses */
    public function testConnectRefusesUnsafeOrUnknownAddresses(string $addr, string $code): void
    {
        $r = $this->svc()->connect($addr, 7);
        $this->assertFalse($r['ok'], $addr);
        $this->assertSame($code, $r['code'], $addr);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM mia_websites")->fetchColumn());
    }

    public function refusedAddresses(): array
    {
        return [
            'private dns'  => ['evil.example', 'not_allowed'],
            'unknown host' => ['nowhere.example', 'dns_failed'],
            'ip literal'   => ['10.0.0.1', 'bad_url'],
            'garbage'      => ['not a url', 'bad_url'],
            'localhost'    => ['localhost', 'bad_url'],
            'blocked tld'  => ['db.internal', 'not_allowed'],
        ];
    }

    public function testConnectNeedsARealUser(): void
    {
        $this->assertSame('no_user', $this->svc()->connect('mowology.ca', 0)['code']);
    }

    // ---- verify: meta ----

    public function testMetaTagVerifies(): void
    {
        $id = $this->connected();
        $this->pages['https://mowology.ca/'] = ['status' => 200, 'headers' => ['content-type' => 'text/html'],
            'body' => '<html><head><meta charset="utf-8"><META NAME="ittai-mia-verification" CONTENT="' . self::TOKEN . '"></head></html>'];
        $r = $this->svc()->verify($id, 'meta');
        $this->assertTrue($r['ok']);
        $this->assertSame('meta', $r['method']);
        $this->assertTrue($this->svc()->isVerified($id));
        $this->assertSame('meta', $this->svc()->get($id)['verified_via']);
        $this->assertNotNull($this->svc()->requireVerified($id));
    }

    /** @dataProvider wrongMeta */
    public function testWrongOrLookalikeMetaDoesNotVerify(string $html): void
    {
        $id = $this->connected();
        $this->pages['https://mowology.ca/'] = ['status' => 200, 'headers' => [], 'body' => $html];
        $this->assertSame('not_verified', $this->svc()->verify($id, 'meta')['code']);
        $this->assertFalse($this->svc()->isVerified($id));
    }

    public function wrongMeta(): array
    {
        $t = self::TOKEN;
        return [
            'wrong token'      => ['<meta name="ittai-mia-verification" content="ffffffffffffffffffffffffffffffff">'],
            'wrong name'       => ["<meta name=\"other\" content=\"$t\">"],
            'token in comment' => ["<!-- <p>$t</p> --><p>$t</p>"],
            'token as text'    => ["<html><body>ittai-mia-verification $t</body></html>"],
            'name suffix'      => ["<meta name=\"ittai-mia-verification-x\" content=\"$t\">"],
            'partial token'    => ['<meta name="ittai-mia-verification" content="' . substr($t, 0, 20) . '">'],
            'empty'            => [''],
            'in script'        => ["<html><head><script>var x = '<meta name=\"ittai-mia-verification\" content=\"$t\">';</script></head></html>"],
            'in comment'       => ["<html><head><!-- <meta name=\"ittai-mia-verification\" content=\"$t\"> --></head></html>"],
            'in body'          => ["<html><head></head><body><meta name=\"ittai-mia-verification\" content=\"$t\"></body></html>"],
            'data attributes'  => ["<html><head><meta data-name=\"ittai-mia-verification\" data-content=\"$t\"></head></html>"],
            'in textarea'      => ["<html><head></head><body><textarea><meta name=\"ittai-mia-verification\" content=\"$t\"></textarea></body></html>"],
        ];
    }

    // ---- verify: file ----

    public function testFileVerifiesAndIgnoresWhitespace(): void
    {
        $id = $this->connected();
        $this->pages['https://mowology.ca/.well-known/ittai-mia.txt'] = ['status' => 200, 'headers' => ['content-type' => 'text/plain'], 'body' => self::TOKEN . "\n"];
        $this->assertSame('file', $this->svc()->verify($id, 'any')['method']);
    }

    public function testFileWithWrongTokenOr404DoesNotVerify(): void
    {
        $id = $this->connected();
        $this->assertSame('not_verified', $this->svc()->verify($id, 'file')['code'], '404');
        $this->pages['https://mowology.ca/.well-known/ittai-mia.txt'] = ['status' => 200, 'headers' => [], 'body' => 'nope'];
        $this->assertSame('not_verified', $this->svc()->verify($id, 'file')['code']);
        $this->pages['https://mowology.ca/.well-known/ittai-mia.txt'] = ['status' => 500, 'headers' => [], 'body' => self::TOKEN];
        $this->assertSame('not_verified', $this->svc()->verify($id, 'file')['code'], 'error page that happens to echo the token');
    }

    // ---- verify: dns ----

    public function testDnsTxtVerifies(): void
    {
        $id = $this->connected();
        $this->txt = ['v=spf1 -all', '"ittai-mia=' . self::TOKEN . '"'];
        $this->assertSame('dns', $this->svc()->verify($id, 'dns')['method']);
    }

    public function testWrongDnsTxtDoesNotVerify(): void
    {
        $id = $this->connected();
        $this->txt = ['ittai-mia=' . str_repeat('0', 32), self::TOKEN];            // bare token is not the record
        $this->assertSame('not_verified', $this->svc()->verify($id, 'dns')['code']);
    }

    // ---- verify: redirects and safety ----

    public function testRedirectToAnotherSiteCannotVouch(): void
    {
        $id = $this->connected();
        $this->pages['https://mowology.ca/'] = ['status' => 302, 'headers' => ['location' => 'https://other.example/'], 'body' => ''];
        $this->pages['https://other.example/'] = ['status' => 200, 'headers' => [], 'body' => '<meta name="ittai-mia-verification" content="' . self::TOKEN . '">'];
        $r = $this->svc()->verify($id, 'meta');
        $this->assertSame('not_verified', $r['code']);
        $this->assertStringContainsString('other.example', $r['error']);
    }

    public function testApexRedirectingToWwwCannotVouchButTheWwwAddressCanBeConnected(): void
    {
        $id = $this->connected();
        $this->pages['https://mowology.ca/'] = ['status' => 301, 'headers' => ['location' => 'https://www.mowology.ca/'], 'body' => ''];
        $this->pages['https://www.mowology.ca/'] = ['status' => 200, 'headers' => [], 'body' => '<html><head><meta name="ittai-mia-verification" content="' . self::TOKEN . '"></head></html>'];
        $r = $this->svc()->verify($id, 'meta');
        $this->assertSame('not_verified', $r['code']);
        $this->assertStringContainsString('www.mowology.ca', $r['error']);
        $www = (int)$this->svc()->connect('www.mowology.ca', 7)['id'];
        $this->assertTrue($this->svc()->verify($www, 'meta')['ok']);
    }

    public function testRedirectIntoPrivateNetworkIsBlockedAndNotVerified(): void
    {
        $id = $this->connected();
        $this->pages['https://mowology.ca/'] = ['status' => 302, 'headers' => ['location' => 'http://169.254.169.254/'], 'body' => ''];
        $this->assertSame('not_verified', $this->svc()->verify($id, 'meta')['code']);
    }

    // ---- state ----

    public function testFailedRecheckWithdrawsVerification(): void
    {
        $id = $this->connected();
        $this->txt = ['ittai-mia=' . self::TOKEN];
        $this->svc()->verify($id, 'dns');
        $this->assertTrue($this->svc()->isVerified($id));
        $this->txt = [];
        $this->svc()->verify($id, 'dns');
        $this->assertFalse($this->svc()->isVerified($id));
        $this->assertSame('pending', $this->svc()->get($id)['status']);
        $this->assertNotNull($this->svc()->get($id)['last_verify_error']);
    }

    public function testVerificationExpires(): void
    {
        $id = $this->connected();
        $this->txt = ['ittai-mia=' . self::TOKEN];
        $this->svc()->verify($id, 'dns', new DateTimeImmutable('2026-01-01'));
        $this->assertTrue($this->svc()->isVerified($id, new DateTimeImmutable('2026-03-01')));
        $this->assertFalse($this->svc()->isVerified($id, new DateTimeImmutable('2026-06-01')), 'older than 90 days: prove it again');
    }

    public function testDisconnectBlocksVerifyAndCrawlGate(): void
    {
        $id = $this->connected();
        $this->txt = ['ittai-mia=' . self::TOKEN];
        $this->svc()->verify($id, 'dns');
        $this->assertTrue($this->svc()->disconnect($id));
        $this->assertFalse($this->svc()->isVerified($id));
        $this->assertSame('disconnected', $this->svc()->verify($id, 'dns')['code']);
        $this->assertSame([], $this->svc()->listAll());
        $again = $this->svc()->connect('mowology.ca', 7);
        $this->assertSame($id, $again['id']);
        $this->assertFalse($this->svc()->isVerified($id), 'reconnecting needs fresh proof');
    }

    public function testMetaVerifiesWithUnquotedAttributesAndOddMarkup(): void
    {
        $id = $this->connected();
        $this->pages['https://mowology.ca/'] = ['status' => 200, 'headers' => [], 'body' => '<!doctype html><html><head><title>x</title><meta content=' . self::TOKEN . ' name=ittai-mia-verification></head><body>hi</body></html>'];
        $this->assertTrue($this->svc()->verify($id, 'meta')['ok']);
    }

    public function testVerifyDoesNotResurrectASiteDisconnectedDuringTheFetch(): void
    {
        $id = $this->connected();
        $this->txt = ['ittai-mia=' . self::TOKEN];
        $svc = new MiaWebsiteService($this->db, 1, null, function () use ($id) {
            $this->db->exec("UPDATE mia_websites SET status='disconnected' WHERE id=$id");   // another request disconnects mid-check
            return ['ittai-mia=' . self::TOKEN];
        }, function () { return self::TOKEN; });
        $this->assertSame('disconnected', $svc->verify($id, 'dns')['code']);
        $this->assertSame('disconnected', $this->svc()->get($id)['status']);
        $this->assertNull($this->svc()->get($id)['verified_at']);
    }

    public function testFailedRecheckClearsVerifiedAtAndVia(): void
    {
        $id = $this->connected();
        $this->txt = ['ittai-mia=' . self::TOKEN];
        $this->svc()->verify($id, 'dns');
        $this->txt = [];
        $this->svc()->verify($id, 'dns');
        $w = $this->svc()->get($id);
        $this->assertNull($w['verified_at']);
        $this->assertNull($w['verified_via']);
    }

    public function testBadMethodAndMissingSite(): void
    {
        $id = $this->connected();
        $this->assertSame('bad_method', $this->svc()->verify($id, 'carrier-pigeon')['code']);
        $this->assertSame('not_found', $this->svc()->verify(999, 'any')['code']);
    }

    // ---- tenant isolation ----

    public function testSitesCannotSeeVerifyOrDisconnectEachOthersWebsites(): void
    {
        $id = $this->connected();
        $this->txt = ['ittai-mia=' . self::TOKEN];
        $this->assertNull($this->svc(2)->get($id));
        $this->assertSame('not_found', $this->svc(2)->verify($id, 'dns')['code']);
        $this->assertFalse($this->svc(2)->disconnect($id));
        $this->assertSame('pending', $this->svc(1)->get($id)['status']);
        $this->assertSame([], $this->svc(2)->listAll());
        // the same domain can be connected by site 2 independently
        $r = $this->svc(2)->connect('mowology.ca', 9);
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['existing']);
    }

    public function testSiteZeroIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MiaWebsiteService($this->db, 0);
    }
}
