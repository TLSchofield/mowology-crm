<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Auth/Services/SignUpDirectory.php';

final class SignUpDirectoryTest extends TestCase
{
    private function business(string $key, string $url = 'https://example.ittai.ca'): array
    {
        return ['key' => $key, 'app_url' => $url, 'test' => $key === 'test'];
    }

    private function db(string $email, bool $claimed = false): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE users (id INTEGER, email TEXT, password_hash TEXT, full_name TEXT, role TEXT, last_login TEXT)');
        $db->exec('CREATE TABLE ops_settings (setting_key TEXT, setting_value TEXT)');
        $stmt = $db->prepare("INSERT INTO users VALUES (1, ?, '', '', 'admin', ?)");
        $stmt->execute([$email, $claimed ? '2026-01-01 00:00:00' : null]);
        $setting = $db->prepare("INSERT INTO ops_settings VALUES ('tenant_claimed_at', ?)");
        $setting->execute([$claimed ? '2026-01-01 00:00:00' : '']);
        return $db;
    }

    private function directory(array $businesses, array $dbs, ?callable $limited = null, ?callable $failed = null): SignUpDirectory
    {
        return new SignUpDirectory(
            $businesses,
            static function (string $key) use ($dbs): PDO {
                $db = $dbs[$key] ?? null;
                if (!$db instanceof PDO) throw new RuntimeException('database down');
                return $db;
            },
            $limited ?? static fn(PDO $db, string $email, string $ip): bool => false,
            $failed ?? static function (string $email, string $ip, PDO $db): void {},
            null,
            static fn(PDO $db): string => 'Shown Business'
        );
    }

    public function testOneUnclaimedMatch(): void
    {
        $service = $this->directory([$this->business('one')], ['one' => $this->db('Owner@Example.com')]);
        $result = $service->lookup('owner@example.com', '192.0.2.1');
        self::assertSame('ok', $result['status']);
        self::assertSame(['key' => 'one', 'name' => 'Shown Business', 'app_url' => 'https://example.ittai.ca', 'test' => false], $result['business']);
    }

    public function testClaimedBusinessIsNotAvailable(): void
    {
        $service = $this->directory([$this->business('one')], ['one' => $this->db('owner@example.com', true)]);
        self::assertSame(['status' => 'not_available'], $service->lookup('owner@example.com', '192.0.2.1'));
    }

    public function testNoMatchIsNotAvailable(): void
    {
        $failures = [];
        $failed = static function (string $email, string $ip, PDO $db) use (&$failures): void {
            $failures[] = [$email, $ip];
        };
        $service = $this->directory([$this->business('one')], ['one' => $this->db('other@example.com')], null, $failed);
        self::assertSame(['status' => 'not_available'], $service->lookup('owner@example.com', '192.0.2.1'));
        self::assertSame([['owner@example.com', '192.0.2.1']], $failures);
    }

    public function testTwoMatchesAreNotAvailable(): void
    {
        $businesses = [$this->business('one'), $this->business('two', 'https://two.ittai.ca')];
        $service = $this->directory($businesses, ['one' => $this->db('owner@example.com'), 'two' => $this->db('owner@example.com')]);
        self::assertSame(['status' => 'not_available'], $service->lookup('owner@example.com', '192.0.2.1'));
    }

    public function testMatchWithoutAppUrlIsNotAvailable(): void
    {
        $service = $this->directory([$this->business('one', '')], ['one' => $this->db('owner@example.com')]);
        self::assertSame(['status' => 'not_available'], $service->lookup('owner@example.com', '192.0.2.1'));
    }

    public function testUnopenableDatabaseIsSkipped(): void
    {
        $businesses = [$this->business('down'), $this->business('one')];
        $service = $this->directory($businesses, ['one' => $this->db('owner@example.com')]);
        self::assertSame('ok', $service->lookup('owner@example.com', '192.0.2.1')['status']);
    }

    public function testUnavailableCasesProduceIdenticalPublicBodies(): void
    {
        $cases = [
            $this->directory([$this->business('one')], ['one' => $this->db('other@example.com')]),
            $this->directory([$this->business('one')], ['one' => $this->db('owner@example.com', true)]),
            $this->directory([$this->business('one', '')], ['one' => $this->db('owner@example.com')]),
        ];
        $bodies = [];
        foreach ($cases as $service) {
            $result = $service->lookup('owner@example.com', '192.0.2.1');
            $bodies[] = json_encode($result['status'] === 'not_available' ? ['error' => 'not_available'] : $result);
        }
        self::assertCount(1, array_unique($bodies));
        self::assertSame('{"error":"not_available"}', $bodies[0]);
    }

    public function testRateLimitUsesEmailAndIpAndDoesNotRecordAnotherFailure(): void
    {
        $seen = [];
        $failures = 0;
        $limited = static function (PDO $db, string $email, string $ip) use (&$seen): bool {
            $seen = [$email, $ip];
            return true;
        };
        $failed = static function () use (&$failures): void { $failures++; };
        $service = $this->directory([$this->business('one')], ['one' => $this->db('owner@example.com')], $limited, $failed);
        self::assertSame(['status' => 'rate_limited'], $service->lookup('OWNER@example.com', '192.0.2.9'));
        self::assertSame(['owner@example.com', '192.0.2.9'], $seen);
        self::assertSame(0, $failures);
    }
}
