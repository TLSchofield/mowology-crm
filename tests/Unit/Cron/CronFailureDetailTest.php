<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Standing rule (owner, 2026-10-21): a failed cron step is recorded in cron_runs with the exception's class,
 * file:line AND message — never just "failed" or the class name. "Otto auto-place failed" nine runs in a row
 * left Ada with nothing to act on. cronFailureDetail() builds that text; no cron script may log a bare
 * get_class($e).
 */
class CronFailureDetailTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!function_exists('cronFailureDetail')) {
            // The helper alone — CrmFunctions.php is not loadable on its own in a test.
            $src = (string)file_get_contents(__DIR__ . '/../../../app/Services/CrmFunctions.php');
            if (!preg_match('/function cronFailureDetail\(.*?\n}\n/s', $src, $m)) {
                self::fail('cronFailureDetail() is missing from app/Services/CrmFunctions.php');
            }
            eval($m[0]);
        }
    }

    public function testNamesClassFileLineAndMessage(): void
    {
        $d = cronFailureDetail(new RuntimeException("boom\n   on two lines"));
        $this->assertStringContainsString('RuntimeException at CronFailureDetailTest.php:', $d);
        $this->assertStringEndsWith('— boom on two lines', $d);
    }

    public function testMessageIsTrimmedTo200Characters(): void
    {
        $d = cronFailureDetail(new LogicException(str_repeat('x', 500)));
        $this->assertSame(200, strlen(substr($d, strpos($d, '— ') + strlen('— '))));
    }

    /** @return array<string, array{string}> */
    public static function cronScripts(): array
    {
        $out = [];
        foreach (glob(__DIR__ . '/../../../app/Modules/*/Cron/*.php') ?: [] as $f) {
            $out[basename(dirname(dirname($f))) . '/' . basename($f)] = [$f];
        }
        return $out;
    }

    /** @dataProvider cronScripts */
    public function testNoCatchLogsOnlyTheClass(string $file): void
    {
        $this->addToAssertionCount(1);   // a script with no get_class() catch passes by having nothing to check
        $tokens = token_get_all((string)file_get_contents($file));
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CATCH) continue;
            $line = $tokens[$i][2];
            while ($i < $n && $tokens[$i] !== '{') $i++;          // skip "(Type $e)", comments and all
            $depth = 0;
            $usesClass = $namesCause = false;
            for (; $i < $n; $i++) {
                $t = $tokens[$i];
                if ($t === '{') $depth++;
                elseif ($t === '}' && --$depth === 0) break;
                elseif (is_array($t) && $t[0] === T_CURLY_OPEN || is_array($t) && $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) $depth++;
                elseif (is_array($t) && $t[0] === T_STRING) {      // code only: comments and strings are other token types
                    if (strcasecmp($t[1], 'get_class') === 0) $usesClass = true;
                    if (in_array(strtolower($t[1]), ['getmessage', 'cronfailuredetail'], true)) $namesCause = true;
                }
            }
            if ($usesClass) {
                $this->assertTrue($namesCause, basename($file) . ' line ' . $line
                    . ': a catch logs get_class() without the message — use cronFailureDetail($e)');
            }
        }
    }

    public function testRedactsSecretsAndKeepsMultibyteValid(): void
    {
        $d = cronFailureDetail(new RuntimeException(
            'cURL error for https://maps.example.com/geocode?address=1+Main&key=AIzaSECRET failed; '
            . 'mysql://root:hunter2@db/x ; Authorization: Bearer abc.def ; password=hunter2'
        ));
        foreach (['AIzaSECRET', 'hunter2', 'abc.def'] as $secret) {
            $this->assertStringNotContainsString($secret, $d);
        }
        $this->assertStringContainsString('https://maps.example.com/geocode?[redacted]', $d);

        $m = cronFailureDetail(new RuntimeException(str_repeat('é', 300)));
        $this->assertTrue(mb_check_encoding($m, 'UTF-8'));
    }
}
