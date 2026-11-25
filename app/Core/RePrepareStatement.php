<?php
declare(strict_types=1);

/**
 * PDOStatement that survives MySQL 1615 ("Prepared statement needs to be re-prepared").
 *
 * On production a server-side prepared write to a heavily referenced table can fail with 1615 every time — seen on
 * `expenses` (ExpenseGate, 2026-10-26) and on `users` in business database rehearsal2 (sign-up claim and the
 * onboarding name step, 2026-11-04; proven live: prepared fails, the same statement emulated works). Turning
 * emulation on for the whole connection is not safe: 40 queries bind `LIMIT ?`, which emulation would quote.
 *
 * So only a statement that failed with 1615 is retried, once, as an emulated statement, with each value bound by
 * its PHP type (an int stays an int, so `LIMIT ?` still works). Everything else behaves exactly as before.
 * Installed by getDB() through PDO::ATTR_STATEMENT_CLASS. Not covered: a 1615 raised inside PDO::query() (PDO runs
 * that internally, not through execute()); every write here goes through prepare()->execute().
 */
class RePrepareStatement extends PDOStatement
{
    /** @var PDO */
    private $pdo;
    /** @var PDOStatement|null the emulated copy that ran instead, read from by fetch*() */
    private $retry = null;
    /** @var array|null the last setFetchMode() arguments, carried to the copy (Codex review 2026-11-04) */
    private $fetchMode = null;

    protected function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Pure (tested): is this the 1615 re-prepare error? */
    public static function isReprepare(Throwable $e): bool
    {
        $info = $e instanceof PDOException ? ($e->errorInfo ?? null) : null;
        if (is_array($info) && isset($info[1]) && (int)$info[1] === 1615) return true;
        return strpos($e->getMessage(), '1615') !== false && stripos($e->getMessage(), 're-prepared') !== false;
    }

    /** Pure (tested): the PDO type each value is bound with on the retry. */
    public static function paramType($value): int
    {
        if (is_int($value)) return PDO::PARAM_INT;
        if (is_bool($value)) return PDO::PARAM_BOOL;
        if ($value === null) return PDO::PARAM_NULL;
        return PDO::PARAM_STR;
    }

    #[\ReturnTypeWillChange]
    public function execute($params = null)
    {
        $this->retry = null;
        try {
            return parent::execute($params);
        } catch (PDOException $e) {
            // Values bound one by one (bindValue) are not visible here: rethrow rather than run without them.
            if (!self::isReprepare($e) || ($params !== null && !is_array($params))) throw $e;
            if ($params === null && strpos($this->queryString, '?') !== false) throw $e;
            if ($params === null && preg_match('/:[A-Za-z_]/', $this->queryString)) throw $e;
            $was = $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
            try {
                $s = $this->pdo->prepare($this->queryString);
                if ($this->fetchMode !== null) $s->setFetchMode(...$this->fetchMode);
                foreach ((array)$params as $k => $v) {
                    $s->bindValue(is_int($k) ? $k + 1 : (strpos((string)$k, ':') === 0 ? $k : ':' . $k), $v, self::paramType($v));
                }
                $ok = $s->execute();
                $this->retry = $s;
                error_log('[db] 1615 re-prepared as emulated: ' . substr(preg_replace('/\s+/', ' ', $this->queryString), 0, 120));
                return $ok;
            } finally {
                $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $was);
            }
        }
    }

    // Variadic pass-through: the same on PHP 7.4 (production) and 8.x (no FETCH_DEFAULT on 7.4).
    #[\ReturnTypeWillChange]
    public function fetch(...$args)
    {
        return $this->retry ? $this->retry->fetch(...$args) : parent::fetch(...$args);
    }

    #[\ReturnTypeWillChange]
    public function fetchAll(...$args)
    {
        return $this->retry ? $this->retry->fetchAll(...$args) : parent::fetchAll(...$args);
    }

    #[\ReturnTypeWillChange]
    public function fetchColumn(...$args)
    {
        return $this->retry ? $this->retry->fetchColumn(...$args) : parent::fetchColumn(...$args);
    }

    #[\ReturnTypeWillChange]
    public function setFetchMode($mode, ...$args)
    {
        $this->fetchMode = array_merge([$mode], $args);
        if ($this->retry) $this->retry->setFetchMode($mode, ...$args);
        return parent::setFetchMode($mode, ...$args);
    }

    #[\ReturnTypeWillChange]
    public function closeCursor()
    {
        if ($this->retry) { $r = $this->retry->closeCursor(); $this->retry = null; parent::closeCursor(); return $r; }
        return parent::closeCursor();
    }

    #[\ReturnTypeWillChange]
    public function columnCount()
    {
        return $this->retry ? $this->retry->columnCount() : parent::columnCount();
    }

    #[\ReturnTypeWillChange]
    public function getColumnMeta($column)
    {
        return $this->retry ? $this->retry->getColumnMeta($column) : parent::getColumnMeta($column);
    }

    #[\ReturnTypeWillChange]
    public function errorCode()
    {
        return $this->retry ? $this->retry->errorCode() : parent::errorCode();
    }

    #[\ReturnTypeWillChange]
    public function errorInfo()
    {
        return $this->retry ? $this->retry->errorInfo() : parent::errorInfo();
    }

    #[\ReturnTypeWillChange]
    public function nextRowset()
    {
        return $this->retry ? $this->retry->nextRowset() : parent::nextRowset();
    }

    #[\ReturnTypeWillChange]
    public function rowCount()
    {
        return $this->retry ? $this->retry->rowCount() : parent::rowCount();
    }
}
