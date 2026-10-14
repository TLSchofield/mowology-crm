<?php
/**
 * RemoteVendorKnowledgeStore — a tenant install talking to the hub over HTTPS.
 *
 * Configure in the install's secrets.php:
 *   define('KB_MODE', 'client');
 *   define('KB_HUB_URL', 'https://mowology.ca/api/knowledge/vendor.php');
 *   define('KB_API_KEY', '…');   // issued by the hub owner (kb_tenants)
 *
 * Fail-soft by design: 2 s timeout, any error → null/false, answers cached 24 h in kb_cache.
 * Contributions that fail are simply dropped (the next confirmation will try again).
 */
declare(strict_types=1);

require_once __DIR__ . '/VendorKnowledgeStore.php';
require_once __DIR__ . '/VendorIdentity.php';

final class RemoteVendorKnowledgeStore implements VendorKnowledgeStore
{
    private PDO $db;
    private string $url;
    private string $key;

    public function __construct(PDO $db, string $hubUrl, string $apiKey)
    {
        $this->db = $db;
        $this->url = rtrim($hubUrl, '/');
        $this->key = $apiKey;
    }

    public function ready(): bool
    {
        return $this->url !== '' && $this->key !== '' && function_exists('curl_init');
    }

    public function resolve(array $identity): ?array
    {
        if (!$this->ready()) return null;
        $q = array_filter([
            'gst_number'   => $identity['gst_number'] ?? null,
            'phone_digits' => $identity['phone_digits'] ?? null,
            'domain'       => $identity['domain'] ?? null,
            'name_key'     => $identity['name_key'] ?? null,
        ]);
        if (!$q) return null;
        $cacheKey = sha1(json_encode($q));
        $cached = $this->cacheGet($cacheKey);
        if ($cached !== null) return $cached ?: null;

        $res = $this->http('GET', $this->url . '?mode=resolve&' . http_build_query($q));
        $vendor = ($res && !empty($res['ok']) && !empty($res['vendor'])) ? $res['vendor'] : null;
        $this->cachePut($cacheKey, $vendor ?: []);   // cache misses too (as [])
        return $vendor;
    }

    public function contribute(array $lesson, string $tenantKey)
    {
        if (!$this->ready()) return false;
        // The hub attributes by API key; the tenant's own name never travels.
        unset($lesson['tenant']);
        $res = $this->http('POST', $this->url . '?mode=contribute', $lesson);
        return $res && !empty($res['ok']);
    }

    // ── plumbing ──────────────────────────────────────────────────────────────

    private function http(string $method, string $url, ?array $body = null): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_HTTPHEADER     => ['X-KB-Key: ' . $this->key, 'Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code !== 200) return null;
        $j = json_decode((string)$raw, true);
        return is_array($j) ? $j : null;
    }

    private function cacheGet(string $key): ?array
    {
        try {
            $s = $this->db->prepare("SELECT payload_json FROM kb_cache WHERE cache_key = ? AND expires_at > NOW()");
            $s->execute([$key]);
            $p = $s->fetchColumn();
            return $p === false ? null : (json_decode((string)$p, true) ?? []);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function cachePut(string $key, array $payload): void
    {
        try {
            $this->db->prepare("REPLACE INTO kb_cache (cache_key, payload_json, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))")
                ->execute([$key, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) { /* cache is optional */ }
    }
}
