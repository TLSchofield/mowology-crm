<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Core/Auth/TwoStepService.php';

final class TwoStepServiceTest extends TestCase
{
    private const KEY_HEX = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';
    private int $now = 1700000000;

    private function pdo(bool $tables = true): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($tables) {
            $db->exec("CREATE TABLE user_two_step (user_id INTEGER PRIMARY KEY, totp_secret_enc TEXT NOT NULL, enabled_at TEXT NULL, last_step INTEGER NULL, created_at TEXT NULL)");
            $db->exec("CREATE TABLE user_backup_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, code_hash TEXT NOT NULL, used_at TEXT NULL, created_at TEXT NULL)");
            $db->exec("CREATE TABLE user_trusted_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash TEXT NOT NULL, user_agent TEXT NULL, created_at TEXT NOT NULL, last_seen_at TEXT NOT NULL, expires_at TEXT NOT NULL)");
        }
        return $db;
    }

    private function svc(PDO $db, ?string $key = 'default'): TwoStepService
    {
        $k = $key === 'default' ? QboTokenVault::decodeKey(self::KEY_HEX) : $key;
        return new TwoStepService($db, $k, function () { return $this->now; });
    }

    /** Sets up user 7 fully; returns [service, raw secret, backup codes]. */
    private function enrol(PDO $db): array
    {
        $svc = $this->svc($db);
        $secret = $svc->beginSetup(7);
        $raw = TwoStepService::base32Decode($secret);
        $codes = $svc->confirmSetup(7, TwoStepService::totpCode($raw, intdiv($this->now, 30)));
        $this->assertNotNull($codes);
        return [$svc, $raw, $codes];
    }

    public function test_rfc6238_vectors_six_digits(): void
    {
        $secret = '12345678901234567890';
        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924',
                  2000000000 => '279037', 20000000000 => '353130'] as $t => $code) {
            $this->assertSame($code, TwoStepService::totpCode($secret, intdiv($t, 30)), "T=$t");
        }
    }

    public function test_base32_round_trip_and_rfc_vector(): void
    {
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', TwoStepService::base32Encode('12345678901234567890'));
        $raw = random_bytes(20);
        $this->assertSame($raw, TwoStepService::base32Decode(TwoStepService::base32Encode($raw)));
        $this->assertSame('12345678901234567890', TwoStepService::base32Decode('gezd gnbv-GY3TQOJQ GEZDGNBVGY3TQOJQ'));
        $this->assertNull(TwoStepService::base32Decode('not*base32'));
        $this->assertNull(TwoStepService::base32Decode(''));
    }

    public function test_window_is_one_step_either_side_and_replay_is_refused(): void
    {
        $s = '12345678901234567890';
        $now = 1111111111;
        $cur = intdiv($now, 30);
        $this->assertSame($cur, TwoStepService::matchStep($s, TwoStepService::totpCode($s, $cur), $now, null));
        $this->assertSame($cur - 1, TwoStepService::matchStep($s, TwoStepService::totpCode($s, $cur - 1), $now, null));
        $this->assertSame($cur + 1, TwoStepService::matchStep($s, TwoStepService::totpCode($s, $cur + 1), $now, null));
        $this->assertNull(TwoStepService::matchStep($s, TwoStepService::totpCode($s, $cur - 2), $now, null));
        $this->assertNull(TwoStepService::matchStep($s, TwoStepService::totpCode($s, $cur + 2), $now, null));
        $this->assertNull(TwoStepService::matchStep($s, TwoStepService::totpCode($s, $cur), $now, $cur), 'same step again');
        $this->assertNull(TwoStepService::matchStep($s, TwoStepService::totpCode($s, $cur - 1), $now, $cur), 'older step');
        $this->assertNull(TwoStepService::matchStep($s, '12345', $now, null));
    }

    public function test_setup_stores_only_an_encrypted_secret(): void
    {
        $db = $this->pdo();
        $svc = $this->svc($db);
        $secret = $svc->beginSetup(7);
        $enc = (string)$db->query("SELECT totp_secret_enc FROM user_two_step WHERE user_id = 7")->fetchColumn();
        $this->assertStringStartsWith('q1:', $enc);
        $this->assertStringNotContainsString($secret, $enc);
        $this->assertFalse($svc->isEnrolled(7), 'not enrolled until the first code is confirmed');
        $this->assertSame($secret, $svc->setupSecret(7));
        $this->assertNull($svc->confirmSetup(7, '000000'));
        $this->assertFalse($svc->isEnrolled(7));
    }

    public function test_no_key_means_setup_unavailable(): void
    {
        $svc = $this->svc($this->pdo(), null);
        $this->assertFalse($svc->available());
        $this->expectException(RuntimeException::class);
        $svc->beginSetup(7);
    }

    public function test_confirm_enrols_and_gives_ten_hashed_single_use_codes(): void
    {
        $db = $this->pdo();
        [$svc, $raw, $codes] = $this->enrol($db);
        $this->assertTrue($svc->isEnrolled(7));
        $this->assertCount(10, $codes);
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}$/', $codes[0]);
        foreach ($db->query("SELECT code_hash FROM user_backup_codes")->fetchAll() as $r) {
            $this->assertStringStartsWith('$', $r['code_hash']);
            $this->assertStringNotContainsString('-', $r['code_hash']);
        }
        $this->assertTrue($svc->verify(7, $codes[0]));
        $this->assertFalse($svc->verify(7, $codes[0]), 'a backup code works once');
        $this->assertTrue($svc->verify(7, strtolower(str_replace('-', ' ', $codes[1]))), 'case, space and hyphen are forgiven');
        $this->assertSame(8, $svc->status(7)['backup_left']);
        $this->assertFalse($svc->verify(7, 'AAAAA-AAAAA'));
    }

    public function test_totp_verify_accepts_once_then_refuses_replay(): void
    {
        $db = $this->pdo();
        [$svc, $raw] = $this->enrol($db);   // the confirm used the current step
        $this->assertFalse($svc->verify(7, TwoStepService::totpCode($raw, intdiv($this->now, 30))), 'confirm code cannot sign in too');
        $this->now += 30;
        $code = TwoStepService::totpCode($raw, intdiv($this->now, 30));
        $this->assertTrue($svc->verify(7, $code));
        $this->assertFalse($svc->verify(7, $code), 'replay');
        $this->assertFalse($svc->verify(7, '123456'));
    }

    public function test_totp_cannot_be_checked_with_the_wrong_key_but_backup_codes_still_work(): void
    {
        $db = $this->pdo();
        [$svc, $raw, $codes] = $this->enrol($db);
        $this->now += 60;
        $wrong = $this->svc($db, str_repeat('k', 32));
        $this->assertFalse($wrong->verify(7, TwoStepService::totpCode($raw, intdiv($this->now, 30))));
        $this->assertTrue($wrong->verify(7, $codes[2]));
    }

    public function test_missing_tables_means_not_enrolled_never_an_error(): void
    {
        $svc = $this->svc($this->pdo(false));
        $this->assertFalse($svc->isEnrolled(7));
        $this->assertSame(0, $svc->revokeAllDevices(7));
        $this->assertFalse($svc->status(7)['tables']);
    }

    public function test_enrolled_with_tables_is_required_and_other_errors_are_not_swallowed(): void
    {
        $db = $this->pdo();
        [$svc] = $this->enrol($db);
        $this->assertTrue($svc->isEnrolled(7));
        $this->assertFalse($svc->isEnrolled(8));
        $db->exec("ALTER TABLE user_two_step RENAME COLUMN enabled_at TO gone");   // a different failure: must throw
        $this->expectException(PDOException::class);
        $svc->isEnrolled(7);
    }

    public function test_trusted_device_token_is_hashed_expires_and_revokes(): void
    {
        $db = $this->pdo();
        $svc = $this->svc($db);
        $tok = $svc->trust(7, 'Mozilla/5.0 (Macintosh; Intel Mac OS X) Chrome/120 Safari/537');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $tok);
        $stored = (string)$db->query("SELECT token_hash FROM user_trusted_devices")->fetchColumn();
        $this->assertSame(hash('sha256', $tok), $stored);
        $this->assertNotSame($tok, $stored);
        $this->assertTrue($svc->isTrusted(7, $tok));
        $this->assertFalse($svc->isTrusted(8, $tok), "another person's account");
        $this->assertFalse($svc->isTrusted(7, str_repeat('0', 64)));
        $this->assertFalse($svc->isTrusted(7, 'junk'));
        $this->now += 29 * 86400;
        $this->assertTrue($svc->isTrusted(7, $tok));
        $this->now += 2 * 86400;
        $this->assertFalse($svc->isTrusted(7, $tok), 'after 30 days');
        $this->assertSame([], $svc->devices(7));

        $t2 = $svc->trust(7, 'x'); $t3 = $svc->trust(7, 'y');
        $id = (int)$svc->devices(7)[0]['id'];
        $this->assertFalse($svc->revokeDevice(8, $id), "someone else's device");
        $this->assertTrue($svc->revokeDevice(7, $id));
        $this->assertSame(1, $svc->revokeAllDevices(7));
        $this->assertFalse($svc->isTrusted(7, $t2) || $svc->isTrusted(7, $t3));
    }

    public function test_disable_removes_secret_codes_and_devices(): void
    {
        $db = $this->pdo();
        [$svc] = $this->enrol($db);
        $svc->trust(7, 'ua');
        $svc->disable(7);
        $this->assertFalse($svc->isEnrolled(7));
        foreach (['user_two_step', 'user_backup_codes', 'user_trusted_devices'] as $t) {
            $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM $t")->fetchColumn(), $t);
        }
    }

    public function test_regenerate_replaces_codes_and_needs_enrolment(): void
    {
        $db = $this->pdo();
        $svc = $this->svc($db);
        $this->assertNull($svc->regenerateBackupCodes(7));
        [$svc, , $old] = $this->enrol($db);
        $new = $svc->regenerateBackupCodes(7);
        $this->assertCount(10, $new);
        $this->assertFalse($svc->verify(7, $old[0]));
        $this->assertTrue($svc->verify(7, $new[0]));
    }

    public function test_pending_state_expires_and_locks_after_five_tries(): void
    {
        $p = TwoStepService::pendingNew(7, 'a@b.c', false, 'user', 'abcd1234', 1000);
        $this->assertSame('ok', TwoStepService::pendingState($p, 1000));
        $this->assertSame('ok', TwoStepService::pendingState($p, 1300));
        $this->assertSame('expired', TwoStepService::pendingState($p, 1301));
        $p['tries'] = 4;
        $this->assertSame('ok', TwoStepService::pendingState($p, 1010));
        $p['tries'] = 5;
        $this->assertSame('locked', TwoStepService::pendingState($p, 1010));
        $this->assertSame('none', TwoStepService::pendingState(null, 1000));
        $this->assertSame('none', TwoStepService::pendingState(['uid' => 0, 'at' => 1], 1000));
        $this->assertSame('none', TwoStepService::pendingState('x', 1000));
    }

    public function test_cookie_name_is_per_business(): void
    {
        $this->assertSame('MWTD', TwoStepService::cookieName(true, 'mowology'));
        $this->assertSame('ITTD_claudia', TwoStepService::cookieName(false, 'claudia'));
    }

    public function test_login_user_never_signs_in_before_the_two_step_check(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/app/Core/Auth/auth.php');
        $start = strpos($src, 'function loginUser(');
        $end = strpos($src, "\nfunction ", $start + 10);
        $body = substr($src, $start, $end === false ? null : $end - $start);
        $this->assertDoesNotMatchRegularExpression('/\$_SESSION\[\'user_id\'\]\s*=[^=]/', $body, 'the session keys are set only in finishSignIn()');
        $this->assertLessThan(strpos($body, 'finishSignIn('), strpos($body, 'isEnrolled('));
        $this->assertStringContainsString('func_num_args() >= 5', $body, 'a caller that cannot ask for a code is refused');
    }
}
