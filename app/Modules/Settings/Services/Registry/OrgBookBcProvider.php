<?php
declare(strict_types=1);

require_once __DIR__ . '/BusinessRegistryProvider.php';

final class OrgBookBcProvider implements BusinessRegistryProvider
{
    private const BASE = 'https://orgbook.gov.bc.ca/api/v4';
    private const MAX_RESPONSE_BYTES = 2097152;
    private string $cacheDir;
    private string $tenantKey;
    private ?Closure $fetcher;

    public function __construct(string $tenantKey, ?string $cacheDir = null, ?callable $fetcher = null)
    {
        if (trim($tenantKey) === '') throw new InvalidArgumentException('Tenant key must not be blank');
        $this->tenantKey = hash('sha256', $tenantKey);
        $this->cacheDir = $cacheDir ?? sys_get_temp_dir() . '/ittai-orgbook-cache';
        $this->fetcher = $fetcher === null ? null : Closure::fromCallable($fetcher);
    }

    public function search(string $name): RegistrySearch
    {
        $key = hash('sha256', self::normaliseName($name));
        $cached = $this->cacheRead('search-' . $key, 86400);
        if ($cached !== null) return new RegistrySearch($cached['status'], $cached['matches']);
        if (!$this->takeRateToken()) return new RegistrySearch('rate_limited');

        $started = microtime(true);
        $json = $this->get(self::BASE . '/search/topic?' . http_build_query(['q' => $name, 'latest' => 'true']));
        if ($json === null) {
            $this->logResult('unavailable', $started, 0);
            return new RegistrySearch('unavailable');
        }
        $items = $this->items($json);
        $matches = [];
        foreach ($items as $item) {
            $record = $this->normaliseRecord($item);
            if ($record === null) continue;
            $fields = $record->fields;
            $candidateNames = array_merge([$fields['legal_name'] ?? ''], $fields['operating_names'] ?? []);
            $exact = false;
            foreach ($candidateNames as $candidate) {
                if (self::normaliseName((string)$candidate) === self::normaliseName($name)) { $exact = true; break; }
            }
            unset($fields['registry_business_number']);
            $fields['match'] = $exact ? 'exact' : 'similar';
            $matches[] = $fields;
        }
        usort($matches, static fn(array $a, array $b): int => ($a['match'] === $b['match']) ? 0 : ($a['match'] === 'exact' ? -1 : 1));
        $status = $matches ? 'matches' : 'none';
        $payload = ['status' => $status, 'matches' => array_slice($matches, 0, 5)];
        $this->cacheWrite('search-' . $key, $payload);
        $this->logResult($status, $started, count($payload['matches']));
        return new RegistrySearch($status, $payload['matches']);
    }

    public function fetch(string $recordId): ?RegistryRecord
    {
        $started = microtime(true);
        if (!$this->takeRateToken()) { $this->logResult('rate_limited', $started, 0); return null; }
        // Preserve the deployed provider baseline; correct only the record lookup query.
        // BC's official guide uses search/topic?q=<registration number> (Step 1):
        // https://github.com/bcgov/orgbook-bc-api-docs/blob/main/docs/api.md#step-1-query-for-a-topic
        // inactive=true is an exact inactive-only filter; 'any' removes it so active
        // and historical records can both be re-fetched (official StatusFilterBuilder):
        // https://github.com/bcgov/aries-vcr/blob/main/server/vcr-server/api/v2/search/filters.py
        // Keep the exact source_id check below: search can return unrelated candidates.
        $json = $this->get(self::BASE . '/search/topic?' . http_build_query([
            'q' => $recordId, 'latest' => 'true', 'inactive' => 'any',
        ]));
        if ($json === null) { $this->logResult('unavailable', $started, 0); return null; }
        foreach ($this->items($json) as $item) {
            $record = $this->normaliseRecord($item);
            if ($record !== null && ($record->fields['record_id'] ?? '') === $recordId) {
                $this->logResult('matches', $started, 1);
                return $record;
            }
        }
        $this->logResult('none', $started, 0);
        return null;
    }

    /** @return array<int,array<string,mixed>> */
    private function items(array $json): array
    {
        $items = $json['results'] ?? $json['objects'] ?? $json;
        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    private function normaliseRecord(array $item): ?RegistryRecord
    {
        $topic = isset($item['topic']) && is_array($item['topic']) ? $item['topic'] : $item;
        $sourceId = $topic['source_id'] ?? null;
        if (!is_string($sourceId) || $sourceId === '') return null;
        $out = ['record_id' => $sourceId, 'registration_number' => $sourceId, 'registration_type' => 'bc_registration'];
        $operating = [];
        foreach (($topic['names'] ?? $item['names'] ?? []) as $name) {
            if (!is_array($name) || empty($name['text'])) continue;
            $type = (string)($name['type'] ?? '');
            if ($type === 'entity_name' && !isset($out['legal_name'])) $out['legal_name'] = (string)$name['text'];
            elseif ($type === 'business_number') $out['registry_business_number'] = (string)$name['text'];
            else $operating[] = (string)$name['text'];
        }
        if ($operating) $out['operating_names'] = array_values(array_unique($operating));
        foreach (($topic['attributes'] ?? $item['attributes'] ?? []) as $attribute) {
            if (!is_array($attribute) || !isset($attribute['type'], $attribute['value'])) continue;
            $type = (string)$attribute['type']; $value = (string)$attribute['value'];
            if ($type === 'entity_type') $out['legal_structure'] = self::entityType($value);
            elseif ($type === 'entity_status') $out['status'] = self::entityStatus($value);
            elseif ($type === 'home_jurisdiction') $out['jurisdiction'] = $value;
            elseif ($type === 'registration_date') $out['registered_on'] = $value;
        }
        return new RegistryRecord($out);
    }

    private static function entityType(string $code): string
    {
        return ['BC'=>'BC company','ULC'=>'Unlimited liability company','CP'=>'Cooperative association','SP'=>'Sole proprietorship','GP'=>'General partnership','LP'=>'Limited partnership','LL'=>'Limited liability partnership','EX'=>'Extraprovincial company'][$code] ?? $code;
    }

    private static function entityStatus(string $code): string
    { return ['ACT'=>'active','HIS'=>'historical'][$code] ?? strtolower($code); }

    public static function normaliseName(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/\b(limited|ltd|incorporated|inc|corporation|corp|company|co)\b\.?/i', ' ', $name) ?? $name;
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name);
    }

    private function get(string $url): ?array
    {
        $raw = ''; $oversize = false;
        $writer = static function ($unused, string $chunk) use (&$raw, &$oversize): int {
            if (strlen($raw) + strlen($chunk) > self::MAX_RESPONSE_BYTES) { $oversize = true; return 0; }
            $raw .= $chunk; return strlen($chunk);
        };
        if ($this->fetcher !== null) {
            $code = (int)($this->fetcher)($url, $writer);
        } else {
            if (!function_exists('curl_init')) return null;
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>6, CURLOPT_WRITEFUNCTION=>$writer,
                CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: Ittai/1.0 (+https://ittai.ca)']]);
            $ok = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($ok === false) return null;
        }
        if ($oversize || $code !== 200) return null;
        try { $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { return null; }
        return is_array($decoded) ? $decoded : null;
    }

    private function cacheRead(string $key, int $ttl): ?array
    {
        $path = $this->path($key); if (!is_file($path) || filemtime($path) < time() - $ttl) return null;
        $value = json_decode((string)@file_get_contents($path), true); return is_array($value) ? $value : null;
    }

    private function cacheWrite(string $key, array $value): void
    {
        if (!is_dir($this->cacheDir)) @mkdir($this->cacheDir, 0700, true);
        @file_put_contents($this->path($key), json_encode($value), LOCK_EX);
    }

    private function takeRateToken(): bool
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0700, true) && !is_dir($this->cacheDir)) return $this->limiterFailed();
        $path = $this->path('rate'); $handle = @fopen($path, 'c+'); if ($handle === false) return $this->limiterFailed();
        if (!@flock($handle, LOCK_EX)) { fclose($handle); return $this->limiterFailed(); }
        $raw = stream_get_contents($handle);
        if ($raw === false) { flock($handle, LOCK_UN); fclose($handle); return $this->limiterFailed(); }
        $times = json_decode($raw ?: '[]', true);
        $times = is_array($times) ? array_values(array_filter($times, static fn($t): bool => is_int($t) && $t > time() - 3600)) : [];
        $allowed = count($times) < 10; if ($allowed) $times[] = time();
        $encoded = json_encode($times);
        $written = is_string($encoded) && @ftruncate($handle, 0) && rewind($handle) && @fwrite($handle, $encoded) === strlen($encoded) && @fflush($handle);
        $unlocked = @flock($handle, LOCK_UN); fclose($handle);
        if (!$written || !$unlocked) return $this->limiterFailed();
        return $allowed;
    }

    private function limiterFailed(): bool
    { error_log('OrgBook rate limiter failed'); return false; }

    private function path(string $key): string { return $this->cacheDir . '/' . $this->tenantKey . '-' . $key . '.json'; }
    private function logResult(string $status, float $started, int $count): void
    { error_log(sprintf('OrgBook lookup status=%s latency_ms=%d count=%d', $status, (int)((microtime(true)-$started)*1000), $count)); }
}
