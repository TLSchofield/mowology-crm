<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Ada's nightly security-posture checks (cron security_posture, 2026-10-13). */
final class SecurityPostureRulesTest extends TestCase
{
    private const NOW = '2026-10-13 04:00:00';

    public function test_every_check_is_in_the_security_group_with_adas_shape(): void
    {
        $c = SecurityPostureRules::blockedPathsCheck(['/a' => 404]);
        foreach (['system', 'group', 'name', 'last_worked', 'state', 'problem', 'evidence', 'handoff', 'handoff_to'] as $k) {
            $this->assertArrayHasKey($k, $c);
        }
        $this->assertSame('Security', $c['group']);
        $this->assertSame('posture:blocked-paths', $c['system']);
    }

    public function test_blocked_paths_red_on_any_200_with_the_url(): void
    {
        $c = SecurityPostureRules::blockedPathsCheck(['/crm/tmp/jobber1.csv' => 200, '/app/' => 403, '/vendor/' => 404]);
        $this->assertSame('red', $c['state']);
        $this->assertStringContainsString('/crm/tmp/jobber1.csv', $c['evidence']);
        $this->assertSame('green', SecurityPostureRules::blockedPathsCheck(['/app/' => 403, '/vendor/' => 404])['state']);
        $this->assertSame('unknown', SecurityPostureRules::blockedPathsCheck(['/app/' => 0, '/x' => 0])['state']);
        $this->assertSame('amber', SecurityPostureRules::blockedPathsCheck(['/app/' => 302])['state']);
        $this->assertContains('/crm/tmp/jobber1.csv', SecurityPostureRules::BLOCKED_PATHS);
        $this->assertContains('/app_config/secrets.php', SecurityPostureRules::BLOCKED_PATHS);
    }

    public function test_stray_files_skip_uploads_media_vendor_and_migrations(): void
    {
        $this->assertTrue(SecurityPostureRules::isStray('crm/tmp/jobber1.csv'));
        $this->assertTrue(SecurityPostureRules::isStray('app/Storage/backups/dump.sql.gz'));
        $this->assertTrue(SecurityPostureRules::isStray('old.BAK'));
        $this->assertFalse(SecurityPostureRules::isStray('uploads/expenses/a.csv'));
        $this->assertFalse(SecurityPostureRules::isStray('_media/x.zip'));
        $this->assertFalse(SecurityPostureRules::isStray('vendor/pkg/tests/data.csv'));
        $this->assertFalse(SecurityPostureRules::isStray('database/migrations/1347_security_posture.sql'));
        $this->assertFalse(SecurityPostureRules::isStray('crm/index.php'));
        $many = array_map(fn($i) => "x/f{$i}.csv", range(1, 25));
        $c = SecurityPostureRules::strayFilesCheck($many);
        $this->assertSame('amber', $c['state']);
        $this->assertStringContainsString('and 5 more', $c['evidence']);
        $this->assertSame('green', SecurityPostureRules::strayFilesCheck([])['state']);
    }

    public function test_db_privileges_red_while_the_app_can_change_tables(): void
    {
        $all = ["GRANT USAGE ON *.* TO `u`@`localhost`", "GRANT ALL PRIVILEGES ON `mowology\\_landscape\\_crm`.* TO `u`@`localhost`"];
        $c = SecurityPostureRules::dbPrivilegesCheck($all, 'mowology_landscape_crm');
        $this->assertSame('red', $c['state']);
        $this->assertStringStartsWith('app login can change tables — finish step 3 (data-only user)', $c['evidence']);

        $data = ["GRANT USAGE ON *.* TO `u`@`localhost`",
                 "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE ON `mowology\\_landscape\\_crm`.* TO `u`@`localhost`"];
        $this->assertSame('green', SecurityPostureRules::dbPrivilegesCheck($data, 'mowology_landscape_crm')['state']);

        $alter = ["GRANT SELECT, ALTER ON `crm`.* TO `u`@`%`"];
        $this->assertSame('red', SecurityPostureRules::dbPrivilegesCheck($alter, 'crm')['state']);
        // A grant on another database does not count.
        $this->assertSame('unknown', SecurityPostureRules::dbPrivilegesCheck(["GRANT ALL PRIVILEGES ON `other`.* TO `u`@`%`"], 'crm')['state']);
    }

    public function test_stripe_webhook_names_the_missing_events(): void
    {
        $ep = [['url' => 'https://mowology.ca/api/stripe/webhook', 'status' => 'enabled',
                'enabled_events' => ['payment_intent.succeeded', 'payment_intent.payment_failed']]];
        $c = SecurityPostureRules::stripeWebhookCheck($ep, true);
        $this->assertSame('amber', $c['state']);
        $this->assertStringContainsString('charge.dispute.created', $c['evidence']);
        $this->assertStringContainsString('charge.refunded', $c['evidence']);
        $this->assertStringNotContainsString('payment_intent.succeeded', $c['evidence']);

        $ep[0]['enabled_events'] = SecurityPostureRules::STRIPE_EVENTS;
        $this->assertSame('green', SecurityPostureRules::stripeWebhookCheck($ep, true)['state']);
        $ep[0]['enabled_events'] = ['*'];
        $this->assertSame('green', SecurityPostureRules::stripeWebhookCheck($ep, true)['state']);
        $ep[0]['status'] = 'disabled';
        $this->assertSame('red', SecurityPostureRules::stripeWebhookCheck($ep, true)['state']);
        $this->assertSame('unknown', SecurityPostureRules::stripeWebhookCheck(null, false)['state']);
        $this->assertSame('amber', SecurityPostureRules::stripeWebhookCheck([['url' => 'https://x.test/hook']], true)['state']);
    }

    public function test_payment_mail_red_when_everything_is_held(): void
    {
        $c = SecurityPostureRules::paymentMailCheck(['Yardi' => ['held' => 3, 'verified' => 0], 'Interac' => ['held' => 0, 'verified' => 4]]);
        $this->assertSame('red', $c['state']);
        $this->assertStringContainsString('real Yardi/Interac mail may be failing the signature check', $c['evidence']);
        $this->assertStringContainsString('Yardi: 0 verified, 3 held', $c['evidence']);
        $ok = SecurityPostureRules::paymentMailCheck(['Yardi' => ['held' => 1, 'verified' => 2], 'Interac' => ['held' => 0, 'verified' => 0]]);
        $this->assertSame('green', $ok['state']);
    }

    public function test_backups_db_red_files_amber_after_36_hours(): void
    {
        $fresh = ['finished_at' => '2026-10-13 02:40:00', 'bytes' => 52428800, 'ok' => 1];
        $old = ['finished_at' => '2026-10-11 02:40:00', 'bytes' => 1, 'ok' => 1];
        $this->assertSame('green', SecurityPostureRules::backupCheck('db', $fresh, $fresh, self::NOW)['state']);
        $this->assertSame('red', SecurityPostureRules::backupCheck('db', $old, $old, self::NOW)['state']);
        $this->assertSame('red', SecurityPostureRules::backupCheck('db', null, null, self::NOW)['state']);
        $this->assertSame('amber', SecurityPostureRules::backupCheck('files', null, null, self::NOW)['state']);
        $failed = ['finished_at' => '2026-10-13 02:40:00', 'ok' => 0];
        $this->assertStringContainsString('FAILED', SecurityPostureRules::backupCheck('db', $old, $failed, self::NOW)['evidence']);
    }

    public function test_composer_amber_on_advisories_or_old_report(): void
    {
        $this->assertSame('amber', SecurityPostureRules::composerCheck(null, self::NOW)['state']);
        $this->assertSame('amber', SecurityPostureRules::composerCheck(['finished_at' => '2026-10-12 02:00:00', 'advisories' => 1, 'detail' => 'setasign/fpdi'], self::NOW)['state']);
        $this->assertSame('amber', SecurityPostureRules::composerCheck(['finished_at' => '2026-09-20 02:00:00', 'advisories' => 0], self::NOW)['state']);
        $this->assertSame('green', SecurityPostureRules::composerCheck(['finished_at' => '2026-10-12 02:00:00', 'advisories' => 0], self::NOW)['state']);
    }

    public function test_audit_copy_tail_verifies_and_must_grow(): void
    {
        $prev = 'genesis';
        $lines = [];
        foreach ([1, 2, 3] as $id) {
            [$line, $prev] = AuditExportService::line(['id' => $id, 'action' => "a{$id}", 'created_at' => '2026-10-12 10:00:00'], $prev);
            $lines[] = $line;
        }
        $this->assertSame(0, SecurityPostureRules::verifyAuditTail($lines));
        $this->assertSame(0, SecurityPostureRules::verifyAuditTail(array_slice($lines, 1)), 'a tail starting mid-file trusts its first prev');
        $tampered = $lines;
        $tampered[1] = str_replace('"a2"', '"a9"', $tampered[1]);
        $this->assertSame(2, SecurityPostureRules::verifyAuditTail($tampered));
        $dropped = [$lines[0], $lines[2]];
        $this->assertSame(2, SecurityPostureRules::verifyAuditTail($dropped));

        $t = strtotime(self::NOW);
        $this->assertSame('red', SecurityPostureRules::auditCopyCheck(null, null, null, 0, self::NOW)['state']);
        $this->assertSame('red', SecurityPostureRules::auditCopyCheck('/x', $t - 40 * 3600, 10, 0, self::NOW)['state']);
        $this->assertSame('red', SecurityPostureRules::auditCopyCheck('/x', $t - 3600, 10, 3, self::NOW)['state']);
        $this->assertSame('green', SecurityPostureRules::auditCopyCheck('/x', $t - 3600, 10, 0, self::NOW)['state']);
    }

    public function test_certificate_amber_under_21_days_red_under_7(): void
    {
        $t = strtotime(self::NOW);
        $this->assertSame('green', SecurityPostureRules::certCheck($t + 60 * 86400, null, self::NOW)['state']);
        $this->assertSame('amber', SecurityPostureRules::certCheck($t + 20 * 86400, null, self::NOW)['state']);
        $this->assertSame('red', SecurityPostureRules::certCheck($t + 6 * 86400, null, self::NOW)['state']);
        $this->assertSame('unknown', SecurityPostureRules::certCheck(null, 'timeout', self::NOW)['state']);
    }

    public function test_drift_amber_and_red_on_auth_or_money_paths(): void
    {
        $m = ['updated_at' => '2026-10-13 01:00:00 -0700', 'files' => ['public/crm/x.php' => 'a', 'app/Core/Auth/auth.php' => 'b']];
        $this->assertSame('unknown', SecurityPostureRules::driftCheck(null, [])['state']);
        $this->assertSame('green', SecurityPostureRules::driftCheck($m, [])['state']);
        $this->assertSame('amber', SecurityPostureRules::driftCheck($m, ['public/crm/x.php' => 'changed'])['state']);
        $this->assertSame('red', SecurityPostureRules::driftCheck($m, ['app/Core/Auth/auth.php' => 'changed'])['state']);
        $this->assertSame('red', SecurityPostureRules::driftCheck($m, ['app/Modules/Accounting/Services/X.php' => 'missing'])['state']);
        $this->assertSame('red', SecurityPostureRules::driftCheck($m, ['public/api/stripe/webhook.php' => 'changed'])['state']);
    }

    public function test_page_render_red_on_truncation_even_with_200(): void
    {
        $ok = ['code' => 200, 'html_end' => true, 'bytes' => 5000, 'error' => null];
        $this->assertSame('green', SecurityPostureRules::pageRenderCheck(['/' => $ok, '/about' => $ok])['state']);
        $c = SecurityPostureRules::pageRenderCheck(['/' => $ok, '/about' => ['code' => 200, 'html_end' => false, 'bytes' => 812, 'error' => null]]);
        $this->assertSame('red', $c['state']);
        $this->assertStringContainsString('/about (cut off', $c['evidence']);
        $portal = '/customer/invoice.php?token=ada-posture-check-not-a-token';
        $this->assertSame('green', SecurityPostureRules::pageRenderCheck([$portal => ['code' => 404, 'html_end' => true, 'bytes' => 900, 'error' => null]])['state']);
        $this->assertSame('red', SecurityPostureRules::pageRenderCheck([$portal => ['code' => 500, 'html_end' => true, 'bytes' => 900, 'error' => null]])['state']);
    }

    public function test_pending_migrations_ignore_prefixed_files(): void
    {
        $files = ['1346_a.sql', '1347_security_posture.sql', 'done-100_x.sql', 'todo-028_y.sql', '1347_security_posture.sql'];
        $this->assertSame(['1347_security_posture.sql'], SecurityPostureRules::pendingMigrations($files, ['1346_a.sql']));
        $c = SecurityPostureRules::migrationsCheck(['1347_security_posture.sql'], 3);
        $this->assertSame('amber', $c['state']);
        $this->assertStringContainsString('1347', $c['evidence']);
        $this->assertStringContainsString('never run: 1347, 1200', SecurityPostureRules::migrationsCheck(['1200_a.sql', '1347_b.sql'], 3)['evidence'], 'newest first');
        $this->assertSame('green', SecurityPostureRules::migrationsCheck([], 3)['state']);
    }

    public function test_php_errors_parse_dedupe_redact_and_thresholds(): void
    {
        $p = SecurityPostureRules::parseErrorLine('[13-Oct-2026 01:02:03 America/Vancouver] PHP Warning:  Undefined array key "x" in /home/a.php on line 12');
        $this->assertNotNull($p);
        $this->assertStringStartsWith('PHP Warning', $p[1]);
        $this->assertNull(SecurityPostureRules::parseErrorLine('#0 /home/a.php(12): foo()'));
        $this->assertSame(
            SecurityPostureRules::errorKey('PHP Warning: Undefined array key "x" for invoice #1234'),
            SecurityPostureRules::errorKey('PHP Warning: Undefined array key "y" for invoice #9876'));
        $r = SecurityPostureRules::redact('Stripe failed sk_live_abcdefghijklmnop password=hunter2 for bob@example.com token: ' . str_repeat('a', 40));
        $this->assertStringNotContainsString('abcdefghijklmnop', $r);
        $this->assertStringNotContainsString('hunter2', $r);
        $this->assertStringNotContainsString('bob@example.com', $r);
        $this->assertStringNotContainsString(str_repeat('a', 40), $r);

        $this->assertTrue(SecurityPostureRules::isErrorMessage('PHP Fatal error:  Uncaught TypeError'));
        $this->assertTrue(SecurityPostureRules::isErrorMessage("Yui renewals: SQLSTATE[HY000]: General error: 1267 Illegal mix of collations"));
        $this->assertTrue(SecurityPostureRules::isErrorMessage('backup-heartbeat: insert failed'));
        $this->assertFalse(SecurityPostureRules::isErrorMessage('process_ocr_queue: === OCR queue worker started ==='));
        $this->assertFalse(SecurityPostureRules::isErrorMessage('process_ocr_queue: No pending jobs.'));
        $this->assertFalse(SecurityPostureRules::isErrorMessage('Charlie: house brief took 80 ms'));
        $this->assertStringContainsString('(of 3465 log lines)', SecurityPostureRules::phpErrorsCheck(10, false, [], ['~/error_log'], 3465)['evidence']);
        $this->assertSame('green', SecurityPostureRules::phpErrorsCheck(10, false, [], ['~/error_log'])['state']);
        $this->assertSame('amber', SecurityPostureRules::phpErrorsCheck(51, false, ['m' => 51], ['~/error_log'])['state']);
        $this->assertSame('red', SecurityPostureRules::phpErrorsCheck(501, true, ['m' => 501], ['~/error_log'])['state']);
        $this->assertStringContainsString('at least 501', SecurityPostureRules::phpErrorsCheck(501, true, [], ['~/error_log'])['evidence']);
        $this->assertSame('unknown', SecurityPostureRules::phpErrorsCheck(0, false, [], [])['state']);
    }

    public function test_spf_and_mail_dns(): void
    {
        $dns = [
            'TXT:inc.test' => ['v=spf1 ip4:10.0.0.0/24 -all'],
            'A:mowology.ca' => ['104.193.174.145'],
            'MX:mowology.ca' => ['mowology.ca'],
        ];
        $resolve = fn(string $t, string $n) => $dns["{$t}:{$n}"] ?? [];
        $spf = 'v=spf1 include:inc.test ip4:104.193.174.145 +a +mx ~all';
        $this->assertTrue(SecurityPostureRules::spfAllows($spf, '104.193.174.145', 'mowology.ca', $resolve));
        $this->assertTrue(SecurityPostureRules::spfAllows('v=spf1 include:inc.test ~all', '10.0.0.7', 'mowology.ca', $resolve));
        $this->assertTrue(SecurityPostureRules::spfAllows('v=spf1 +a ~all', '104.193.174.145', 'mowology.ca', $resolve));
        $this->assertFalse(SecurityPostureRules::spfAllows('v=spf1 -ip4:1.2.3.4 ~all', '1.2.3.4', 'mowology.ca', $resolve));
        $this->assertFalse(SecurityPostureRules::spfAllows('v=spf1 include:inc.test ~all', '9.9.9.9', 'mowology.ca', $resolve));

        $ok = SecurityPostureRules::mailDnsCheck('mowology.ca', [$spf], ['v=DMARC1; p=none'], 'default', ['v=DKIM1; k=rsa; p=MII'], 'mail.mowology.ca', '104.193.174.145', $resolve);
        $this->assertSame('green', $ok['state']);
        $noDmarc = SecurityPostureRules::mailDnsCheck('mowology.ca', [$spf], [], 'default', ['v=DKIM1; p=x'], 'mail.mowology.ca', '104.193.174.145', $resolve);
        $this->assertSame('amber', $noDmarc['state']);
        $this->assertStringContainsStringIgnoringCase('no DMARC record', $noDmarc['evidence']);
        $badSpf = SecurityPostureRules::mailDnsCheck('mowology.ca', ['v=spf1 -all'], ['v=DMARC1; p=none'], null, [], 'smtp.x', '5.5.5.5', $resolve);
        $this->assertSame('amber', $badSpf['state']);
    }

    public function test_a_new_red_alerts_once_and_a_lasting_red_keeps_its_key(): void
    {
        $red = ['system' => 'posture:db-privileges', 'name' => 'Database login rights', 'state' => 'red', 'evidence' => 'x'];
        $green = ['system' => 'posture:certificate', 'name' => 'Website certificate', 'state' => 'green', 'evidence' => 'y'];

        $night1 = SecurityPostureRules::redSince([$red, $green], [], '2026-10-13 03:40:00');
        $this->assertSame('2026-10-13 03:40:00', $night1[0]['red_since']);
        $this->assertNull($night1[1]['red_since']);
        $night2 = SecurityPostureRules::redSince([$red, $green], ['posture:db-privileges' => '2026-10-13 03:40:00'], '2026-10-14 03:40:00');
        $this->assertSame('2026-10-13 03:40:00', $night2[0]['red_since'], 'still red: same since → same alert key → not re-sent');
        $k1 = SecurityPostureRules::urgentAlerts($night1)[0]['key'];
        $k2 = SecurityPostureRules::urgentAlerts($night2)[0]['key'];
        $this->assertSame($k1, $k2);
        $this->assertCount(1, SecurityPostureRules::urgentAlerts($night1), 'greens never alert');
        $this->assertSame('security', SecurityPostureRules::urgentAlerts($night1)[0]['kind']);

        // Cleared, then red again → a new key.
        $cleared = SecurityPostureRules::redSince([array_merge($red, ['state' => 'green'])], ['posture:db-privileges' => '2026-10-13 03:40:00'], '2026-10-15 03:40:00');
        $this->assertNull($cleared[0]['red_since']);
        $again = SecurityPostureRules::redSince([$red], ['posture:db-privileges' => null], '2026-10-16 03:40:00');
        $this->assertNotSame($k1, SecurityPostureRules::urgentAlerts($again)[0]['key']);
    }

    public function test_stale_run_and_heartbeat_validation(): void
    {
        $this->assertSame('amber', SecurityPostureRules::staleCheck(null, self::NOW)['state']);
        $this->assertSame('amber', SecurityPostureRules::staleCheck('2026-10-11 03:40:00', self::NOW)['state']);
        $this->assertNull(SecurityPostureRules::staleCheck('2026-10-13 03:40:00', self::NOW));

        $row = SecurityPostureRules::heartbeatRow(['kind' => 'db', 'ok' => true, 'bytes' => '123', 'finished_at' => '2026-10-13 02:41:00'], self::NOW);
        $this->assertIsArray($row);
        $this->assertSame(1, $row['ok']);
        $this->assertSame(123, $row['bytes']);
        $this->assertIsString(SecurityPostureRules::heartbeatRow(['kind' => 'shell'], self::NOW));
        $this->assertIsString(SecurityPostureRules::heartbeatRow(['kind' => 'db', 'finished_at' => '2030-01-01'], self::NOW));
    }
}
