<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** One phone sign-in for every business on ittai.ca (2026-11-11). PhoneSignIn and the databases are faked. */
final class BusinessDirectoryTest extends TestCase
{
    /** key → the status that business's attempt answers. */
    private array $answers = [];
    private array $tried = [];      // check-only sweeps, in order
    private array $issued = [];     // real sign-ins (token issued)
    private array $failedAt = [];   // businesses where a failure was recorded
    private array $pdoKey = [];

    private function biz(string $key, string $url = '', bool $test = false): array
    {
        return ['key' => $key, 'app_url' => $url !== '' ? $url : 'https://' . $key . '.ittai.ca', 'db' => ['db_' . $key, 'u', 'p'], 'test' => $test];
    }

    private function directory(array $businesses, array $broken = []): BusinessDirectory
    {
        $open = function (string $key) use ($broken): PDO {
            if (in_array($key, $broken, true)) throw new PDOException('connection refused');
            $pdo = new PDO('sqlite::memory:');
            $this->pdoKey[spl_object_id($pdo)] = $key;
            return $pdo;
        };
        $attempt = function (PDO $db, string $key, string $cred, string $pw, string $aud, string $ip, bool $checkOnly = false): array {
            if ($checkOnly) $this->tried[] = $key; else $this->issued[] = $key;
            $s = $this->answers[$key] ?? 'invalid';
            if ($s !== 'ok') return ['status' => $s];
            if ($checkOnly) return ['status' => 'match', 'user' => ['business_name' => ucfirst($key) . ' Co']];
            return ['status' => 'ok', 'token' => 'tok-' . $key, 'expires_in' => 2592000,
                'user' => ['id' => 1, 'email' => $cred, 'name' => 'Ann', 'role' => 'admin', 'business_name' => ucfirst($key) . ' Co', 'chief_name' => 'Kaname']];
        };
        $record = function (string $cred, string $ip, PDO $db): void { $this->failedAt[] = $this->pdoKey[spl_object_id($db)]; };
        return new BusinessDirectory($businesses, $open, $attempt, $record);
    }

    protected function setUp(): void
    {
        $this->answers = [];
        $this->tried = $this->issued = $this->failedAt = $this->pdoKey = [];
    }

    public function test_the_sweep_writes_nothing_and_the_token_is_issued_at_the_one_business_only(): void
    {
        $this->answers = ['greenleaf' => 'ok'];
        $r = $this->directory([$this->biz('default', 'https://mowology.ca'), $this->biz('claudia'), $this->biz('greenleaf')])
            ->signIn('ann@example.com', 'pw', 'mobile', '10.0.0.1');
        $this->assertSame('ok', $r['status']);
        $this->assertSame(['default', 'claudia', 'greenleaf'], $this->tried);
        $this->assertSame(['greenleaf'], $this->issued, 'last_login, activity and the token: at greenleaf only');
        $this->assertSame([], $this->failedAt, 'a right password at greenleaf is never a wrong one at default or claudia');
    }

    public function test_a_wrong_password_is_recorded_at_every_business_that_looked(): void
    {
        $r = $this->directory([$this->biz('default', 'https://mowology.ca'), $this->biz('greenleaf')], ['default'])
            ->signIn('ann@example.com', 'pw', 'mobile', '10.0.0.1');
        $this->assertSame('invalid', $r['status']);
        $this->assertSame(['greenleaf'], $this->failedAt, 'default could not look, so nothing is recorded there');
        $this->assertSame([], $this->issued);
    }

    public function test_the_second_call_with_a_chosen_business_tries_only_that_one(): void
    {
        $this->answers = ['default' => 'ok', 'greenleaf' => 'ok'];
        $r = $this->directory([$this->biz('default', 'https://mowology.ca'), $this->biz('greenleaf')])
            ->signIn('ann@example.com', 'pw', 'mobile', '10.0.0.1', 'greenleaf');
        $this->assertSame('ok', $r['status']);
        $this->assertSame(['greenleaf'], $this->tried, 'the other matched business is not touched again');
        $this->assertSame(['greenleaf'], $this->issued);
    }

    public function test_one_match_answers_with_its_address_and_token(): void
    {
        $this->answers = ['greenleaf' => 'ok'];
        $r = $this->directory([$this->biz('default', 'https://mowology.ca'), $this->biz('greenleaf', '', true)])
            ->signIn('ann@example.com', 'pw', 'mobile', '10.0.0.1');
        $this->assertSame('ok', $r['status']);
        $this->assertSame(['key' => 'greenleaf', 'name' => 'Greenleaf Co', 'app_url' => 'https://greenleaf.ittai.ca', 'test' => true], $r['business']);
        $this->assertSame('tok-greenleaf', $r['token']);
        $this->assertSame(2592000, $r['expires_in']);
        $this->assertSame('ann@example.com', $r['user']['email']);
        $this->assertSame(['default', 'greenleaf'], $this->tried);
    }

    public function test_two_matches_ask_which_without_any_token(): void
    {
        $this->answers = ['default' => 'ok', 'greenleaf' => 'ok'];
        $r = $this->directory([$this->biz('default', 'https://mowology.ca'), $this->biz('claudia'), $this->biz('greenleaf')])
            ->signIn('ann@example.com', 'pw', 'mobile', '10.0.0.1');
        $this->assertSame('choose', $r['status']);
        $this->assertSame([
            ['key' => 'default', 'name' => 'Default Co', 'app_url' => 'https://mowology.ca', 'test' => false],
            ['key' => 'greenleaf', 'name' => 'Greenleaf Co', 'app_url' => 'https://greenleaf.ittai.ca', 'test' => false],
        ], $r['businesses']);
        $this->assertStringNotContainsString('tok-', json_encode($r));
    }

    public function test_two_matches_and_a_chosen_business_answers_for_that_one(): void
    {
        $this->answers = ['default' => 'ok', 'greenleaf' => 'ok'];
        $r = $this->directory([$this->biz('default', 'https://mowology.ca'), $this->biz('greenleaf')])
            ->signIn('ann@example.com', 'pw', 'mobile', '10.0.0.1', 'greenleaf');
        $this->assertSame('ok', $r['status']);
        $this->assertSame('greenleaf', $r['business']['key']);
        $this->assertSame('tok-greenleaf', $r['token']);
    }

    public function test_a_chosen_business_that_did_not_match_is_invalid(): void
    {
        $this->answers = ['default' => 'ok'];
        $dir = $this->directory([$this->biz('default', 'https://mowology.ca'), $this->biz('greenleaf')]);
        $this->assertSame(['status' => 'invalid'], $dir->signIn('ann@example.com', 'pw', 'mobile', '10.0.0.1', 'greenleaf'));
        $this->assertSame(['status' => 'invalid'], $dir->signIn('ann@example.com', 'pw', 'mobile', '10.0.0.1', 'nobody'));
    }

    public function test_no_match_is_invalid_unless_a_business_was_rate_limited(): void
    {
        $list = [$this->biz('default', 'https://mowology.ca'), $this->biz('greenleaf')];
        $this->assertSame(['status' => 'invalid'], $this->directory($list)->signIn('a@b.c', 'pw', 'mobile', '10.0.0.1'));
        $this->answers = ['greenleaf' => 'rate_limited'];
        $this->assertSame(['status' => 'rate_limited'], $this->directory($list)->signIn('a@b.c', 'pw', 'mobile', '10.0.0.1'));
        // The accountant's web-only login never counts as a match.
        $this->answers = ['greenleaf' => 'web_only'];
        $this->assertSame(['status' => 'invalid'], $this->directory($list)->signIn('a@b.c', 'pw', 'mobile', '10.0.0.1'));
        // A match elsewhere wins over a rate limit at another business.
        $this->answers = ['default' => 'ok', 'greenleaf' => 'rate_limited'];
        $this->assertSame('ok', $this->directory($list)->signIn('a@b.c', 'pw', 'mobile', '10.0.0.1')['status']);
    }

    public function test_a_business_whose_database_will_not_open_never_blocks_the_others(): void
    {
        $this->answers = ['greenleaf' => 'ok', 'claudia' => 'error'];
        $r = $this->directory([$this->biz('default', 'https://mowology.ca'), $this->biz('claudia'), $this->biz('greenleaf')], ['default'])
            ->signIn('a@b.c', 'pw', 'mobile', '10.0.0.1');
        $this->assertSame('ok', $r['status']);
        $this->assertSame('greenleaf', $r['business']['key']);
        $this->assertSame(['claudia', 'greenleaf'], $this->tried);
    }

    public function test_when_no_business_could_check_the_answer_is_unavailable_not_invalid(): void
    {
        $this->answers = ['greenleaf' => 'error'];
        $r = $this->directory([$this->biz('default', 'https://mowology.ca'), $this->biz('greenleaf')], ['default'])
            ->signIn('a@b.c', 'pw', 'mobile', '10.0.0.1');
        $this->assertSame('unavailable', $r['status']);

        $this->answers = ['greenleaf' => 'invalid'];
        $r = $this->directory([$this->biz('greenleaf')])->signIn('a@b.c', 'pw', 'mobile', '10.0.0.1');
        $this->assertSame('invalid', $r['status'], 'one business that looked and said no is a plain no');
    }

    public function test_a_business_with_no_app_address_is_skipped(): void
    {
        $this->answers = ['default' => 'ok', 'greenleaf' => 'ok'];
        $list = [['key' => 'default', 'app_url' => '', 'db' => ['x', 'u', 'p'], 'test' => false], $this->biz('greenleaf')];
        $r = $this->directory($list)->signIn('a@b.c', 'pw', 'mobile', '10.0.0.1');
        $this->assertSame('ok', $r['status']);
        $this->assertSame('greenleaf', $r['business']['key']);
        $this->assertSame(['greenleaf'], $this->tried, 'never even tried');
    }

    public function test_the_real_business_list_puts_the_original_business_first(): void
    {
        $reg = [
            'zeta'  => ['hosts' => ['zeta.ittai.ca'], 'db_name' => 'mowology_zeta', 'app_url' => 'https://zeta.ittai.ca'],
            'alpha' => ['hosts' => ['alpha.ittai.ca'], 'db_name' => 'mowology_alpha', 'app_url' => 'https://alpha.ittai.ca'],
        ];
        $this->answers = ['default' => 'ok', 'alpha' => 'ok', 'zeta' => 'ok'];
        $r = $this->directory(Tenant::businesses($reg, 'https://mowology.ca'))->signIn('a@b.c', 'pw', 'mobile', '10.0.0.1');
        $this->assertSame(['default', 'alpha', 'zeta'], array_column($r['businesses'], 'key'));
    }
}
