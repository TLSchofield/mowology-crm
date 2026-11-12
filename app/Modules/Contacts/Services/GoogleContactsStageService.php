<?php
declare(strict_types=1);

final class GoogleContactsStageService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function stage(string $nonce, int $userId, array $contacts): void
    {
        $this->purge();
        $payload = json_encode(array_values($contacts), JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) throw new InvalidArgumentException('Contacts could not be staged.');
        // Stay under MEDIUMTEXT (16 MiB) with room to spare: a refused list is told to the owner, never half-saved.
        if (strlen($payload) > 8000000) throw new LengthException('That Google contact list is too large to bring in at once.');
        $this->db->prepare('DELETE FROM onboarding_google_contacts_stage WHERE nonce = ?')->execute([$nonce]);
        $this->db->prepare('INSERT INTO onboarding_google_contacts_stage (nonce, user_id, payload, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$nonce, $userId, $payload, gmdate('Y-m-d H:i:s')]);
    }

    public function take(string $nonce, int $userId): ?array
    {
        $this->purge();
        // created_at is always written and compared in UTC from PHP, so the database's own time zone never matters.
        $statement = $this->db->prepare('SELECT payload, created_at FROM onboarding_google_contacts_stage WHERE nonce = ? AND user_id = ? LIMIT 1');
        $statement->execute([$nonce, $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;   // another user's nonce is left alone: only its owner may use or consume it
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
