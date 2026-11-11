<?php
declare(strict_types=1);

/** SQLite stand-in for MySQL's INSERT IGNORE (the stores' Message-ID dedupe). */
class IcloudDedupePdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $query), $options);
    }
}
