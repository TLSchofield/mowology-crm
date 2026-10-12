<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Session JSON APIs: a database error never reaches the screen, and a SQLSTATE code never
 * reaches http_response_code() (review round 2, 2026-10-12).
 */
class ApiResponseTest extends TestCase
{
    public function test_status_uses_only_int_http_codes(): void
    {
        $this->assertSame(403, ApiResponse::status(new Exception('Admin only', 403)));
        $this->assertSame(500, ApiResponse::status(new Exception('x', 0)));
        $this->assertSame(500, ApiResponse::status(new Exception('x', 23000)));
        $pdo = new PDOException('SQLSTATE[42S22]: Column not found');
        // PDOException's code is a string such as '42S22'
        (function () { $this->code = '42S22'; })->call($pdo);
        $this->assertSame(500, ApiResponse::status($pdo));
    }

    public function test_message_hides_database_and_php_errors(): void
    {
        $this->assertSame('Missing id', ApiResponse::message(new Exception('Missing id', 400)));
        $old = ini_set('error_log', '/dev/null');
        $this->assertSame(ApiResponse::GENERIC, ApiResponse::message(new PDOException('SQLSTATE[42S22]: Column not found: expenses.foo')));
        $this->assertSame(ApiResponse::GENERIC, ApiResponse::message(new TypeError('bad arg')));
        $this->assertSame(ApiResponse::GENERIC, ApiResponse::message(new RuntimeException('wrapped', 0, new PDOException('x'))));
        $this->assertSame(ApiResponse::GENERIC, ApiResponse::message(new Exception('Insert failed: SQLSTATE[23000] duplicate')));
        ini_set('error_log', (string)$old);
    }

    public function test_csrf_token_from_body_post_or_header(): void
    {
        $this->assertSame('a', ApiResponse::csrfToken(['csrf_token' => 'a']));
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'h';
        $this->assertSame('h', ApiResponse::csrfToken([]));
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $this->assertSame('', ApiResponse::csrfToken(['csrf_token' => ['x']]));
    }
}
