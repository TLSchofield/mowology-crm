<?php
/**
 * QboTransport — the one seam between the QuickBooks services and the network (2026-10-07).
 *
 * Every Intuit call (token exchange, refresh, revoke, every /v3 request, the multipart upload)
 * goes through send(). Production uses QboCurlTransport; the unit tests use a fake that
 * replays canned Intuit responses, so no test ever touches developer.intuit.com and no
 * credential is needed to run the suite.
 *
 * A response is always ['status' => int, 'headers' => [lowercase name => value], 'body' => string].
 * Transport errors (DNS, TLS, timeout) throw RuntimeException; HTTP errors do not — the caller
 * reads the status.
 */
declare(strict_types=1);

interface QboTransport
{
    /**
     * @param string            $method  GET | POST
     * @param string            $url     full URL
     * @param array<string>     $headers "Name: value" lines
     * @param string|array|null $body    string = sent as-is; array = multipart/form-data parts
     *                                   (each value a string or ['path' => ..., 'type' => ..., 'name' => ...])
     */
    public function send(string $method, string $url, array $headers, $body = null): array;
}

class QboCurlTransport implements QboTransport
{
    private int $timeout;

    public function __construct(int $timeout = 30)
    {
        $this->timeout = $timeout;
    }

    public function send(string $method, string $url, array $headers, $body = null): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADER         => true,
        ];
        if (strtoupper($method) === 'POST') {
            $opts[CURLOPT_POST] = true;
            if (is_array($body)) {
                $parts = [];
                foreach ($body as $name => $part) {
                    if (is_array($part) && isset($part['path'])) {
                        $parts[$name] = new CURLFile($part['path'], $part['type'] ?? 'application/octet-stream', $part['name'] ?? basename($part['path']));
                    } else {
                        $parts[$name] = (string)$part;
                    }
                }
                $opts[CURLOPT_POSTFIELDS] = $parts;   // curl sets multipart/form-data + boundary
            } else {
                $opts[CURLOPT_POSTFIELDS] = (string)$body;
            }
        }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            throw new RuntimeException('QuickBooks transport error: ' . ($err ?: 'no response'));
        }
        $rawHeaders = substr((string)$raw, 0, $hdrSize);
        $bodyText   = substr((string)$raw, $hdrSize);
        return ['status' => $code, 'headers' => self::parseHeaders($rawHeaders), 'body' => $bodyText];
    }

    /** Last header block only (curl concatenates redirects); names lower-cased. */
    public static function parseHeaders(string $raw): array
    {
        $blocks = preg_split("/\r?\n\r?\n/", trim($raw));
        $last   = end($blocks) ?: '';
        $out = [];
        foreach (preg_split("/\r?\n/", $last) as $line) {
            if (strpos($line, ':') === false) continue;
            [$k, $v] = explode(':', $line, 2);
            $out[strtolower(trim($k))] = trim($v);
        }
        return $out;
    }
}
