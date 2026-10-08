<?php
/**
 * QboApiClient — authenticated calls to /v3/company/{realmId}/… (2026-10-07).
 *
 * What it does for every call (developer.intuit.com "Basic schema and data formats",
 * "API call limits and throttles", "Basic ID and field definitions", read 2026-10-07):
 *   - Accept: application/json, Authorization: Bearer <access token>, minorversion=75.
 *   - 401 → refresh the token once through QboConnectionService and retry once.
 *   - 429 → QboThrottledException ("wait 60 seconds"); 500 req/min per realm, 10/s per app.
 *   - Fault in the body → QboApiException with Intuit's Message + Detail + code and the
 *     intuit_tid response header (what Intuit support asks for).
 *   - writes carry ?requestid=<uuid> (≤ 50 chars): the same id replays the same result,
 *     never a duplicate — this is the API-side half of the sync ledger's idempotency.
 *   - query(): the SQL-like language, MAXRESULTS ≤ 1000, STARTPOSITION for paging.
 *
 * The client never deletes anything (there is no delete method on purpose).
 */
declare(strict_types=1);

require_once __DIR__ . '/QboConfig.php';
require_once __DIR__ . '/QboTransport.php';
require_once __DIR__ . '/QboConnectionService.php';

class QboApiException extends RuntimeException
{
    public ?string $intuitTid = null;
    public int $httpStatus = 0;
    public ?string $qboCode = null;
}

class QboThrottledException extends QboApiException {}

class QboApiClient
{
    private QboConfig $config;
    private QboTransport $transport;
    private QboConnectionService $connections;
    private array $conn;
    /** @var callable|null fn(array $logRow): void */
    private $logger;

    public function __construct(QboConfig $config, QboTransport $transport, QboConnectionService $connections, array $conn, ?callable $logger = null)
    {
        $this->config      = $config;
        $this->transport   = $transport;
        $this->connections = $connections;
        $this->conn        = $conn;
        $this->logger      = $logger;
    }

    public function realmId(): string { return (string)$this->conn['realm_id']; }
    public function connectionId(): int { return (int)$this->conn['id']; }

    /** GET an entity: get('companyinfo/' . $realmId), get('preferences'). */
    public function get(string $path, array $query = []): array
    {
        return $this->call('GET', $path, $query, null);
    }

    /** One page of a query. */
    public function query(string $sql): array
    {
        $r = $this->call('GET', 'query', ['query' => $sql], null);
        return $r['QueryResponse'] ?? [];
    }

    /** Every row of "select * from X [where …]" (pages of 1000). */
    public function queryAll(string $entity, string $where = '', int $pageSize = 1000): array
    {
        $rows = [];
        $start = 1;
        while (true) {
            $sql = "SELECT * FROM {$entity}" . ($where !== '' ? " WHERE {$where}" : '') . " STARTPOSITION {$start} MAXRESULTS {$pageSize}";
            $page = $this->query($sql);
            $batch = $page[$entity] ?? [];
            foreach ($batch as $row) $rows[] = $row;
            if (count($batch) < $pageSize) break;
            $start += $pageSize;
            if ($start > 20000) break; // safety: discovery never needs more
        }
        return $rows;
    }

    /** "select count(*) from X where …" → int. */
    public function count(string $entity, string $where = ''): int
    {
        $page = $this->query("SELECT COUNT(*) FROM {$entity}" . ($where !== '' ? " WHERE {$where}" : ''));
        return (int)($page['totalCount'] ?? 0);
    }

    /** Create or update. $requestId makes the call idempotent on Intuit's side. */
    public function post(string $path, array $payload, ?string $requestId = null, array $query = []): array
    {
        if ($requestId !== null) $query['requestid'] = substr($requestId, 0, 50);
        return $this->call('POST', $path, $query, json_encode($payload));
    }

    /**
     * Upload one file and link it to a transaction (Attachable):
     * POST /v3/company/{realm}/upload, multipart with file_metadata_01 (JSON) + file_content_01 (the file).
     * Verified against the Attachable reference's "Upload attachments" section, 2026-10-07.
     */
    public function upload(string $filePath, string $mimeType, string $fileName, string $entityType, string $entityId, ?string $requestId = null): array
    {
        $meta = [
            'AttachableRef' => [['EntityRef' => ['type' => $entityType, 'value' => $entityId], 'IncludeOnSend' => false]],
            'ContentType'   => $mimeType,
            'FileName'      => $fileName,
            'Category'      => 'Receipt',
        ];
        $query = ['minorversion' => QboConfig::MINOR_VERSION];
        if ($requestId !== null) $query['requestid'] = substr($requestId, 0, 50);
        $url = $this->config->apiBase() . '/v3/company/' . rawurlencode($this->realmId()) . '/upload?' . http_build_query($query);
        $body = [
            'file_metadata_01' => json_encode($meta),
            'file_content_01'  => ['path' => $filePath, 'type' => $mimeType, 'name' => $fileName],
        ];
        $r = $this->send('POST', $url, $body, true);
        $json = json_decode((string)$r['body'], true) ?: [];
        $this->log(['operation' => 'upload', 'qbo_type' => 'Attachable', 'http_status' => $r['status'], 'request_id' => $requestId,
                    'intuit_tid' => $r['headers']['intuit_tid'] ?? null, 'summary' => $fileName . ' → ' . $entityType . ' ' . $entityId]);
        $first = $json['AttachableResponse'][0] ?? [];
        if (!empty($first['Fault'])) {
            throw self::faultException($first, $r);
        }
        return $first['Attachable'] ?? [];
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function call(string $method, string $path, array $query, ?string $body): array
    {
        $query['minorversion'] = QboConfig::MINOR_VERSION;
        $url = $this->config->apiBase() . '/v3/company/' . rawurlencode($this->realmId()) . '/' . ltrim($path, '/') . '?' . http_build_query($query);
        $r = $this->send($method, $url, $body, false);
        $json = json_decode((string)$r['body'], true);
        if ($method === 'POST') {
            $this->log(['operation' => isset($json['Fault']) ? 'error' : 'create', 'qbo_type' => ucfirst(explode('/', $path)[0]), 'http_status' => $r['status'],
                        'request_id' => $query['requestid'] ?? null, 'intuit_tid' => $r['headers']['intuit_tid'] ?? null,
                        'summary' => substr($path, 0, 200), 'payload_hash' => $body !== null ? sha1($body) : null,
                        'error' => isset($json['Fault']) ? substr(self::faultMessage($json), 0, 1000) : null]);
        }
        if ($r['status'] >= 400 || !is_array($json) || isset($json['Fault'])) {
            throw self::faultException(is_array($json) ? $json : [], $r);
        }
        return $json;
    }

    /** Send with bearer auth; on 401 refresh once and retry; on 429 throw throttled. */
    private function send(string $method, string $url, $body, bool $multipart): array
    {
        $token = $this->connections->accessToken($this->conn);
        $r = $this->transport->send($method, $url, $this->headers($token, $multipart, $body), $body);
        if ($r['status'] === 401) {
            $token = $this->connections->accessToken($this->conn, true);
            $r = $this->transport->send($method, $url, $this->headers($token, $multipart, $body), $body);
        }
        if ($r['status'] === 429) {
            $e = new QboThrottledException('QuickBooks is throttling us (HTTP 429) — wait 60 seconds and try again.');
            $e->httpStatus = 429;
            $e->intuitTid = $r['headers']['intuit_tid'] ?? null;
            throw $e;
        }
        $this->connections->touch($this->connectionId());
        return $r;
    }

    private function headers(string $token, bool $multipart, $body): array
    {
        $h = ['Accept: application/json', 'Authorization: Bearer ' . $token];
        if (!$multipart && $body !== null) $h[] = 'Content-Type: application/json';
        return $h;
    }

    private function log(array $row): void
    {
        if ($this->logger === null) return;
        try {
            ($this->logger)($row + ['connection_id' => $this->connectionId()]);
        } catch (Throwable $e) {
            error_log('[qbo] log: ' . $e->getMessage());
        }
    }

    /** Intuit's Fault → one readable line. Pure. */
    public static function faultMessage(array $json): string
    {
        $err = $json['Fault']['Error'][0] ?? null;
        if (!$err) return 'QuickBooks returned an error without detail.';
        $parts = array_filter([$err['Message'] ?? '', $err['Detail'] ?? '']);
        $line = implode(' — ', $parts);
        if (!empty($err['code'])) $line .= ' (code ' . $err['code'] . ')';
        return $line;
    }

    private static function faultException(array $json, array $r): QboApiException
    {
        $msg = isset($json['Fault']) ? self::faultMessage($json) : ('QuickBooks HTTP ' . $r['status'] . ': ' . substr((string)$r['body'], 0, 300));
        $e = new QboApiException($msg);
        $e->httpStatus = (int)$r['status'];
        $e->intuitTid  = $r['headers']['intuit_tid'] ?? null;
        $e->qboCode    = isset($json['Fault']['Error'][0]['code']) ? (string)$json['Fault']['Error'][0]['code'] : null;
        return $e;
    }
}
