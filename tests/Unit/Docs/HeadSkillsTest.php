<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Keeps every head's skill true (owner, 2026-10-11): Penny, Kaname, Sam, Otto, Mia, Yui, Ada and
 * the shared regions. For each skill under .claude/skills/<head-…>/:
 *  - every `app/…`, `public/…`, `scripts/…` path and `Class::method()` / `function()` it names exists;
 *  - it has a "Before and after every change (Obsidian + Graphify)" section that sends you to the
 *    Obsidian notes and to graphify;
 *  - its frontmatter lists `covers:` (the code it describes) — the end-of-session hook
 *    (~/.claude/hooks/vault-writeback.py) uses it to name the skills to update automatically.
 * Region files: every "review by" date in a table must not have passed.
 */
final class HeadSkillsTest extends TestCase
{
    /** Head skills (folder => display name). Add a head here when it gets a skill. */
    private const HEADS = [
        'penny-bc-bookkeeping'   => 'Penny',
        'kaname-chief-of-staff' => 'Kaname',
        'sam-sales'              => 'Sam',
        'otto-operations'        => 'Otto',
        'mia-marketing'          => 'Mia',
        'yui-comms'              => 'Yui',
        'ada-technology'         => 'Ada',
        'regions'                => 'Regions',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function dir(string $skill): string
    {
        return self::root() . '/.claude/skills/' . $skill;
    }

    private static function text(string $skill): string
    {
        $t = (string)@file_get_contents(self::dir($skill) . '/SKILL.md');
        foreach (glob(self::dir($skill) . '/references/*.md') ?: [] as $f) $t .= "\n" . file_get_contents($f);
        return $t;
    }

    private static ?string $code = null;

    private static function code(): string
    {
        if (self::$code !== null) return self::$code;
        $code = '';
        foreach (['app', 'public/crm', 'public/customer', 'public/includes'] as $d) {
            if (!is_dir(self::root() . '/' . $d)) continue;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root() . '/' . $d, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) if (substr((string)$f, -4) === '.php') $code .= file_get_contents((string)$f);
        }
        return self::$code = $code;
    }

    public static function skills(): array
    {
        $out = [];
        foreach (self::HEADS as $dir => $name) $out[$name] = [$dir];
        return $out;
    }

    /** @dataProvider skills */
    public function testSkillExists(string $skill): void
    {
        $this->assertFileExists(self::dir($skill) . '/SKILL.md');
    }

    /** @dataProvider skills */
    public function testNamedPathsExist(string $skill): void
    {
        preg_match_all('#`((?:app|public|scripts|database|docs|tests|ios)/[A-Za-z0-9_./-]+\.(?:php|sh|sql|md|js|swift|py|json))`#', self::text($skill), $m);
        $missing = array_values(array_filter(array_unique($m[1]), fn($p) => !is_file(self::root() . '/' . $p)));
        $this->assertSame([], $missing, "{$skill} names files that no longer exist");
    }

    /** @dataProvider skills */
    public function testNamedCodeExists(string $skill): void
    {
        $t = self::text($skill);
        $code = self::code();
        $missing = [];
        preg_match_all('/`([A-Z][A-Za-z0-9]+)::([a-z][A-Za-z0-9]+)\(\)`/', $t, $m, PREG_SET_ORDER);
        foreach ($m as [, $class, $method]) {
            if (!preg_match('/class\s+' . $class . '\b/', $code) || !preg_match('/function\s+' . $method . '\s*\(/', $code)) $missing[] = "{$class}::{$method}()";
        }
        preg_match_all('/`([a-z][A-Za-z0-9]+)\(\)`/', $t, $f);
        foreach (array_unique($f[1]) as $fn) {
            if (!preg_match('/function\s+' . $fn . '\s*\(/', $code)) $missing[] = "{$fn}()";
        }
        $this->assertSame([], array_values(array_unique($missing)), "{$skill} names code that no longer exists");
    }

    /** @dataProvider skills */
    public function testSendsYouToObsidianAndGraphify(string $skill): void
    {
        $s = (string)@file_get_contents(self::dir($skill) . '/SKILL.md');
        $this->assertStringContainsString('Before and after every change', $s, "{$skill}: missing the Obsidian + Graphify section");
        $this->assertMatchesRegularExpression('/obsidian/i', $s, "{$skill}: does not send you to the Obsidian notes");
        $this->assertMatchesRegularExpression('/graphify/i', $s, "{$skill}: does not send you to graphify");
    }

    /** @dataProvider skills */
    public function testDeclaresTheCodeItCovers(string $skill): void
    {
        $s = (string)@file_get_contents(self::dir($skill) . '/SKILL.md');
        $front = explode('---', $s, 3)[1] ?? '';
        $this->assertMatchesRegularExpression('/^covers:\s*$(\n\s+-\s+\S+)+/m', $front, "{$skill}: frontmatter needs a covers: list (the end-of-session hook uses it)");
    }

    public function testRegionRulesAreNotPastReview(): void
    {
        $past = [];
        foreach (glob(self::root() . '/.claude/skills/regions/*.md') ?: [] as $f) {
            preg_match_all('/\|\s*(\d{4}-\d{2}-\d{2})\s*\|\s*$/m', (string)file_get_contents($f), $m);
            foreach ($m[1] as $d) if ($d < date('Y-m-d')) $past[] = basename($f) . ' ' . $d;
        }
        $this->assertSame([], $past, 'Re-check these region rules (with Steve Lim where marked), then move their review-by dates');
    }
}
