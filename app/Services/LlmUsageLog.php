<?php
declare(strict_types=1);

/**
 * LlmUsageLog — one row per paid AI call: purpose, model, tokens, ok, receipt (migration 1422). Quiet before the migration exists
 * and never the reason a receipt fails. Tokens only — no receipt text is stored. Dollars are worked out from tokens when needed,
 * because prices change and this table must not.
 *
 * Global namespace; require_once it.
 */
final class LlmUsageLog
{
    public static function record(PDO $db, string $purpose, string $model, int $in, int $out, bool $ok, ?int $expenseId = null, ?string $note = null): void
    {
        try {
            $db->prepare("INSERT INTO llm_usage_log (purpose, model, input_tokens, output_tokens, ok, expense_id, note) VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([mb_substr($purpose, 0, 40), mb_substr($model, 0, 60), max(0, $in), max(0, $out), $ok ? 1 : 0, $expenseId, $note !== null ? mb_substr($note, 0, 120) : null]);
        } catch (Throwable $e) {
            // Table not there yet, or a hiccup: the call already happened, the receipt carries on.
        }
    }

    /** Calls and tokens per purpose over the last $days days. @return list<array{purpose:string, calls:int, failed:int, input_tokens:int, output_tokens:int}> */
    public static function summary(PDO $db, int $days = 30): array
    {
        try {
            $s = $db->prepare("SELECT purpose, COUNT(*) calls, SUM(ok = 0) failed, SUM(input_tokens) tin, SUM(output_tokens) tout
                               FROM llm_usage_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY purpose ORDER BY calls DESC");
            $s->execute([max(1, $days)]);
            return array_map(fn($r) => ['purpose' => (string)$r['purpose'], 'calls' => (int)$r['calls'], 'failed' => (int)$r['failed'],
                                        'input_tokens' => (int)$r['tin'], 'output_tokens' => (int)$r['tout']], $s->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }
}
