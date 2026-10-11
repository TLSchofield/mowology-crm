<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Keeps the penny-bc-bookkeeping skill true (2026-10-11):
 *  - every app/… or public/… path and every `Class::method()` / `function()` it names exists;
 *  - no dated rule in references/bc-rules.md is past its "review by" date.
 * A failure means: update the skill in the same commit as the code (or re-check the rule).
 */
final class PennySkillTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../.claude/skills/penny-bc-bookkeeping';

    private static function text(): string
    {
        $t = (string)file_get_contents(self::DIR . '/SKILL.md');
        foreach (glob(self::DIR . '/references/*.md') ?: [] as $f) $t .= "\n" . file_get_contents($f);
        return $t;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testNamedPathsExist(): void
    {
        preg_match_all('#`((?:app|public|scripts|database|docs|tests)/[A-Za-z0-9_./-]+\.(?:php|sh|sql|md|js))`#', self::text(), $m);
        $missing = array_values(array_filter(array_unique($m[1]), fn($p) => !is_file(self::root() . '/' . $p)));
        $this->assertSame([], $missing, 'The Penny skill names files that no longer exist');
    }

    public function testNamedFunctionsExist(): void
    {
        $code = '';
        foreach (['app'] as $d) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root() . '/' . $d, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) if (substr((string)$f, -4) === '.php') $code .= file_get_contents((string)$f);
        }
        $t = self::text();
        preg_match_all('/`([A-Z][A-Za-z0-9]+)::([a-z][A-Za-z0-9]+)\(\)`/', $t, $m, PREG_SET_ORDER);
        $missing = [];
        foreach ($m as [$all, $class, $method]) {
            if (!preg_match('/class\s+' . $class . '\b/', $code) || !preg_match('/function\s+' . $method . '\s*\(/', $code)) $missing[] = "{$class}::{$method}()";
        }
        preg_match_all('/`([a-z][A-Za-z0-9]+)\(\)`/', $t, $f);
        foreach (array_unique($f[1]) as $fn) {
            if (!preg_match('/function\s+' . $fn . '\s*\(/', $code)) $missing[] = "{$fn}()";
        }
        preg_match_all('/`([A-Z][a-z]+[A-Z][A-Za-z]+(?:Service|Learning|Gate|Engine|Map|Explainer|Adapter))`/', $t, $c);
        foreach (array_unique($c[1]) as $class) {
            if (!preg_match('/class\s+' . $class . '\b/', $code)) $missing[] = $class;
        }
        $this->assertSame([], array_values(array_unique($missing)), 'The Penny skill names code that no longer exists');
    }

    public function testNoRuleIsPastItsReviewDate(): void
    {
        $rules = (string)file_get_contents(self::DIR . '/references/bc-rules.md');
        preg_match_all('/\|\s*(\d{4}-\d{2}-\d{2})\s*\|\s*$/m', $rules, $m);
        $this->assertNotEmpty($m[1], 'bc-rules.md has no review-by dates');
        $past = array_values(array_filter($m[1], fn($d) => $d < date('Y-m-d')));
        $this->assertSame([], $past, 'Re-check these BC rules (and with Steve Lim where marked), then move their review-by dates');
    }
}
