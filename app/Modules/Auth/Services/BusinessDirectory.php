<?php
declare(strict_types=1);

/**
 * BusinessDirectory — one phone sign-in for every business (2026-11-11, "one app per platform" steps 4–5).
 *
 * Every business has its own database and its own users, so the phone cannot know where to sign in from an
 * email alone. The ittai.ca sign-in (public/ittai.ca/api/sign-in.php) asks here: the password is tried at every
 * business on this install (PhoneSignIn::attempt, on that business's own database) and the ones where it
 * verified answer. One → that business's address and a token issued FOR it; several → a list to choose from,
 * with no tokens, and the phone asks again naming one; none → invalid, never which businesses exist.
 *
 * A business whose database cannot be opened is logged and skipped; so is one with no app address
 * ($ITTAI_DEFAULT_APP_URL / app_url in tenants.php) — the app could not reach it anyway.
 *
 * Cost: one bcrypt check per business per attempt, and each business records its own failed attempt. Fine for
 * a handful of businesses; past a few dozen, the next step is a stored email → business index (not built).
 *
 * The seams (business list, database opener, attempt) are for tests; production passes none.
 * No namespace / no autoloader in production: require_once.
 */

require_once dirname(__DIR__, 3) . '/Core/Tenant.php';
require_once dirname(__DIR__, 3) . '/Core/Auth/PhoneSignIn.php';

final class BusinessDirectory
{
    private ?array $businesses;
    /** @var callable(string): PDO */
    private $open;
    /** @var callable(PDO, string, string, string, string, string): array */
    private $attempt;

    public function __construct(?array $businesses = null, ?callable $open = null, ?callable $attempt = null)
    {
        $this->businesses = $businesses;
        $this->open = $open ?? static fn(string $key): PDO => Database::forBusiness($key);
        $this->attempt = $attempt ?? [PhoneSignIn::class, 'attempt'];
    }

    /**
     * @return array ['status' => 'ok', 'business' => [key, name, app_url, test], 'token', 'user', 'expires_in']
     *             | ['status' => 'choose', 'businesses' => [[key, name, app_url, test], …]]
     *             | ['status' => 'rate_limited'] | ['status' => 'invalid']
     *             | ['status' => 'unavailable'] (no business could check at all)
     */
    public function signIn(string $credential, string $password, string $audience, string $ip, ?string $business = null): array
    {
        $ok = [];
        $rateLimited = false;
        $answered = 0;   // businesses that could actually check the password
        foreach ($this->businesses ?? Tenant::businesses() as $b) {
            $key = (string)$b['key'];
            if ((string)$b['app_url'] === '') {
                error_log('[directory] skipped ' . $key . ': no app address');
                continue;
            }
            try {
                $db = ($this->open)($key);
                $r = ($this->attempt)($db, $key, $credential, $password, $audience, $ip);
            } catch (Throwable $e) {
                error_log('[directory] skipped ' . $key . ': ' . get_class($e));
                continue;
            }
            $status = (string)($r['status'] ?? 'error');
            if (in_array($status, ['ok', 'invalid', 'rate_limited', 'web_only'], true)) $answered++;
            if ($status === 'ok') {
                $ok[] = [
                    'business' => [
                        'key'     => $key,
                        'name'    => (string)($r['user']['business_name'] ?? ''),
                        'app_url' => (string)$b['app_url'],
                        'test'    => !empty($b['test']),
                    ],
                    'result' => $r,
                ];
            } elseif ($status === 'rate_limited') {
                $rateLimited = true;
            } elseif ($status === 'web_only') {
                // The accountant's login is web-only: no phone token, and it does not count as a match.
                error_log('[directory] web-only login at ' . $key . ' not matched');
            } elseif ($status !== 'invalid') {
                error_log('[directory] ' . $key . ' answered ' . $status);
            }
        }

        if (!$ok) {
            // No business could even look (every database down or every address missing): say so, not
            // "wrong password" — a 401 would make the phone drop its sign-in for a server fault.
            if ($answered === 0) return ['status' => 'unavailable'];
            return ['status' => $rateLimited ? 'rate_limited' : 'invalid'];
        }
        if ($business === null) {
            if (count($ok) === 1) return self::okShape($ok[0]);
            return ['status' => 'choose', 'businesses' => array_column($ok, 'business')];
        }
        foreach ($ok as $m) {
            if ($m['business']['key'] === $business) return self::okShape($m);
        }
        return ['status' => 'invalid'];
    }

    private static function okShape(array $m): array
    {
        return [
            'status'     => 'ok',
            'business'   => $m['business'],
            'token'      => $m['result']['token'],
            'user'       => $m['result']['user'],
            'expires_in' => $m['result']['expires_in'],
        ];
    }
}
