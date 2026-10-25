<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SSRF guard for every outbound fetch of a user/page-supplied URL (Mia site audits).
 * No network: resolver and transport are faked.
 */
class SafeHttpFetcherTest extends TestCase
{
    /** @param array<string,string[]> $dns */
    private function fetcher(array $dns = [], ?callable $transport = null, array $opts = []): SafeHttpFetcher
    {
        $resolver = function (string $host) use ($dns) {
            return $dns[$host] ?? [];
        };
        $transport = $transport ?: function () {
            return ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => 'ok'];
        };
        return new SafeHttpFetcher($opts, $resolver, $transport);
    }

    /** @dataProvider privateIps */
    public function testPrivateAndReservedIpsAreNotPublic(string $ip): void
    {
        $this->assertFalse(SafeHttpFetcher::isPublicIp($ip), $ip);
    }

    public function privateIps(): array
    {
        $ips = ['0.0.0.0', '10.1.2.3', '100.64.0.1', '127.0.0.1', '127.255.255.254', '169.254.169.254',
                '172.16.0.1', '172.31.255.255', '192.0.0.1', '192.168.1.1', '198.18.0.1', '224.0.0.1',
                '240.0.0.1', '255.255.255.255',
                '::', '::1', 'fe80::1', 'fc00::1', 'fd12:3456::1', 'ff02::1', '2001:db8::1',
                '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '::127.0.0.1',
                '64:ff9b::7f00:1', '2002:7f00:1::1', '2002:a9fe:a9fe::1'];
        return array_map(function ($i) { return [$i]; }, $ips);
    }

    /** @dataProvider publicIps */
    public function testPublicIpsPass(string $ip): void
    {
        $this->assertTrue(SafeHttpFetcher::isPublicIp($ip), $ip);
    }

    public function publicIps(): array
    {
        return [['8.8.8.8'], ['1.1.1.1'], ['172.32.0.1'], ['100.63.255.255'], ['2606:4700:4700::1111'], ['::ffff:8.8.8.8']];
    }

    /** @dataProvider hostileUrls */
    public function testHostileUrlsAreBlockedBeforeAnyRequest(string $url, string $code): void
    {
        $called = false;
        $f = $this->fetcher(
            ['evil.example' => ['10.0.0.5'], 'mixed.example' => ['8.8.8.8', '127.0.0.1'], 'nx.example' => []],
            function () use (&$called) { $called = true; return ['status' => 200, 'headers' => [], 'body' => '']; }
        );
        $r = $f->fetch($url);
        $this->assertFalse($r['ok'], $url);
        $this->assertSame($code, $r['code'], $url);
        $this->assertFalse($called, 'transport must not be reached: ' . $url);
    }

    public function hostileUrls(): array
    {
        return [
            'metadata ip'        => ['http://169.254.169.254/latest/meta-data/', 'blocked_ip'],
            'loopback'           => ['http://127.0.0.1/', 'blocked_ip'],
            'loopback v6'        => ['http://[::1]/', 'blocked_ip'],
            'mapped v6 loopback' => ['http://[::ffff:127.0.0.1]/', 'blocked_ip'],
            'rfc1918'            => ['http://192.168.0.1/admin', 'blocked_ip'],
            'decimal ip'         => ['http://2130706433/', 'blocked_host'],
            'hex ip'             => ['http://0x7f000001/', 'blocked_host'],
            'octal dotted'       => ['http://0177.0.0.1/', 'blocked_host'],
            'short dotted'       => ['http://127.1/', 'blocked_host'],
            'localhost'          => ['http://localhost/', 'blocked_host'],
            'localhost sub'      => ['http://foo.localhost/', 'blocked_host'],
            'internal tld'       => ['http://db.internal/', 'blocked_host'],
            'gcp metadata'       => ['http://metadata.google.internal/', 'blocked_host'],
            'name -> private'    => ['http://evil.example/', 'blocked_ip'],
            'one private of two' => ['http://mixed.example/', 'blocked_ip'],
            'no dns'             => ['http://nx.example/', 'dns_failed'],
            'file scheme'        => ['file:///etc/passwd', 'bad_scheme'],
            'gopher'             => ['gopher://example.com/', 'bad_scheme'],
            'ftp'                => ['ftp://example.com/', 'bad_scheme'],
            'userinfo'           => ['http://user:pw@example.com/', 'userinfo'],
            'userinfo trick'     => ['http://example.com@127.0.0.1/', 'userinfo'],
            'odd port'           => ['http://example.com:3306/', 'bad_port'],
            'ssh port'           => ['http://example.com:22/', 'bad_port'],
            'empty'              => ['', 'bad_url'],
            'no host'            => ['http:///path', 'bad_url'],
            'control char'       => ["http://example.com/a\r\nHost: x", 'bad_url'],
            'space'              => ['http://exa mple.com/', 'bad_url'],
            'trailing dot local' => ['http://localhost./', 'blocked_host'],
        ];
    }

    public function testPublicNameIsFetchedAndPinnedToCheckedIp(): void
    {
        $seen = null;
        $f = $this->fetcher(['example.com' => ['93.184.216.34']], function ($url, $host, $port, $ip) use (&$seen) {
            $seen = [$url, $host, $port, $ip];
            return ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => '<html>'];
        });
        $r = $f->fetch('https://Example.com/page');
        $this->assertTrue($r['ok']);
        $this->assertSame(200, $r['status']);
        $this->assertSame(['https://Example.com/page', 'example.com', 443, '93.184.216.34'], $seen);
    }

    public function testRedirectToPrivateHostIsBlockedOnTheHop(): void
    {
        $n = 0;
        $f = $this->fetcher(['example.com' => ['93.184.216.34']], function () use (&$n) {
            $n++;
            return ['status' => 302, 'headers' => ['location' => 'http://169.254.169.254/latest/'], 'body' => ''];
        });
        $r = $f->fetch('http://example.com/');
        $this->assertFalse($r['ok']);
        $this->assertSame('blocked_ip', $r['code']);
        $this->assertSame(1, $n, 'only the first (public) hop may be requested');
    }

    public function testRedirectToRebindingNameIsBlocked(): void
    {
        $f = $this->fetcher(['a.example' => ['8.8.8.8'], 'b.example' => ['127.0.0.1']], function ($url) {
            return strpos($url, 'a.example') !== false
                ? ['status' => 301, 'headers' => ['location' => 'http://b.example/'], 'body' => '']
                : ['status' => 200, 'headers' => [], 'body' => 'secret'];
        });
        $r = $f->fetch('http://a.example/');
        $this->assertFalse($r['ok']);
        $this->assertSame('blocked_ip', $r['code']);
        $this->assertSame('', $r['body']);
    }

    public function testRelativeRedirectFollowsAndIsRecorded(): void
    {
        $f = $this->fetcher(['example.com' => ['93.184.216.34']], function ($url) {
            return $url === 'https://example.com/old/index.html'
                ? ['status' => 301, 'headers' => ['location' => '../new'], 'body' => '']
                : ['status' => 200, 'headers' => [], 'body' => 'done'];
        });
        $r = $f->fetch('https://example.com/old/index.html');
        $this->assertTrue($r['ok']);
        $this->assertSame(['https://example.com/old/../new'], $r['redirects']);
    }

    public function testRedirectLoopStopsAtLimit(): void
    {
        $f = $this->fetcher(['example.com' => ['93.184.216.34']], function () {
            return ['status' => 302, 'headers' => ['location' => 'http://example.com/again'], 'body' => ''];
        }, ['max_redirects' => 3]);
        $r = $f->fetch('http://example.com/');
        $this->assertFalse($r['ok']);
        $this->assertSame('too_many_redirects', $r['code']);
    }

    public function testRedirectToDisallowedSchemeIsBlocked(): void
    {
        $f = $this->fetcher(['example.com' => ['93.184.216.34']], function () {
            return ['status' => 302, 'headers' => ['location' => 'file:///etc/passwd'], 'body' => ''];
        });
        $r = $f->fetch('http://example.com/');
        $this->assertSame('bad_scheme', $r['code']);
    }

    public function testTransportFailureCodesPassThrough(): void
    {
        foreach (['too_large', 'timeout', 'bad_content_type', 'transport'] as $code) {
            $f = $this->fetcher(['example.com' => ['93.184.216.34']], function () use ($code) {
                return ['code' => $code, 'error' => 'x'];
            });
            $r = $f->fetch('http://example.com/');
            $this->assertFalse($r['ok']);
            $this->assertSame($code, $r['code']);
        }
    }

    public function testResolveLocation(): void
    {
        $this->assertSame('https://a.com/x', SafeHttpFetcher::resolveLocation('https://a.com/q/r', '/x'));
        $this->assertSame('https://a.com/q/y', SafeHttpFetcher::resolveLocation('https://a.com/q/r', 'y'));
        $this->assertSame('https://b.com/z', SafeHttpFetcher::resolveLocation('https://a.com/q', '//b.com/z'));
        $this->assertSame('http://b.com/', SafeHttpFetcher::resolveLocation('https://a.com/', 'http://b.com/'));
        $this->assertNull(SafeHttpFetcher::resolveLocation('https://a.com/', "/x\r\nSet-Cookie: a=b"));
        $this->assertNull(SafeHttpFetcher::resolveLocation('https://a.com/', ''));
    }

    public function testCustomAllowedPorts(): void
    {
        $f = $this->fetcher(['example.com' => ['93.184.216.34']], null, ['allowed_ports' => [80, 443, 8080]]);
        $this->assertTrue($f->fetch('http://example.com:8080/')['ok']);
    }

    public function testByteAndTimeBudgetIsSharedAcrossRedirects(): void
    {
        $seen = [];
        $f = $this->fetcher(['example.com' => ['93.184.216.34']], function ($url, $h, $p, $ip, $opts) use (&$seen) {
            $seen[] = [$opts['max_bytes'], $opts['timeout']];
            return ['status' => 302, 'headers' => ['location' => 'http://example.com/n' . count($seen)], 'body' => str_repeat('a', 400)];
        }, ['max_bytes' => 1000, 'timeout' => 10, 'max_redirects' => 5]);
        $f->fetch('http://example.com/');
        $this->assertSame(1000, $seen[0][0]);
        $this->assertSame(600, $seen[1][0]);
        $this->assertSame(200, $seen[2][0]);
        $this->assertLessThanOrEqual(10, $seen[2][1]);
    }

    public function testExhaustedTimeBudgetStopsBeforeNextHop(): void
    {
        $n = 0;
        $f = $this->fetcher(['example.com' => ['93.184.216.34']], function () use (&$n) {
            $n++;
            usleep(1100000);
            return ['status' => 302, 'headers' => ['location' => 'http://example.com/x'], 'body' => ''];
        }, ['timeout' => 1]);
        $r = $f->fetch('http://example.com/');
        $this->assertSame('timeout', $r['code']);
        $this->assertSame(1, $n);
    }
}
