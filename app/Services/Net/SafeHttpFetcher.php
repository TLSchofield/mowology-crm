<?php
/**
 * SafeHttpFetcher — the ONLY way server code may fetch a URL a user or a web page supplied.
 *
 * Why: any feature that fetches a customer-given address (site audits, link checks, previews) is a
 * server-side request forgery hole unless the destination is checked. This class refuses private,
 * loopback, link-local, cloud-metadata and reserved addresses; resolves the name itself and PINS the
 * connection to the checked address (so a name that changes its answer between check and connect
 * cannot redirect us inside); follows redirects by hand, re-checking each hop; and caps time and size.
 *
 * Never throws on a bad destination: returns ['ok' => false, 'code' => ..., 'error' => ...].
 * Codes: bad_url, bad_scheme, bad_port, userinfo, blocked_host, blocked_ip, dns_failed,
 *        too_many_redirects, bad_redirect, too_large, bad_content_type, timeout, transport.
 *
 * PHP 7.4-compatible (no enums / union types / str_contains).
 * The resolver and transport are injectable so the guard can be tested without a network.
 */
class SafeHttpFetcher
{
    public const USER_AGENT = 'IttaiMiaBot/1.0 (+https://mowology.ca; site audit for the site owner)';

    /** @var array<string,mixed> */
    private $opts;
    /** @var callable */
    private $resolver;
    /** @var callable */
    private $transport;

    /**
     * Options: max_bytes (2 MiB), timeout (10 s), connect_timeout (5 s), max_redirects (5),
     *          allowed_ports ([80,443]), allowed_types (null = any; else list of 'text/html', ...),
     *          user_agent.
     *
     * @param callable|null $resolver  fn(string $host): string[]   IPs for the name
     * @param callable|null $transport fn(string $url, string $host, int $port, string $ip, array $opts): array
     *        returns ['status'=>int,'headers'=>array<lowercase name,string>,'body'=>string,'error'=>?string,'code'=>?string]
     */
    public function __construct(array $opts = [], ?callable $resolver = null, ?callable $transport = null)
    {
        $this->opts = array_merge([
            'max_bytes'       => 2 * 1024 * 1024,
            'timeout'         => 10,
            'connect_timeout' => 5,
            'max_redirects'   => 5,
            'allowed_ports'   => [80, 443],
            'allowed_types'   => null,
            'user_agent'      => self::USER_AGENT,
        ], $opts);
        $this->resolver  = $resolver  ?: [self::class, 'defaultResolve'];
        $this->transport = $transport ?: [self::class, 'curlTransport'];
    }

    /**
     * @return array{ok:bool,url:string,status:int,headers:array,body:string,redirects:string[],code:?string,error:?string}
     */
    public function fetch(string $url): array
    {
        $result = ['ok' => false, 'url' => $url, 'status' => 0, 'headers' => [], 'body' => '',
                   'redirects' => [], 'code' => null, 'error' => null];
        $current = $url;
        // One budget for the whole fetch, redirects included (Codex review 2026-10-25).
        $deadline  = microtime(true) + (float)$this->opts['timeout'];
        $bytesLeft = (int)$this->opts['max_bytes'];

        for ($hop = 0; $hop <= (int)$this->opts['max_redirects']; $hop++) {
            $check = $this->checkUrl($current);
            if (!$check['ok']) {
                return $this->fail($result, $check['code'], $check['error']);
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return $this->fail($result, 'timeout', 'Timed out');
            }
            $hopOpts = array_merge($this->opts, ['timeout' => max(1, (int)ceil($remaining)), 'max_bytes' => max(0, $bytesLeft)]);
            $resp = call_user_func($this->transport, $current, $check['host'], $check['port'], $check['ip'], $hopOpts);
            $bytesLeft -= strlen((string)($resp['body'] ?? ''));

            if (!empty($resp['code'])) {
                return $this->fail($result, (string)$resp['code'], (string)($resp['error'] ?? 'fetch failed'));
            }
            $status  = (int)($resp['status'] ?? 0);
            $headers = is_array($resp['headers'] ?? null) ? $resp['headers'] : [];

            if ($status >= 300 && $status < 400 && isset($headers['location']) && $headers['location'] !== '') {
                $next = self::resolveLocation($current, (string)$headers['location']);
                if ($next === null) {
                    return $this->fail($result, 'bad_redirect', 'Redirect target could not be understood');
                }
                $result['redirects'][] = $next;
                $current = $next;
                continue;
            }

            $result['url']     = $current;
            $result['status']  = $status;
            $result['headers'] = $headers;
            $result['body']    = (string)($resp['body'] ?? '');
            $result['ok']      = $status > 0;
            return $result;
        }
        return $this->fail($result, 'too_many_redirects', 'Too many redirects');
    }

    /**
     * Validate a URL and resolve it to one safe IP. No network traffic except DNS via the resolver.
     *
     * @return array{ok:bool,code?:string,error?:string,host?:string,port?:int,ip?:string,scheme?:string}
     */
    public function checkUrl(string $url): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return self::bad('bad_url', 'Not a usable URL');
        }
        $p = parse_url($url);
        if ($p === false || empty($p['scheme'])) {
            return self::bad('bad_url', 'Not a usable URL');
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return self::bad('bad_scheme', 'Only http and https are allowed');
        }
        if (empty($p['host'])) {
            return self::bad('bad_url', 'Not a usable URL');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return self::bad('userinfo', 'Addresses with a username or password are not allowed');
        }
        $port = isset($p['port']) ? (int)$p['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, array_map('intval', (array)$this->opts['allowed_ports']), true)) {
            return self::bad('bad_port', 'Port not allowed');
        }

        $host = strtolower(rtrim($p['host'], '.'));
        $host = trim($host, '[]');
        if ($host === '' || self::isBlockedName($host)) {
            return self::bad('blocked_host', 'That address is not allowed');
        }

        // IP literal (v4 dotted quad or v6): check directly. Any other all-numeric/hex-looking host
        // (2130706433, 0x7f.1, 127.1, 0177.0.0.1) is refused: libc would turn it into an IP we never checked.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } elseif (preg_match('/^[0-9a-fx.:]+$/i', $host)) {
            return self::bad('blocked_host', 'That address is not allowed');
        } else {
            $ips = (array)call_user_func($this->resolver, $host);
            $ips = array_values(array_filter(array_map('strval', $ips), function ($ip) {
                return filter_var($ip, FILTER_VALIDATE_IP) !== false;
            }));
            if (!$ips) {
                return self::bad('dns_failed', 'Could not look up that address');
            }
        }
        // EVERY answer must be public — a name with one private A record is an attack, not a typo.
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return self::bad('blocked_ip', 'That address points inside a private network');
            }
        }
        return ['ok' => true, 'host' => $host, 'port' => $port, 'ip' => $ips[0], 'scheme' => $scheme];
    }

    // ---- static helpers (unit-tested directly) ---------------------------------------------------

    public static function isBlockedName(string $host): bool
    {
        if ($host === 'localhost' || $host === 'metadata' || $host === 'metadata.google.internal') {
            return true;
        }
        foreach (['.localhost', '.local', '.internal', '.localdomain', '.home', '.lan', '.corp', '.intranet'] as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return true;
            }
        }
        return false;
    }

    /** True only for globally routable unicast addresses. Unwraps v4-in-v6 forms first. */
    public static function isPublicIp(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 16) {
            $v4 = self::embeddedV4($bin);
            if ($v4 !== null) {
                return self::isPublicIp($v4);
            }
            // ::/128, ::1, and anything starting 0000... other than handled embeddings
            if (self::inV6($bin, '::', 8)) {
                return false;
            }
            foreach ([['fc00::', 7], ['fe80::', 10], ['fec0::', 10], ['ff00::', 8], ['100::', 64],
                      ['2001:db8::', 32], ['2001::', 32], ['2001:10::', 28], ['2001:20::', 28]] as $r) {
                if (self::inV6($bin, $r[0], $r[1])) {
                    return false;
                }
            }
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        foreach ([['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
                  ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16],
                  ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]] as $r) {
            $mask = $r[1] === 0 ? 0 : (~((1 << (32 - $r[1])) - 1)) & 0xFFFFFFFF;
            if ((($long & 0xFFFFFFFF) & $mask) === (ip2long($r[0]) & $mask)) {
                return false;
            }
        }
        return true;
    }

    /** Embedded IPv4 for ::ffff:a.b.c.d (mapped), ::a.b.c.d (compatible), 64:ff9b::/96 (NAT64), 2002::/16 (6to4). */
    private static function embeddedV4(string $bin): ?string
    {
        $zeros10 = str_repeat("\0", 10);
        if (substr($bin, 0, 10) === $zeros10 && substr($bin, 10, 2) === "\xff\xff") {
            return inet_ntop(substr($bin, 12, 4));
        }
        if (substr($bin, 0, 12) === str_repeat("\0", 12) && substr($bin, 12, 4) !== "\0\0\0\0"
            && substr($bin, 12, 4) !== "\0\0\0\1") {
            return inet_ntop(substr($bin, 12, 4));
        }
        if (substr($bin, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
            return inet_ntop(substr($bin, 12, 4));
        }
        if (substr($bin, 0, 2) === "\x20\x02") {
            return inet_ntop(substr($bin, 2, 4));
        }
        return null;
    }

    private static function inV6(string $bin, string $prefix, int $bits): bool
    {
        $p = inet_pton($prefix);
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($bin, 0, $bytes) !== substr($p, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($bin[$bytes]) & $mask) === (ord($p[$bytes]) & $mask);
    }

    /** Resolve a Location header against the URL it came from. Returns null if it makes no sense. */
    public static function resolveLocation(string $base, string $location): ?string
    {
        $location = trim($location);
        if ($location === '' || preg_match('/[\x00-\x1f\x7f]/', $location)) {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;                       // absolute (scheme checked later)
        }
        $b = parse_url($base);
        if (!$b || empty($b['scheme']) || empty($b['host'])) {
            return null;
        }
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (strpos($location, '//') === 0) {
            return $b['scheme'] . ':' . $location;
        }
        if ($location[0] === '/') {
            return $origin . $location;
        }
        $path = $b['path'] ?? '/';
        $dir  = substr($path, 0, (int)strrpos($path, '/') + 1);
        return $origin . $dir . $location;
    }

    public static function defaultResolve(string $host): array
    {
        // dns_get_record asks for real A/AAAA records only; it does not interpret "2130706433"-style names.
        $ips = [];
        $recs = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($recs)) {
            foreach ($recs as $r) {
                if (!empty($r['ip'])) {
                    $ips[] = $r['ip'];
                } elseif (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        return $ips;
    }

    /** Default transport: cURL pinned to the checked IP, no auto-redirects, byte/time/type caps. */
    public static function curlTransport(string $url, string $host, int $port, string $ip, array $opts): array
    {
        if (!function_exists('curl_init')) {
            return ['code' => 'transport', 'error' => 'cURL is not available'];
        }
        $max     = (int)$opts['max_bytes'];
        $body    = '';
        $headers = [];
        $abort   = null;
        $types   = $opts['allowed_types'];

        $pinIp = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // Pin BOTH spellings: a URL host with a trailing dot ("example.com.") is looked up under that
            // exact name, so a pin for "example.com" alone would be skipped and cURL would resolve again.
            CURLOPT_RESOLVE         => [$host . ':' . $port . ':' . $pinIp, $host . '.:' . $port . ':' . $pinIp],
            // Never go through an environment proxy: it would resolve the name itself and skip the pin.
            CURLOPT_PROXY           => '',
            CURLOPT_NOPROXY         => '*',
            CURLOPT_USERAGENT       => (string)$opts['user_agent'],
            CURLOPT_TIMEOUT         => (int)$opts['timeout'],
            CURLOPT_CONNECTTIMEOUT  => (int)$opts['connect_timeout'],
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_HTTPHEADER      => ['Accept: text/html,application/xhtml+xml,application/xml,text/plain;q=0.9,*/*;q=0.5',
                                        'Accept-Encoding: identity'],
            CURLOPT_HEADERFUNCTION  => function ($c, $line) use (&$headers, &$abort, $types) {
                $len = strlen($line);
                if (strpos($line, ':') !== false) {
                    list($k, $v) = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                    if (strtolower(trim($k)) === 'content-type' && is_array($types)) {
                        $ct = strtolower(trim(explode(';', $v)[0]));
                        if (!in_array($ct, $types, true)) {
                            $abort = 'bad_content_type';
                            return 0;
                        }
                    }
                } elseif (stripos($line, 'HTTP/') === 0) {
                    $headers = [];                   // new response block (e.g. after 100-continue)
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION   => function ($c, $chunk) use (&$body, &$abort, $max) {
                if (strlen($body) + strlen($chunk) > $max) {
                    $abort = 'too_large';
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $errno  = curl_errno($ch);
        $err    = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);                                  // curl_close() is deprecated in 8.5 and a no-op since 8.0

        if ($abort !== null) {
            return ['code' => $abort, 'error' => $abort === 'too_large' ? 'Response too large' : 'Content type not allowed'];
        }
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            return ['code' => 'timeout', 'error' => 'Timed out'];
        }
        if ($errno !== 0 && $status === 0) {
            return ['code' => 'transport', 'error' => $err !== '' ? $err : 'Request failed'];
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'error' => null, 'code' => null];
    }

    private function fail(array $result, string $code, string $error): array
    {
        $result['ok']    = false;
        $result['code']  = $code;
        $result['error'] = $error;
        return $result;
    }

    private static function bad(string $code, string $error): array
    {
        return ['ok' => false, 'code' => $code, 'error' => $error];
    }
}
