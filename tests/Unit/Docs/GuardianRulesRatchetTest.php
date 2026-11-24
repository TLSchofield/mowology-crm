<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guardian's rules ratchet (2026-11-24): the project's own written rules (CLAUDE.md: no inline <style> in CRM pages, no hard-coded hex,
 * no raw mail(), no visible type=date, MySQL 5.7-only SQL, no committed secrets) are checked on every merge, so they stop being
 * advice. Existing violations are recorded in scripts/guardian/baseline.json; a file may only get BETTER. Fix the new violation;
 * never raise the baseline. After cleaning something up: python3 scripts/guardian/rules.py --tighten
 */
class GuardianRulesRatchetTest extends TestCase
{
    public function testNoNewRuleViolations(): void
    {
        $root = realpath(__DIR__ . '/../../..');
        $py = trim((string)shell_exec('command -v python3'));
        if ($py === '') {
            $this->markTestSkipped('python3 not available');
        }
        $out = [];
        $code = 0;
        exec(escapeshellarg($py) . ' ' . escapeshellarg($root . '/scripts/guardian/rules.py') . ' --check --root ' . escapeshellarg($root) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, "New rule violations (fix them, do not raise the baseline):\n" . implode("\n", $out));
    }

    public function testBaselineOnlyContainsKnownRules(): void
    {
        $b = json_decode((string)file_get_contents(__DIR__ . '/../../../scripts/guardian/baseline.json'), true);
        $this->assertIsArray($b['counts'] ?? null);
        foreach (array_keys($b['counts']) as $rule) {
            $this->assertContains($rule, ['inline-style', 'hardcoded-hex', 'raw-mail', 'native-date-input', 'mysql8-only']);
        }
        $this->assertArrayNotHasKey('secret', $b['counts'], 'secrets are never baselined');
    }
}
