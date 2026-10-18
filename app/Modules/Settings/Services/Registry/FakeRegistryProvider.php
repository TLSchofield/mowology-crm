<?php
declare(strict_types=1);

require_once __DIR__ . '/BusinessRegistryProvider.php';

final class FakeRegistryProvider implements BusinessRegistryProvider
{
    public RegistrySearch $searchResult;
    /** @var array<string,RegistryRecord> */
    public array $records;
    public int $fetchCount = 0;

    public function __construct(?RegistrySearch $searchResult = null, array $records = [])
    {
        $this->searchResult = $searchResult ?? new RegistrySearch('none');
        $this->records = $records;
    }

    public function search(string $name): RegistrySearch { return $this->searchResult; }

    public function fetch(string $recordId): ?RegistryRecord
    {
        $this->fetchCount++;
        return $this->records[$recordId] ?? null;
    }
}
