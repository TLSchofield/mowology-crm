<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Core/ApiResponse.php';

/**
 * Fable review 2026-10-12: ~150 catch sites echoed $e->getMessage() (SQL, file paths, library
 * internals) to the browser. They now go through ApiResponse::message(), which logs the detail and
 * shows a generic sentence for anything but our own validation exceptions.
 */
class NoRawExceptionMessagesTest extends TestCase
{
    /** Admin-only database tools keep raw SQL errors on purpose. */
    private const ALLOW = ['/Modules/Database/Api/', '/run-migration-'];

    private $prevLog;
    protected function setUp(): void { $this->prevLog = ini_set('error_log', '/dev/null'); }
    protected function tearDown(): void { ini_set('error_log', (string)$this->prevLog); }

    public function test_no_api_echoes_a_raw_exception_message(): void
    {
        $root = dirname(__DIR__, 3);
        $files = array_merge(
            glob($root . '/public/crm/api/*.php'), glob($root . '/public/crm/api/*/*.php'),
            glob($root . '/app/Modules/*/Api/*.php')
        );
        $bad = [];
        foreach ($files as $f) {
            foreach (self::ALLOW as $a) if (strpos($f, $a) !== false) continue 2;
            foreach (file($f) as $n => $line) {
                if (strpos($line, 'getMessage()') === false || stripos($line, 'error_log') !== false) continue;
                if (preg_match('/\b(echo|print|exit|die)\b|json_encode\(|=>\s*(htmlspecialchars\()?\$\w+->getMessage\(\)|(Err|error|fail)\w*\(\s*\$\w+->getMessage\(\)/i', $line)
                    && !preg_match('/ApiResponse::message\(/', $line)) {
                    $bad[] = substr($f, strlen($root) + 1) . ':' . ($n + 1);
                }
            }
        }
        $this->assertSame([], $bad, "Raw exception messages sent to the client:\n" . implode("\n", $bad));
    }

    public function test_internal_errors_become_generic(): void
    {
        $this->assertSame(ApiResponse::GENERIC, ApiResponse::message(new PDOException('SQLSTATE[42S22]: Column not found'), 't'));
        $this->assertSame(ApiResponse::GENERIC, ApiResponse::message(new RuntimeException('failed to open /home/mowology/public_html/x.php'), 't'));
        $this->assertSame(ApiResponse::GENERIC, ApiResponse::message(new ErrorException('Undefined index'), 't'));
        $this->assertSame(ApiResponse::GENERIC, ApiResponse::message(new TypeError('x'), 't'));
        $this->assertSame('Invoice not found', ApiResponse::message(new Exception('Invoice not found'), 't'));
        $this->assertSame('Password must be at least 8 characters', ApiResponse::message(new InvalidArgumentException('Password must be at least 8 characters'), 't'));
    }

    public function test_namespaced_library_exceptions_are_hidden(): void
    {
        $e = new class('No such customer: cus_123; api_key sk_live_x') extends Exception {};
        // anonymous classes have no backslash in get_class() — the key-material filter catches this one
        $this->assertSame(ApiResponse::GENERIC, ApiResponse::message($e, 't'));
        $this->assertFalse(ApiResponse::isSafe(new JsonException('Syntax error')));
    }
}
