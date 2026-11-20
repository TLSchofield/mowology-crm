<?php
declare(strict_types=1);

require_once __DIR__ . '/ModelCatalog.php';
require_once __DIR__ . '/LlmFence.php';

/**
 * LlmGateway — the one door every Anthropic call goes through (Ittai audit 2026-10-27).
 *
 * Eleven call sites used to carry their own cURL block, cost sum, refusal handling and (no) retry.
 * This is a drop-in for each site's private send(): the site keeps building its own request body and
 * validating its own output, and hands the body here. Same {code, body} shape as the injectable test
 * transport, plus what the gateway adds:
 *   - one retry on a network failure, 429, or a 5xx / 529 overload (a 4xx other than 429 is the request's fault);
 *   - the kill switch: ops_settings agents_kill_all = 1, or agents_kill_<head> = 1, stops the call before it is made;
 *   - token and cost accounting (cache reads and writes priced properly by ModelCatalog);
 *   - one llm_calls row per logical call (migration 1400) — logging never throws and never blocks the answer.
 *
 *   $r = LlmGateway::send($body, ['head' => 'charlie', 'skill' => 'ask', 'db' => $this->db,
 *                                 'timeout' => 60, 'transport' => $this->transport]);
 *   // $r['code'], $r['body'] as before; $r['usage'], $r['cost_usd'], $r['error'] are new.
 *
 * ctx keys: head, skill, db (PDO, optional), site_id (default 1), timeout (default 60), headers (extra
 * request headers, e.g. anthropic-beta), transport (callable fn(array $body): array{code:int, body:string}),
 * sleep (false in tests to skip the retry pause), api_key (tests).
 *
 * Global namespace; require_once it.
 */
final class LlmGateway
{
    public const API_URL     = 'https://api.anthropic.com/v1/messages';
    public const API_VERSION = '2023-06-01';
    /**
     * HTTP codes worth one more try. Only responses that mean "not processed": 429 rate limit, 503 and 529
     * overloaded. 500/502/504 and timeouts are NOT retried — the model may already have run and been billed.
     * A network failure is retried only when nothing was sent (could not resolve or connect).
     */
    public const RETRY_CODES = [429, 503, 529];
    /** curl errno: 6 could not resolve host, 7 could not connect. The request never left. */
    public const RETRY_CURL_ERRNO = [6, 7];

    /**
     * @param array<string,mixed> $body request body for /v1/messages
     * @param array<string,mixed> $ctx  see class comment
     * @return array{code:int, body:string, attempts:int, usage:array<string,int>, cost_usd:?float,
     *               stop_reason:string, outcome:string, error:?string, model:string}
     */
    public static function send(array $body, array $ctx = []): array
    {
        $started = microtime(true);
        $model   = (string)($body['model'] ?? '');
        $db      = ($ctx['db'] ?? null) instanceof PDO ? $ctx['db'] : null;
        $head    = (string)($ctx['head'] ?? '');

        if ($db && self::killed($db, $head)) {
            $res = self::result(0, '', 1, $model, 'killed', 'agents switched off');
            self::log($db, $ctx, $res, $started);
            return $res;
        }

        $attempts = 0;
        $code = 0;
        $raw = '';
        $netError = null;
        do {
            $attempts++;
            [$code, $raw, $netError, $neverSent] = self::once($body, $ctx);
            $retry = $attempts < 2 && ($neverSent || in_array($code, self::RETRY_CODES, true));
            if ($retry && ($ctx['sleep'] ?? true) !== false) {
                usleep(1000000);
            }
        } while ($retry);

        $res = self::result($code, $raw, $attempts, $model, null, $netError);
        self::log($db, $ctx, $res, $started);
        return $res;
    }

    /** True when the global or per-head kill switch is on. Fails open (a DB error never blocks a call). */
    public static function killed(PDO $db, string $head): bool
    {
        try {
            $keys = ['agents_kill_all'];
            $slug = preg_replace('/[^a-z0-9_]/', '', strtolower($head));
            if ($slug !== '') {
                $keys[] = 'agents_kill_' . $slug;
            }
            $in = implode(',', array_fill(0, count($keys), '?'));
            $s = $db->prepare("SELECT COUNT(*) FROM ops_settings WHERE setting_key IN ($in) AND setting_value = '1'");
            $s->execute($keys);
            return (int)$s->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Take one call from today's allowance for $bucket (LlmDailyCap). Money-adjacent buckets pass
     * $failClosed = true so a database error stops the call instead of letting it through.
     */
    public static function takeCap(PDO $db, string $bucket, int $default, bool $failClosed = false): bool
    {
        require_once dirname(__DIR__) . '/LlmDailyCap.php';
        return LlmDailyCap::take($db, $bucket, $default, $failClosed);
    }

    /** First text block of a response body, or ''. */
    public static function text(string $body): string
    {
        $resp = json_decode($body, true);
        if (!is_array($resp)) {
            return '';
        }
        $out = '';
        foreach ((array)($resp['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $out .= (string)($block['text'] ?? '');
            }
        }
        return trim($out);
    }

    /** @return array{0:int,1:string,2:?string} code, raw body, network error */
    private static function once(array $body, array $ctx): array
    {
        $transport = $ctx['transport'] ?? null;
        if (is_callable($transport)) {
            try {
                $r = $transport($body);
                return [(int)($r['code'] ?? 0), (string)($r['body'] ?? ''), null, !empty($r['never_sent'])];
            } catch (Throwable $e) {
                return [0, '', 'transport: ' . $e->getMessage(), false];
            }
        }

        $key = (string)($ctx['api_key'] ?? (defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ''));
        if ($key === '') {
            return [0, '', 'no API key', false];
        }
        $headers = [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: ' . self::API_VERSION,
        ];
        foreach ((array)($ctx['headers'] ?? []) as $h) {
            $headers[] = (string)$h;
        }
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(5, (int)($ctx['timeout'] ?? 60)),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($body),
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = $raw === false ? curl_error($ch) : null;
        $errno = $raw === false ? curl_errno($ch) : 0;
        curl_close($ch);
        return [$raw === false ? 0 : $code, $raw === false ? '' : (string)$raw, $err, in_array($errno, self::RETRY_CURL_ERRNO, true)];
    }

    private static function result(int $code, string $raw, int $attempts, string $model, ?string $forcedOutcome, ?string $netError): array
    {
        $resp  = json_decode($raw, true);
        $resp  = is_array($resp) ? $resp : [];
        $u     = (array)($resp['usage'] ?? []);
        $usage = [
            'input'       => (int)($u['input_tokens'] ?? 0),
            'output'      => (int)($u['output_tokens'] ?? 0),
            'cache_read'  => (int)($u['cache_read_input_tokens'] ?? 0),
            'cache_write' => (int)($u['cache_creation_input_tokens'] ?? 0),
        ];
        $stop  = (string)($resp['stop_reason'] ?? '');
        $model = (string)($resp['model'] ?? $model);

        if ($forcedOutcome !== null) {
            $outcome = $forcedOutcome;
            $error = $netError;
        } elseif ($code === 200 && $stop === 'refusal') {
            $outcome = 'refused';
            $error = 'Declined';
        } elseif ($code === 200) {
            $outcome = 'ok';
            $error = null;
        } else {
            $outcome = 'error';
            $error = $netError ?? (string)($resp['error']['message'] ?? ('HTTP ' . $code));
        }

        return [
            'code'        => $code,
            'body'        => $raw,
            'attempts'    => $attempts,
            'usage'       => $usage,
            'cost_usd'    => ModelCatalog::cost($model, $usage['input'], $usage['output'], $usage['cache_read'], $usage['cache_write']),
            'stop_reason' => $stop,
            'outcome'     => $outcome,
            'error'       => $error !== null ? mb_substr($error, 0, 255) : null,
            'model'       => $model,
        ];
    }

    private static function log(?PDO $db, array $ctx, array $res, float $started): void
    {
        if (!$db) {
            return;
        }
        try {
            $db->prepare("INSERT INTO llm_calls (site_id, head, skill, model, input_tokens, output_tokens, cache_read_tokens,
                                                 cache_write_tokens, cost_usd, http_code, outcome, attempts, duration_ms, error, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   (int)($ctx['site_id'] ?? 1),
                   mb_substr((string)($ctx['head'] ?? ''), 0, 24),
                   mb_substr((string)($ctx['skill'] ?? ''), 0, 48),
                   mb_substr($res['model'], 0, 48),
                   $res['usage']['input'], $res['usage']['output'], $res['usage']['cache_read'], $res['usage']['cache_write'],
                   $res['cost_usd'], $res['code'], $res['outcome'], $res['attempts'],
                   (int)round((microtime(true) - $started) * 1000),
                   $res['error'],
                   date('Y-m-d H:i:s'),
               ]);
        } catch (Throwable $e) {
            error_log('LlmGateway log: ' . $e->getMessage());
        }
    }
}
