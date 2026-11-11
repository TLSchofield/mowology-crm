<?php
declare(strict_types=1);

/**
 * In-memory SQLite that accepts the few MySQL-isms Mia's email hub writes:
 * INSERT IGNORE, ON DUPLICATE KEY UPDATE … VALUES(x), and NOW().
 */
class MiaHubTestDb extends PDO
{
    public string $now = '2026-10-06 09:00:00';

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $self = $this;
        @$this->sqliteCreateFunction('NOW', fn() => $self->now, 0);
    }

    public static function translate(string $sql): string
    {
        $sql = (string)preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $sql);
        if (preg_match('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $sql)) {
            $sql = (string)preg_replace('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', 'ON CONFLICT DO UPDATE SET', $sql);
            $sql = (string)preg_replace('/\bVALUES\((\w+)\)/i', 'excluded.$1', $sql);
        }
        return $sql;
    }

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        return parent::prepare(self::translate($query), $options);
    }

    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        return parent::exec(self::translate($statement));
    }
}
