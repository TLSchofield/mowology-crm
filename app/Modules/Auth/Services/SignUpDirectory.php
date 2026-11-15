<?php
declare(strict_types=1);

/** Find the one unclaimed business a provisioned owner may sign up for. */

require_once dirname(__DIR__, 3) . '/Core/Tenant.php';

final class SignUpDirectory
{
    private ?array $businesses;
    /** @var callable(string): PDO */
    private $open;
    /** @var callable(PDO, string, string): bool */
    private $limited;
    /** @var callable(string, string, PDO): void */
    private $recordFailure;
    /** @var callable(PDO, string): bool */
    private $matches;
    /** @var callable(PDO): string */
    private $businessName;

    public function __construct(
        ?array $businesses = null,
        ?callable $open = null,
        ?callable $limited = null,
        ?callable $recordFailure = null,
        ?callable $matches = null,
        ?callable $businessName = null
    ) {
        $this->businesses = $businesses;
        $this->open = $open ?? static fn(string $key): PDO => Database::forBusiness($key);
        $this->limited = $limited ?? static fn(PDO $db, string $email, string $ip): bool => isLoginRateLimited($email, $ip, $db);
        $this->recordFailure = $recordFailure ?? 'recordFailedLogin';
        $this->matches = $matches ?? static function (PDO $db, string $email): bool {
            require_once dirname(__DIR__, 2) . '/Onboarding/Services/SignUpService.php';
            return (new SignUpService($db, ''))->isUnclaimedOwnerEmail($email);
        };
        $this->businessName = $businessName ?? static function (PDO $db): string {
            require_once dirname(__DIR__, 3) . '/Services/BusinessIdentity.php';
            return (string)(BusinessIdentity::get($db)['name'] ?? '');
        };
    }

    /** @return array{status:string,business?:array} */
    public function lookup(string $email, string $ip): array
    {
        $email = strtolower(trim($email));
        $matched = [];
        $looked = [];
        $rateLimited = false;

        foreach ($this->businesses ?? Tenant::businesses() as $business) {
            $key = (string)$business['key'];
            try {
                $db = ($this->open)($key);
                $looked[] = $db;
                if (($this->limited)($db, $email, $ip)) {
                    $rateLimited = true;
                    continue;
                }
                if (($this->matches)($db, $email)) $matched[] = [$business, $db];
            } catch (Throwable $e) {
                error_log('[signup-directory] skipped ' . $key . ': ' . get_class($e));
            }
        }

        if ($rateLimited) return ['status' => 'rate_limited'];
        if (count($matched) === 1 && trim((string)$matched[0][0]['app_url']) !== '') {
            [$business, $db] = $matched[0];
            $name = '';
            try { $name = ($this->businessName)($db); }
            catch (Throwable $e) { error_log('[signup-directory] name of ' . $business['key'] . ': ' . get_class($e)); }
            return ['status' => 'ok', 'business' => [
                'key' => (string)$business['key'],
                'name' => $name,
                'app_url' => (string)$business['app_url'],
                'test' => !empty($business['test']),
            ]];
        }

        foreach ($looked as $db) {
            try { ($this->recordFailure)($email, $ip, $db); }
            catch (Throwable $e) { error_log('[signup-directory] record failure: ' . get_class($e)); }
        }
        return ['status' => 'not_available'];
    }
}
