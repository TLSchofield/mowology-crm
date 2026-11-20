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
 * Two passes (review 2026-11-11): the sweep only CHECKS (nothing written anywhere), then the token is issued at
 * the one business that is signing in. A failed attempt is recorded only when no business matched, and then at
 * every business that looked — a right password at business A is never a wrong one at B.
 *
 * A business whose database cannot be opened is logged and skipped; so is one with no app address
 * ($ITTAI_DEFAULT_APP_URL / app_url in tenants.php) — the app could not reach it anyway.
 *
 * Cost: one bcrypt check per business per attempt, plus one at the business signing in. Fine for a handful of
 * businesses; past a few dozen, the next step is a stored email → business index (not built).
 *
 * The seams (business list, database opener, attempt, failure recorder) are for tests; production passes none.
 * No namespace / no autoloader in production: require_once.
 */

require_once dirname(__DIR__, 3) . '/Core/Tenant.php';
require_once dirname(__DIR__, 3) . '/Core/Auth/PhoneSignIn.php';

final class BusinessDirectory
{
    private ?array $businesses;
    /** @var callable(string): PDO */
    private $open;
    /** @var callable(PDO, string, string, string, string, string, bool): array */
    private $attempt;
    /** @var callable(string, string, PDO): void */
    private $recordFailure;

    public function __construct(?array $businesses = null, ?callable $open = null, ?callable $attempt = null, ?callable $recordFailure = null)
    {
        $this->businesses = $businesses;
        $this->open = $open ?? static fn(string $key): PDO => Database::forBusiness($key);
        $this->attempt = $attempt ?? [PhoneSignIn::class, 'attempt'];
        $this->recordFailure = $recordFailure ?? 'recordFailedLogin';
    }

    /**
     * @return array ['status' => 'ok', 'business' => [key, name, app_url, test], 'token', 'user', 'expires_in']
     *             | ['status' => 'choose', 'businesses' => [[key, name, app_url, test], …]]
     *             | ['status' => 'rate_limited'] | ['status' => 'invalid']
     *             | ['status' => 'unavailable'] (no business could check at all)
     */
    public function signIn(string $credential, string $password, string $audience, string $ip, ?string $business = null): array
    {
        $matched = [];      // key => business row
        $names = [];        // key => shown name when the check already carried it, else null (looked up)
        $looked = [];       // key => PDO, every business that could check the password
        $rateLimited = false;
        $known = $business === null;   // a named business that no list has is the caller's mistake, not a fault
        foreach ($this->businesses ?? Tenant::businesses() as $b) {
            $key = (string)$b['key'];
            if ((string)$b['app_url'] === '') {
                error_log('[directory] skipped ' . $key . ': no app address');
                continue;
            }
            // Asked for one business: only that one is tried (the second call after a choose list).
            if ($business !== null && $key !== $business) continue;
            $known = true;
            try {
                $db = ($this->open)($key);
                $r = ($this->attempt)($db, $key, $credential, $password, $audience, $ip, true);
            } catch (Throwable $e) {
                error_log('[directory] skipped ' . $key . ': ' . get_class($e));
                continue;
            }
            $status = (string)($r['status'] ?? 'error');
            if (in_array($status, ['match', 'ok', 'invalid', 'rate_limited', 'web_only'], true)) $looked[$key] = $db;
            if ($status === 'match' || $status === 'ok') {
                $matched[$key] = $b;
                $names[$key] = isset($r['user']['business_name']) ? (string)$r['user']['business_name'] : null;
            } elseif ($status === 'rate_limited') {
                $rateLimited = true;
            } elseif ($status === 'web_only') {
                // The accountant's login is web-only: no phone token, and it does not count as a match.
                error_log('[directory] web-only login at ' . $key . ' not matched');
            } elseif ($status !== 'invalid') {
                error_log('[directory] ' . $key . ' answered ' . $status);
            }
        }

        if (!$matched) {
            if (!$known) return ['status' => 'invalid'];
            // No business could even look (every database down or every address missing): say so, not
            // "wrong password" — a 401 would make the phone drop its sign-in for a server fault.
            if (!$looked) return ['status' => 'unavailable'];
            foreach ($looked as $db) {
                try { ($this->recordFailure)($credential, $ip, $db); } catch (Throwable $e) { error_log('[directory] record failure: ' . get_class($e)); }
            }
            return ['status' => $rateLimited ? 'rate_limited' : 'invalid'];
        }
        if (count($matched) > 1) {
            return ['status' => 'choose', 'businesses' => array_map(fn(array $b) => $this->shown($b, $looked[$b['key']], $names[$b['key']]), array_values($matched))];
        }

        // Exactly one: sign in there for real (the token, last_login, activity, cleared attempts — at this business only).
        $b = reset($matched);
        $key = (string)$b['key'];
        try {
            $r = ($this->attempt)($looked[$key], $key, $credential, $password, $audience, $ip, false);
        } catch (Throwable $e) {
            error_log('[directory] sign-in at ' . $key . ': ' . get_class($e));
            return ['status' => 'unavailable'];
        }
        if (($r['status'] ?? '') !== 'ok') {
            error_log('[directory] ' . $key . ' matched then answered ' . (string)($r['status'] ?? 'error'));
            return ['status' => ($r['status'] ?? '') === 'rate_limited' ? 'rate_limited' : 'invalid'];
        }
        return [
            'status'     => 'ok',
            'business'   => $this->shown($b, $looked[$key], (string)($r['user']['business_name'] ?? '')),
            'token'      => $r['token'],
            'user'       => $r['user'],
            'expires_in' => $r['expires_in'],
        ];
    }

    /** The business as the phone shows it. The name is its own shown name (BusinessIdentity); '' when unknown. */
    private function shown(array $b, PDO $db, ?string $name = null): array
    {
        if ($name === null) {
            $name = '';
            try {
                require_once dirname(__DIR__, 3) . '/Services/BusinessIdentity.php';
                $name = (string)(BusinessIdentity::get($db)['name'] ?? '');
            } catch (Throwable $e) { error_log('[directory] name of ' . $b['key'] . ': ' . get_class($e)); }
        }
        return ['key' => (string)$b['key'], 'name' => $name, 'app_url' => (string)$b['app_url'], 'test' => !empty($b['test'])];
    }
}
