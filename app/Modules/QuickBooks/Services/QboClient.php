<?php
/**
 * QboClient — reads from one QuickBooks Online company (step 1 only reads; sends come in step 2).
 *
 * minorversion=75 on every call (Intuit: 1–74 were retired on 2025-08-01; 75 is the default).
 * A query pages with STARTPOSITION / MAXRESULTS (1,000 at most per page).
 * The access token comes from a callable, so the connection service owns refreshing and storing it.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class QboClient
{
    public const MINOR_VERSION = 75;
    public const PAGE = 1000;
    public const HOSTS = [
        'sandbox'    => 'https://sandbox-quickbooks.api.intuit.com',
        'production' => 'https://quickbooks.api.intuit.com',
    ];

    private string $base;
    /** @var callable(bool $force): ?string  the access token; $force = refresh first */
    private $token;
    /** @var callable(string $url, array $headers): array{status:int, body:string} */
    private $http;
    /** @var callable(string $url, array $headers, string $body): array{status:int, body:string} */
    private $httpPost;

    public function __construct(string $environment, string $realmId, callable $token, ?callable $http = null, ?callable $httpPost = null)
    {
        $this->httpPost = $httpPost ?? [QboOAuth::class, 'curlPost'];
        $host = self::HOSTS[$environment] ?? self::HOSTS['sandbox'];
        $this->base = $host . '/v3/company/' . rawurlencode($realmId);
        $this->token = $token;
        $this->http = $http ?? [self::class, 'curlGet'];
    }

    /** Pure (tested). */
    public static function queryUrl(string $base, string $sql): string
    {
        return $base . '/query?' . http_build_query(['query' => $sql, 'minorversion' => self::MINOR_VERSION], '', '&', PHP_QUERY_RFC3986);
    }

    /** Pure (tested): "SELECT * FROM Account WHERE Active = true" page n → with STARTPOSITION/MAXRESULTS. */
    public static function paged(string $sql, int $start, int $max = self::PAGE): string
    {
        return $sql . ' STARTPOSITION ' . max(1, $start) . ' MAXRESULTS ' . max(1, min(self::PAGE, $max));
    }

    /**
     * Every row of an entity (all pages).
     * @return array{ok:bool, rows?:list<array>, error?:string}
     */
    public function all(string $entity, string $where = ''): array
    {
        $rows = [];
        for ($start = 1; $start < 100000; $start += self::PAGE) {
            $sql = self::paged('SELECT * FROM ' . $entity . ($where !== '' ? ' WHERE ' . $where : ''), $start);
            $r = $this->get(self::queryUrl($this->base, $sql));
            if (!$r['ok']) return $r;
            $page = (array)($r['data']['QueryResponse'][$entity] ?? []);
            foreach ($page as $row) $rows[] = $row;
            if (count($page) < self::PAGE) break;
        }
        return ['ok' => true, 'rows' => $rows];
    }

    /** @return array{ok:bool, data?:array, error?:string} */
    public function companyInfo(string $realmId): array
    {
        return $this->get($this->base . '/companyinfo/' . rawurlencode($realmId) . '?minorversion=' . self::MINOR_VERSION);
    }

    /**
     * Create an entity (step 2). $requestId is fixed per Ittai thing: Intuit answers a repeat with the
     * first result, so a retry never makes a second copy.
     * @return array{ok:bool, data?:array, error?:string, code?:string}
     */
    public function create(string $entity, array $body, string $requestId): array
    {
        $url = $this->base . '/' . strtolower($entity) . '?' . http_build_query(['minorversion' => self::MINOR_VERSION, 'requestid' => $requestId], '', '&', PHP_QUERY_RFC3986);
        foreach ([false, true] as $force) {
            $tok = ($this->token)($force);
            if ($tok === null) return ['ok' => false, 'error' => 'Not connected to QuickBooks (the sign-in has expired). Connect again.'];
            $r = ($this->httpPost)($url, ['Authorization: Bearer ' . $tok, 'Accept: application/json', 'Content-Type: application/json'], (string)json_encode($body));
            if ($r['status'] === 401 && !$force) continue;
            if ($r['status'] === 200) {
                $d = json_decode($r['body'], true);
                return is_array($d) ? ['ok' => true, 'data' => $d] : ['ok' => false, 'error' => 'QuickBooks sent something that is not JSON.'];
            }
            return ['ok' => false, 'error' => self::faultLine($r['status'], $r['body']), 'code' => self::faultCode($r['body']),
                    'refused' => self::refused($r['status'])];
        }
        return ['ok' => false, 'error' => 'QuickBooks refused the sign-in twice. Connect again.'];
    }

    /**
     * Pure (tested): QuickBooks looked at the request and said no (bad data, a missing mapping) — as
     * opposed to a timeout, an outage or "busy", where it may or may not have been created.
     */
    public static function refused(int $status): bool
    {
        return $status >= 400 && $status < 500 && !in_array($status, [401, 408, 429], true);
    }

    /**
     * Attach a file (a receipt photo) to an entity — Intuit's /upload: a multipart body with the
     * Attachable metadata (file_metadata_01) and the bytes (file_content_01).
     * @return array{ok:bool, data?:array, error?:string}
     */
    public function attach(string $entityType, string $entityId, string $fileName, string $mime, string $bytes): array
    {
        $boundary = 'ittai' . bin2hex(random_bytes(8));
        $meta = json_encode(['AttachableRef' => [['EntityRef' => ['type' => $entityType, 'value' => $entityId]]],
                             'FileName' => $fileName, 'ContentType' => $mime]);
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"file_metadata_01\"\r\nContent-Type: application/json\r\n\r\n{$meta}\r\n"
              . "--{$boundary}\r\nContent-Disposition: form-data; name=\"file_content_01\"; filename=\"" . str_replace('"', '', $fileName) . "\"\r\nContent-Type: {$mime}\r\n\r\n"
              . $bytes . "\r\n--{$boundary}--\r\n";
        $url = $this->base . '/upload?minorversion=' . self::MINOR_VERSION;
        foreach ([false, true] as $force) {
            $tok = ($this->token)($force);
            if ($tok === null) return ['ok' => false, 'error' => 'Not connected to QuickBooks (the sign-in has expired). Connect again.'];
            $r = ($this->httpPost)($url, ['Authorization: Bearer ' . $tok, 'Accept: application/json', 'Content-Type: multipart/form-data; boundary=' . $boundary], $body);
            if ($r['status'] === 401 && !$force) continue;
            if ($r['status'] === 200) {
                $d = json_decode($r['body'], true);
                $att = $d['AttachableResponse'][0]['Attachable'] ?? null;
                return $att ? ['ok' => true, 'data' => $att] : ['ok' => false, 'error' => self::faultLine(200, json_encode($d['AttachableResponse'][0] ?? []))];
            }
            return ['ok' => false, 'error' => self::faultLine($r['status'], $r['body'])];
        }
        return ['ok' => false, 'error' => 'QuickBooks refused the sign-in twice. Connect again.'];
    }

    /** Run one query (first page), e.g. to find a customer by name. */
    public function query(string $sql): array
    {
        return $this->get(self::queryUrl($this->base, $sql));
    }

    /** Pure (tested): QuickBooks' error code ("6240" = duplicate name), or ''. */
    public static function faultCode(string $body): string
    {
        $d = json_decode($body, true);
        return (string)($d['Fault']['Error'][0]['code'] ?? '');
    }

    /** Pure (tested): a value inside a QuickBooks query string ('…' with \' and \\ escaped). */
    public static function quote(string $v): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $v) . "'";
    }

    /** One GET; a 401 refreshes the token once and tries again. */
    private function get(string $url): array
    {
        foreach ([false, true] as $force) {
            $tok = ($this->token)($force);
            if ($tok === null) return ['ok' => false, 'error' => 'Not connected to QuickBooks (the sign-in has expired). Connect again.'];
            $r = ($this->http)($url, ['Authorization: Bearer ' . $tok, 'Accept: application/json']);
            if ($r['status'] === 401 && !$force) continue;
            if ($r['status'] === 200) {
                $d = json_decode($r['body'], true);
                return is_array($d) ? ['ok' => true, 'data' => $d] : ['ok' => false, 'error' => 'QuickBooks sent something that is not JSON.'];
            }
            return ['ok' => false, 'error' => self::faultLine($r['status'], $r['body'])];
        }
        return ['ok' => false, 'error' => 'QuickBooks refused the sign-in twice. Connect again.'];
    }

    /** Pure (tested): QuickBooks' Fault block → one line. */
    public static function faultLine(int $status, string $body): string
    {
        if ($status === 429) return 'QuickBooks is busy (too many requests). It will be tried again shortly.';
        if ($status === 0) return 'QuickBooks could not be reached. Try again in a minute.';
        $d = json_decode($body, true);
        $e = $d['Fault']['Error'][0] ?? null;
        if (is_array($e)) {
            $msg = trim((string)($e['Message'] ?? '') . (isset($e['Detail']) ? ': ' . $e['Detail'] : ''));
            if ($msg !== '') return 'QuickBooks: ' . mb_substr($msg, 0, 300);
        }
        return 'QuickBooks answered ' . $status . '.';
    }

    /** @return array{status:int, body:string} */
    public static function curlGet(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $out = curl_exec($ch);
        $status = $out === false ? 0 : (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $out === false ? '' : (string)$out];
    }
}
