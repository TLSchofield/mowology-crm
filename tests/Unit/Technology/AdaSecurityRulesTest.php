<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Ada's security alerts from the audit trail (Fable security audit #25d, 2026-10-12). */
final class AdaSecurityRulesTest extends TestCase
{
    private const NOW = '2026-10-12 18:00:00';

    public function test_kind_classifies_the_actions_the_crm_writes(): void
    {
        $this->assertSame('role', AdaSecurityRules::kind('Updated roles for user #7', 'Roles: staff → admin'));
        $this->assertSame('role', AdaSecurityRules::kind('Updated RBAC roles for user #7'));
        $this->assertSame('role', AdaSecurityRules::kind('Changed employee #9 account', 'role: user → admin; password changed'));
        $this->assertSame('password', AdaSecurityRules::kind('Changed password for user #4'));
        $this->assertSame('password', AdaSecurityRules::kind('Changed employee #9 account', 'password changed'));
        $this->assertSame('active', AdaSecurityRules::kind('Toggled active status for user #3', 'Active: yes → no'));
        $this->assertSame('account', AdaSecurityRules::kind('Created employee #12', 'email: x@y.test'));
        $this->assertSame('migration', AdaSecurityRules::kind('Migration run', 'Applied 1345_measurement_type_varchar.sql'));
        $this->assertSame('log-clear', AdaSecurityRules::kind('system_log_cleared'));
        $this->assertNull(AdaSecurityRules::kind('Invoice sent'));
        $this->assertNull(AdaSecurityRules::kind('Autopay enrolled'));
    }

    public function test_login_failures_green_below_limit(): void
    {
        $c = AdaSecurityRules::loginFailuresCheck([['hour' => '2026-10-12 12', 'n' => 16], ['hour' => '2026-10-12 13', 'n' => 4]], self::NOW);
        $this->assertSame('green', $c['state']);
        $this->assertStringContainsString('20 in the last 24 hours, at most 16 in one hour', $c['evidence']);
    }

    public function test_login_failures_red_over_fifty_an_hour_and_urgent_once_per_hour(): void
    {
        $hours = [['hour' => '2026-10-12 03', 'n' => 51], ['hour' => '2026-10-12 04', 'n' => 50]];
        $c = AdaSecurityRules::loginFailuresCheck($hours, self::NOW);
        $this->assertSame('red', $c['state']);
        $this->assertSame('spike', $c['problem']);
        $this->assertStringContainsString('51 in one hour (Oct 12, 3 am)', $c['evidence']);
        $u = AdaSecurityRules::urgentAlerts($hours, []);
        $this->assertCount(1, $u);   // 50 is not over the limit
        $this->assertSame('urgent:security:logins:2026-10-12 03', $u[0]['key']);
    }

    public function test_sensitive_changes_amber_with_evidence_red_when_log_cleared(): void
    {
        $rows = [
            ['id' => 9, 'action' => 'Migration run', 'details' => 'Applied 1345', 'created_at' => '2026-10-12 17:00:00', 'who' => 'Tim Schofield'],
            ['id' => 8, 'action' => 'Updated roles for user #7', 'details' => 'Roles: staff → admin', 'created_at' => '2026-10-12 16:00:00', 'who' => 'Tim Schofield'],
            ['id' => 7, 'action' => 'Invoice sent', 'details' => null, 'created_at' => '2026-10-12 15:00:00', 'who' => 'Tim'],
        ];
        $c = AdaSecurityRules::sensitiveChangesCheck($rows, self::NOW);
        $this->assertSame('amber', $c['state']);
        $this->assertStringContainsString('1 role change, 1 migration run', $c['evidence']);
        $this->assertStringContainsString('Updated roles for user #7 (Tim Schofield, 2 hours ago)', $c['evidence']);

        $rows[] = ['id' => 6, 'action' => 'system_log_cleared', 'details' => 'System log cleared by Tim', 'created_at' => '2026-10-12 14:00:00', 'who' => null];
        $c = AdaSecurityRules::sensitiveChangesCheck($rows, self::NOW);
        $this->assertSame('red', $c['state']);
        $this->assertSame('log-cleared', $c['problem']);

        $u = AdaSecurityRules::urgentAlerts([], $rows);
        $this->assertSame(['urgent:security:role:8', 'urgent:security:log-clear:6'], array_column($u, 'key'));
        $this->assertStringContainsString('The system error log was cleared by the system', $u[1]['text']);
    }

    public function test_nothing_happened_is_green(): void
    {
        $this->assertSame('green', AdaSecurityRules::sensitiveChangesCheck([], self::NOW)['state']);
        $this->assertSame([], AdaSecurityRules::urgentAlerts([], []));
    }
}
