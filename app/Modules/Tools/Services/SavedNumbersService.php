<?php
declare(strict_types=1);

/** Stores opt-in calculator inputs and manages their secret links. */
final class SavedNumbersService
{
    public const SAVE_TEXT = 'Save my numbers so I can come back to them';
    public const NEWS_TEXT = 'Send me Ittai news by email. You can unsubscribe at any time.';
    public const BENCHMARK_TEXT = 'Add my numbers, without my name, to local benchmarks (shown only for groups of 10 or more businesses).';
    private PDO $db;
    /** Creates a saved-numbers service. */
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
    private static function token(): array
    {
        $raw = random_bytes(32);
        return [
            rtrim(strtr(base64_encode($raw), '+/', '-_'), '='),
            hash('sha256', $raw),
        ];
    }

    private static function hashToken(string $token): string
    {
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        return $raw === false ? '' : hash('sha256', $raw);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
    /** Creates a pending save and returns its secret action tokens. */
    public function createPending(string $email, string $tool, array $inputs, bool $news, bool $benchmark, string $source): array
    {
        [$confirm, $confirmHash] = self::token();
        [$delete, $deleteHash] = self::token();
        $email = strtolower(trim($email));
        $sql = 'INSERT INTO saved_numbers '
            . '(email,email_hash,tool,inputs_json,version,status,confirm_token_hash,delete_token_hash,'
            . 'consent_save_text,consent_news_text,consent_benchmark_text,source_page,created_at,last_used_at) '
            . 'VALUES (?,?,?,?,1,\'pending\',?,?,?,?,?,?,?,?)';
        $statement = $this->db->prepare($sql);
        $now = $this->now();
        $statement->execute([
            $email,
            hash('sha256', $email),
            $tool,
            json_encode($inputs),
            $confirmHash,
            $deleteHash,
            self::SAVE_TEXT,
            $news ? self::NEWS_TEXT : null,
            $benchmark ? self::BENCHMARK_TEXT : null,
            $source,
            $now,
            $now,
        ]);
        return [
            'id' => (int) $this->db->lastInsertId(),
            'confirm_token' => $confirm,
            'delete_token' => $delete,
        ];
    }
    private function row(string $column, string $token): ?array
    {
        $hash = self::hashToken($token);
        if ($hash === '') {
            return null;
        }
        $statement = $this->db->prepare("SELECT * FROM saved_numbers WHERE {$column} = ? LIMIT 1");
        $statement->execute([$hash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row && hash_equals((string) $row[$column], $hash) ? $row : null;
    }
    /** Finds a row authorized by a confirmation token. */
    public function findConfirm(string $token): ?array
    {
        return $this->row('confirm_token_hash', $token);
    }

    /** Finds a row authorized by a deletion token. */
    public function findDelete(string $token): ?array
    {
        return $this->row('delete_token_hash', $token);
    }

    /** Confirms a pending save without changing its deletion token. */
    public function confirm(string $token): ?array
    {
        $row = $this->findConfirm($token);
        if (!$row || $row['status'] !== 'pending') {
            return null;
        }
        $now = $this->now();
        $sql = 'UPDATE saved_numbers SET status=\'confirmed\',confirmed_at=?,last_used_at=?,'
            . 'consent_save_at=?,consent_news_at=?,consent_benchmark_at=? WHERE id=? AND status=\'pending\'';
        $statement = $this->db->prepare($sql);
        $statement->execute([
            $now,
            $now,
            $now,
            $row['consent_news_text'] !== null ? $now : null,
            $row['consent_benchmark_text'] !== null ? $now : null,
            $row['id'],
        ]);
        return $this->findDeleteTokenRow((int) $row['id']);
    }

    private function findDeleteTokenRow(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM saved_numbers WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    /** Deletes a row authorized by its deletion token. */
    public function delete(string $token): bool
    {
        $row = $this->findDelete($token);
        if (!$row) {
            return false;
        }
        $statement = $this->db->prepare('DELETE FROM saved_numbers WHERE id = ?');
        $statement->execute([$row['id']]);
        return $statement->rowCount() === 1;
    }

    /** Marks a confirmed row as recently used. */
    public function keep(string $token): bool
    {
        $row = $this->findConfirm($token);
        if (!$row || $row['status'] !== 'confirmed') {
            return false;
        }
        $statement = $this->db->prepare('UPDATE saved_numbers SET last_used_at = ?, reminder_sent_at = NULL WHERE id = ?');
        $statement->execute([$this->now(), $row['id']]);
        return true;
    }

    /** Rotates both secret links for a reminder email. */
    public function issueReminderTokens(int $id): array
    {
        [$keep, $keepHash] = self::token();
        [$delete, $deleteHash] = self::token();
        $statement = $this->db->prepare(
            'UPDATE saved_numbers SET confirm_token_hash = ?, delete_token_hash = ? WHERE id = ?'
        );
        $statement->execute([$keepHash, $deleteHash, $id]);
        return ['keep' => $keep, 'delete' => $delete];
    }

    /** Purges expired rows and sends due reminders. */
    public function purge(?DateTimeImmutable $at = null, ?callable $remind = null): array
    {
        $at = $at ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $pendingCutoff = $at->modify('-48 hours')->format('Y-m-d H:i:s');
        $unusedCutoff = $at->modify('-12 months')->format('Y-m-d H:i:s');
        $warningCutoff = $at->modify('-11 months -15 days')->format('Y-m-d H:i:s');

        $statement = $this->db->prepare("DELETE FROM saved_numbers WHERE status = 'pending' AND created_at < ?");
        $statement->execute([$pendingCutoff]);
        $pendingDeleted = $statement->rowCount();

        $statement = $this->db->prepare(
            "DELETE FROM saved_numbers WHERE status = 'confirmed' AND COALESCE(last_used_at, confirmed_at) < ?"
        );
        $statement->execute([$unusedCutoff]);
        $confirmedDeleted = $statement->rowCount();

        $sql = "SELECT * FROM saved_numbers WHERE status = 'confirmed' "
            . 'AND COALESCE(last_used_at, confirmed_at) < ? '
            . 'AND COALESCE(last_used_at, confirmed_at) >= ? AND reminder_sent_at IS NULL';
        $statement = $this->db->prepare($sql);
        $statement->execute([$warningCutoff, $unusedCutoff]);
        $remindersSent = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tokens = $this->issueReminderTokens((int) $row['id']);
            if ($remind === null || $remind($row, $tokens['keep'], $tokens['delete']) !== false) {
                $update = $this->db->prepare('UPDATE saved_numbers SET reminder_sent_at = ? WHERE id = ?');
                $update->execute([$at->format('Y-m-d H:i:s'), $row['id']]);
                $remindersSent++;
            }
        }
        return [
            'pending_deleted' => $pendingDeleted,
            'confirmed_deleted' => $confirmedDeleted,
            'reminders_sent' => $remindersSent,
        ];
    }
}
