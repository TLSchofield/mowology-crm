<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Core/Auth/JwtAuth.php';

/** SQLite has no UPDATE … LIMIT nor NOW(); the sign-in's last_login update uses both for MySQL. */
final class PhoneSignInTestPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (stripos(ltrim($query), 'UPDATE') === 0) {
            $query = str_replace('NOW()', "'2026-11-11 08:00:00'", (string)preg_replace('/\s+LIMIT\s+1\s*$/i', '', $query));
        }
        return parent::prepare($query, $options);
    }
}

/** token.php's credential check and token, moved into PhoneSignIn (2026-11-11). */
final class PhoneSignInTest extends TestCase
{
    private array $calls = [];
    private bool $limited = false;

    protected function setUp(): void
    {
        if (!defined('BLUEMOON_JWT_SECRET')) define('BLUEMOON_JWT_SECRET', 'test-secret-that-is-at-least-thirty-two-characters');
        $this->calls = [];
        $this->limited = false;
        PhoneSignIn::forTests([
            'limited' => function (string $e, string $ip, PDO $db) { $this->calls[] = ['limited', $e, $ip, $db]; return $this->limited; },
            'failed'  => function (string $e, string $ip, PDO $db) { $this->calls[] = ['failed', $e, $ip, $db]; },
            'clear'   => function (string $e, string $ip, PDO $db) { $this->calls[] = ['clear', $e, $ip, $db]; },
        ]);
        BusinessIdentity::reset();
        HeadIdentity::reset();
        Tenant::forTests(null);
    }

    protected function tearDown(): void
    {
        PhoneSignIn::forTests([]);
        BusinessIdentity::reset();
        HeadIdentity::reset();
        Tenant::forTests(null);
    }

    private function db(array $users = []): PDO
    {
        $db = new PhoneSignInTestPdo('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, username TEXT, password_hash TEXT, full_name TEXT, role TEXT, is_active INTEGER, last_login TEXT)');
        $db->exec('CREATE TABLE activity_log (id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT, details TEXT, ip_address TEXT)');
        $db->exec('CREATE TABLE business_settings (id INTEGER PRIMARY KEY, company_name TEXT, company_phone TEXT, company_email TEXT, company_website TEXT, company_address TEXT)');
        $db->exec("INSERT INTO business_settings (company_name) VALUES ('Greenleaf Gardens')");
        $ins = $db->prepare('INSERT INTO users (id, email, username, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($users as $u) $ins->execute($u);
        return $db;
    }

    private function user(int $id = 7, string $role = 'admin', int $active = 1): array
    {
        return [$id, 'Owner@Example.com', 'owner', password_hash('right password', PASSWORD_DEFAULT), 'Ada Lovelace', $role, $active];
    }

    private static function claims(string $token): array
    {
        return json_decode(base64url_decode(explode('.', $token)[1]), true);
    }

    public function test_secret_is_the_same_key_jwt_auth_checks(): void
    {
        $this->assertSame(BLUEMOON_JWT_SECRET, PhoneSignIn::secretFor(Tenant::DEFAULT, BLUEMOON_JWT_SECRET));
        $this->assertSame(jwtSecret(), PhoneSignIn::secretFor(Tenant::DEFAULT, BLUEMOON_JWT_SECRET));
        Tenant::forTests(['key' => 'greenleaf', 'cfg' => []]);
        $this->assertSame(jwtSecret(), PhoneSignIn::secretFor('greenleaf', BLUEMOON_JWT_SECRET));
        $this->assertNotSame(BLUEMOON_JWT_SECRET, PhoneSignIn::secretFor('greenleaf', BLUEMOON_JWT_SECRET));
    }

    public function test_a_right_password_gets_a_mobile_token_for_that_business(): void
    {
        $db = $this->db([$this->user()]);
        $r = PhoneSignIn::attempt($db, 'greenleaf', 'owner@example.com', 'right password', 'mobile', '10.0.0.1');

        $this->assertSame('ok', $r['status']);
        $this->assertSame(['id' => 7, 'email' => 'Owner@Example.com', 'name' => 'Ada Lovelace', 'role' => 'admin',
            'business_name' => 'Greenleaf Gardens', 'chief_name' => HeadIdentity::chiefName($db)], $r['user']);
        $this->assertSame(30 * 24 * 3600, $r['expires_in']);

        $c = self::claims($r['token']);
        $this->assertSame(['iss', 'aud', 'sub', 'email', 'name', 'role', 'iat', 'exp', 'pwd_v', 'biz'], array_keys($c));
        $this->assertSame('ios', $c['aud']);
        $this->assertSame('7', $c['sub']);
        $this->assertSame('greenleaf', $c['biz']);
        $this->assertSame(30 * 24 * 3600, $c['exp'] - $c['iat']);
        $hash = (string)$db->query('SELECT password_hash FROM users WHERE id = 7')->fetchColumn();
        $this->assertSame(substr(hash('sha256', $hash), 0, 8), $c['pwd_v']);

        // Signed with greenleaf's own key: valid at greenleaf's address, nowhere else.
        Tenant::forTests(['key' => 'greenleaf', 'cfg' => []]);
        $this->assertNotNull(validateJwtToken($r['token']));
        Tenant::forTests(null);
        $this->assertNull(validateJwtToken($r['token']));

        // The throttle is cleared on THIS business's database; the sign-in is logged there.
        $this->assertSame(['limited', 'clear'], array_column($this->calls, 0));
        $this->assertSame($db, $this->calls[1][3]);
        $this->assertSame(['iOS app login', 'JWT issued', '10.0.0.1'], array_values($db->query('SELECT action, details, ip_address FROM activity_log')->fetch(PDO::FETCH_ASSOC)));
        $this->assertSame('2026-11-11 08:00:00', $db->query('SELECT last_login FROM users WHERE id = 7')->fetchColumn());
    }

    public function test_a_web_token_is_short_and_the_username_works_too(): void
    {
        $db = $this->db([$this->user()]);
        $r = PhoneSignIn::attempt($db, Tenant::DEFAULT, 'owner', 'right password', 'web', '10.0.0.1');
        $this->assertSame('ok', $r['status']);
        $this->assertSame(8 * 3600, $r['expires_in']);
        $c = self::claims($r['token']);
        $this->assertSame('bluemoon', $c['aud']);
        $this->assertSame('default', $c['biz']);
        $this->assertNotNull(validateJwtToken($r['token']), 'the original business keeps the install secret');
        $this->assertSame('BlueMoon web login', $db->query('SELECT action FROM activity_log')->fetchColumn());
    }

    public function test_check_only_verifies_the_password_and_writes_nothing(): void
    {
        $db = $this->db([$this->user()]);
        $r = PhoneSignIn::attempt($db, 'greenleaf', 'owner@example.com', 'right password', 'mobile', '10.0.0.1', true);
        $this->assertSame(['status' => 'match'], $r);
        $this->assertSame(['limited'], array_column($this->calls, 0), 'no clear, no failure');
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM activity_log')->fetchColumn());
        $this->assertNull($db->query('SELECT last_login FROM users WHERE id = 7')->fetchColumn());

        $this->calls = [];
        $this->assertSame(['status' => 'invalid'], PhoneSignIn::attempt($db, 'greenleaf', 'owner@example.com', 'wrong', 'mobile', '10.0.0.1', true));
        $this->assertSame(['status' => 'invalid'], PhoneSignIn::attempt($db, 'greenleaf', 'nobody@example.com', 'right password', 'mobile', '10.0.0.1', true));
        $this->assertSame(['limited', 'limited'], array_column($this->calls, 0), 'a check-only miss is not recorded: the directory records it when nobody matched');
    }

    public function test_a_wrong_password_or_an_unknown_user_is_invalid_and_recorded(): void
    {
        $db = $this->db([$this->user()]);
        $this->assertSame(['status' => 'invalid'], PhoneSignIn::attempt($db, 'greenleaf', 'owner@example.com', 'wrong', 'mobile', '10.0.0.1'));
        $this->assertSame(['status' => 'invalid'], PhoneSignIn::attempt($db, 'greenleaf', 'nobody@example.com', 'right password', 'mobile', '10.0.0.1'));
        $this->assertSame(['limited', 'failed', 'limited', 'failed'], array_column($this->calls, 0));
        $this->assertSame(['failed', 'owner@example.com', '10.0.0.1', $db], $this->calls[1]);
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM activity_log')->fetchColumn());
    }

    public function test_an_inactive_user_is_invalid_even_with_the_right_password(): void
    {
        $db = $this->db([$this->user(7, 'admin', 0)]);
        $this->assertSame(['status' => 'invalid'], PhoneSignIn::attempt($db, 'greenleaf', 'owner@example.com', 'right password', 'mobile', '10.0.0.1'));
        $this->assertSame('failed', $this->calls[1][0]);
    }

    public function test_rate_limited_stops_before_the_password_is_checked(): void
    {
        $this->limited = true;
        $db = $this->db([$this->user()]);
        $this->assertSame(['status' => 'rate_limited'], PhoneSignIn::attempt($db, 'greenleaf', 'owner@example.com', 'right password', 'mobile', '10.0.0.1'));
        $this->assertSame(['limited'], array_column($this->calls, 0));
    }

    public function test_the_accountant_login_is_web_only(): void
    {
        $db = $this->db([$this->user(7, 'accountant')]);
        $this->assertSame(['status' => 'web_only'], PhoneSignIn::attempt($db, 'greenleaf', 'owner@example.com', 'right password', 'mobile', '10.0.0.1'));
        $this->assertNotContains('clear', array_column($this->calls, 0));
    }

    public function test_a_database_failure_is_an_error_not_invalid(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);   // no users table: the SELECT throws
        $this->assertSame(['status' => 'error'], PhoneSignIn::attempt($db, 'greenleaf', 'owner@example.com', 'right password', 'mobile', '10.0.0.1'));
        $this->assertNotContains('failed', array_column($this->calls, 0));
    }

    public function test_no_secret_signs_nothing(): void
    {
        PhoneSignIn::forTests(['limited' => fn() => false, 'failed' => fn() => null, 'clear' => fn() => null, 'secret' => 'too short']);
        $db = $this->db([$this->user()]);
        $this->assertSame(['status' => 'not_configured'], PhoneSignIn::attempt($db, 'greenleaf', 'owner@example.com', 'right password', 'mobile', '10.0.0.1'));
    }

    /** JwtAuth (2026-11-11): a token naming another business is refused; one naming none is judged as before. */
    public function test_validate_refuses_a_token_for_another_business(): void
    {
        $user = ['id' => 3, 'email' => 'a@example.com', 'full_name' => 'A', 'role' => 'staff', 'password_hash' => 'x'];
        $greenKey = PhoneSignIn::secretFor('greenleaf', BLUEMOON_JWT_SECRET);
        Tenant::forTests(['key' => 'greenleaf', 'cfg' => []]);
        $this->assertNotNull(validateJwtToken(PhoneSignIn::token($user, 'greenleaf', 'mobile', $greenKey, time())[0]));
        $this->assertNull(validateJwtToken(PhoneSignIn::token($user, 'claudia', 'mobile', $greenKey, time())[0]), 'right key, wrong biz');

        Tenant::forTests(null);
        $this->assertNull(validateJwtToken(PhoneSignIn::token($user, 'greenleaf', 'mobile', BLUEMOON_JWT_SECRET, time())[0]));
        $this->assertNotNull(validateJwtToken(PhoneSignIn::token($user, 'default', 'mobile', BLUEMOON_JWT_SECRET, time())[0]));
        $this->assertNotNull(validateJwtToken(generateMobileJwt($user, 120)), 'no biz claim: accepted as before');
    }
}
