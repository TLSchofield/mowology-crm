<?php
declare(strict_types=1);

/** Counts anonymous daily calculator events. */
final class ToolUsageCounter
{
    private PDO $db;

    /** Creates a usage counter. */
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Increments an allowed counter for an allowed tool. */
    public function increment(string $tool, string $column = 'calcs'): void
    {
        if (!in_array($tool, ToolCalculator::tools(), true)) {
            return;
        }
        if (!in_array($column, ['calcs', 'saves_confirmed'], true)) {
            return;
        }
        $sql = "INSERT INTO tool_usage_daily (tool, day, {$column}) VALUES (?, CURRENT_DATE, 1) "
            . "ON DUPLICATE KEY UPDATE {$column} = {$column} + 1";
        $this->db->prepare($sql)->execute([$tool]);
    }
}
