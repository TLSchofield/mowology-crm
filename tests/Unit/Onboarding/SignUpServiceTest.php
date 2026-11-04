<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SignUpServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, full_name TEXT, first_name TEXT, last_name TEXT, preferred_name TEXT, role TEXT, is_active INT, last_login TEXT, phone TEXT, contact_channel TEXT, quiet_start TEXT, quiet_end TEXT, quiet_weekends INT DEFAULT 0)');
        $db->exec('CREATE TABLE business_settings (id INTEGER PRIMARY KEY, company_name TEXT, gst_registration TEXT)');
        $db->exec('CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)');
        $db->exec('CREATE TABLE activity_log (id INTEGER PRIMARY KEY, user_id INT, contact_id INT, action TEXT, details TEXT, ip_address TEXT)');
        $db->exec('CREATE TABLE business_identity (id INTEGER PRIMARY KEY, legal_name TEXT)');
        $db->exec("INSERT INTO users (id,email,password_hash,full_name,role,is_active) VALUES (7,'owner@example.com','one-time','Old Owner','admin',1)");
        $db->exec("INSERT INTO business_settings VALUES (1,'Provisioned Placeholder','')");
        $db->exec("INSERT INTO business_identity VALUES (1,'Operator Identity')");
        return $db;
    }

    private function valid(string $code = ''): array
    {
        $in = ['full_name' => 'Ada Lovelace', 'mobile' => '+1 604 555 0101', 'email' => 'OWNER@example.com', 'password' => str_repeat('p', 17), 'audience' => 'mobile'];
        if ($code !== '') $in['code'] = $code;
        return $in;
    }

    public function test_name_mobile_and_validation_are_exact(): void
    {
        $pw = 'Use at least 10 characters.';
        $this->assertSame(['Ada', 'Lovelace Byron'], SignUpService::splitName(' Ada   Lovelace Byron '));
        $this->assertNull(SignUpService::splitName('Ada'));
        $this->assertSame('(604) 555-0101', SignUpService::mobile('+1 (604) 555-0101'));
        $this->assertSame(['full_name' => 'Enter your full name.', 'mobile' => 'Enter your mobile number (10 digits).', 'email' => 'Enter a valid email address.', 'password' => $pw], SignUpService::validate([]));
        $this->assertSame(['code' => 'Enter the 6-digit code.'], SignUpService::validate($this->valid(), true));
    }

    public function test_request_replaces_code_and_claim_checks(): void
    {
        $db = $this->db(); $svc = new SignUpService($db, 'tenant');
        $this->assertSame('validation', $svc->requestCode(array_merge($this->valid(), ['email' => 'wrong@example.com']), '111111', 1000)['error']);
        $this->assertTrue($svc->requestCode($this->valid(), '111111', 1000)['ok']);
        $first = $db->query("SELECT setting_value FROM ops_settings WHERE setting_key='signup_code_hash'")->fetchColumn();
        $svc->requestCode($this->valid(), '222222', 1100);
        $second = $db->query("SELECT setting_value FROM ops_settings WHERE setting_key='signup_code_hash'")->fetchColumn();
        $this->assertNotSame($first, $second); $this->assertTrue(password_verify('222222', $second));
    }

    /** Production 2026-11-03: a second code request died on "Duplicate entry 'signup_code_tries'" because MySQL's
     *  rowCount() counts changed rows, and re-writing tries = '0' changes nothing. Mimic MySQL here. */
    public function test_second_code_request_survives_mysql_changed_row_count(): void
    {
        $db = $this->db();
        $db->exec('CREATE UNIQUE INDEX uq_setting_key ON ops_settings (setting_key)');
        $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SignUpMysqlLikeStatement::class, []]);
        $svc = new SignUpService($db, 'tenant');
        $this->assertTrue($svc->requestCode($this->valid(), '111111', 1000)['ok']);
        $this->assertTrue($svc->requestCode($this->valid(), '222222', 1100)['ok']);
        $this->assertSame(3, (int)$db->query("SELECT COUNT(*) FROM ops_settings WHERE setting_key LIKE 'signup_code_%'")->fetchColumn());
        $this->assertTrue(password_verify('222222', (string)$db->query("SELECT setting_value FROM ops_settings WHERE setting_key='signup_code_hash'")->fetchColumn()));
    }

    public function test_expiry_wrong_code_and_five_try_limit(): void
    {
        $db = $this->db(); $svc = new SignUpService($db, 'tenant');
        $svc->requestCode($this->valid(), '123456', 1000);
        $this->assertSame('code_expired', $svc->confirm($this->valid('123456'), '127.0.0.1', null, 2000)['error']);
        $svc->requestCode($this->valid(), '123456', 1000);
        for ($i = 0; $i < 5; $i++) $this->assertSame('code_wrong', $svc->confirm($this->valid('000000'), '127.0.0.1', null, 1001)['error']);
        $this->assertSame('code_expired', $svc->confirm($this->valid('123456'), '127.0.0.1', null, 1001)['error']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM ops_settings WHERE setting_key LIKE 'signup_code_%'")->fetchColumn());
    }

    public function test_right_code_claims_person_and_does_not_touch_company(): void
    {
        $db = $this->db(); $svc = new SignUpService($db, 'tenant'); $svc->requestCode($this->valid(), '123456', 1000);
        $r = $svc->confirm($this->valid('123456'), '127.0.0.1', static function (array $user): string { return 'token'; }, 1001);
        $this->assertTrue($r['ok']); $this->assertSame('token', $r['token']); $this->assertSame('name', $r['next']);
        $this->assertSame('Sign-up (mobile)', $db->query('SELECT action FROM activity_log')->fetchColumn(), 'the claim is logged (production table has no client_id; walk 1)');
        $u = $db->query('SELECT first_name,last_name,preferred_name,phone,contact_channel FROM users WHERE id=7')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['first_name'=>'Ada','last_name'=>'Lovelace','preferred_name'=>'Ada','phone'=>'(604) 555-0101','contact_channel'=>'text'], $u);
        $this->assertSame('Provisioned Placeholder', $db->query('SELECT company_name FROM business_settings')->fetchColumn());
        $this->assertSame('Operator Identity', $db->query('SELECT legal_name FROM business_identity')->fetchColumn());
    }

    public function test_token_failure_rolls_back_claim_and_code_consumption(): void
    {
        $db = $this->db(); $svc = new SignUpService($db, 'tenant'); $svc->requestCode($this->valid(), '123456', 1000);
        $r = $svc->confirm($this->valid('123456'), '127.0.0.1', static function (array $user): string { throw new RuntimeException('no'); }, 1001);
        $this->assertSame(500, $r['status']);
        $this->assertSame('one-time', $db->query('SELECT password_hash FROM users WHERE id=7')->fetchColumn());
        $this->assertSame(3, (int)$db->query("SELECT COUNT(*) FROM ops_settings WHERE setting_key LIKE 'signup_code_%'")->fetchColumn());
    }

    public function test_production_response_shape_never_adds_plain_code(): void
    {
        $this->assertArrayNotHasKey('code', SignUpService::requestResponse(['email'], '123456', false));
        $this->assertSame('123456', SignUpService::requestResponse(['email'], '123456', true)['code']);
    }

    public function test_repeated_code_requests_fill_the_bucket_and_the_next_is_refused(): void
    {
        $attempts = 0;
        $limited = static function (string $key, string $ip) use (&$attempts): bool { return $attempts >= 5; };
        $record = static function (string $key, string $ip) use (&$attempts): void { $attempts++; };
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(SignUpRequestThrottle::reserve('OWNER@example.com', '127.0.0.1', $limited, $record));
        }
        $this->assertFalse(SignUpRequestThrottle::reserve('owner@example.com', '127.0.0.1', $limited, $record));
        $this->assertSame(5, $attempts, 'A refused request must not add another throttle row.');
    }
}

/** Worst case of MySQL's changed-rows count: every UPDATE reports 0 rows, even when it matched. */
final class SignUpMysqlLikeStatement extends PDOStatement
{
    protected function __construct() {}

    public function rowCount(): int
    {
        return stripos(ltrim($this->queryString), 'UPDATE') === 0 ? 0 : parent::rowCount();
    }
}
