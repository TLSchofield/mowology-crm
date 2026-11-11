<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Regression: OrgBook ignores topic_source_id on search/topic; confirm must query by q. */
final class OrgBookBcProviderTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/orgbook-confirm-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
    }

    public function testConfirmationQueriesRegistrationAndSelectsExactRecordAfterUnrelatedResult(): void
    {
        $requestedUrl = null;
        $provider = $this->provider([
            $this->topic('FM0000001', 'Unrelated Synthetic Business'),
            $this->topic('BC0944284', 'Synthetic Lawn Care Ltd.'),
        ], $requestedUrl);

        $record = $provider->fetch('BC0944284');

        $this->assertNotNull($record);
        $this->assertSame('BC0944284', $record->fields['record_id']);
        $this->assertSame('Synthetic Lawn Care Ltd.', $record->fields['legal_name']);
        $query = [];
        parse_str((string)parse_url((string)$requestedUrl, PHP_URL_QUERY), $query);
        $this->assertSame('BC0944284', $query['q'] ?? null);
        $this->assertArrayNotHasKey('topic_source_id', $query);
        $this->assertSame('true', $query['latest'] ?? null);
        $this->assertSame('any', $query['inactive'] ?? null, 'true excludes active companies; any includes active and historical');
        $this->assertSame('/api/v4/search/topic', parse_url((string)$requestedUrl, PHP_URL_PATH));
    }

    public function testConfirmationRejectsSearchResultsWithoutExactSourceId(): void
    {
        $requestedUrl = null;
        $provider = $this->provider([
            $this->topic('BC09442840', 'Similar Synthetic Lawn Care Ltd.'),
            $this->topic('BC0000002', 'Another Synthetic Business'),
        ], $requestedUrl);

        $this->assertNull($provider->fetch('BC0944284'));
    }

    private function topic(string $id, string $name): array
    {
        return ['topic' => ['source_id' => $id, 'names' => [['type' => 'entity_name', 'text' => $name]]]];
    }

    private function provider(array $items, ?string &$requestedUrl): OrgBookBcProvider
    {
        $fetcher = static function (string $url, callable $writer) use ($items, &$requestedUrl): int {
            $requestedUrl = $url;
            $writer(null, (string)json_encode(['results' => $items]));
            return 200;
        };
        return new OrgBookBcProvider('synthetic-test-tenant', $this->cacheDir, $fetcher);
    }
}
