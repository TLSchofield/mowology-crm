<?php
/**
 * HttpFetch — one way to read a public web page or JSON endpoint from a cron or a page.
 *
 * Why it exists (2026-10-22): the City of Vancouver lot map, the province's ParcelMap, market prices and the
 * address geocoder all read with `@file_get_contents('https://…')`. The server's CLI PHP (what cron runs) has
 * allow_url_fopen=0, so every one of them threw "https:// wrapper is disabled" — and the app's error handler
 * turns even an @-suppressed warning into an ErrorException. "Otto auto-place failed" nine runs in a row.
 * cURL does not depend on allow_url_fopen; the stream wrapper stays as the fallback where cURL is missing.
 * Never throws: any failure is null, so a caller treats it as "source unavailable" and tries again later.
 *
 * No namespace / no autoloader in production: require_once and call HttpFetch::get().
 */
class HttpFetch
{
    /**
     * @param string[] $headers  e.g. ['User-Agent: Mowology-CRM/1.0 (…)', 'Accept: application/json']
     * @return string|null       the response body (any HTTP status — callers parse it), or null on no response
     */
    public static function get(string $url, int $timeout = 12, array $headers = []): ?string
    {
        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                if ($ch !== false) {
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_MAXREDIRS      => 3,
                        CURLOPT_TIMEOUT        => $timeout,
                        CURLOPT_CONNECTTIMEOUT => min($timeout, 8),
                        CURLOPT_HTTPHEADER     => $headers,
                        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    ]);
                    $body = curl_exec($ch);
                    curl_close($ch);
                    return is_string($body) ? $body : null;
                }
            }
            $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true,
                'header' => $headers ? implode("\r\n", $headers) . "\r\n" : '']]);
            $body = file_get_contents($url, false, $ctx);
            return $body === false ? null : $body;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
