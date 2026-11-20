<?php
declare(strict_types=1);

final class RegistrySearch
{
    public string $status;
    /** @var array<int,array<string,mixed>> */
    public array $matches;

    public function __construct(string $status, array $matches = [])
    {
        $this->status = $status;
        $this->matches = array_slice($matches, 0, 5);
    }
}

final class RegistryRecord
{
    /** @var array<string,mixed> */
    public array $fields;

    public function __construct(array $fields) { $this->fields = $fields; }
}

interface BusinessRegistryProvider
{
    public function search(string $name): RegistrySearch;
    public function fetch(string $recordId): ?RegistryRecord;
}
