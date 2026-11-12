<?php
declare(strict_types=1);

final class GoogleContactsStageService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** $grant is a one-time secret that only travels in the browser's redirect back to the phone that ran the consent; only its hash is kept. */
    public function stage(string $nonce, int $userId, array $contacts, string $grant): void
    {
        $this->purge();
        $payload = json_encode(array_values($contacts), JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) throw new InvalidArgumentException('Contacts could not be staged.');
        // Stay under MEDIUMTEXT (16 MiB) with room to spare: a refused list is told to the owner, never half-saved.
        if (strlen($payload) > 8000000) throw new LengthException('That Google contact list is too large to bring in at once.');
        $this->db->prepare('DELETE FROM onboarding_google_contacts_stage WHERE nonce = ?')->execute([$nonce]);
        $this->db->prepare('INSERT INTO onboarding_google_contacts_stage (nonce, user_id, grant_hash, payload, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$nonce, $userId, hash('sha256', $grant), $payload, gmdate('Y-m-d H:i:s')]);
    }

    public function take(string $nonce, int $userId, string $grant): ?array
    {
        $this->purge();
        // created_at is always written and compared in UTC from PHP, so the database's own time zone never matters.
        $statement = $this->db->prepare('SELECT payload, created_at, grant_hash FROM onboarding_google_contacts_stage WHERE nonce = ? AND user_id = ? LIMIT 1');
        $statement->execute([$nonce, $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        // Another user's nonce, or a missing/wrong grant, is left alone: only the phone that completed the consent holds the grant.
        if (!is_array($row) || !hash_equals((string)$row['grant_hash'], hash('sha256', $grant))) return null;
        // The delete is the lock: of two parallel requests only the one that removes the row gets the list.
        $gone = $this->db->prepare('DELETE FROM onboarding_google_contacts_stage WHERE nonce = ? AND user_id = ?');
        $gone->execute([$nonce, $userId]);
        if ($gone->rowCount() !== 1 || strtotime((string)$row['created_at'] . ' UTC') <= time() - 1800) return null;
        $contacts = json_decode((string)$row['payload'], true);
        return is_array($contacts) ? array_values($contacts) : null;
    }

    private function purge(): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - 1800);
        $this->db->prepare('DELETE FROM onboarding_google_contacts_stage WHERE created_at <= ?')->execute([$cutoff]);
    }
}
